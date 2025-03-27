<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

class Disciple_Tools_AI_Endpoints
{
    /**
     * @todo Set the permissions your endpoint needs
     * @link https://github.com/DiscipleTools/Documentation/blob/master/theme-core/capabilities.md
     * @var string[]
     */
    public $permissions = [ 'access_contacts', 'dt_all_access_contacts', 'view_project_metrics' ];


    /**
     * @todo define the name of the $namespace
     * @todo define the name of the rest route
     * @todo defne method (CREATABLE, READABLE)
     * @todo apply permission strategy. '__return_true' essentially skips the permission check.
     */
    //See https://github.com/DiscipleTools/disciple-tools-theme/wiki/Site-to-Site-Link for outside of wordpress authentication
    public function add_api_routes() {
        $namespace = 'disciple-tools-ai/v1';

        register_rest_route(
            $namespace, '/dt-ai-summarize', [
                'methods'  => 'POST',
                'callback' => [ $this, 'summarize' ],
                'permission_callback' => function( WP_REST_Request $request ) {
                    return $this->has_permission();
                },
            ]
        );

        register_rest_route(
            $namespace, '/dt-ai-create-filter', [
                'methods'  => 'POST',
                'callback' => [ $this, 'create_filter' ],
                'permission_callback' => function( WP_REST_Request $request ) {
                    return $this->has_permission();
                },
            ]
        );
    }

    public function summarize( WP_REST_Request $request ) {
        // Get the prompt from the request and make a call to the OpenAI API to summarize and return the response
        $prompt = $request->get_param( 'prompt' );

        $post_type = $request->get_param( 'post_type' );
        $post_id = $request->get_param( 'post_id' );

        $llm_endpoint_root = get_option( 'DT_AI_llm_endpoint' );
        $llm_api_key = get_option( 'DT_AI_llm_api_key' );
        $llm_model = get_option( 'DT_AI_llm_model' );

        $llm_endpoint = $llm_endpoint_root . '/chat/completions';

        $response = wp_remote_post( $llm_endpoint, [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $llm_api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode( [
                'model' => $llm_model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => $prompt,
                    ],
                ],
                'max_completion_tokens' => 1000,
                'temperature' => 1,
                'top_p' => 1,
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'api_error', 'Failed to connect to LLM API', [ 'status' => 500 ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        $summary = $body['choices'][0]['message']['content'];

        $post_updated = false;
        if ( isset( $post_type, $post_id ) && in_array( $post_type, [ 'contacts', 'ai' ] ) ) {
            $updated = DT_Posts::update_post( $post_type, $post_id, [
                'ai_summary' => $summary
            ] );

            $post_updated = !is_wp_error( $updated );
        }

        return [
            'updated' => $post_updated,
            'summary' => $summary
        ];
    }

    public function create_filter( WP_REST_Request $request ) {
        $params = $request->get_params();

        $prompt = $params['prompt'];
        $post_type = $params['post_type'];

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
                        $response = json_decode( $inferred_response['choices'][0]['message']['content'], true );
                        $attempts = 2;
                    }

                }

            } catch (Exception $e) {
                dt_write_log( $e->getMessage() );
            }
        }

        return $response;
    }

    private static $_instance = null;
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()
    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );
    }
    public function has_permission(){
        $pass = false;
        foreach ( $this->permissions as $permission ){
            if ( current_user_can( $permission ) ){
                $pass = true;
            }
        }
        return $pass;
    }
}
Disciple_Tools_AI_Endpoints::instance();
