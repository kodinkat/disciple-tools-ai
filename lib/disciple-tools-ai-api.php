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
}
