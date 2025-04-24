<?php
if ( !defined( 'ABSPATH' ) ){
    exit;
} // Exit if accessed directly

class Disciple_Tools_AI_API {

    public static function handle_create_filter_request( $prompt, $post_type ): array {
        if ( !isset( $prompt, $post_type ) ) {
            return [];
        }

        $llm_endpoint_root = get_option( 'DT_AI_llm_endpoint' );
        $llm_api_key = get_option( 'DT_AI_llm_api_key' );
        $llm_model = get_option( 'DT_AI_llm_model' );
        $dt_ai_filter_specs = apply_filters( 'dt_ai_filter_specs', [], $post_type );
        $llm_endpoint = $llm_endpoint_root . '/chat/completions';

        // Convert filtered specifications into desired content shape.
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
         * Support retries; in the event of initial faulty json shaped responses.
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

                    // Ensure a valid json structure has been inferred; otherwise, retry!
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
         * Support retries; in the event of initial faulty json shaped responses.
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

                    // Ensure a valid json structure has been inferred; otherwise, retry!
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

    public static function parse_locations_for_ids( $locations ): array {
        if ( empty( $locations ) ) {
            return [];
        }

        $parsed_locations = [];

        // Iterate over locations, in search of corresponding grid ids.
        foreach ( $locations as $location ) {
            $hits = Disciple_Tools_Mapping_Queries::search_location_grid_by_name( [
                'search_query' => $location,
                'filter' => 'all'
            ] );

            $parsed_locations[] = [
                'prompt' => $location,
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

    public static function parse_connections_for_users( $users, $post_type ): array {
        if ( empty( $users ) ) {
            return [];
        }

        $parsed_users = [];

        // Iterate over connections, in search of corresponding system users.
        foreach ( $users as $user ) {
            $hits = Disciple_Tools_Users::get_assignable_users_compact( $user, true, $post_type );
            $parsed_users[] = [
                'prompt' => $user,
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

    public static function parse_connections_for_post_names( $names, $post_type ): array {
        if ( empty( $names ) ) {
            return [];
        }

        $parsed_post_names = [];

        // Iterate over connections, in search of corresponding post records.
        foreach ( $names as $name ) {

            $records = DT_Posts::list_posts( $post_type, [
                'fields' => [
                    [
                        'name' => $name
                    ]
                ],
                'sort' => '-last_modified',
                'overall_status' => '-closed',
                'fields_to_return' => [
                    'name'
                ]
            ]);

            $parsed_post_names[] = [
                'prompt' => $name,
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
                            'name' => $location['label'],
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
