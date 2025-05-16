<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

/**
 * @todo replace all occurrences of the string "template" with a string of your choice
 * @todo also rename in charts-loader.php
 */

class Disciple_Tools_AI_Dynamic_Maps extends DT_Metrics_Chart_Base
{
    public $base_slug = 'disciple-tools-ai-metrics'; // lowercase
    public $base_title = 'Disciple Tools AI Metrics';

    public $title = 'Dynamic AI Maps';
    public $slug = 'dynamic_ai_maps'; // lowercase
    public $js_object_name = 'wp_js_object'; // This object will be loaded into the metrics.js file by the wp_localize_script.
    public $js_file_name = 'dynamic-ai-maps.js'; // should be full file name plus extension
    public $permissions = [ 'dt_all_access_contacts', 'view_project_metrics' ];

    public function __construct() {
        parent::__construct();

        add_action( 'rest_api_init', [ $this, 'add_api_routes' ] );

        if ( !$this->has_permission() ){
            return;
        }
        $url_path = dt_get_url_path();

        // only load scripts if exact url
        if ( "metrics/$this->base_slug/$this->slug" === $url_path ) {

            add_action( 'wp_enqueue_scripts', [ $this, 'scripts' ], 99 );
        }
    }


    /**
     * Load scripts for the plugin
     */
    public function scripts() {
        DT_Mapbox_API::load_mapbox_header_scripts();

        wp_register_script( 'amcharts-core', 'https://www.amcharts.com/lib/4/core.js', false, '4' );
        wp_register_script( 'amcharts-charts', 'https://www.amcharts.com/lib/4/charts.js', false, '4' );

        wp_enqueue_script( 'dt_'.$this->slug.'_script', trailingslashit( plugin_dir_url( __FILE__ ) ) . $this->js_file_name, [
            'jquery',
            'amcharts-core',
            'amcharts-charts'
        ], filemtime( plugin_dir_path( __FILE__ ) .$this->js_file_name ), true );

        // Localize script with array data
        wp_localize_script(
            'dt_'.$this->slug.'_script', 'dt_mapbox_metrics', [
                'settings' => [
                    'map_key' => DT_Mapbox_API::get_key(),
                    'no_map_key_msg' => _x( 'To view this map, a mapbox key is needed; click here to add.', 'install mapbox key to view map', 'disciple-tools-ai' ),
                    'map_mirror' => dt_get_location_grid_mirror( true ),
                    'menu_slug' => $this->base_slug,
                    'post_type' => 'contacts',
                    'title' => $this->title,
                    'rest_endpoints_base' => esc_url_raw( rest_url() ) . "$this->base_slug/$this->slug",
                    'nonce' => wp_create_nonce( 'wp_rest' )
                ],
                'translations' => [
                    'placeholder' => __( 'Describe the map you wish to view...', 'disciple-tools-ai' ),
                    'details_title' => __( 'Maps', 'disciple-tools-ai' ),
                    'multiple_options' => [
                        'title' => __( 'Multiple Options Detected', 'disciple-tools-ai' ),
                        'locations' => __( 'Locations', 'disciple-tools-ai' ),
                        'users' => __( 'Users', 'disciple-tools-ai' ),
                        'posts' => __( 'Posts', 'disciple-tools-ai' ),
                        'ignore_option' => __( '-- Ignore --', 'disciple-tools-ai' ),
                        'submit_but' => __( 'Submit', 'disciple-tools-ai' ),
                        'close_but' => __( 'Close', 'disciple-tools-ai' )
                    ]
                ]
            ]
        );
    }

    public function add_api_routes() {
        $namespace = "$this->base_slug/$this->slug";
        register_rest_route(
            $namespace, '/create_filter', [
                'methods'  => 'POST',
                'callback' => [ $this, 'create_filter' ],
                'permission_callback' => function( WP_REST_Request $request ) {
                    return $this->has_permission();
                },
            ]
        );
    }

    public function create_filter( WP_REST_Request $request ) {
        $params = $request->get_params();
        if ( ! isset( $params['prompt'], $params['post_type'] ) ) {
            return new WP_Error( __METHOD__, 'Missing parameters.' );
        }

        $prompt = $params['prompt'];
        $post_type = $params['post_type'];

        if ( isset( $params['selections'] ) ) {
            return $this->handle_create_filter_with_selections_request( $post_type, $prompt, $params['selections'] );
        } else {
            return $this->handle_create_filter_request( $post_type, $prompt );
        }
    }

    private function handle_create_filter_request( $post_type, $prompt ): array {

        /**
         * If the initial response is multiple_options_detected, then return; otherwise,
         * filter locations and then return.
         */

        $response = Disciple_Tools_AI_API::list_posts( $post_type, $prompt );
        if ( isset( $response['status'] ) && $response['status'] === 'multiple_options_detected' ) {
            return $response;
        }

        /**
         * Next, filter out records with valid location metadata and format in required
         * geojson shape.
         */

        $filtered_posts = array_filter( $response['posts'] ?? [], function ( $post ) {
            $location_grid_meta_key = 'location_grid_meta';
            return isset( $post[$location_grid_meta_key] ) && is_array( $post[$location_grid_meta_key] ) && count( $post[$location_grid_meta_key] ) > 0;
        } );

        $geojson_points = Disciple_Tools_AI_API::convert_posts_to_geojson( $filtered_posts, $post_type );

        /**
         * Finally, the finish line - return the response.
         */

        return [
            'status' => 'success',
            'prompt' => $response['prompt'] ?? [],
            'pii' => $response['pii'] ?? [],
            'connections' => $response['connections'] ?? [],
            'filter' => $response['filter'] ?? [],
            'points' => $geojson_points
        ];
    }

    private function handle_create_filter_with_selections_request( $post_type, $prompt, $selections ): array {

        $response = Disciple_Tools_AI_API::list_posts_with_selections( $post_type, $prompt, $selections );

        /**
         * Next, filter out records with valid location metadata and format in required
         * geojson shape.
         */

        $filtered_posts = array_filter( $response['posts'] ?? [], function ( $post ) {
            $location_grid_meta_key = 'location_grid_meta';
            return isset( $post[$location_grid_meta_key] ) && is_array( $post[$location_grid_meta_key] ) && count( $post[$location_grid_meta_key] ) > 0;
        } );

        $geojson_points = Disciple_Tools_AI_API::convert_posts_to_geojson( $filtered_posts, $post_type );

        /**
         * Finally, the finish line - return the response.
         */

        return [
            'status' => 'success',
            'prompt' => $response['prompt'] ?? [],
            'filter' => $response['filter'] ?? [],
            'points' => $geojson_points
        ];
    }
}
