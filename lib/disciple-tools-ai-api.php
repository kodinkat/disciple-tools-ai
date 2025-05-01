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

        $pii = self::parse_prompt_for_pii( $prompt );
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
                return count( $location['options'] ) > 1;
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
                return count( $user['options'] ) > 1;
            } );

            /**
             * Posts.
             */

            // Extract any post-names from identified connections.
            $posts = self::parse_connections_for_post_names( $connections['connections'], $post_type, $pii['mappings'] ?? [] );

            // Identify posts with multiple options.
            $multiple_posts = array_filter( $posts, function( $post ) {
                return count( $post['options'] ) > 1;
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

        $filter =  self::handle_create_filter_request( $parsed_prompt, $post_type );

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
         * Almost home! Now we need to create the final query filter, based on parsed prompt.
         */

        $filter =  self::handle_create_filter_request( $parsed_prompt, $post_type );

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
            'filter' => $filter,
            'posts' => $list_posts
        ];
    }

    public static function parse_prompt_for_pii( $prompt ): array {
        if ( !isset( $prompt ) ) {
            return [];
        }

        $llm_endpoint_root = get_option( 'DT_AI_llm_endpoint' );
        $llm_api_key = get_option( 'DT_AI_llm_api_key' );
        $llm_endpoint = $llm_endpoint_root . '/PII';

        /**
         * Support retries; in the event of initial faulty JSON shaped responses.
         */

        $attempts = 0;
        $pii = [];

        while ( $attempts++ < 2 ) {
            try {

                // Dispatch to prediction guard for prompted pii inference.
                $inferred = wp_remote_post( $llm_endpoint, [
                    'method' => 'POST',
                    'headers' => [
                        'Authorization' => 'Bearer ' . $llm_api_key,
                        'Content-Type' => 'application/json',
                    ],
                    'body' => json_encode( [
                        'prompt' => $prompt,
                        'replace' => false
                    ] ),
                    'timeout' => 30,
                ] );

                // Retry, in the event of an error.
                if ( !is_wp_error( $inferred ) ) {

                    // Ensure a valid JSON structure has been inferred; otherwise, retry!
                    $inferred_response = json_decode( wp_remote_retrieve_body( $inferred ), true );
                    if ( isset( $inferred_response['checks'][0]['types_and_positions'], $inferred_response['checks'][0]['status'] ) && $inferred_response['checks'][0]['status'] === 'success' ) {

                        // Extract inferred connections into final response and stop retry attempts.
                        $pii = json_decode( str_replace( [ '\n', '\r' ], '', trim( $inferred_response['checks'][0]['types_and_positions'] ) ), true );
                        if ( !empty( $pii ) ) {
                            $attempts = 2;
                        }
                    }
                }
            } catch ( Exception $e ) {
                dt_write_log( $e->getMessage() );
            }
        }

        /**
         * If pii are detected, then create obfuscation mappings.
         */

        $pii_mappings = [];
        foreach ( $pii ?? [] as $type_and_position ) {

            // Ensure to ignore conflicting types, such as URLs, which conflict with EMAIL_ADDRESS.
            if ( !in_array( $type_and_position['type'], ['URL'] ) ) {
                $original = substr( $prompt, $type_and_position['start'], $type_and_position['end'] - $type_and_position['start'] );
                $obfuscated = str_shuffle( $original . uniqid() );
                $pii_mappings[$obfuscated] = $original;
            }
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

        return $response;
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

    public static function parse_prompt( $prompt, $post_type, $offsets = [
        'users' => 0,
        'locations' => 0
    ] ) {
        $user_prefix = 'u::';
        $location_prefix = 'l::';

        /**
         * Users.
         */

        $user_lookup_idx_start = strpos( $prompt, $user_prefix, $offsets['users'] );
        if ( $user_lookup_idx_start !== false ) {

            // First, extract index and actual lookup value.
            $length = strpos( $prompt, ' ', $user_lookup_idx_start ) - $user_lookup_idx_start;
            $user_lookup_query = substr( $prompt, $user_lookup_idx_start, ( $length > strlen( $user_prefix ) ) ? $length : null );
            $user_lookup_query_value = substr( $user_lookup_query, strlen( $user_prefix ) );

            // Execute locations search.
            $users = Disciple_Tools_Users::get_assignable_users_compact( $user_lookup_query_value, true, $post_type );

            // If available, source the first hit and update prompt accordingly.
            $parsed_user_str = '';
            if ( count( $users ) > 0 ) {
                $user = $users[0];
                $parsed_user_str = '@['. $user['name'] .']('. $user['ID'] .')';
            }

            if ( !empty( $parsed_user_str ) ) {
                $prompt = str_replace( $user_lookup_query, $parsed_user_str, $prompt );
            }

            // Update users offset count (to avoid endless recursion) and recursive, in search of other user lookup references.
            $offsets['users'] = $user_lookup_idx_start + 1;

            // Recurse in search of others.
            $prompt = self::parse_prompt( $prompt, $post_type, $offsets );
        }

        /**
         * Locations.
         */

        $location_lookup_idx_start = strpos( $prompt, $location_prefix, $offsets['users'] );
        if ( $location_lookup_idx_start !== false ) {

            // First, extract index and actual lookup value.
            $length = strpos( $prompt, ' ', $location_lookup_idx_start ) - $location_lookup_idx_start;
            $location_lookup_query = substr( $prompt, $location_lookup_idx_start, ( $length > strlen( $location_prefix ) ) ? $length : null );
            $location_lookup_query_value = substr( $location_lookup_query, strlen( $location_prefix ) );

            // Execute locations search.
            $locations = Disciple_Tools_Mapping_Queries::search_location_grid_by_name( [
                'search_query' => $location_lookup_query_value,
                'filter' => 'all'
            ] );

            // If available, source the first hit and update prompt accordingly.
            $parsed_location_str = '';
            if ( isset( $locations['location_grid'] ) && count( $locations['location_grid'] ) > 0 ) {
                $location = $locations['location_grid'][0];
                $parsed_location_str = '@['. $location['label'] .']('. $location['grid_id'] .')';
            }

            if ( !empty( $parsed_location_str ) ) {
                $prompt = str_replace( $location_lookup_query, $parsed_location_str, $prompt );
            }

            // Update locations offset count (to avoid endless recursion) and recursive, in search of other location lookup references.
            $offsets['locations'] = $location_lookup_idx_start + 1;

            // Recurse in search of others.
            $prompt = self::parse_prompt( $prompt, $post_type, $offsets );
        }

        return $prompt;
    }
}
