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
                'callback' => [ $this, 'endpoint' ],
                'permission_callback' => function( WP_REST_Request $request ) {
                    return $this->has_permission();
                },
            ]
        );
    }


    public function endpoint( WP_REST_Request $request ) {
        // Get the prompt from the request and make a call to the OpenAI API to summarize and return the response
        $prompt = $request->get_param( 'prompt' );
        $LLM_endpoint_root = get_option( 'DT_AI_LLM_endpoint' );
        $LLM_api_key = get_option( 'DT_AI_LLM_api_key' );
        $LLM_model = get_option( 'DT_AI_LLM_model' );

        $LLM_endpoint = $LLM_endpoint_root . '/chat/completions';

        $response = wp_remote_post( $LLM_endpoint, [
            'method' => 'POST',
            'headers' => [
                'Authorization' => 'Bearer ' . $LLM_api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode( [
                'model' => $LLM_model,
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

        $body = json_decode(wp_remote_retrieve_body($response), true);

        return $body['choices'][0]['message']['content'];

        return rest_ensure_response( $data );
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
