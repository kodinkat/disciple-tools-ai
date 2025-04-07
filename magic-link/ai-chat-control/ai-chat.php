<?php
if ( !defined( 'ABSPATH' ) ){
    exit;
} // Exit if accessed directly.


/**
 * Class Disciple_Tools_Plugin_Starter_Template_Magic_User_App
 */
class DT_AI_Chat extends DT_Magic_Url_Base{

    public $page_title = 'AI Chat Control';
    public $page_description = 'AI Chat Control';
    public $root = 'ai';
    public $type = 'control';
    public $post_type = 'user';
    private $meta_key = '';
    public $show_bulk_send = false;
    public $show_app_tile = false;
    public $namespace = 'ai/v1/control';

    private static $_instance = null;
    public $meta = []; // Allows for instance specific data.

    public static function instance(){
        if ( is_null( self::$_instance ) ){
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct(){

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
            'app_type' => 'magic_link',
            'post_type' => $this->post_type,
            'contacts_only' => false,
            'fields' => [
                [
                    'id' => 'name',
                    'label' => 'Name'
                ]
            ],
            'icon' => 'mdi mdi-cog-outline',
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
        if ( strpos( $url, $this->root . '/' . $this->type ) === false ){
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
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
    }


    public function enqueue_scripts(){
        wp_enqueue_style( 'material-font-icons-css', 'https://cdn.materialdesignicons.com/5.4.55/css/materialdesignicons.min.css', [], null, 'all' );
        wp_enqueue_script( 'ai-chat-control', plugin_dir_url( __FILE__ ) . 'ai-chat.js', [ 'jquery' ], filemtime( plugin_dir_path( __FILE__ ) . 'ai-chat.js' ), true );
        wp_enqueue_style( 'ai-chat-control', plugin_dir_url( __FILE__ ) . 'ai-chat.css', [], filemtime( plugin_dir_path( __FILE__ ) . 'ai-chat.css' ) );

        // Localize script with nonce and root URL
        wp_localize_script( 'ai-chat-control', 'dt_magic_link_data', [
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'root' => esc_url_raw( rest_url() ),
            'parts' => $this->parts,
            'site_url' => trailingslashit( site_url() ),
            'translations' => [
                'placeholder' => __( 'Type a command like \'I met with John and we talked about his baptism\'...', 'disciple-tools-ai' ),
                'send' => __( 'Send', 'disciple-tools-ai' ),
                'welcome' => __( 'Welcome to AI Chat Control. You can type commands like "I met with John and we talked about his baptism" to update contact records and add meeting notes.', 'disciple-tools-ai' )
            ]
        ] );
    }


    public function dt_magic_url_base_allowed_js( $allowed_js ){
        // @todo add or remove js files with this filter
        $allowed_js[] = 'ai-chat-control';
        $allowed_js[] = 'jquery';
        return $allowed_js;
    }

    public function dt_magic_url_base_allowed_css( $allowed_css ){
        // @todo add or remove js files with this filter
        $allowed_css[] = 'ai-chat-control';
        $allowed_css[] = 'material-font-icons-css';
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
    public function dt_settings_apps_list( $apps_list ){
        $apps_list[$this->meta_key] = [
            'key' => $this->meta_key,
            'url_base' => $this->root . '/' . $this->type,
            'label' => $this->page_title,
            'description' => $this->page_description,
            'settings_display' => true
        ];

        return $apps_list;
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
                <div class="grid-x chat-input-container">
                    <div class="cell">
                        <div class="input-group">
                            <input type="text" id="text-input" class="input-group-field"
                                   placeholder="Type a command like 'I met with John and we talked about his baptism'...">
                            <div class="input-group-button">
                                <button id="voice-btn" class="button secondary" type="button"><i
                                        class="mdi mdi-microphone"></i></button>
                                <button id="submit-btn" class="button" type="submit"><i class="mdi mdi-send"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
        <?php
    }

    /**
     * Register REST Endpoints
     * @link https://github.com/DiscipleTools/disciple-tools-theme/wiki/Site-to-Site-Link for outside of wordpress authentication
     */
    public function add_endpoints(){
        register_rest_route(
            $this->namespace, '/' . $this->type, [
                [
                    'methods' => 'GET',
                    'callback' => [ $this, 'endpoint_get' ],
                    'permission_callback' => function ( WP_REST_Request $request ){
                        $magic = new DT_Magic_URL( $this->root );

                        return $magic->verify_rest_endpoint_permissions_on_post( $request );
                    },
                ],
            ]
        );
        register_rest_route(
            $this->namespace, '/' . $this->type, [
                [
                    'methods' => 'POST',
                    'callback' => [ $this, 'update_record' ],
                    'permission_callback' => function ( WP_REST_Request $request ){
                        $magic = new DT_Magic_URL( $this->root );

                        return $magic->verify_rest_endpoint_permissions_on_post( $request );
                    },
                ],
            ]
        );
        register_rest_route(
            $this->namespace, '/go', [
                'methods' => 'POST',
                'callback' => [ $this, 'process_chat_command' ],
                'permission_callback' => function ( WP_REST_Request $request ){
                    $magic = new DT_Magic_URL( $this->root );

                    return $magic->verify_rest_endpoint_permissions_on_post( $request );
                },
            ]
        );
        register_rest_route(
            $this->namespace, '/transcribe', [
                'methods' => 'POST',
                'callback' => [ $this, 'process_audio_transcription' ],
                'permission_callback' => function ( WP_REST_Request $request ){
                    $magic = new DT_Magic_URL( $this->root );
                    $params = $request->get_params();
                    $params['parts'] = json_decode( $params['parts'], true );
                    if ( $params['parts']['root'] !== $this->root || $params['parts']['type'] !== $this->type || empty( $params['parts']['meta_key'] ) ){
                        return false;
                    }
                    $parts = $magic->parse_wp_rest_url_parts( $params );
                    if ( $parts['meta_key'] !== $params['parts']['meta_key'] ){
                        return false;
                    }
                    return true;
                },
            ]
        );
    }

    /**
     * Process audio transcription
     */
    public function process_audio_transcription( WP_REST_Request $request ){
        if ( !isset( $_FILES['audio'] ) ){ //phpcs:ignore
            return new WP_Error( 'missing_audio', 'No audio file received', [ 'status' => 400 ] );
        }

        $llm_endpoint_root = get_option( 'DT_AI_llm_endpoint' );
        $llm_api_key = get_option( 'DT_AI_llm_api_key' );
        $llm_model = get_option( 'DT_AI_llm_model' );
        $llm_endpoint = $llm_endpoint_root . '/audio/transcriptions';


        $audio_file = $_FILES['audio']; //phpcs:ignore

        $curl_file = new CURLFile(
            $audio_file['tmp_name'],
            $audio_file['type'],
            $audio_file['name']
        );

        $curl = curl_init();
        curl_setopt_array( $curl, [
            CURLOPT_URL => $llm_endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => [
                'file' => $curl_file,
                'model' => 'base',
            ],
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $llm_api_key,
            ],
        ] );

        $response = curl_exec( $curl );
        $err = curl_error( $curl );
        $httpcode = curl_getinfo( $curl, CURLINFO_HTTP_CODE );
        curl_close( $curl );

        if ( $err ){
            return new WP_Error( 'curl_error', 'cURL Error: ' . $err, [ 'status' => 500 ] );
        } else {
            if ( $httpcode >= 400 ){
                return new WP_Error( 'api_error', 'API Error: ' . $response, [ 'status' => $httpcode ] );
            }

            $transcription_response = json_decode( $response, true );
            if ( !isset( $transcription_response['text'] ) ){
                return new WP_Error( 'invalid_response', 'Invalid response from transcription API', [ 'status' => 500 ] );
            }

            return [
                'success' => true,
                'text' => $transcription_response['text']
            ];
        }
    }

    /**
     * Process chat commands to update contacts
     */
    public function process_chat_command( WP_REST_Request $request ){
        $command = $request->get_param( 'command' );
        if ( empty( $command ) ){
            return new WP_Error( 'missing_command', 'Command is required', [ 'status' => 400 ] );
        }

        // Get AI interpretation of the command
        $llm_endpoint_root = get_option( 'DT_AI_llm_endpoint' );
        $llm_api_key = get_option( 'DT_AI_llm_api_key' );
        $llm_model = get_option( 'DT_AI_llm_model' );
        $llm_endpoint = $llm_endpoint_root . '/chat/completions';


        $fields_settings = DT_Posts::get_post_field_settings( 'contacts' );

        // Create a structured field settings description for the LLM
        $fields_description = [];
        foreach ( $fields_settings as $field_key => $field_settings ){
            if ( isset( $field_settings['name'] ) && $field_key !== 'title' ){
                $field_type = isset( $field_settings['type'] ) ? $field_settings['type'] : 'text';
                $description = [
                    'key' => $field_key,
                    'name' => $field_settings['name'],
                    'type' => $field_type,
                ];

                // Add options for dropdown fields
                if ( $field_type === 'key_select' && isset( $field_settings['default'] ) && is_array( $field_settings['default'] ) ){
                    $options = [];
                    foreach ( $field_settings['default'] as $option_key => $option_value ){
                        if ( is_array( $option_value ) && isset( $option_value['label'] ) ){
                            $options[$option_key] = $option_value['label'];
                        } else {
                            $options[$option_key] = $option_value;
                        }
                    }
                    $description['options'] = $options;
                }

                // Add options for multi-select fields
                if ( $field_type === 'multi_select' && isset( $field_settings['default'] ) && is_array( $field_settings['default'] ) ){
                    $options = [];
                    foreach ( $field_settings['default'] as $option_key => $option_value ){
                        if ( is_array( $option_value ) && isset( $option_value['label'] ) ){
                            $options[$option_key] = $option_value['label'];
                        } else {
                            $options[$option_key] = $option_value;
                        }
                    }
                    $description['options'] = $options;
                }

                $fields_description[] = $description;
            }
        }

        $fields_json = json_encode( $fields_description, JSON_PRETTY_PRINT );

        // First part: Extract basic meeting information
        $initial_prompt = "Analyze this statement about a contact: '$command'.
                  Return a JSON object with:
                  {
                    \"contact_name\": \"[full name]\",
                    \"action\": \"met\", \"update\", or \"none\",
                    \"message\": \"[conversation content]\"
                  }
                  If the statement indicates a meeting (e.g., 'I met with X', 'had a meeting with X', 'visited X', etc), set action to 'met'.
                  If the statement indicates updating contact information, set action to 'update'. Examples of updates include:
                  - 'X's email is Y'
                  - 'Update X's phone to Y'
                  - 'X's phone number is Y'
                  - 'X has a new email: Y'
                  - 'Change X's Facebook to Y'
                  - 'X baptized Y'
                  - Any statement that assigns or changes a contact's information
                  Otherwise set action to 'none'.
                  Extract the full name of the person referenced in the command.
                  For meetings, extract any content about what was discussed into the message field.
                  For updates, include the information being updated in the message field.";

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
                        'content' => $initial_prompt,
                    ],
                ],
                'max_completion_tokens' => 500,
                'temperature' => 0.3,
                'top_p' => 1,
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $response ) ){
            return new WP_Error( 'api_error', 'Failed to interpret command', [ 'status' => 500 ] );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $ai_response = $body['choices'][0]['message']['content'];

        // Parse the JSON response from the AI
        $initial_data = json_decode( $ai_response, true );
        if ( !$initial_data || !isset( $initial_data['contact_name'] ) || !isset( $initial_data['action'] ) ){
            return new WP_Error( 'parse_error', 'Failed to parse AI response', [ 'status' => 500 ] );
        }

        $contact_name = trim( $initial_data['contact_name'] );
        $action = $initial_data['action'];
        $message = isset( $initial_data['message'] ) ? trim( $initial_data['message'] ) : '';

        // Check if we have a contact name
        if ( empty( $contact_name ) ){
            return [
                'success' => false,
                'message' => 'No contact name detected',
            ];
        }

        // Check if we're processing a selection from multiple contacts
        $contact_selection = $request->get_param( 'contact_selection' );

        if ( !empty( $contact_selection ) ){
            // The user has selected a specific contact
            $contact_id = intval( $contact_selection );
            $contact = DT_Posts::get_post( 'contacts', $contact_id );

            if ( is_wp_error( $contact ) ){
                return [
                    'success' => false,
                    'message' => 'Failed to retrieve the selected contact'
                ];
            }
        } else {
            // Search for contacts by name regardless of action
            $search_results = DT_Posts::list_posts( 'contacts', [
                'name' => $contact_name
            ], false );

            if ( is_wp_error( $search_results ) ){
                return new WP_Error( 'search_error', 'Failed to search contacts', [ 'status' => 500 ] );
            }

            if ( empty( $search_results['posts'] ) ){
                return [
                    'success' => false,
                    'message' => "Contact '$contact_name' not found"
                ];
            }

            // If multiple contacts found with the same name, ask user to choose
            if ( count( $search_results['posts'] ) > 1 ){
                $contacts_list = [];
                foreach ( $search_results['posts'] as $contact_item ){
                    // Prepare contact details to help user identify the right contact
                    $details = '';

                    // Add phone if available
                    if ( isset( $contact_item['contact_phone'] ) &&
                        isset( $contact_item['contact_phone']['values'] ) &&
                        !empty( $contact_item['contact_phone']['values'] ) ){
                        $phone_values = array_column( $contact_item['contact_phone']['values'], 'value' );
                        $details .= implode( ', ', $phone_values );
                    }

                    // Add email if available and no phone was added
                    if ( empty( $details ) &&
                        isset( $contact_item['contact_email'] ) &&
                        isset( $contact_item['contact_email']['values'] ) &&
                        !empty( $contact_item['contact_email']['values'] ) ){
                        $email_values = array_column( $contact_item['contact_email']['values'], 'value' );
                        $details .= implode( ', ', $email_values );
                    }

                    // Add location if available and no other details
                    if ( empty( $details ) && isset( $contact_item['location_grid_meta'] ) ){
                        $details = $contact_item['location_grid_meta'][0]['label'] ?? '';
                    }

                    // Add a label if we have details
                    if ( !empty( $details ) ){
                        $details = '(' . $details . ')';
                    }

                    $contacts_list[] = [
                        'id' => $contact_item['ID'], // Use uppercase ID for consistency
                        'name' => $contact_item['name'],
                        'details' => $details
                    ];
                }

                return [
                    'success' => true,
                    'ambiguous' => true,
                    'message' => "Multiple contacts found with the name '$contact_name'. Please select one:",
                    'contacts' => $contacts_list,
                    'original_command' => $command
                ];
            }

            // Get the first matching contact
            $contact = $search_results['posts'][0];
        }

        // If action is "log", just add a comment
        if ( $action === 'none' ){
            // Prepare comment content from the AI-extracted message or fall back to the original command
            $comment_content = !empty( $message ) ? $message : $command;
            $comment_content = 'Note: ' . $comment_content;

            // Add the comment about the meeting
            $comment_added = DT_Posts::add_post_comment( 'contacts', $contact['ID'], $comment_content, 'comment' );

            if ( is_wp_error( $comment_added ) ){
                return [
                    'success' => false,
                    'message' => 'Failed to add note to ' . $contact['title']
                ];
            }

            return [
                'success' => true,
                'message' => 'Added note to ' . $contact['title']
            ];
        }

        // Second part: Extract specific field updates based on the field settings
        $field_prompt = "
            Based on this command: '$command'

            I have a list of available fields for a contact in my system:
            $fields_json

            Analyze the command and determine which fields should be updated for the contact named '$contact_name'.
            Return a JSON object where each key is a field key that should be updated, and the value is the appropriate value for that field.
            For example:
            {
                \"faith_status\": \"growing\",
                \"baptism_date\": \"2023-12-25\",
                \"requires_update\": true,
                \"milestones\": [\"milestone_baptized\"],
                \"contact_email\": [\"test@example.com\"],
                \"contact_phone\": [\"+1234567890\"],
                \"seeker_path\": [\"met\"]
            }

            Rules:
            1. Only include fields that are clearly mentioned or implied in the command
            2. For key_select fields, use ONLY the exact key from the options provided, not the label.
                For example, if options are {'growing': 'Growing in Faith', 'seeking': 'Seeking'},
                and the command mentions 'growing in their faith', use 'growing' as the value, not 'Growing in Faith'.
            3. For multi_select fields, return an array of appropriate keys (not labels).
                For example, if milestones has an option 'milestone_baptized' for 'was Baptized', and the command mentions
                'was baptized yesterday', include [\"milestone_baptized\"] in the milestones field.
                For 'Baptized {name}', include [\"milestone_baptizing\"] in the milestones field.
            4. For communication channels (contact_email, contact_phone, etc.), ONLY extract them when EXPLICITLY mentioned.
                For example, if the command mentions 'John's email is john@example.com', include \"contact_email\": [\"john@example.com\"].
                Examples of explicit mentions include ONLY:
                'AI\\'s email is ai@example.com',
                'Update John\\'s phone to (123) 456-7890',
                'X\\'s phone number is Y',
                'Set Mary\\'s WhatsApp to +1234567890',
                'Jane can be reached on Telegram at @jane_doe'.
                NEVER suggest communication channels if not explicitly mentioned.
            5. When significant events like baptism are mentioned, make sure to update ALL related fields:
                - If baptism is mentioned, update baptism_date AND milestones (to include \"milestone_baptized\")
                - If someone led someone else to Christ, update milestones to include \"milestone_sharing\" and/or \"milestone_planting\"
            6. For date fields, use YYYY-MM-DD format and ACCURATELY calculate relative dates:
                - Today's date is " . gmdate( 'Y-m-d' ) /** phpcs:ignore **/ . "
                - CURRENT TIME REFERENCE: Today is " . gmdate( 'l, F j, Y' ) . "
                - 'Yesterday' MUST BE CONVERTED TO " . gmdate( 'Y-m-d', strtotime( '-1 day' ) ) . "
                - 'Two days ago' MUST BE CONVERTED TO " . gmdate( 'Y-m-d', strtotime( '-2 days' ) ) . "
                - 'Last week' MUST BE CONVERTED TO " . gmdate( 'Y-m-d', strtotime( '-7 days' ) ) . "
                - 'Last month' MUST BE CONVERTED TO " . gmdate( 'Y-m-d', strtotime( '-1 month' ) ) . "
                - 'Tomorrow' MUST BE CONVERTED TO " . gmdate( 'Y-m-d', strtotime( '+1 day' ) ) . "
                - 'Next Sunday' MUST BE CONVERTED TO " . gmdate( 'Y-m-d', strtotime( 'next Sunday' ) ) . "
                - 'Next month' MUST BE CONVERTED TO " . gmdate( 'Y-m-d', strtotime( '+1 month' ) ) . "
                - If a time isn't specified and the context implies a past event, use today's date
                - For future dates (like scheduled baptism), convert accurately to YYYY-MM-DD
                - For past events mentioned with no specific date, use today's date (" . gmdate( 'Y-m-d' ) . ")
                - IMPORTANT: Always provide properly converted absolute YYYY-MM-DD dates
                - Do NOT use any future dates for events that would typically be in the past (like past baptisms)
                - Events like baptism mentioned without dates should use today's date unless context suggests another date
            7. For boolean fields, use true or false
            8. For connection fields or complex fields, don't include them
            9. Don't create any new fields that aren't in the list
            10. If no fields should be updated, return an empty object {}
            11. Don't include the example fields in the response unless they are explicitly mentioned in the command.
            12. Check key_select and multi_select fields for options that might be mentioned in the command.
        ";


        $field_response = wp_remote_post( $llm_endpoint, [
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
                        'content' => $field_prompt,
                    ],
                ],
                'max_completion_tokens' => 1000,
                'temperature' => 0.3,
                'top_p' => 1,
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $field_response ) ){
            return new WP_Error( 'api_error', 'Failed to analyze field updates', [ 'status' => 500 ] );
        }

        $field_body = json_decode( wp_remote_retrieve_body( $field_response ), true );

        // Check for errors in the response
        if ( isset( $field_body['error'] ) || !isset( $field_body['choices'] ) ||
            !isset( $field_body['choices'][0] ) || !isset( $field_body['choices'][0]['message'] ) ||
            !isset( $field_body['choices'][0]['message']['content'] ) ){

            // Log the error for debugging
            error_log( 'AI Field Parsing Error: ' . wp_json_encode( $field_body ) );

            // Since the AI failed, try to extract basic information directly
            return new WP_Error( 'ai_error', 'Failed to analyze field updates', [ 'status' => 500 ] );
        } else {
            // Extract the AI response if everything is OK
            $field_ai_response = $field_body['choices'][0]['message']['content'];

            // Parse the JSON response
            $field_updates = json_decode( $field_ai_response, true );
            if ( !is_array( $field_updates ) ){
                $field_updates = [];
            }
        }

        // Always set overall_status to active for a meeting
        $update_fields = [
            'overall_status' => 'active'
        ];

        // Format field updates according to their type
        foreach ( $field_updates as $field_key => $field_value ){
            if ( !isset( $fields_settings[$field_key] ) ){
                // Skip fields that don't exist
                continue;
            }

            $field_type = $fields_settings[$field_key]['type'] ?? 'text';

            switch ( $field_type ){
                case 'multi_select':
                    if ( is_array( $field_value ) ){
                        $update_fields[$field_key] = [
                            'values' => array_map( function ( $val ){
                                return [ 'value' => $val ];
                            }, $field_value )
                        ];
                    } else if ( is_string( $field_value ) ){
                        // Handle when AI returns a single value as string
                        $update_fields[$field_key] = [
                            'values' => [ [ 'value' => $field_value ] ]
                        ];
                    }
                    break;

                case 'key_select':
                    // Validate that the value is one of the valid options for this field
                    if ( isset( $fields_settings[$field_key]['default'] ) &&
                        is_array( $fields_settings[$field_key]['default'] ) ){

                        $valid_options = array_keys( $fields_settings[$field_key]['default'] );

                        // Check if the provided value is valid
                        if ( in_array( $field_value, $valid_options ) ){
                            $update_fields[$field_key] = $field_value;
                        } else {
                            // Try to match by label if the value isn't a valid key
                            $found = false;
                            foreach ( $fields_settings[$field_key]['default'] as $option_key => $option_value ){
                                $option_label = is_array( $option_value ) ? ( $option_value['label'] ?? '' ) : $option_value;

                                //$field value might be an array of values, so we need to check if any of the values match
                                if ( is_array( $field_value ) ){
                                    foreach ( $field_value as $value ){
                                        if ( strcasecmp( $option_label, $value ) === 0 ){
                                            $update_fields[$field_key] = $option_key;
                                            $found = true;
                                            break;
                                        }
                                    }
                                } else {
                                    // Case-insensitive comparison with label
                                    if ( strcasecmp( $option_label, $field_value ) === 0 ){
                                        $update_fields[$field_key] = $option_key;
                                        $found = true;
                                        break;
                                    }
                                }
                            }

                            // If still not found, check for partial matches in labels
                            if ( !$found ){
                                foreach ( $fields_settings[$field_key]['default'] as $option_key => $option_value ){
                                    $option_label = is_array( $option_value ) ? ( $option_value['label'] ?? '' ) : $option_value;

                                    // $field value might be an array of values, so we need to check if any of the values match
                                    if ( is_array( $field_value ) ){
                                        foreach ( $field_value as $value ){
                                            if ( stripos( $option_label, $value ) !== false ){
                                                $update_fields[$field_key] = $option_key;
                                                break;
                                            }
                                        }
                                    } else {
                                        // Check if field value is contained in the label or vice versa
                                        if ( stripos( $option_label, $field_value ) !== false ||
                                            stripos( $field_value, $option_label ) !== false ){
                                            $update_fields[$field_key] = $option_key;
                                            break;
                                        }
                                    }
                                }
                            }
                        }
                    } else {
                        // No validation possible, just use the value
                        $update_fields[$field_key] = $field_value;
                    }
                    break;

                case 'text':
                    $update_fields[$field_key] = $field_value;
                    break;

                case 'date':
                    // Simply use the date as provided by the LLM
                    if ( !empty( $field_value ) ){
                        $timestamp = strtotime( $field_value );
                        if ( $timestamp ){
                            $update_fields[$field_key] = gmdate( 'Y-m-d', $timestamp );
                        }
                    }
                    break;

                case 'boolean':
                    $update_fields[$field_key] = (bool) $field_value;
                    break;

                case 'number':
                    $update_fields[$field_key] = is_numeric( $field_value ) ? $field_value : 0;
                    break;

                // Handle communication channels
                case 'communication_channel':
                    if ( is_array( $field_value ) ){
                        $update_fields[$field_key] = [
                            'values' => array_map( function ( $val ){
                                return [ 'value' => $val ];
                            }, $field_value )
                        ];
                    } else if ( is_string( $field_value ) ){
                        // Handle when AI returns a single value as string
                        $update_fields[$field_key] = [
                            'values' => [ [ 'value' => $field_value ] ]
                        ];
                    }
                    break;

                // Skip connection fields and other complex types
                default:
                    break;
            }
        }


        // Update the contact with all fields
        $updated = DT_Posts::update_post( 'contacts', $contact['ID'], $update_fields );

        if ( is_wp_error( $updated ) ){
            return new WP_Error( 'update_error', 'Failed to update contact: ' . $updated->get_error_message(), [ 'status' => 500 ] );
        }

        // Add comment only for meeting actions
        if ( $action === 'met' ){
            // Prepare comment content
            $comment_content = 'Meeting recorded via chat command';
            if ( !empty( $message ) ){
                $comment_content = 'Meeting notes: ' . $message;
            }

            // Add the comment about the meeting
            $comment_added = DT_Posts::add_post_comment( 'contacts', $contact['ID'], $comment_content, 'comment' );

            if ( is_wp_error( $comment_added ) ){
                return [
                    'success' => true,
                    'message' => 'Updated ' . $contact['title'] . ' (but failed to add comment)'
                ];
            }
        }

        // Generate a readable message about what was updated
        $updated_fields = array_keys( $update_fields );
        $updated_fields_message = '';

        if ( count( $updated_fields ) > 1 ){ // We always have overall_status
            $with_updated = array_map(
                function ( $field ) use ( $fields_settings ){
                    return isset( $fields_settings[$field]['name'] ) ? strtolower( $fields_settings[$field]['name'] ) : $field;
                },
                array_filter( $updated_fields, function ( $field ){
                    return $field !== 'overall_status';
                } )
            );
            $updated_fields_message = ' with updated ' . implode( ', ', $with_updated );
        }

        return [
            'success' => true,
            'message' => 'Updated ' . $contact['title'] . $updated_fields_message .
                ( $action === 'met' && !empty( $message ) ? ' and added meeting notes' : '' ),
            'contact_data' => [
                'id' => $contact['ID'],
                'name' => $contact['title']
            ]
        ];
    }

    public function update_record( WP_REST_Request $request ){
        $params = $request->get_params();
        $params = dt_recursive_sanitize_array( $params );

        $post_id = $params['parts']['post_id']; //has been verified in verify_rest_endpoint_permissions_on_post()

        return true;
    }

    public function endpoint_get( WP_REST_Request $request ){
        $params = $request->get_params();
        if ( !isset( $params['parts'], $params['action'] ) ){
            return new WP_Error( __METHOD__, 'Missing parameters', [ 'status' => 400 ] );
        }

        $data = [];

        // Nothing to return for now, as our main interface is through the disciple-tools-ai/v1/dt-ai-chat-command endpoint
        return $data;
    }
}

DT_AI_Chat::instance();
