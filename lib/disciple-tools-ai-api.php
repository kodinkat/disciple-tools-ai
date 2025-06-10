<?php
if ( !defined( 'ABSPATH' ) ){
    exit;
} // Exit if accessed directly

class Disciple_Tools_AI_API {

    public static $module_default_id_dt_ai_list_filter = 'dt_ai_list_filter';
    public static $module_default_id_dt_ai_ml_list_filter = 'dt_ai_ml_list_filter';
    public static $module_default_id_dt_ai_metrics_dynamic_maps = 'dt_ai_metrics_dynamic_maps';

    public static function simplified_list_posts( string $post_type, string $prompt ): array {

        /**
         * Before submitting to LLM for analysis, ensure to obfuscate any PII.
         */

        $original_prompt = $prompt;
        $pii = self::parse_prompt_for_pii( $post_type, $prompt );
        $has_pii = ( !empty( $pii['pii'] ) && !empty( $pii['mappings'] ) && isset( $pii['prompt']['obfuscated'] ) );
        if ( $has_pii ) {
            $prompt = $pii['prompt']['obfuscated'];
        }

        /**
         * Following obfuscation, proceed with LLM call to parse prompt for relevant fields.
         */

        $fields = self::parse_prompt_for_fields( $post_type, $original_prompt, $prompt );

        /**
         * Ensure any encountered errors are echoed back to calling client.
         */

        if ( isset( $fields['status'] ) && $fields['status'] == 'error' ) {
            return $fields;
        }

        /**
         * Next, identify any connections within incoming prompt; especially
         * the ones with multiple options; as these will need to be handled
         * separately, in a different flow, causing the client to select which
         * option to use.
         */

        $locations = [];
        $multiple_locations = [];

        $users = [];
        $multiple_users = [];

        $posts = [];
        $multiple_posts = [];

        $connections = self::parse_fields_for_connections( $post_type, $fields );

        // Extract any locations from identified connections.
        if ( !empty( $connections['locations'] ) ) {
            $locations = self::parse_locations_for_ids( $connections['locations'], $pii['mappings'] ?? [] );

            // Identify locations with multiple options.
            $multiple_locations = array_filter( $locations, function( $location ) {
                return count( $location['options'] ) > 0;
            } );
        }

        // Extract any users (takes priority over posts) or posts from identified connections.
        if ( !empty( $connections['connections'] ) ) {

            /**
             * Users.
             */

            // Extract any users from identified connections.
            $users = self::parse_connections_for_users( $connections['connections'], $post_type, $pii['mappings'] ?? [] );

            // Identify users with multiple options.
            $multiple_users = array_filter( $users, function( $user ) {
                return count( $user['options'] ) > 0;
            } );

            /**
             * Posts.
             */

            // Extract any post-names from identified connections.
            $posts = self::parse_connections_for_post_names( $connections['connections'], $post_type, $pii['mappings'] ?? [] );

            // Identify posts with multiple options.
            $multiple_posts = array_filter( $posts, function( $post ) {
                return count( $post['options'] ) > 0;
            } );
        }

        /**
         * Determine if flow is to be paused, due to multiple options.
         */

        if ( count( array_merge( $multiple_locations, $multiple_users, $multiple_posts ) ) > 0 ) {
            return [
                'status' => 'multiple_options_detected',
                'multiple_options' => [
                    'locations' => $multiple_locations,
                    'users' => $multiple_users,
                    'posts' => $multiple_posts
                ],
                'pii' => $pii,
                'fields' => $fields
            ];
        }

        /**
         * If no multiple options are detected, proceed with system query.
         * But first, ensure any remaining obfuscated entries are mapped back
         * into plain prompt values, or corresponding multiple option ids.
         *
         * Also, reshape fields into required list post query structure.
         */

        $reshaped_fields = self::reshape_fields_to_required_list_post_query_structure( $post_type, $fields, $pii['mappings'], [
            'locations' => $multiple_locations,
            'users' => $multiple_users,
            'posts' => $multiple_posts
        ] );

        /**
         * Finally, query system for posts, using inferred filters.
         */

        $list_posts = [];
        if ( !is_wp_error( $reshaped_fields ) ) {
            $list = DT_Posts::list_posts( $post_type, [
                'fields' => $reshaped_fields
            ] );

            $list_posts = ( !is_wp_error( $list ) && isset( $list['posts'] ) ) ? $list['posts'] : [];
        }

        return [
            'status' => 'success',
            'prompt' => [
                'original' => $has_pii ? $pii['prompt']['original'] : $prompt,
                'parsed' => $has_pii ? $pii['prompt']['obfuscated'] : $prompt
            ],
            'pii' => $pii,
            'connections' => [
                'extracted' => [
                    'locations' => $locations,
                    'users' => $users,
                    'posts' => $posts
                ]
            ],
            'filter' => $reshaped_fields,
            'posts' => $list_posts
        ];
    }

    private static function reshape_selection_mappings( $selections, $pii_mappings, $processed_prompts = [] ): array {
        $reshaped_mappings = [];

        foreach ( $selections ?? [] as $selection ) {
            if ( !in_array( $selection['prompt'], $processed_prompts ) && $selection['id'] !== 'ignore' ) {
                $prompt = $selection['prompt'];
                $pii_prompt = array_search( $prompt, $pii_mappings );

                $reshaped_mappings[] = [
                    'prompt' => $prompt,
                    'pii_prompt' => !empty( $pii_prompt ) ? $pii_prompt : $prompt,
                    'options' => [
                        [
                            'id' => $selection['id'],
                            'label' => $selection['label']
                        ]
                    ]
                ];

                $processed_prompts[] = $prompt;
            }
        }

        return [
            'mappings' => $reshaped_mappings,
            'processed_prompts' => $processed_prompts
        ];
    }

    public static function simplified_list_posts_with_selections( string $post_type, string $prompt, array $selections, array $pii, array $filtered_fields ): array {

        /**
         * First, update prompt with selected replacements.
         */

        $locations = self::reshape_selection_mappings( $selections['locations'] ?? [], $pii['mappings'] );
        $users = self::reshape_selection_mappings( $selections['users'] ?? [], $pii['mappings'], $locations['processed_prompts'] );
        $posts = self::reshape_selection_mappings( $selections['posts'] ?? [], $pii['mappings'], $users['processed_prompts'] );

        /**
         * Ensure any remaining obfuscated entries are mapped back into plain prompt values, before executing returned filter fields.
         */

        $reshaped_fields = self::reshape_fields_to_required_list_post_query_structure( $post_type, $filtered_fields, $pii['mappings'], [
            'locations' => $locations['mappings'],
            'users' => $users['mappings'],
            'posts' => $posts['mappings']
        ] );

        /**
         * Finally, using the filtered fields, query the posts.
         */

        $list_posts = [];
        if ( !is_wp_error( $reshaped_fields ) ) {
            $list = DT_Posts::list_posts( $post_type, [
                'fields' => $reshaped_fields
            ] );

            $list_posts = ( !is_wp_error( $list ) && isset( $list['posts'] ) ) ? $list['posts'] : [];
        }

        return [
            'status' => 'success',
            'prompt' => [
                'original' => $prompt
            ],
            'pii' => $pii,
            'filter' => $reshaped_fields,
            'posts' => $list_posts
        ];
    }

