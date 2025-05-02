<?php
if ( !defined( 'ABSPATH' ) ){
    exit;
} // Exit if accessed directly.

/**
 * Class Disciple_Tools_AI_Magic_List
 */
class Disciple_Tools_AI_Magic_List_App extends DT_Magic_Url_Base {

    public $page_title = 'AI Filtered List App';
    public $page_description = 'Dynamically display AI generated filtered record lists.';
    public $root = 'ai'; // @todo define the root of the url {yoursite}/root/type/key/action
    public $type = 'list_app'; // @todo define the type
    public $post_type = 'user';
    private $meta_key = '';
    public $show_bulk_send = false;
    public $show_app_tile = false;

    private $default_post_type = 'contacts';
    private $default_fields = [
        'name',
        'age',
        'faith_status',
        'milestones',
        'ai_summary'
    ];

    private static $_instance = null;
    public $meta = []; // Allows for instance specific data.

    public static function instance(){
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct() {

        /**
         * Specify metadata structure, specific to the processing of current
         * magic link type.
         *
         * - meta:              Magic link plugin related data.
         *      - app_type:     Flag indicating type to be processed by magic link plugin.
         *      - post_type     Magic link type post type.
         *      - contacts_only:    Boolean flag indicating how magic link type user assignments are to be handled within magic link plugin.
         *                          If True, lookup field to be provided within plugin for contacts only searching.
         *                          If false, Dropdown option to be provided for user, team or group selection.
         *      - fields:       List of fields to be displayed within magic link frontend form.
         *      - icon:         Custom font icon to be associated with magic link.
         *      - show_in_home_apps:    Boolean flag indicating if magic link should be automatically loaded and shown within Home Screen Plugin.
         */
        $this->meta = [
            'app_type'      => 'magic_link',
            'post_type'     => $this->post_type,
            'contacts_only' => false,
            'fields'        => [
                [
                    'id'    => 'name',
                    'label' => 'Name'
                ]
            ],
            'icon'           => 'mdi mdi-cog-outline',
            'show_in_home_apps' => true
        ];

        $this->meta_key = $this->root . '_' . $this->type . '_magic_key';
        parent::__construct();

        /**
         * user_app and module section
         */
        add_filter( 'dt_settings_apps_list', [ $this, 'dt_settings_apps_list' ], 10, 1 );
        add_action( 'rest_api_init', [ $this, 'add_endpoints' ] );

        /**
         * tests if other URL
         */
        $url = dt_get_url_path();
        if ( strpos( $url, $this->root . '/' . $this->type ) === false ) {
            return;
        }
        /**
         * tests magic link parts are registered and have valid elements
         */
        if ( !$this->check_parts_match() ){
            return;
        }

        // load if valid url
        add_action( 'dt_blank_body', [ $this, 'body' ] );
        add_filter( 'dt_magic_url_base_allowed_css', [ $this, 'dt_magic_url_base_allowed_css' ], 10, 1 );
        add_filter( 'dt_magic_url_base_allowed_js', [ $this, 'dt_magic_url_base_allowed_js' ], 10, 1 );
        add_action( 'wp_enqueue_scripts', [ $this, 'wp_enqueue_scripts' ], 100 );
    }

    public function wp_enqueue_scripts() {
        $js_path = './assets/ai-list-app.js';
        $css_path = './assets/ai-list-app.css';

        wp_enqueue_style( 'ml-ai-list-app-css', plugin_dir_url( __FILE__ ) . $css_path, null, filemtime( plugin_dir_path( __FILE__ ) . $css_path ) );
        wp_enqueue_script( 'ml-ai-list-app-js', plugin_dir_url( __FILE__ ) . $js_path, null, filemtime( plugin_dir_path( __FILE__ ) . $js_path ) );
        wp_localize_script(
            'ml-ai-list-app-js', 'dt_ai_obj', [
                'translations' => [
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

        $dtwc_version = '0.6.6';
        wp_enqueue_style( 'dt-web-components-css', "https://cdn.jsdelivr.net/npm/@disciple.tools/web-components@$dtwc_version/src/styles/light.css", [], $dtwc_version ); // remove 'src' after v0.7
        wp_enqueue_script( 'dt-web-components-js', "https://cdn.jsdelivr.net/npm/@disciple.tools/web-components@$dtwc_version/dist/index.js", $dtwc_version );
        add_filter( 'script_loader_tag', 'add_module_type_to_script', 10, 3 );
        function add_module_type_to_script( $tag, $handle, $src ) {
            if ( 'dt-web-components-js' === $handle ) {
                // @codingStandardsIgnoreStart
                $tag = '<script type="module" src="' . esc_url( $src ) . '"></script>';
                // @codingStandardsIgnoreEnd
            }
            return $tag;
        }
        wp_enqueue_script( 'dt-web-components-services-js', "https://cdn.jsdelivr.net/npm/@disciple.tools/web-components@$dtwc_version/dist/services.min.js", array( 'jquery' ), true ); // not needed after v0.7

        $mdi_version = '6.6.96';
        wp_enqueue_style( 'material-font-icons-css', "https://cdn.jsdelivr.net/npm/@mdi/font@$mdi_version/css/materialdesignicons.min.css", [], $mdi_version );

        if ( class_exists( 'Disciple_Tools_Bulk_Magic_Link_Sender_API' ) ) {
            Disciple_Tools_Bulk_Magic_Link_Sender_API::enqueue_magic_link_utilities_script();
        }
    }

    public function dt_magic_url_base_allowed_js( $allowed_js ) {
        $allowed_js[] = 'dt-web-components-js';
        $allowed_js[] = 'dt-web-components-services-js';
        $allowed_js[] = 'ml-ai-list-app-js';

        if ( class_exists( 'Disciple_Tools_Bulk_Magic_Link_Sender_API' ) ) {
            $allowed_js[] = Disciple_Tools_Bulk_Magic_Link_Sender_API::get_magic_link_utilities_script_handle();
        }

        return $allowed_js;
    }

    public function dt_magic_url_base_allowed_css( $allowed_css ) {
        $allowed_css[] = 'material-font-icons-css';
        $allowed_css[] = 'dt-web-components-css';
        $allowed_css[] = 'ml-ai-list-app-css';

        return $allowed_css;
    }

    /**
     * Builds magic link type settings payload:
     * - key:               Unique magic link type key; which is usually composed of root, type and _magic_key suffix.
     * - url_base:          URL path information to map with parent magic link type.
     * - label:             Magic link type name.
     * - description:       Magic link type description.
     * - settings_display:  Boolean flag which determines if magic link type is to be listed within frontend user profile settings.
     *
     * @param $apps_list
     *
     * @return mixed
     */
    public function dt_settings_apps_list( $apps_list ) {
        $apps_list[ $this->meta_key ] = [
            'key'              => $this->meta_key,
            'url_base'         => $this->root . '/' . $this->type,
            'label'            => $this->page_title,
            'description'      => $this->page_description,
            'settings_display' => true
        ];

        return $apps_list;
    }

    /**
     * Writes custom styles to header
     *
     * @see DT_Magic_Url_Base()->header_style() for default state
     * @todo remove if not needed
     */
    public function header_style() {
        ?>
        <style>
            body {
                background-color: white;
                padding: 1em;
            }
        </style>
        <?php
    }

    /**
     * Writes javascript to the header
     *
     * @see DT_Magic_Url_Base()->header_javascript() for default state
     * @todo remove if not needed
     */
    public function header_javascript() {
        ?>
        <script>
            console.log('insert header_javascript')
        </script>
        <?php
    }

    /**
     * Writes javascript to the footer
     *
     * @see DT_Magic_Url_Base()->footer_javascript() for default state
     * @todo remove if not needed
     */
    public function footer_javascript() {
        ?>
        <script>
            let jsObject = [<?php echo json_encode( [
                'root' => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'default_post_type' => $this->default_post_type,
                'sys_type' => 'wp_user',
                'parts' => $this->parts,
                'translations' => [
                    'item_saved' => esc_attr__( 'Item Saved', 'disciple-tools-ai' )
                ]
            ] ) ?>][0];

            document.getElementById('search').addEventListener('keyup', function(e) {
                e.preventDefault();

                if (e.key === 'Enter') { // Enter key pressed.
                    create_filter();

                } else { // Manage field clearing option.
                    show_filter_clear_option();
                }
            });
        </script>
        <?php
    }

    public function body() {
        ?>
        <main>
            <div id="list" class="is-expanded">
                <header>
                    <h1><?php echo esc_html( $this->page_title ); ?></h1>
                </header>

                <div id="search-filter">
                    <div id="search-bar">
                        <input type="text" id="search" placeholder="<?php esc_html_e( 'Describe the list to show...', 'disciple-tools-ai' ); ?>" />
                        <button id="clear-button" style="display: none;" class="clear-button mdi mdi-close" onclick="clear_filter();"></button>
                        <button class="filter-button mdi mdi-star-four-points-outline" onclick="create_filter();"></button>
                    </div>
                </div>

                <ul id="list-items" class="items"></ul>
                <div id="spinner-div" style="justify-content: center; display: flex;">
                    <span id="temp-spinner" class="loading-spinner inactive" style="margin: 0; position: absolute; top: 50%; -ms-transform: translateY(-50%); transform: translateY(-50%); height: 100px; width: 100px; z-index: 100;"></span>
                </div>
                <template id="list-item-template">
                    <li>
                        <a href="javascript:load_post_details()">
                            <span class="post-id"></span>
                            <span class="post-title"></span>
                            <span class="post-updated-date"></span>
                        </a>
                    </li>
                </template>
            </div>
            <div id="detail" class="">

                <form>
                    <header>
                        <button type="button" class="details-toggle mdi mdi-arrow-left" onclick="toggle_panels()"></button>
                        <h2 id="detail-title"></h2>
                        <span id="detail-title-post-id"></span>
                    </header>

                    <div id="detail-content"></div>
                    <footer>
                        <dt-button onclick="save_item(event)" type="submit" context="primary"><?php esc_html_e( 'Submit Update', 'disciple-tools-ai' ) ?></dt-button>
                    </footer>
                </form>

                <template id="comment-header-template">
                    <div class="comment-header">
                        <span><strong id="comment-author"></strong></span>
                        <span class="comment-date" id="comment-date"></span>
                    </div>
                </template>
                <template id="comment-content-template">
                    <div class="activity-text">
                        <div dir="auto" class="" data-comment-id="" id="comment-id">
                            <div class="comment-text" title="" dir="auto" id="comment-content">
                            </div>
                        </div>
                    </div>
                </template>

                <template id="post-detail-template">
                    <input type="hidden" name="id" id="post-id" />
                    <input type="hidden" name="type" id="post-type" />

                    <dt-tile id="all-fields" open>
                        <?php
                        // ML Plugin required.
                        if ( class_exists( 'Disciple_Tools_Magic_Links_Helper' ) ) {
                            $post_field_settings = DT_Posts::get_post_field_settings( $this->default_post_type );
                            foreach ( $post_field_settings as $field_key => $field ) {
                                if ( in_array( $field_key, $this->default_fields ) ) {

                                    // display standard DT fields
                                    $post_field_settings[$field_key]['custom_display'] = false;
                                    $post_field_settings[$field_key]['readonly'] = false;

                                    Disciple_Tools_Magic_Links_Helper::render_field_for_display( $field_key, $post_field_settings, [] );
                                }
                            }
                        }
                        ?>
                    </dt-tile>

                    <dt-tile id="comments-tile" title="Comments">
                        <div>
                            <textarea id="comments-text-area"
                                      style="resize: none;"
                                      placeholder="<?php echo esc_html_x( 'Write your comment or note here', 'input field placeholder', 'disciple-tools-ai' ) ?>"
                            ></textarea>
                        </div>
                        <div class="comment-button-container">
                            <button class="button loader" type="button" id="comment-button">
                                <?php esc_html_e( 'Submit comment', 'disciple-tools-ai' ) ?>
                            </button>
                        </div>
                    </dt-tile>
                </template>
            </div>
            <div id="snackbar-area"></div>
            <template id="snackbar-item-template">
                <div class="snackbar-item"></div>
            </template>
        </main>
        <div class="reveal small" id="modal-small" data-v-offset="0" data-reveal>
            <h3 id="modal-small-title"></h3>
            <div id="modal-small-content"></div>
            <button class="close-button" data-close aria-label="<?php esc_html_e( 'Close', 'disciple-tools-ai' ); ?>" type="button">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
        <?php
    }

    /**
     * Register REST Endpoints
     * @link https://github.com/DiscipleTools/disciple-tools-theme/wiki/Site-to-Site-Link for outside of wordpress authentication
     */
    public function add_endpoints() {
        $namespace = $this->root . '/v1';

        register_rest_route(
            $namespace, '/' . $this->type . '/create_filter', [
                [
                    'methods'  => 'POST',
                    'callback' => [ $this, 'create_filter' ],
                    'permission_callback' => function( WP_REST_Request $request ){
                        $magic = new DT_Magic_URL( $this->root );

                        return $magic->verify_rest_endpoint_permissions_on_post( $request );
                    },
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/get_post', [
                [
                    'methods'  => 'POST',
                    'callback' => [ $this, 'get_post' ],
                    'permission_callback' => function( WP_REST_Request $request ){
                        $magic = new DT_Magic_URL( $this->root );

                        return $magic->verify_rest_endpoint_permissions_on_post( $request );
                    },
                ],
            ]
        );

        register_rest_route(
            $namespace, '/' . $this->type . '/comment', [
                [
                    'methods'  => 'POST',
                    'callback' => [ $this, 'comment' ],
                    'permission_callback' => function( WP_REST_Request $request ){
                        $magic = new DT_Magic_URL( $this->root );

                        return $magic->verify_rest_endpoint_permissions_on_post( $request );
                    },
                ],
            ]
        );

        register_rest_route(
            $namespace, '/'.$this->type . '/update', [
                [
                    'methods'  => 'POST',
                    'callback' => [ $this, 'update_record' ],
                    'permission_callback' => function( WP_REST_Request $request ){
                        $magic = new DT_Magic_URL( $this->root );

                        return $magic->verify_rest_endpoint_permissions_on_post( $request );
                    },
                ],
            ]
        );
    }

    public function create_filter( WP_REST_Request $request ) {
        $params = $request->get_params();

        if ( ! isset( $params['filter'], $params['parts'], $params['action'], $params['sys_type'] ) ) {
            return new WP_Error( __METHOD__, 'Missing parameters', [ 'status' => 400 ] );
        }

        // Sanitize and fetch user/post id
        $params = dt_recursive_sanitize_array( $params );

        // Update logged-in user state if required accordingly, based on their sys_type
        if ( !is_user_logged_in() ) {
            $this->update_user_logged_in_state( $params['sys_type'], $params['parts']['post_id'] );
        }

        $prompt = $params['filter']['prompt'];
        $post_type = $params['filter']['post_type'];

        if ( isset( $params['filter']['selections'] ) ) {
            return $this->handle_create_filter_with_selections_request( $post_type, $prompt, $params['filter']['selections'] );
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
         * Finally, the finish line - return the response.
         */

        return [
            'status' => 'success',
            'prompt' => $response['prompt'] ?? [],
            'pii' => $response['pii'] ?? [],
            'connections' => $response['connections'] ?? [],
            'filter' => $response['filter'] ?? [],
            'posts' => $response['posts'] ?? []
        ];
    }

    private function handle_create_filter_with_selections_request( $post_type, $prompt, $selections ): array {

        $response = Disciple_Tools_AI_API::list_posts_with_selections( $post_type, $prompt, $selections );

        /**
         * Finally, the finish line - return the response.
         */

        return [
            'status' => 'success',
            'prompt' => $response['prompt'] ?? [],
            'filter' => $response['filter'] ?? [],
            'posts' => $response['posts'] ?? []
        ];
    }

    public function get_post( WP_REST_Request $request ) {
        $params = $request->get_params();

        if ( ! isset( $params['post_id'], $params['post_type'], $params['parts'], $params['action'], $params['comment_count'], $params['sys_type'] ) ) {
            return new WP_Error( __METHOD__, 'Missing parameters', [ 'status' => 400 ] );
        }

        // Sanitize and fetch user/post id
        $params = dt_recursive_sanitize_array( $params );

        // Update logged-in user state if required accordingly, based on their sys_type
        if ( !is_user_logged_in() ) {
            $this->update_user_logged_in_state( $params['sys_type'], $params['parts']['post_id'] );
        }

        // Fetch corresponding post object.
        $post = DT_Posts::get_post( $params['post_type'], $params['post_id'] );
        if ( empty( $post ) || is_wp_error( $post ) ) {
            return new WP_Error( __METHOD__, 'Missing parameters', [ 'status' => 400 ] );
        }

        // Assuming we have a valid hit, return along with specified comments.
        return [
            'success' => true,
            'post' => $post,
            'comments' => DT_Posts::get_post_comments( $params['post_type'], $params['post_id'], false, 'all', [ 'number' => $params['comment_count'] ] )
        ];
    }

    public function comment( WP_REST_Request $request ) {
        $params = $request->get_params();

        if ( ! isset( $params['post_id'], $params['post_type'], $params['parts'], $params['action'], $params['comment'], $params['comment_count'], $params['sys_type'] ) ) {
            return new WP_Error( __METHOD__, 'Missing parameters', [ 'status' => 400 ] );
        }

        // Sanitize and fetch user/post id
        $params = dt_recursive_sanitize_array( $params );

        // Update logged-in user state if required accordingly, based on their sys_type
        if ( !is_user_logged_in() ) {
            $this->update_user_logged_in_state( $params['sys_type'], $params['parts']['post_id'] );
        }

        // Insert comment for specified post id.
        $comment_id = DT_Posts::add_post_comment( $params['post_type'], $params['post_id'], $params['comment'], 'comment', [], false );

        return [
            'success' => !is_wp_error( $comment_id ),
            'comments' => DT_Posts::get_post_comments( $params['post_type'], $params['post_id'], false, 'all', [ 'number' => $params['comment_count'] ] )
        ];
    }

    public function update_record( WP_REST_Request $request ) {
        $params = $request->get_params();

        if ( ! isset( $params['post_id'], $params['post_type'], $params['parts'], $params['action'], $params['fields'], $params['sys_type'] ) ) {
            return new WP_Error( __METHOD__, 'Missing parameters', [ 'status' => 400 ] );
        }

        // Sanitize and fetch user/post id
        $params = dt_recursive_sanitize_array( $params );

        // Update logged-in user state if required accordingly, based on their sys_type
        if ( !is_user_logged_in() ) {
            $this->update_user_logged_in_state( $params['sys_type'], $params['parts']['post_id'] );
        }


        // Package field updates...
        $updates = [];
        foreach ( $params['fields']['dt'] ?? [] as $field ) {
            if ( $field['id'] === 'age' ) {
                $field['value'] = str_replace( '&lt;', '<', $field['value'] );
                $field['value'] = str_replace( '&gt;', '>', $field['value'] );
            }
            if ( isset( $field['value'] ) ) {
                switch ( $field['type'] ) {
                    case 'text':
                    case 'textarea':
                    case 'number':
                    case 'date':
                    case 'datetime':
                    case 'boolean':
                    case 'key_select':
                    case 'multi_select':
                        $updates[$field['id']] = $field['value'];
                        break;
                    case 'communication_channel':
                        $updates[$field['id']] = [
                            'values' => $field['value'],
                            'force_values' => true,
                        ];
                        break;
                    case 'location_meta':
                        $values = array_map(function ( $value ) {
                            // try to send without grid_id to get more specific location
                            if ( isset( $value['lat'], $value['lng'], $value['label'], $value['level'], $value['source'] ) ) {
                                return array_intersect_key($value, array_fill_keys([
                                    'lat',
                                    'lng',
                                    'label',
                                    'level',
                                    'source',
                                ], null));
                            }
                            return array_intersect_key($value, array_fill_keys([
                                'lat',
                                'lng',
                                'label',
                                'level',
                                'source',
                                'grid_id'
                            ], null));
                        }, $field['value'] );
                        $updates[$field['id']] = [
                            'values' => $values,
                            'force_values' => true,
                        ];
                        break;
                    default:
                        // unhandled field types
                        dt_write_log( 'Unsupported field type: ' . $field['value'] );
                        break;
                }
            }
        }

        // Execute final post field updates.
        $updated_post = DT_Posts::update_post( $params['post_type'], $params['post_id'], $updates, false, false );

        // Finally, return response accordingly by post state.
        $success = !( empty( $updated_post ) || is_wp_error( $updated_post ) );
        return [
            'success' => $success,
            'message' => '',
            'post' => $success ? $updated_post : null
        ];
    }

    public function update_user_logged_in_state( $sys_type, $user_id ) {
        switch ( strtolower( trim( $sys_type ) ) ) {
            case 'post':
                wp_set_current_user( 0 );
                $current_user = wp_get_current_user();
                $current_user->add_cap( 'magic_link' );
                $current_user->display_name = sprintf( __( '%s Submission', 'disciple_tools' ), apply_filters( 'dt_magic_link_global_name', __( 'Magic Link', 'disciple_tools' ) ) );
                break;
            default: // wp_user
                wp_set_current_user( $user_id );
                break;

        }
    }
}

Disciple_Tools_AI_Magic_List_App::instance();
