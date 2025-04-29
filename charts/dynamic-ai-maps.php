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
            return $this->handle_create_filter_with_selections_request( $prompt, $post_type, $params['selections'] );
        } else {
            return $this->handle_create_filter_request( $prompt, $post_type );
        }
    }

    private function handle_create_filter_request( $prompt, $post_type ): array {

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

        $pii = Disciple_Tools_AI_API::parse_prompt_for_pii( $prompt );
        $has_pii = ( !empty( $pii['pii'] ) && !empty( $pii['mappings'] ) && isset( $pii['prompt']['obfuscated'] ) );
        if ( $has_pii ) {
            $prompt = $pii['prompt']['obfuscated'];
        }

        /**
         * Proceed with parsing prompt for connections.
         */

        $connections = Disciple_Tools_AI_API::parse_prompt_for_connections( $prompt );

        // Extract any locations from identified connections.
        if ( !empty( $connections['locations'] ) ) {
            $locations = Disciple_Tools_AI_API::parse_locations_for_ids( $connections['locations'], $pii['mappings'] ?? [] );

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
            $users = Disciple_Tools_AI_API::parse_connections_for_users( $connections['connections'], $post_type, $pii['mappings'] ?? [] );

            // Identify users with multiple options.
            $multiple_users = array_filter( $users, function( $user ) {
                return count( $user['options'] ) > 1;
            } );

            /**
             * Posts.
             */

            // Extract any post-names from identified connections.
            $posts = Disciple_Tools_AI_API::parse_connections_for_post_names( $connections['connections'], $post_type, $pii['mappings'] ?? [] );

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

        $filter =  Disciple_Tools_AI_API::handle_create_filter_request( $parsed_prompt, $post_type );

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

        /**
         * Next, filter out records with valid location metadata and format in required
         * geojson shape.
         */

        $filtered_posts = array_filter( $list_posts, function ( $post ) {
            $location_grid_meta_key = 'location_grid_meta';
            return isset( $post[$location_grid_meta_key] ) && is_array( $post[$location_grid_meta_key] ) && count( $post[$location_grid_meta_key] ) > 0;
        } );

        $geojson_points = Disciple_Tools_AI_API::convert_posts_to_geojson( $filtered_posts, $post_type );

        /**
         * Finally, the finish line - return the response.
         */

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
            'points' => $geojson_points
        ];
    }

    private function handle_create_filter_with_selections_request( $prompt, $post_type, $selections ): array {
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

        $filter =  Disciple_Tools_AI_API::handle_create_filter_request( $parsed_prompt, $post_type );

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

        /**
         * Next, filter out records with valid location metadata and format in required
         * geojson shape.
         */

        $filtered_posts = array_filter( $list_posts, function ( $post ) {
            $location_grid_meta_key = 'location_grid_meta';
            return isset( $post[$location_grid_meta_key] ) && is_array( $post[$location_grid_meta_key] ) && count( $post[$location_grid_meta_key] ) > 0;
        } );

        $geojson_points = Disciple_Tools_AI_API::convert_posts_to_geojson( $filtered_posts, $post_type );

        /**
         * Finally, the finish line - return the response.
         */

        return [
            'status' => 'success',
            'prompt' => [
                'original' => $prompt,
                'parsed' => $parsed_prompt
            ],
            'filter' => $filter,
            'points' => $geojson_points
        ];
    }
}