    public static function parse_prompt_for_fields( string $post_type, string $original_prompt, string $parsed_prompt ): array {
        if ( !isset( $post_type, $parsed_prompt ) ) {
            return [];
        }

        $llm_endpoint_root = get_option( 'DT_AI_llm_endpoint' );
        $llm_api_key = get_option( 'DT_AI_llm_api_key' );
        $llm_model = get_option( 'DT_AI_llm_model' );
        $dt_ai_field_specs = apply_filters( 'dt_ai_field_specs', [], $post_type );
        $llm_endpoint = $llm_endpoint_root . '/chat/completions';

        /**
         * Convert filtered specifications into the desired content shape.
         */

        $llm_model_specs_content = '';
        if ( !empty( $dt_ai_field_specs ) && isset( $dt_ai_field_specs['fields'] ) ) {
            $fields = $dt_ai_field_specs['fields'];

            if ( !empty( $fields['brief'] ) ) {
                $llm_model_specs_content .= implode( '\n', $fields['brief'] ) .'\n';
            }

            if ( !empty( $fields['instructions'] ) ) {
                $llm_model_specs_content .= implode( '\n', $fields['instructions'] ) .'\n';
            }

            if ( !empty( $fields['examples'] ) ) {
                $llm_model_specs_content .= implode( '\n', $fields['examples'] );
            }
        }

        /**
         * Support retries; in the event of initial faulty JSON shaped responses.
         */

        $attempts = 0;
        $response = [];

        while ( $attempts++ < 2 ) {
            try {

                // Dispatch to prediction guard for prompted inference.
                $inferred = wp_remote_post( $llm_endpoint, [
                    'method' => 'POST',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $llm_api_key,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode( [
                        'model' => $llm_model,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $llm_model_specs_content
                            ],
                            [
                                'role' => 'user',
                                'content' => $parsed_prompt
                            ],
                        ],
                        'max_completion_tokens' => 1000,
                        'temperature' => 0.1,
                        'top_p' => 1,
                    ] ),
                    'timeout' => 30,
                ] );

                // Retry, in the event of an error.
                if ( !is_wp_error( $inferred ) ) {

                    // Ensure a valid JSON structure has been inferred; otherwise, retry!
                    $inferred_response = json_decode( wp_remote_retrieve_body( $inferred ), true );
                    if ( isset( $inferred_response['choices'][0]['message']['content'] ) ) {

                        /**
                         * Attempt to cleanse inferred.....
                         */

                        $cleansed_inferred_response = str_replace( [ '\n', '\r' ], '', trim( $inferred_response['choices'][0]['message']['content'] ) );
                        $cleansed_inferred_response = str_replace( [ '\"' ], '"', $cleansed_inferred_response );

                        // Extract inferred filter into final response and stop retry attempts.
                        $response = json_decode( $cleansed_inferred_response, true );

                        if ( !empty( $response ) ) {
                            $attempts = 2;
                        }
                    } elseif ( isset( $inferred_response['error'] ) ) {
                        $attempts = 2;
                        $response = [
                            'status' => 'error',
                            'message' => sprintf( _x( 'Unable to process prompt: %s', 'Unable to process prompt', 'disciple-tools-ai' ), $inferred_response['error'] )
                        ];
                    }
                } else {
                    $attempts = 2;
                    $response = [
                        'status' => 'error',
                        'message' => sprintf( _x( 'Unable to process prompt: %s', 'Unable to process prompt', 'disciple-tools-ai' ), $inferred->get_error_message() )
                    ];
                }
            } catch ( Exception $e ) {
                $attempts = 2;
                $response = [
                    'status' => 'error',
                    'message' => sprintf( _x( 'Unable to process prompt: %s', 'Unable to process prompt', 'disciple-tools-ai' ), $e->getMessage() )
                ];
            }
        }

        return !empty( $response ) ? $response : [
            'status' => 'error',
            'message' => sprintf( _x( 'Unable to process prompt: %s', 'Unable to process prompt', 'disciple-tools-ai' ), $original_prompt )
        ];
    }

    public static function list_posts( string $post_type, string $prompt ): array {

        return self::simplified_list_posts( $post_type, $prompt );

        /**
         * First, identify any connections within incoming prompt; especially
         * the ones with multiple options; as these will need to be handled
         * separately, in a different flow, causing the client to select which
         * option to use.
         */

        $locations = [];
        $multiple_locations = [];

        $users = [];
        $multiple_users = [];

        $posts = [];
        $multiple_posts = [];

        /**
         * Before submitting to LLM for analysis, ensure to obfuscate any PII.
         */

        $pii = self::parse_prompt_for_pii( $post_type, $prompt );

        $has_pii = ( !empty( $pii['pii'] ) && !empty( $pii['mappings'] ) && isset( $pii['prompt']['obfuscated'] ) );
        if ( $has_pii ) {
            $prompt = $pii['prompt']['obfuscated'];
        }

        /**
         * Proceed with parsing prompt for connections.
         */

        $connections = self::parse_prompt_for_connections( $prompt );

        // Extract any locations from identified connections.
        if ( !empty( $connections['locations'] ) ) {
            $locations = self::parse_locations_for_ids( $connections['locations'], $pii['mappings'] ?? [] );

            // Identify locations with multiple options.
            $multiple_locations = array_filter( $locations, function( $location ) {
                return count( $location['options'] ) > 0;
            } );
        }

        // Extract any users (takes priority over posts) or posts from identified connections.
        if ( !empty( $connections['connections'] ) ) {

            /**
             * Users.
             */

            // Extract any users from identified connections.
            $users = self::parse_connections_for_users( $connections['connections'], $post_type, $pii['mappings'] ?? [] );

            // Identify users with multiple options.
            $multiple_users = array_filter( $users, function( $user ) {
                return count( $user['options'] ) > 0;
            } );

            /**
             * Posts.
             */

            // Extract any post-names from identified connections.
            $posts = self::parse_connections_for_post_names( $connections['connections'], $post_type, $pii['mappings'] ?? [] );

            // Identify posts with multiple options.
            $multiple_posts = array_filter( $posts, function( $post ) {
                return count( $post['options'] ) > 0;
            } );
        }

        /**
         * Determine if flow is to be paused, due to multiple options.
         */

        if ( count( array_merge( $multiple_locations, $multiple_users, $multiple_posts ) ) > 0 ) {
            return [
                'status' => 'multiple_options_detected',
                'multiple_options' => [
                    'locations' => $multiple_locations,
                    'users' => $multiple_users,
                    'posts' => $multiple_posts
                ]
            ];
        }

        /**
         * If no multiple options are detected, proceed with parsing prompt
         * and encode identified connections into the required filter format.
         * By this point, connections should have, at most, a single option, having gone through
         * the multiple options client selection flow.
         */

        $parsed_prompt = $prompt;
        $processed_connection_prompts = [];

        // Process locations.
        foreach ( $locations as $location ) {
            $location_prompt = ( $has_pii && isset( $location['pii_prompt'] ) ) ? $location['pii_prompt'] : $location['prompt'];
            if ( !in_array( $location_prompt, $processed_connection_prompts ) && !empty( $location['options'] ) ) {
                $option = $location['options'][0];
                $option_formatted = '@[####]('. $option['id'] .')';
                $parsed_prompt = str_replace( $location_prompt, $option_formatted, $parsed_prompt );

                $processed_connection_prompts[] = $location_prompt;
            }
        }

        // Process users.
        foreach ( $users as $user ) {
            $user_prompt = ( $has_pii && isset( $user['pii_prompt'] ) ) ? $user['pii_prompt'] : $user['prompt'];
            if ( !in_array( $user_prompt, $processed_connection_prompts ) && !empty( $user['options'] ) ) {
                $option = $user['options'][0];
                $option_formatted = '@[####]('. $option['id'] .')';
                $parsed_prompt = str_replace( $user_prompt, $option_formatted, $parsed_prompt );

                $processed_connection_prompts[] = $user_prompt;
            }
        }

        // Process posts.
        foreach ( $posts as $post ) {
            $post_prompt = ( $has_pii && isset( $post['pii_prompt'] ) ) ? $post['pii_prompt'] : $post['prompt'];
            if ( !in_array( $post_prompt, $processed_connection_prompts ) && !empty( $post['options'] ) ) {
                $option = $post['options'][0];
                $option_formatted = '@[####]('. $option['id'] .')';
                $parsed_prompt = str_replace( $post_prompt, $option_formatted, $parsed_prompt );

                $processed_connection_prompts[] = $post_prompt;
            }
        }

        /**
         * Almost home! Now we need to create the final query filter, based on parsed prompt.
         */

        $filter = self::handle_create_filter_request( $parsed_prompt, $post_type );

        /**
         * Ensure any remaining obfuscated entries are mapped back into plain prompt values, before executing returned filter fields.
         */

        if ( $has_pii ) {
            $filter['fields'] = self::convert_filter_fields_from_obfuscated_to_plain( $filter['fields'], $pii['mappings'] );
        }

        /**
         * Next, using the inferred filter, query the posts.
         */

        $list_posts = [];
        if ( !is_wp_error( $filter ) && isset( $filter['fields'] ) ) {
            $list = DT_Posts::list_posts( $post_type, [
                'fields' => $filter['fields']
            ]);

            $list_posts = ( !is_wp_error( $list ) && isset( $list['posts'] ) ) ? $list['posts'] : [];
        }

        return [
            'status' => 'success',
            'prompt' => [
                'original' => $has_pii ? $pii['prompt']['original'] : $prompt,
                'parsed' => $parsed_prompt
            ],
            'pii' => $pii,
            'connections' => [
                'parsed' => $connections,
                'extracted' => [
                    'locations' => $locations,
                    'users' => $users,
                    'posts' => $posts
                ]
            ],
            'filter' => $filter,
            'posts' => $list_posts
        ];
    }

    public static function list_posts_with_selections( string $post_type, string $prompt, array $selections, array $pii, array $filtered_fields ): array {

        return self::simplified_list_posts_with_selections( $post_type, $prompt, $selections, $pii, $filtered_fields );

        $processed_prompts = [];
        $parsed_prompt = $prompt;

        /**
         * First, update prompt with selected replacements.
         */

        // Process location selections.
        foreach ( $selections['locations'] ?? [] as $location ) {
            if ( !in_array( $location['prompt'], $processed_prompts ) && $location['id'] !== 'ignore' ) {
                $replacement = '@[####]('. $location['id'] .')';
                $parsed_prompt = str_replace( $location['prompt'], $replacement, $parsed_prompt );

                $processed_prompts[] = $location['prompt'];
            }
        }

        // Process user selections.
        foreach ( $selections['users'] ?? [] as $user ) {
            if ( !in_array( $user['prompt'], $processed_prompts ) && $user['id'] !== 'ignore' ) {
                $replacement = '@[####]('. $user['id'] .')';
                $parsed_prompt = str_replace( $user['prompt'], $replacement, $parsed_prompt );

                $processed_prompts[] = $user['prompt'];
            }
        }

        // Process post selections.
        foreach ( $selections['posts'] ?? [] as $post ) {
            if ( !in_array( $post['prompt'], $processed_prompts ) && $post['id'] !== 'ignore' ) {
                $replacement = '@[####]('. $post['id'] .')';
                $parsed_prompt = str_replace( $post['prompt'], $replacement, $parsed_prompt );

                $processed_prompts[] = $post['prompt'];
            }
        }

        /**
         * Before submitting to LLM for analysis, ensure to obfuscate any remaining PII.
         */

        $pii = self::parse_prompt_for_pii( $post_type, $parsed_prompt );

        $has_pii = ( !empty( $pii['pii'] ) && !empty( $pii['mappings'] ) && isset( $pii['prompt']['obfuscated'] ) );

        $parsed_prompt = $has_pii ? $pii['prompt']['obfuscated'] : $parsed_prompt;

        /**
         * Almost home! Now we need to create the final query filter, based on parsed prompt.
         */

        $filter = self::handle_create_filter_request( $parsed_prompt, $post_type );

        /**
         * Ensure any remaining obfuscated entries are mapped back into plain prompt values, before executing returned filter fields.
         */

        if ( $has_pii ) {
            $filter['fields'] = self::convert_filter_fields_from_obfuscated_to_plain( $filter['fields'], $pii['mappings'] );
        }

        /**
         * Next, using the inferred filter, query the posts.
         */

        $list_posts = [];
        if ( !is_wp_error( $filter ) && isset( $filter['fields'] ) ) {
            $list = DT_Posts::list_posts( $post_type, [
                'fields' => $filter['fields']
            ]);

            $list_posts = ( !is_wp_error( $list ) && isset( $list['posts'] ) ) ? $list['posts'] : [];
        }

        return [
            'status' => 'success',
            'prompt' => [
                'original' => $prompt,
                'parsed' => $parsed_prompt
            ],
            'pii' => $pii,
            'filter' => $filter,
            'posts' => $list_posts
        ];
    }

    public static function parse_prompt_for_pii( $post_type, $prompt ): array {
        if ( !isset( $prompt ) ) {
            return [];
        }

        /**
         * Parse prompt for specific pii data tokens.
         */

        $pii = array_unique(
            array_merge(
                self::parse_prompt_for_pii_names( $post_type, $prompt ),
                self::parse_prompt_for_pii_locations( $prompt ),
                self::parse_prompt_for_pii_emails( $prompt ),
                self::parse_prompt_for_pii_phone_numbers( $prompt )
            )
        );

        /**
         * If pii are detected, then create obfuscation mappings.
         */

        $pii_mappings = [];
        foreach ( $pii ?? [] as $token ) {
            $obfuscated = str_shuffle( $token . uniqid() );
            $pii_mappings[$obfuscated] = $token;
        }

        /**
         * Next, if we have mappings, proceed with original prompt obfuscation.
         */

        $obfuscated_prompt = $prompt;
        foreach ( $pii_mappings as $obfuscated => $original ) {
            $obfuscated_prompt = str_replace( $original, $obfuscated, $obfuscated_prompt );
        }

        /**
         * Finally, return updated obfuscated prompt.
         */

        return [
            'prompt' => [
                'original' => $prompt,
                'obfuscated' => $obfuscated_prompt,
            ],
            'pii' => $pii,
            'mappings' => $pii_mappings
        ];
    }

    public static function handle_create_filter_request( $prompt, $post_type ): array {
        if ( !isset( $prompt, $post_type ) ) {
            return [];
        }

        $llm_endpoint_root = get_option( 'DT_AI_llm_endpoint' );
        $llm_api_key = get_option( 'DT_AI_llm_api_key' );
        $llm_model = get_option( 'DT_AI_llm_model' );
        $dt_ai_filter_specs = apply_filters( 'dt_ai_filter_specs', [], $post_type );
        $llm_endpoint = $llm_endpoint_root . '/chat/completions';

        // Convert filtered specifications into the desired content shape.
        $llm_model_specs_filter = '';
        if ( !empty( $dt_ai_filter_specs ) && isset( $dt_ai_filter_specs['filters'] ) ) {
            $filters = $dt_ai_filter_specs['filters'];

            if ( !empty( $filters['brief'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $filters['brief'] ) .'\n';
            }

            if ( !empty( $filters['structure'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $filters['structure'] ) .'\n';
            }

            if ( !empty( $filters['post_type_specs'] ) ) {
                // Reshape associated array into escaped json string.
                $llm_model_specs_filter .= addslashes( json_encode( $filters['post_type_specs'] ) ) . '\n';
            }

            if ( !empty( $filters['instructions'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $filters['instructions'] ) .'\n';
            }

            if ( !empty( $filters['considerations'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $filters['considerations'] ) .'\n';
            }

            if ( !empty( $filters['examples'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $filters['examples'] );
            }
        }

        /**
         * Support retries; in the event of initial faulty JSON shaped responses.
         */

        $attempts = 0;
        $response = [];

        while ( $attempts++ < 2 ) {
            try {

                // Dispatch to prediction guard for prompted inference.
                $inferred = wp_remote_post( $llm_endpoint, [
                    'method' => 'POST',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $llm_api_key,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode( [
                        'model' => $llm_model,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $llm_model_specs_filter
                            ],
                            [
                                'role' => 'user',
                                'content' => $prompt
                            ],
                        ],
                        'max_completion_tokens' => 1000,
                        'temperature' => 0.1,
                        'top_p' => 1,
                    ] ),
                    'timeout' => 30,
                ] );

                // Retry, in the event of an error.
                if ( !is_wp_error( $inferred ) ) {

                    // Ensure a valid JSON structure has been inferred; otherwise, retry!
                    $inferred_response = json_decode( wp_remote_retrieve_body( $inferred ), true );
                    if ( isset( $inferred_response['choices'][0]['message']['content'] ) ) {

                        // Extract inferred filter into final response and stop retry attempts.
                        $response = json_decode( str_replace( [ '\n', '\r' ], '', trim( $inferred_response['choices'][0]['message']['content'] ) ), true );
                        if ( !empty( $response ) ) {
                            $attempts = 2;
                        }
                    }
                }
            } catch ( Exception $e ) {
                dt_write_log( $e->getMessage() );
            }
        }

        return $response ?? [];
    }

    public static function parse_prompt_for_connections( $prompt ): array {
        if ( !isset( $prompt ) ) {
            return [];
        }

        $llm_endpoint_root = get_option( 'DT_AI_llm_endpoint' );
        $llm_api_key = get_option( 'DT_AI_llm_api_key' );
        $llm_model = get_option( 'DT_AI_llm_model' );
        $dt_ai_connection_specs = apply_filters( 'dt_ai_connection_specs', [] );
        $llm_endpoint = $llm_endpoint_root . '/chat/completions';

        // Convert specifications into desired content shape.
        $llm_model_specs_filter = '';
        if ( !empty( $dt_ai_connection_specs ) && isset( $dt_ai_connection_specs['connections'] ) ) {
            $connections = $dt_ai_connection_specs['connections'];

            if ( !empty( $connections['brief'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $connections['brief'] ) .'\n';
            }

            if ( !empty( $connections['instructions'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $connections['instructions'] ) .'\n';
            }

            if ( !empty( $connections['considerations'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $connections['considerations'] ) .'\n';
            }

            if ( !empty( $connections['examples'] ) ) {
                $llm_model_specs_filter .= implode( '\n', $connections['examples'] );
            }
        }

        /**
         * Support retries; in the event of initial faulty JSON shaped responses.
         */

        $attempts = 0;
        $response = [];

        while ( $attempts++ < 2 ) {
            try {

                // Dispatch to prediction guard for prompted inference.
                $inferred = wp_remote_post( $llm_endpoint, [
                    'method' => 'POST',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $llm_api_key,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode( [
                        'model' => $llm_model,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $llm_model_specs_filter
                            ],
                            [
                                'role' => 'user',
                                'content' => $prompt
                            ],
                        ],
                        'max_completion_tokens' => 1000,
                        'temperature' => 0.1,
                        'top_p' => 1,
                    ] ),
                    'timeout' => 30,
                ] );

                // Retry, in the event of an error.
                if ( !is_wp_error( $inferred ) ) {

                    // Ensure a valid JSON structure has been inferred; otherwise, retry!
                    $inferred_response = json_decode( wp_remote_retrieve_body( $inferred ), true );
                    if ( isset( $inferred_response['choices'][0]['message']['content'] ) ) {

                        // Extract inferred connections into final response and stop retry attempts.
                        $response = json_decode( str_replace( [ '\n', '\r' ], '', trim( $inferred_response['choices'][0]['message']['content'] ) ), true );
                        if ( !empty( $response ) ) {
                            $attempts = 2;
                        }
                    }
                }
            } catch ( Exception $e ) {
                dt_write_log( $e->getMessage() );
            }
        }

        return $response;
    }

    private static function parse_fields_for_connections( $post_type, $fields ): array {
        $connections = [
            'locations' => [],
            'connections' => []
        ];

        $field_settings = DT_Posts::get_post_field_settings( $post_type );
        foreach ( $fields ?? [] as $field ) {
            if ( isset( $field['field_value'], $field_settings[ $field['field_key'] ] ) ) {
                if ( in_array( $field_settings[ $field['field_key'] ]['type'], [ 'location', 'location_meta' ] ) ) {
                    $connections['locations'][] = $field['field_value'];
                }

                if ( in_array( $field_settings[ $field['field_key'] ]['type'], [ 'user_select' ] ) ) {
                    $connections['connections'][] = $field['field_value'];
                }
            }
        }

        return $connections;
    }

    public static function parse_locations_for_ids( $locations, $pii_mappings = [] ): array {
        if ( empty( $locations ) ) {
            return [];
        }

        $parsed_locations = [];

        // Iterate over locations, in search of corresponding grid ids.
        foreach ( $locations as $location ) {
            $hits = Disciple_Tools_Mapping_Queries::search_location_grid_by_name( [
                'search_query' => $pii_mappings[$location] ?? $location,
                'filter' => 'all'
            ] );

            $parsed_locations[] = [
                'prompt' => $pii_mappings[$location] ?? $location,
                'pii_prompt' => $location,
                'options' => array_map( function( $hit ) {
                    return [
                        'id' => $hit['grid_id'],
                        'label' => $hit['label']
                    ];
                }, $hits['location_grid'] ?? [] )
            ];
        }

        return $parsed_locations;
    }

    public static function parse_connections_for_users( $users, $post_type, $pii_mappings = [] ): array {
        if ( empty( $users ) ) {
            return [];
        }

        $parsed_users = [];

        // Iterate over connections, in search of corresponding system users.
        foreach ( $users as $user ) {
            $hits = Disciple_Tools_Users::get_assignable_users_compact( $pii_mappings[$user] ?? $user, true, $post_type );
            $parsed_users[] = [
                'prompt' => $pii_mappings[$user] ?? $user,
                'pii_prompt' => $user,
                'options' => array_map( function( $hit ) {
                    return [
                        'id' => $hit['ID'],
                        'label' => $hit['name']
                    ];
                }, $hits ?? [] )
            ];
        }

        return $parsed_users;
    }

    public static function parse_connections_for_post_names( $names, $post_type, $pii_mappings = [] ): array {
        if ( empty( $names ) ) {
            return [];
        }

        $parsed_post_names = [];

        // Iterate over connections, in search of corresponding post-records.
        foreach ( $names as $name ) {

            $records = DT_Posts::list_posts( $post_type, [
                'fields' => [
                    [
                        'name' => $pii_mappings[$name] ?? $name
                    ]
                ],
                'sort' => '-last_modified',
                'overall_status' => '-closed',
                'fields_to_return' => [
                    'name'
                ]
            ]);

            $parsed_post_names[] = [
                'prompt' => $pii_mappings[$name] ?? $name,
                'pii_prompt' => $name,
                'options' => array_map( function( $record ) {
                    return [
                        'id' => $record['ID'],
                        'label' => $record['name']
                    ];
                }, $records['posts'] ?? [] )
            ];
        }

        return $parsed_post_names;
    }

    public static function convert_posts_to_geojson( $posts, $post_type ): array {
        $features = [];
        $location_grid_meta_key = 'location_grid_meta';

        foreach ( $posts as $post ) {
            if ( isset( $post[$location_grid_meta_key] ) && is_array( $post[$location_grid_meta_key] ) && count( $post[$location_grid_meta_key] ) > 0 ) {
                foreach ( $post[$location_grid_meta_key] as $location ) {
                    if ( !empty( $location['lng'] ) && !empty( $location['lat'] ) ) {
                        $features[] = array(
                            'type' => 'Feature',
                            'properties' => array(
                                'address' => $location['address'] ?? '',
                                'post_id' => $post['ID'],
                                'name' => $post['name'] ?? ( $location['label'] ?? '' ),
                                'post_type' => $post_type
                            ),
                            'geometry' => array(
                                'type' => 'Point',
                                'coordinates' => array(
                                    $location['lng'],
                                    $location['lat'],
                                    1
                                )
                            )
                        );
                    }
                }
            }
        }

        return array(
            'type' => 'FeatureCollection',
            'features' => $features,
        );
    }

    private static function generate_post_names( $post_type ): array {
        global $wpdb;

        $names = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT post_title AS name
            FROM $wpdb->posts
            WHERE post_type = %s
            ORDER BY name ASC",
            $post_type
        ), ARRAY_A );

        return array_map( function( $name ) {
            return $name['name'] ?? '';
        }, $names );
    }

    private static function generate_location_names(): array {
        global $wpdb;

        $names = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT name
            FROM $wpdb->dt_location_grid
            ORDER BY name ASC"
        ), ARRAY_A );

        return array_map( function( $name ) {
            return $name['name'] ?? '';
        }, $names );
    }

    private static function parse_prompt_for_pii_names( $post_type, $prompt, $params = [] ): array {

        // Capture required processing parameters.
        $min_chars = $params['min_chars'] ?? 3;
        $similarity_threshold = $params['similarity_threshold'] ?? 80; // Percentage for fuzzy matching
        $max_levenshtein_distance = $params['max_levenshtein_distance'] ?? 2;

        // Fetch an array of all post names currently associated with the specified post-type.
        $post_names = self::generate_post_names( $post_type );

        // Better tokenization: split by various whitespace and punctuation
        $prompt_tokens = preg_split( '/[\s,;:!?\.\(\)\[\]\{\}"\']+/', $prompt, -1, PREG_SPLIT_NO_EMPTY );

        $found_names = [];
        $used_tokens = []; // Track which token positions have been used

        // FIRST: Check 3-word combinations
        $max_count = count( $prompt_tokens ) - 2;
        for ( $i = 0; $i < $max_count; $i++ ) {
            // Skip if any of these tokens are already used
            if ( isset( $used_tokens[$i] ) || isset( $used_tokens[$i + 1] ) || isset( $used_tokens[$i + 2] ) ) {
                continue;
            }

            $three_word = trim( $prompt_tokens[$i] . ' ' . $prompt_tokens[$i + 1] . ' ' . $prompt_tokens[$i + 2] );
            if ( strlen( $three_word ) >= $min_chars ) {
                $matches = self::find_name_matches( $three_word, $post_names, $similarity_threshold, $max_levenshtein_distance );
                if ( !empty( $matches ) ) {
                    $found_names = array_merge( $found_names, $matches );
                    // Mark these tokens as used
                    $used_tokens[$i] = true;
                    $used_tokens[$i + 1] = true;
                    $used_tokens[$i + 2] = true;
                }
            }
        }

        // SECOND: Check 2-word combinations (only for unused tokens)
        $max_count = count( $prompt_tokens ) - 1;
        for ( $i = 0; $i < $max_count; $i++ ) {
            // Skip if any of these tokens are already used
            if ( isset( $used_tokens[$i] ) || isset( $used_tokens[$i + 1] ) ) {
                continue;
            }

            $two_word = trim( $prompt_tokens[$i] . ' ' . $prompt_tokens[$i + 1] );
            if ( strlen( $two_word ) >= $min_chars ) {
                $matches = self::find_name_matches( $two_word, $post_names, $similarity_threshold, $max_levenshtein_distance );
                if ( !empty( $matches ) ) {
                    $found_names = array_merge( $found_names, $matches );
                    // Mark these tokens as used
                    $used_tokens[$i] = true;
                    $used_tokens[$i + 1] = true;
                }
            }
        }

        // THIRD: Check single words (only for unused tokens)
        $max_count = count( $prompt_tokens );
        for ( $i = 0; $i < $max_count; $i++ ) {
            // Skip if this token is already used
            if ( isset( $used_tokens[$i] ) ) {
                continue;
            }

            $token = trim( $prompt_tokens[$i] );
            if ( strlen( $token ) >= $min_chars ) {
                $matches = self::find_name_matches( $token, $post_names, $similarity_threshold, $max_levenshtein_distance );
                if ( !empty( $matches ) ) {
                    $found_names = array_merge( $found_names, $matches );
                    // Mark this token as used
                    $used_tokens[$i] = true;
                }
            }
        }

        // Remove duplicates and return
        return array_unique( $found_names );
    }

    private static function find_name_matches( $search_term, $post_names, $similarity_threshold, $max_levenshtein_distance ): array {
        $matches = [];
        $search_term_lower = strtolower( $search_term );

        // Skip very common words that are unlikely to be locations
        $common_words = [ 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'about', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'between', 'among', 'this', 'that', 'these', 'those' ];
        if ( in_array( $search_term_lower, $common_words ) ) {
            return [];
        }

        foreach ( $post_names as $post_name ) {
            $post_name_lower = strtolower( $post_name );

            // Exact match (case insensitive)
            if ( $search_term_lower === $post_name_lower ) {
                $matches[] = $search_term;
                continue;
            }

            // Prefix match (improved regex)
            if ( preg_match( '/^' . preg_quote( $search_term_lower, '/' ) . '/i', $post_name_lower ) ) {
                $matches[] = $search_term;
                continue;
            }

            // Contains match (for partial names)
            if ( strpos( $post_name_lower, $search_term_lower ) !== false ) {
                $matches[] = $search_term;
                continue;
            }

            // Fuzzy matching using similar_text
            $similarity = 0;
            similar_text( $search_term_lower, $post_name_lower, $similarity );
            if ( $similarity >= $similarity_threshold ) {
                $matches[] = $search_term;
                continue;
            }

            // Levenshtein distance for typos
            if ( strlen( $search_term ) > 3 && strlen( $post_name ) > 3 ) {
                $distance = levenshtein( $search_term_lower, $post_name_lower );
                if ( $distance <= $max_levenshtein_distance ) {
                    $matches[] = $search_term;
                    continue;
                }
            }
        }

        return $matches;
    }

    private static function parse_prompt_for_pii_locations( $prompt, $params = [] ): array {

        // Capture required processing parameters.
        $min_chars = $params['min_chars'] ?? 3;
        $similarity_threshold = $params['similarity_threshold'] ?? 75; // Slightly lower for locations due to variations
        $max_levenshtein_distance = $params['max_levenshtein_distance'] ?? 2;

        // Fetch an array of all location names from the database
        $location_names = self::generate_location_names();

        // First, extract potential addresses and postal codes using regex patterns
        $found_locations = [];

        // Extract addresses (number + street patterns)
        $address_patterns = [
            '/\b\d+\s+[A-Za-z\s]+(Street|St|Avenue|Ave|Road|Rd|Lane|Ln|Drive|Dr|Boulevard|Blvd|Way|Place|Pl|Court|Ct|Circle|Cir)\b/i',
            '/\b\d+\s+[A-Za-z\s]+\s+(Street|St|Avenue|Ave|Road|Rd|Lane|Ln|Drive|Dr|Boulevard|Blvd|Way|Place|Pl|Court|Ct|Circle|Cir)\b/i'
        ];

        foreach ( $address_patterns as $pattern ) {
            preg_match_all( $pattern, $prompt, $matches, PREG_SET_ORDER );
            foreach ( $matches as $match ) {
                $found_locations[] = trim( $match[0] );
            }
        }

        // Extract postal codes (various international formats)
        $postal_patterns = [
            '/\b\d{5}(-\d{4})?\b/',           // US ZIP codes
            '/\b[A-Z]\d[A-Z]\s?\d[A-Z]\d\b/', // Canadian postal codes
            '/\b\d{4,5}\b/',                  // Simple numeric postal codes
            '/\b[A-Z]{1,2}\d[A-Z\d]?\s?\d[A-Z]{2}\b/' // UK postal codes
        ];

        foreach ( $postal_patterns as $pattern ) {
            preg_match_all( $pattern, $prompt, $matches, PREG_SET_ORDER );
            foreach ( $matches as $match ) {
                $found_locations[] = trim( $match[0] );
            }
        }

        // Better tokenization: split by various whitespace and punctuation, but preserve some location-specific characters
        $prompt_tokens = preg_split( '/[\s,;:!?\(\)\[\]\{\}"\']+/', $prompt, -1, PREG_SPLIT_NO_EMPTY );

        $used_tokens = []; // Track which token positions have been used

        // FIRST: Check 4-word combinations (for longer place names like "United States of America")
        $max_count = count( $prompt_tokens ) - 3;
        for ( $i = 0; $i < $max_count; $i++ ) {
            if ( isset( $used_tokens[$i] ) || isset( $used_tokens[$i + 1] ) || isset( $used_tokens[$i + 2] ) || isset( $used_tokens[$i + 3] ) ) {
                continue;
            }

            $four_word = trim( $prompt_tokens[$i] . ' ' . $prompt_tokens[$i + 1] . ' ' . $prompt_tokens[$i + 2] . ' ' . $prompt_tokens[$i + 3] );
            if ( strlen( $four_word ) >= $min_chars ) {
                $matches = self::find_location_matches( $four_word, $location_names, $similarity_threshold, $max_levenshtein_distance );
                if ( !empty( $matches ) ) {
                    $found_locations = array_merge( $found_locations, $matches );
                    $used_tokens[$i] = $used_tokens[$i + 1] = $used_tokens[$i + 2] = $used_tokens[$i + 3] = true;
                }
            }
        }

        // SECOND: Check 3-word combinations (for places like "New York City", "Los Angeles County")
        $max_count = count( $prompt_tokens ) - 2;
        for ( $i = 0; $i < $max_count; $i++ ) {
            if ( isset( $used_tokens[$i] ) || isset( $used_tokens[$i + 1] ) || isset( $used_tokens[$i + 2] ) ) {
                continue;
            }

            $three_word = trim( $prompt_tokens[$i] . ' ' . $prompt_tokens[$i + 1] . ' ' . $prompt_tokens[$i + 2] );
            if ( strlen( $three_word ) >= $min_chars ) {
                $matches = self::find_location_matches( $three_word, $location_names, $similarity_threshold, $max_levenshtein_distance );
                if ( !empty( $matches ) ) {
                    $found_locations = array_merge( $found_locations, $matches );
                    $used_tokens[$i] = $used_tokens[$i + 1] = $used_tokens[$i + 2] = true;
                }
            }
        }

        // THIRD: Check 2-word combinations (for places like "New York", "San Francisco")
        $max_count = count( $prompt_tokens ) - 1;
        for ( $i = 0; $i < $max_count; $i++ ) {
            if ( isset( $used_tokens[$i] ) || isset( $used_tokens[$i + 1] ) ) {
                continue;
            }

            $two_word = trim( $prompt_tokens[$i] . ' ' . $prompt_tokens[$i + 1] );
            if ( strlen( $two_word ) >= $min_chars ) {
                $matches = self::find_location_matches( $two_word, $location_names, $similarity_threshold, $max_levenshtein_distance );
                if ( !empty( $matches ) ) {
                    $found_locations = array_merge( $found_locations, $matches );
                    $used_tokens[$i] = $used_tokens[$i + 1] = true;
                }
            }
        }

        // FOURTH: Check single words (for single-word locations like "London", "Tokyo")
        $max_count = count( $prompt_tokens );
        for ( $i = 0; $i < $max_count; $i++ ) {
            if ( isset( $used_tokens[$i] ) ) {
                continue;
            }

            $token = trim( $prompt_tokens[$i] );
            if ( strlen( $token ) >= $min_chars ) {
                $matches = self::find_location_matches( $token, $location_names, $similarity_threshold, $max_levenshtein_distance );
                if ( !empty( $matches ) ) {
                    $found_locations = array_merge( $found_locations, $matches );
                    $used_tokens[$i] = true;
                }
            }
        }

        // Remove duplicates and return
        return array_unique( $found_locations );
    }

    private static function find_location_matches( $search_term, $location_names, $similarity_threshold, $max_levenshtein_distance ): array {
        $matches = [];
        $search_term_lower = strtolower( $search_term );

        // Skip very common words that are unlikely to be locations
        $common_words = [ 'the', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'about', 'into', 'through', 'during', 'before', 'after', 'above', 'below', 'between', 'among', 'this', 'that', 'these', 'those' ];
        if ( in_array( $search_term_lower, $common_words ) ) {
            return [];
        }

        foreach ( $location_names as $location_name ) {
            $location_name_lower = strtolower( $location_name );

            // Exact match (case insensitive)
            if ( $search_term_lower === $location_name_lower ) {
                $matches[] = $search_term;
                continue;
            }

            // Prefix match
            /*if (preg_match('/^' . preg_quote($search_term_lower, '/') . '/i', $location_name_lower)) {
                $matches[] = $search_term;
                continue;
            }

            // Contains match (for partial location names)
            if (strpos($location_name_lower, $search_term_lower) !== false) {
                $matches[] = $search_term;
                continue;
            }

            // Fuzzy matching using similar_text (locations often have variations)
            $similarity = 0;
            similar_text($search_term_lower, $location_name_lower, $similarity);
            if ($similarity >= $similarity_threshold) {
                $matches[] = $search_term;
                continue;
            }

            // Levenshtein distance for typos (common in location names)
            if (strlen($search_term) > 3 && strlen($location_name) > 3) {
                $distance = levenshtein($search_term_lower, $location_name_lower);
                if ($distance <= $max_levenshtein_distance) {
                    $matches[] = $search_term;
                    continue;
                }
            }*/
        }

        return $matches;
    }

    private static function parse_prompt_for_pii_emails( $prompt ): array {

        $found_emails = [];

        /**
         * Comprehensive email regex patterns to catch various email formats
         */

        // Main email regex pattern - comprehensive but practical
        $email_patterns = [
            // Standard email format with proper validation
            '/\b[A-Za-z0-9](?:[A-Za-z0-9._-]*[A-Za-z0-9])?@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,}\b/',

            // Catch emails with + in local part (common for email aliases)
            '/\b[A-Za-z0-9](?:[A-Za-z0-9._+-]*[A-Za-z0-9])?@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,}\b/',

            // Catch emails surrounded by common punctuation
            '/[\s\(\[\{<"\']([A-Za-z0-9](?:[A-Za-z0-9._+-]*[A-Za-z0-9])?@[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\.[A-Za-z]{2,})[\s\)\]\}>"\']/',

            // Simple fallback for basic email detection
            '/\b\S+@\S+\.\S+\b/'
        ];

        // Apply each pattern and collect matches
        foreach ( $email_patterns as $pattern ) {
            preg_match_all( $pattern, $prompt, $matches, PREG_SET_ORDER );
            foreach ( $matches as $match ) {
                // For patterns with capture groups, use the captured email
                $email = isset( $match[1] ) ? $match[1] : $match[0];
                $email = trim( $email );

                // Additional validation to ensure it's a proper email
                if ( self::is_valid_email_format( $email ) ) {
                    $found_emails[] = $email;
                }
            }
        }

        /**
         * Also check for emails that might be obfuscated or written in natural language
         */

        // Look for "dot" and "at" patterns (e.g., "john dot smith at example dot com")
        $obfuscated_pattern = '/\b([A-Za-z0-9]+(?:\s*[\._-]\s*[A-Za-z0-9]+)*)\s+(?:at|@)\s+([A-Za-z0-9]+(?:\s*[\._-]\s*[A-Za-z0-9]+)*)\s*[\._]\s*([A-Za-z]{2,})\b/i';
        preg_match_all( $obfuscated_pattern, $prompt, $obfuscated_matches, PREG_SET_ORDER );

        foreach ( $obfuscated_matches as $match ) {
            // Reconstruct the email from obfuscated format
            $local = str_replace( [ ' ', '_', '-' ], '', $match[1] );
            $domain = str_replace( [ ' ', '_', '-' ], '', $match[2] );
            $tld = str_replace( [ ' ', '_', '-' ], '', $match[3] );

            $reconstructed_email = $local . '@' . $domain . '.' . $tld;
            if ( self::is_valid_email_format( $reconstructed_email ) ) {
                $found_emails[] = $match[0]; // Store original obfuscated version
            }
        }

        // Remove duplicates and return
        return array_unique( $found_emails );
    }

    private static function is_valid_email_format( $email ): bool {
        // Clean up the email
        $email = trim( $email );

        // Basic structure validation
        if ( !str_contains( $email, '@' ) || !str_contains( $email, '.' ) ) {
            return false;
        }

        // Split into parts
        $parts = explode( '@', $email );
        if ( count( $parts ) !== 2 ) {
            return false;
        }

        $local = $parts[0];
        $domain = $parts[1];

        // Validate local part (before @)
        if ( empty( $local ) || strlen( $local ) > 64 ) {
            return false;
        }

        // Check for valid characters in local part
        if ( !preg_match( '/^[A-Za-z0-9._+-]+$/', $local ) ) {
            return false;
        }

        // Local part cannot start or end with a dot
        if ( str_starts_with( $local, '.' ) || str_ends_with( $local, '.' ) ) {
            return false;
        }

        // No consecutive dots in local part
        if ( str_contains( $local, '..' ) ) {
            return false;
        }

        // Validate domain part (after @)
        if ( empty( $domain ) || strlen( $domain ) > 255 ) {
            return false;
        }

        // Domain must contain at least one dot
        if ( !str_contains( $domain, '.' ) ) {
            return false;
        }

        // Check for valid domain format
        if ( !preg_match( '/^[A-Za-z0-9.-]+$/', $domain ) ) {
            return false;
        }

        // Domain cannot start or end with a dot or hyphen
        if ( str_starts_with( $domain, '.' ) || str_ends_with( $domain, '.' ) ||
            str_starts_with( $domain, '-' ) || str_ends_with( $domain, '-' ) ) {
            return false;
        }

        // No consecutive dots in domain
        if ( str_contains( $domain, '..' ) ) {
            return false;
        }

        // Check TLD (top-level domain)
        $domain_parts = explode( '.', $domain );
        $tld = end( $domain_parts );

        // TLD should be at least 2 characters and only letters
        if ( strlen( $tld ) < 2 || !preg_match( '/^[A-Za-z]+$/', $tld ) ) {
            return false;
        }

        // Additional PHP filter validation
        return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
    }

    private static function parse_prompt_for_pii_phone_numbers( $prompt ): array {

        $found_phone_numbers = [];

        /**
         * Comprehensive phone number regex patterns to catch various international formats
         */

        $phone_patterns = [
            // International format with country code (+1, +44, etc.)
            '/\+\d{1,4}[\s\-\.]?\(?(\d{1,4})\)?[\s\-\.]?\d{1,4}[\s\-\.]?\d{1,4}[\s\-\.]?\d{0,9}/',

            // US/Canada formats
            '/\b\(?([0-9]{3})\)?[\s\-\.]?([0-9]{3})[\s\-\.]?([0-9]{4})\b/',           // (123) 456-7890, 123-456-7890, 123.456.7890
            '/\b1[\s\-\.]?\(?([0-9]{3})\)?[\s\-\.]?([0-9]{3})[\s\-\.]?([0-9]{4})\b/', // 1-123-456-7890, 1 (123) 456-7890

            // UK formats
            '/\b0\d{2,4}[\s\-\.]?\d{3,8}\b/',                                          // 0207 123 4567, 01234-567890
            '/\b\+44[\s\-\.]?\d{2,4}[\s\-\.]?\d{3,8}\b/',                             // +44 207 123 4567

            // European formats (general)
            '/\b\+\d{2}[\s\-\.]?\d{1,4}[\s\-\.]?\d{1,4}[\s\-\.]?\d{1,4}[\s\-\.]?\d{1,4}\b/',

            // Australian formats
            '/\b\+61[\s\-\.]?\d{1}[\s\-\.]?\d{4}[\s\-\.]?\d{4}\b/',                   // +61 2 1234 5678
            '/\b0\d[\s\-\.]?\d{4}[\s\-\.]?\d{4}\b/',                                  // 02 1234 5678

            // Generic international patterns
            '/\b\d{2,4}[\s\-\.]?\d{2,4}[\s\-\.]?\d{2,4}[\s\-\.]?\d{2,4}\b/',         // Basic 4-part numbers
            '/\b\d{3}[\s\-\.]?\d{7,10}\b/',                                           // 3 + 7-10 digits

            // Mobile-specific patterns
            '/\b\+\d{1,4}[\s\-\.]?\d{2,4}[\s\-\.]?\d{2,4}[\s\-\.]?\d{2,6}\b/',       // International mobile

            // Extension patterns
            '/\b\(?([0-9]{3})\)?[\s\-\.]?([0-9]{3})[\s\-\.]?([0-9]{4})[\s\-\.]?(?:ext?\.?|extension)[\s\-\.]?(\d{1,6})\b/i',
        ];

        // Apply each pattern and collect matches
        foreach ( $phone_patterns as $pattern ) {
            preg_match_all( $pattern, $prompt, $matches, PREG_SET_ORDER );
            foreach ( $matches as $match ) {
                $phone = trim( $match[0] );

                // Additional validation to ensure it's a proper phone number
                if ( self::is_valid_phone_format( $phone ) ) {
                    $found_phone_numbers[] = $phone;
                }
            }
        }

        /**
         * Also check for phone numbers written in natural language or with text
         */

        // Look for patterns like "call me at", "phone:", "tel:", "mobile:", etc.
        $context_patterns = [
            '/(?:call|phone|tel|mobile|cell|contact)[\s:]*(\+?\d{1,4}[\s\-\.\(\)]?\d{1,4}[\s\-\.\(\)]?\d{1,4}[\s\-\.\(\)]?\d{1,9})/i',
            '/(?:number|#)[\s:]*(\+?\d{1,4}[\s\-\.\(\)]?\d{1,4}[\s\-\.\(\)]?\d{1,4}[\s\-\.\(\)]?\d{1,9})/i'
        ];

        foreach ( $context_patterns as $pattern ) {
            preg_match_all( $pattern, $prompt, $context_matches, PREG_SET_ORDER );
            foreach ( $context_matches as $match ) {
                $phone = trim( $match[1] );
                if ( self::is_valid_phone_format( $phone ) ) {
                    $found_phone_numbers[] = $match[0]; // Store full context
                }
            }
        }

        // Remove duplicates and return
        return array_unique( array_filter( $found_phone_numbers ) );
    }

    private static function is_valid_phone_format( $phone ): bool {
        // Clean up the phone number
        $phone = trim( $phone );

        // Remove common separators for digit counting
        $digits_only = preg_replace( '/[^\d+]/', '', $phone );

        // Basic length validation - phone numbers should have at least 7 digits (local) up to 15 (international standard)
        $digit_count = strlen( preg_replace( '/[^\d]/', '', $digits_only ) );
        if ( $digit_count < 7 || $digit_count > 15 ) {
            return false;
        }

        // Check for valid characters only
        if ( !preg_match( '/^[\d\s\-\.\(\)\+ext]+$/i', $phone ) ) {
            return false;
        }

        // Ensure it's not just repeating digits (like 1111111111)
        $clean_digits = preg_replace( '/[^\d]/', '', $phone );
        if ( preg_match( '/^(\d)\1+$/', $clean_digits ) ) {
            return false;
        }

        // Ensure it has a reasonable structure (not all separators)
        if ( preg_match( '/^[\s\-\.\(\)\+]+$/', $phone ) ) {
            return false;
        }

        // Check for valid patterns
        $valid_patterns = [
            // International with country code
            '/^\+\d{1,4}[\s\-\.]?[\d\s\-\.\(\)]{6,14}$/',

            // US/Canada format
            '/^1?[\s\-\.]?\(?[0-9]{3}\)?[\s\-\.]?[0-9]{3}[\s\-\.]?[0-9]{4}(?:[\s\-\.]?(?:ext?\.?|extension)[\s\-\.]?\d{1,6})?$/i',

            // UK format
            '/^(?:\+44|0)\d{2,4}[\s\-\.]?\d{3,8}$/',

            // European format
            '/^\+\d{2}[\s\-\.]?\d{1,4}[\s\-\.]?\d{1,4}[\s\-\.]?\d{1,4}[\s\-\.]?\d{1,4}$/',

            // Generic format
            '/^\d{2,4}[\s\-\.]?\d{2,4}[\s\-\.]?\d{2,4}[\s\-\.]?\d{0,4}$/',

            // Mobile-specific
            '/^\+\d{1,4}[\s\-\.]?\d{2,4}[\s\-\.]?\d{2,4}[\s\-\.]?\d{2,6}$/'
        ];

        foreach ( $valid_patterns as $pattern ) {
            if ( preg_match( $pattern, $phone ) ) {
                return true;
            }
        }

        return false;
    }

    private static function reshape_fields_to_required_list_post_query_structure( $post_type, $fields, $pii_mappings = [], $multiple_options = [] ): array {
        $reshaped_fields = [];
        $status = null;

        foreach ( $fields ?? [] as $field ) {
            if ( isset( $field['field_key'], $field['field_value'] ) ) {
                $field_key = $field['field_key'];
                $field_value = $field['field_value'];
                $intent = $field['intent'] ?? 'EQUALS';

                /**
                 * Extract values and append to field array accordingly. Separate
                 * statuses and append if detected.
                 */

                $extracted_values = self::extract_reshaped_field_values( $field_key, $field_value, $intent, $pii_mappings, $multiple_options );

                if ( empty( $reshaped_fields[ $field_key ] ) ) {
                    $reshaped_fields[ $field_key ] = [];
                }

                $reshaped_fields[ $field_key ] = array_merge( $reshaped_fields[ $field_key ], $extracted_values['values'] );

                $status = $extracted_values['status'] ?? null;
            }
        }

        if ( !empty( $status ) ) {
            $settings = DT_Posts::get_post_settings( $post_type, false );
            $status_key = $settings['status_field']['status_key'] ?? null;
            if ( !empty( $status_key ) ) {
                $status_value = null;
                switch ( $status ) {
                    case 'STATUS_NEW':
                        $status_value = 'new';
                        break;
                    case 'STATUS_ACTIVE':
                        $status_value = 'active';
                        break;
                    case 'STATUS_INACTIVE':
                        $status_value = 'inactive';
                        break;
                    case 'STATUS_NONE':
                        $status_value = 'none';
                        break;
                    case 'STATUS_CLOSED':
                        $status_value = 'closed';
                        break;
                    case 'STATUS_UNASSIGNABLE':
                        $status_value = 'unassignable';
                        break;
                    case 'STATUS_UNASSIGNED':
                        $status_value = 'unassigned';
                        break;
                    case 'STATUS_ASSIGNED':
                        $status_value = 'assigned';
                        break;
                    case 'STATUS_PAUSED':
                        $status_value = 'paused';
                        break;
                }

                if ( !empty( $status_value ) ) {
                    $reshaped_fields[ $status_key ] = [ $status_value ];
                }
            }
        }

        $final_reshaped_fields = [];
        foreach ( $reshaped_fields as $field => $values ) {
            $final_reshaped_fields[] = [
                $field => $values
            ];
        }

        return $final_reshaped_fields;
    }

    private static function extract_reshaped_field_values( $field_key, $field_value, $intent, $pii_mappings = [], $multiple_options = [] ): array {

        /**
         * First, transform values and intents into arrays.
         */

        if ( !is_array( $field_value ) ) {
            $field_value = [ $field_value ];
        }

        if ( !is_array( $intent ) ) {
            $intent = [ $intent ];
        }

        /**
         * Next, determine the field intentions in order to handle accordingly.
         * The most recent intent case type will always take priority.
         */

        $status = '';
        $prefix = '';
        $loop_values = true;
        foreach ( $intent as $intent_value ) {
            switch ( $intent_value ) {
                case 'NOT_SET':
                    $loop_values = false;
                    break;
                case 'ANY':
                    $prefix = $prefix . '*';
                    break;
                case 'EQUALS':
                    break;
                case 'NOT_EQUALS':
                    $prefix = '-' . $prefix;
                    break;
                case 'STATUS_NEW':
                case 'STATUS_ACTIVE':
                case 'STATUS_INACTIVE':
                case 'STATUS_NONE':
                case 'STATUS_CLOSED':
                case 'STATUS_UNASSIGNABLE':
                case 'STATUS_UNASSIGNED':
                case 'STATUS_ASSIGNED':
                case 'STATUS_PAUSED':
                    $status = $intent_value;
                    break;
                case 'DATES_BETWEEN':
                case 'DATES_AFTER':
                case 'DATES_BEFORE':
                case 'DATES_PREVIOUS_YEARS':
                case 'DATES_PREVIOUS_MONTHS':
                case 'DATES_PREVIOUS_DAYS':
                    $loop_values = false;
                    // TODO: Extract date values accordingly in desired shape.
                    break;
            }
        }

        /**
         * Next, if greenlight given, iterate over values and if required, un-obfuscate, before
         * reshaping accordingly by intent.
         */

        $reshaped_values = [];
        if ( $loop_values ) {
            foreach ( $field_value as $obfuscated_value ) {

                /**
                 * First, search across multiple options; which should already contain system ids.
                 * Failing that, then simply proceed with pii mapping or existing obfuscated value.
                 */

                $hit = null;
                $extracted_option = self::extract_multiple_option_by_key_value( $multiple_options, 'pii_prompt', $obfuscated_value );
                if ( !empty( $extracted_option ) && isset( $extracted_option['options'] ) && is_array( $extracted_option['options'] ) && ( count( $extracted_option['options'] ) > 0 ) && isset( $extracted_option['options'][0]['id'] ) ) {
                    $hit = $extracted_option['options'][0]['id'];
                } elseif ( isset( $pii_mappings[ $obfuscated_value ] ) ) {
                    $hit = $pii_mappings[ $obfuscated_value ];
                } else {
                    $hit = $obfuscated_value;
                }

                /**
                 * Next apply any relevant conditioning, based on intent.
                 */

                $reshaped_values[] = $prefix . $hit;
            }
        }

        return [
            'status' => $status,
            'values' => $reshaped_values
        ];
    }

    private static function simplified_convert_filter_fields_from_obfuscated_to_plain( $filter_fields, $pii_mappings, $multiple_options = [] ): array {
        $fields = [];

        if ( is_array( $pii_mappings ) && is_array( $filter_fields ) && is_array( $multiple_options ) ) {
            foreach ( $filter_fields as $field ) {
                if ( is_array( $field ) ) {
                    foreach ( $field as $key => $value ) {
                        $fields[ $key ] = [];
                        if ( is_array( $value ) ) {
                            foreach ( $value as $obfuscated_value ) {

                                /**
                                 * First, search across multiple options; which should already contain system ids.
                                 * Failing that, then simply proceed with pii mapping or existing obfuscated value.
                                 */

                                $extracted_option = self::extract_multiple_option_by_key_value( $multiple_options, 'pii_prompt', $obfuscated_value );
                                if ( !empty( $extracted_option ) && isset( $extracted_option['options'] ) && is_array( $extracted_option['options'] ) && ( count( $extracted_option['options'] ) > 0 ) && isset( $extracted_option['options'][0]['id'] ) ) {
                                    $fields[ $key ][] = $extracted_option['options'][0]['id'];
                                } elseif ( isset( $pii_mappings[ $obfuscated_value ] ) ) {
                                    $fields[ $key ][] = $pii_mappings[ $obfuscated_value ];
                                } else {
                                    $fields[ $key ][] = $obfuscated_value;
                                }
                            }
                        }
                    }
                }
            }
        } else {
            $fields = $filter_fields;
        }

        return $fields;
    }

    private static function extract_multiple_option_by_key_value( $multiple_options, $key, $value ): array {
        $extracted_option = [];
        foreach ( [ 'locations', 'users', 'posts' ] as $options_id ) {
            if ( empty( $extracted_option ) && is_array( $multiple_options[ $options_id ] ) ) {
                $extracted_option = array_filter( $multiple_options[ $options_id ], function( $option ) use ( $key, $value ) {
                    return isset( $option[ $key ] ) && $option[ $key ] === $value;
                } );
            }
        }

        return !empty( $extracted_option ) ? $extracted_option[0] : [];
    }

    private static function convert_filter_fields_from_obfuscated_to_plain( $filter_fields, $pii_mappings ): array {
        $fields = [];

        if ( is_array( $pii_mappings ) && is_array( $filter_fields ) ) {
            foreach ( $filter_fields as $field ) {
                if ( is_array( $field ) ) {
                    foreach ( $field as $key => $value ) {
                        $fields[ $key ] = [];
                        if ( is_array( $value ) ) {
                            foreach ( $value as $obfuscated_value ) {
                                if ( isset( $pii_mappings[ $obfuscated_value ] ) ) {
                                    $fields[ $key ][] = $pii_mappings[ $obfuscated_value ];
                                } else {
                                    $fields[ $key ][] = $obfuscated_value;
                                }
                            }
                        }
                    }
                }
            }
        } else {
            $fields = $filter_fields;
        }

        return $fields;
    }

    public static function list_modules( $defaults = [
        'dt_ai_list_filter' => [
            'id' => 'dt_ai_list_filter',
            'name' => 'List Filter Enabled',
            'visible' => true,
            'enabled' => 1
        ],
        'dt_ai_ml_list_filter' => [
            'id' => 'dt_ai_ml_list_filter',
            'name' => 'Magic Link List Filter Enabled',
            'visible' => true,
            'enabled' => 1
        ],
        'dt_ai_metrics_dynamic_maps' => [
            'id' => 'dt_ai_metrics_dynamic_maps',
            'name' => 'Metrics Dynamic Maps Enabled',
            'visible' => true,
            'enabled' => 1
        ]
    ] ): array {
        $ai_modules = apply_filters( 'dt_ai_modules', $defaults );
        $module_options = get_option( 'dt_ai_modules', [] );

        // Remove modules not present.
        foreach ( $module_options as $key => $module ) {
            if ( !isset( $ai_modules[$key] ) ) {
                unset( $module_options[$key] );
            }
        }

        // Merge distinct.
        return dt_array_merge_recursive_distinct( $ai_modules, $module_options );
    }

    public static function update_modules( $updated_modules ): bool {
        return update_option( 'dt_ai_modules', $updated_modules );
    }

    public static function has_module_value( $module_id, $module_property, $module_value ): bool {
        $modules = self::list_modules();

        if ( !isset( $modules[ $module_id ] ) ) {
            return false;
        }

        if ( !isset( $modules[ $module_id ][ $module_property ] ) ) {
            return false;
        }

        return $modules[ $module_id ][ $module_property ] === $module_value;
    }
}
