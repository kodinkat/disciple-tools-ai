<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.


/**
 * Class Disciple_Tools_AI_Magic_User_App
 */
class Disciple_Tools_AI_Magic_User_App extends DT_Magic_Url_Base {

    public $page_title = 'Starter - Magic Links - User App';
    public $page_description = 'User App - Magic Links.';
    public $root = 'starter_magic_app'; // @todo define the root of the url {yoursite}/root/type/key/action
    public $type = 'starter_user_app'; // @todo define the type
    public $post_type = 'user';
    private $meta_key = '';
    public $show_bulk_send = false;
    public $show_app_tile = false;

    private static $_instance = null;
    public $meta = []; // Allows for instance specific data.

    public static function instance() {
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
            'show_in_home_apps' => false
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
    }

    public function dt_magic_url_base_allowed_js( $allowed_js ) {
        // @todo add or remove js files with this filter
        return $allowed_js;
    }

    public function dt_magic_url_base_allowed_css( $allowed_css ) {
        // @todo add or remove js files with this filter
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
            'settings_display' => false
        ];

        return $apps_list;
    }

    /**
     * Writes custom styles to header
     *
     * @see DT_Magic_Url_Base()->header_style() for default state
     * @todo remove if not needed
     */
    public function header_style(){
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
    public function header_javascript(){
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
    public function footer_javascript(){
        ?>
        <script>
            console.log('insert footer_javascript')

            let jsObject = [<?php echo json_encode([
                'map_key' => DT_Mapbox_API::get_key(),
                'root' => esc_url_raw( rest_url() ),
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'parts' => $this->parts,
                'translations' => [
                    'add' => __( 'Add Magic', 'disciple-tools-ai' ),
                ],
            ]) ?>][0]

            window.get_magic = () => {
                jQuery.ajax({
                    type: "GET",
                    data: { action: 'get', parts: jsObject.parts },
                    contentType: "application/json; charset=utf-8",
                    dataType: "json",
                    url: jsObject.root + jsObject.parts.root + '/v1/' + jsObject.parts.type,
                    beforeSend: function (xhr) {
                        xhr.setRequestHeader('X-WP-Nonce', jsObject.nonce )
                    }
                })
                    .done(function(data){
                        window.load_magic( data )
                    })
                    .fail(function(e) {
                        console.log(e)
                        jQuery('#error').html(e)
                    })
            }

            window.load_magic = ( data ) => {
                let content = jQuery('#api-content')
                let spinner = jQuery('.loading-spinner')

                content.empty()
                let html = ``
                data.forEach(v=>{
                    html += `
                         <div class="cell">
                             ${window.lodash.escape(v.name)}
                         </div>
                     `
                })
                content.html(html)

                spinner.removeClass('active')

            }

            window.get_magic()


            $('.dt_date_picker').datepicker({
                constrainInput: false,
                dateFormat: 'yy-mm-dd',
                changeMonth: true,
                changeYear: true,
                yearRange: "1900:2050",
            }).each(function() {
                if (this.value && moment.unix(this.value).isValid()) {
                    this.value = window.SHAREDFUNCTIONS.formatDate(this.value);
                }
            })


            $('#submit-form').on("click", function (){
                $(this).addClass("loading")
                let start_date = $('#start_date').val()
                let comment = $('#comment-input').val()
                let update = {
                    start_date,
                    comment
                }

                window.makeRequest( "POST", jsObject.parts.type, { parts: jsObject.parts, update }, jsObject.parts.root + '/v1/' ).done(function(data){
                    window.location.reload()
                })
                    .fail(function(e) {
                        console.log(e)
                        jQuery('#error').html(e)
                    })
            })
        </script>
        <?php
        return true;
    }

    public function body(){
        ?>
        <div id="custom-style"></div>
        <div id="wrapper">
            <div class="grid-x">
                <div class="cell center">
                    <h2 id="title">Title</h2>
                </div>
            </div>
            <hr>
            <div id="content">
                <h3>List From API</h3>
                <div class="grid-x" id="api-content">
                    <!-- javascript container -->
                    <span class="loading-spinner active"></span>
                </div>

                <br>
                <br>
                <br>
                <h3>Form</h3>
                <div class="grid-x" id="form-content">

                </div>
            </div>

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
            $namespace, '/'.$this->type, [
                [
                    'methods'  => 'GET',
                    'callback' => [ $this, 'endpoint_get' ],
                    'permission_callback' => function( WP_REST_Request $request ){
                        $magic = new DT_Magic_URL( $this->root );

                        return $magic->verify_rest_endpoint_permissions_on_post( $request );
                    },
                ],
            ]
        );
        register_rest_route(
            $namespace, '/'.$this->type, [
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

    public function update_record( WP_REST_Request $request ) {
        $params = $request->get_params();
        $params = dt_recursive_sanitize_array( $params );

        $post_id = $params['parts']['post_id']; //has been verified in verify_rest_endpoint_permissions_on_post()

        $args = [];
        if ( !is_user_logged_in() ){
            $global_name = apply_filters( 'dt_magic_link_global_name', __( 'Magic Link', 'disciple_tools' ) );
            $args['comment_author'] = sprintf( __( '%s Submission', 'disciple_tools' ), $global_name );
            wp_set_current_user( 0 );
            $current_user = wp_get_current_user();
            $current_user->add_cap( 'magic_link' );
            $current_user->display_name = sprintf( __( '%s Submission', 'disciple_tools' ), $global_name );
        }

        if ( isset( $params['update']['comment'] ) && !empty( $params['update']['comment'] ) ){
            $update = DT_Posts::add_post_comment( $this->post_type, $post_id, $params['update']['comment'], 'comment', $args, false );
            if ( is_wp_error( $update ) ){
                return $update;
            }
        }

        if ( isset( $params['update']['start_date'] ) && !empty( $params['update']['start_date'] ) ){
            $update = DT_Posts::update_post( $this->post_type, $post_id, [ 'start_date' => $params['update']['start_date'] ], false, false );
            if ( is_wp_error( $update ) ){
                return $update;
            }
        }

        return true;
    }

    public function endpoint_get( WP_REST_Request $request ) {
        $params = $request->get_params();
        if ( ! isset( $params['parts'], $params['action'] ) ) {
            return new WP_Error( __METHOD__, 'Missing parameters', [ 'status' => 400 ] );
        }

        $data = [];

        $data[] = [ 'name' => 'List item' ]; // @todo remove example
        $data[] = [ 'name' => 'List item' ]; // @todo remove example

        return $data;
    }
}
Disciple_Tools_AI_Magic_User_App::instance();
