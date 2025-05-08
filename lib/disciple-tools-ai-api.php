<?php
if ( !defined( 'ABSPATH' ) ){
    exit;
} // Exit if accessed directly

class Disciple_Tools_AI_API {

    public static function list_posts( string $post_type, string $prompt ): array {

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

    public static function list_posts_with_selections( string $post_type, string $prompt, array $selections ): array {
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
                    $features[] = array(
                        'type' => 'Feature',
                        'properties' => array(
                            'address' => $location['address'] ?? '',
                            'post_id' => $location['post_id'],
                            'name' => $post['name'] ?? $location['label'],
                            'post_type' => $post_type
                        ),
                        'geometry' => array(
                            'type' => 'Point',
                            'coordinates' => array(
                                $location['lng'],
                                $location['lat'],
                                1
                            ),
                        ),
                    );
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

        // Fetch an array of all post names currently associated with the specified post-type.
        $post_names = self::generate_post_names( $post_type );

        // Explode incoming prompt into an array.
        $prompt_array = explode( ' ', $prompt );

        // Identify and return any potential pii names.
        return array_filter( $prompt_array, function( $value ) use ( $post_names, $min_chars ) {
            if ( strlen( $value ) > $min_chars ) {
                return !empty( preg_grep( '/^'. $value .'/i', $post_names ) );
            }

            return false;
        } );
    }

    private static function parse_prompt_for_pii_locations( $prompt,  $params = [] ): array {

        // Capture required processing parameters.
        $min_chars = $params['min_chars'] ?? 3;

        // Fetch an array of all location names.
        $location_names = self::generate_location_names();

        // Explode incoming prompt into an array.
        $prompt_array = explode( ' ', $prompt );

        // Identify and return any potential pii locations.
        return array_filter( $prompt_array, function( $value ) use ( $location_names, $min_chars ) {
            if ( strlen( $value ) > $min_chars ) {
                return !empty( preg_grep( '/^'. $value .'/i', $location_names ) );
            }

            return false;
        } );
    }

    private static function parse_prompt_for_pii_emails( $prompt ): array {

        // Explode incoming prompt into an array.
        $prompt_array = explode( ' ', $prompt );

        // Identify and return any potential pii emails.
        return array_filter( $prompt_array, function( $value ) {
            return !empty( preg_grep( '/^\S+@\S+$/i', [ $value ] ) );
        } );
    }

    private static function parse_prompt_for_pii_phone_numbers( $prompt ): array {

        /**
         * Parse for phone numbers in the following formats:
         *  (123) 456-7890
         *  (123) 456-7890
         *  (123)-456-7890
         *  123 456 7890
         *  123-456-7890
         */

        preg_match_all('/\(?\d{3}\)?[\s-]?\d{3}[\s-]\d{4}/', $prompt, $matches, PREG_SET_ORDER, 0);

        // Break if there are no matches.
        if ( empty( $matches ) ) {
            return [];
        }

        // Proceed with extracting matched phone numbers.
        return array_map( function( $match ) {
            if ( is_array( $match ) && !empty( $match ) ) {
                return $match[0];
            }
            return null;
        }, $matches );
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
}
