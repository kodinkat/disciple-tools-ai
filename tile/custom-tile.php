<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

class Disciple_Tools_AI_Tile
{
    private static $_instance = null;
    public static function instance(){
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct(){
        add_filter( 'dt_details_additional_tiles', [ $this, 'dt_details_additional_tiles' ], 10, 2 );
        add_filter( 'dt_custom_fields_settings', [ $this, 'dt_custom_fields' ], 1, 2 );
        add_action( 'dt_details_additional_section', [ $this, 'dt_add_section' ], 30, 2 );
        add_action( 'dt_ai_action_bar_buttons', [ $this, 'dt_ai_action_bar_buttons' ], 10, 1 );
    }

    public function dt_site_scripts(): void {
        dt_theme_enqueue_script( 'tribute-js', 'dt-core/dependencies/tributejs/dist/tribute.min.js', array(), true );
        dt_theme_enqueue_style( 'tribute-css', 'dt-core/dependencies/tributejs/dist/tribute.css', array() );
    }

    /**
     * This function registers a new tile to a specific post type
     *
     * @todo Set the post-type to the target post-type (i.e. contacts, groups, trainings, etc.)
     * @todo Change the tile key and tile label
     *
     * @param $tiles
     * @param string $post_type
     * @return mixed
     */
    public function dt_details_additional_tiles( $tiles, $post_type = '' ) {
        if ( in_array( $post_type, [ 'contacts', 'ai' ] ) ){
            $tiles['disciple_tools_ai'] = [ 'label' => __( 'Disciple Tools AI', 'disciple-tools-ai' ) ];
        }
        return $tiles;
    }

    /**
     * @param array $fields
     * @param string $post_type
     * @return array
     */
    public function dt_custom_fields( array $fields, string $post_type = '' ) {
        return $fields;
    }

    public function dt_add_section( $section, $post_type ) {
        /**
         * @todo set the post type and the section key that you created in the dt_details_additional_tiles() function
         */
        if ( in_array( $post_type, [ 'contacts', 'ai' ] ) && $section === 'disciple_tools_ai' ){
            /**
             * These are two sets of key data:
             * $this_post is the details for this specific post
             * $post_type_fields is the list of the default fields for the post type
             *
             * You can pull any query data into this section and display it.
             */
            $this_post = DT_Posts::get_post( $post_type, get_the_ID() );
            $post_type_fields = DT_Posts::get_post_field_settings( $post_type );
            $nonce = wp_create_nonce( 'wp_rest' ); // Generate the nonce

            ?>
            <script>
                document.addEventListener('DOMContentLoaded', function(){
                    document.getElementById('dt-ai-summary-button').addEventListener('click', function(){
                        var endpoint = '<?php echo esc_url( get_option( 'disciple_tools_ai_llm_endpoint' ) ); ?>';
                        var api_key = '<?php echo esc_js( get_option( 'disciple_tools_ai_llm_api_key' ) ); ?>';
                        var nonce = '<?php echo esc_js( $nonce ); ?>'; // Pass the nonce to JavaScript

                        this.classList.add('loading');

                        const post_type = window.commentsSettings?.post?.post_type;
                        const post_id = window.commentsSettings?.post?.ID;

                        prepareDataForLLM( post_type, post_id, window.commentsSettings.comments.comments, window.commentsSettings.activity.activity, nonce );

                    });
                });

                function prepareDataForLLM(post_type, post_id, commentData, activityData, nonce) {

                    var combinedData = [];

                    commentData.forEach(function(comment){
                        combinedData.push({
                            date: comment.comment_date,
                            content: comment.comment_content,
                            type: 'comment'
                        });
                    });

                    activityData.forEach(function(activity){
                        combinedData.push({
                            date: window.moment.unix(activity.hist_time),
                            content: activity.object_note,
                            type: 'activity'
                        });
                    });

                    combinedData.sort(function(a, b){
                        return new Date(a.date) - new Date(b.date);
                    });

                    let prompt = "If comments count is less than 5, then summarize in only 20 words; otherwise, summarize in only 100 words; the following activities and comments. Prioritize comments over activities:\n\n";
                    combinedData.forEach(function(item){
                        prompt += item.date + " - " + item.type + ": " + item.content + "\n";
                    });

                    fetch(`${wpApiShare.root}disciple-tools-ai/v1/dt-ai-summarize`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': nonce // Include the nonce in the headers
                        },
                        body: JSON.stringify({
                            prompt: prompt,
                            post_type,
                            post_id
                        })
                    })
                    .then(response => response.json())
                    .then(data => {

                        document.querySelector('#dt-ai-summary-button').classList.remove('loading');

                        // Determine action to take based on endpoint response.
                        if ( data?.updated ) {
                            window.location.reload();

                        } else {
                            document.querySelector('#dt-ai-summary').innerText = data?.summary;
                            $('.grid').masonry('layout');
                        }

                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
                }
            </script>
            <!--
            @todo you can add HTML content to this section.
            -->
            <div class="cell small-12 medium-4">
                <!-- @todo remove this notes section-->
                <div class="dt-tile">
                    <div class="dt-tile-content">
                        <button id="dt-ai-summary-button" class="button loader" style="min-width: 100%;"><?php esc_html_e( 'Summarize This Contact', 'disciple-tools-ai' ) ?></button>
                        <p id="dt-ai-summary"></p>
                    </div>
            </div>

        <?php }
    }

    public function dt_ai_action_bar_buttons( $post_type ): void {
        $this->dt_site_scripts();
        ?>
        <input id="dt_ai_filter_prompt" name="dt_ai_filter_prompt" placeholder="<?php esc_html_e( 'Describe the list to show...', 'disciple-tools-ai' ); ?>" />
        <a id="dt_ai_filter_prompt_button" class="button">
            <i class="mdi mdi-star-four-points-outline" style="font-size: 16px;"></i>
        </a>
        <span id="dt_ai_filter_prompt_spinner" class="loading-spinner active" style="display: none;"></span>
        <script>
            jQuery(document).ready(function ($) {

                let settings = [<?php echo json_encode([
                    'post_type' => $post_type,
                    'settings' => DT_Posts::get_post_settings( $post_type, false ),
                    'root' => esc_url_raw( rest_url() ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                    'translations' => [
                        'custom_filter' => __( 'Custom AI Filter', 'disciple-tools-ai' )
                    ]
                ]) ?>][0]

                /**
                 * Hide existing theme search fields.
                 */

                $('.search-wrapper').hide();
                $('#search').hide();

                /**
                 * Proceed with AI filter prompt setup.
                 */

                const tribute = new Tribute({
                    triggerKeys: ['@'],
                    values: (text, callback) => {
                        
                        window.API.search_users(text)
                        .then((userResponse) => {

                            let data = [];

                            // Search location grids by name.
                            window.API.search_location_grid_by_name(text)
                                .then((locationResponse) => {

                                    // Capture users.
                                    userResponse.forEach((user) => {
                                        data.push({
                                            id: user.ID,
                                            name: user.name || "", // Ensure name is always a string
                                            type: settings.post_type,
                                            avatar: user.avatar
                                        });
                                    });

                                    // Capture locations.
                                    locationResponse.location_grid.forEach((location) => {
                                        data.push({
                                            id: location.ID,
                                            name: location.name || "", // Ensure name is always a string
                                            type: settings.post_type,
                                            avatar: null
                                        });
                                    });

                                    // Filter out any items with undefined, null or non-string names
                                    data = data.filter(item => typeof item.name === 'string');

                                    // Sort data array entries by object name.
                                    data.sort((a, b) => {
                                        const aName = a.name.toUpperCase();
                                        const bName = b.name.toUpperCase();

                                        if (aName < bName) {
                                            return -1;

                                        } else if (aName > bName) {
                                            return 1;

                                        } else {
                                            return 0;
                                        }
                                    });

                                    callback(data);
                                })
                                .catch((err) => {
                                    console.error(err);
                                    callback([]); // Return empty array on error
                                });
                        })
                        .catch((err) => {
                            console.error(err);
                            callback([]); // Return empty array on error
                        });
                    },
                    menuItemTemplate: function (item) {
                        if (!item || !item.original) return '';
                        return `<div class="user-item">
                            ${item.original.avatar ? `<img src="${item.original.avatar}">` : ''}
                            <span class="name">${item.original.name || ''}</span>
                            </div>`;
                    },
                    selectTemplate: function (item) {
                        if (!item || !item.original || !item.original.name || !item.original.id) return '';
                        return `@[${item.original.name}](${item.original.id})`;
                    },
                    lookup: 'name', // This should match the property you want to search by
                    fillAttr: 'name', // This should be the property to display in the text field
                    noMatchTemplate: null,
                    searchOpts: {
                        pre: '<span>',
                        post: '</span>',
                        skip: false
                    }
                });

                // Attached tribute to the filter prompt input.
                const filter_prompt = document.getElementById('dt_ai_filter_prompt');
                tribute.attach(filter_prompt);

                // Listen for click event on the filter prompt button.
                const create_filter_spinner = $('#dt_ai_filter_prompt_spinner');
                const dt_ai_filter_prompt_button = document.getElementById('dt_ai_filter_prompt_button');
                dt_ai_filter_prompt_button.addEventListener('click', (e) => {
                    e.preventDefault();

                    const data = filter_prompt.value;
                    if (data) {
                        console.log(data);

                        $(dt_ai_filter_prompt_button).fadeOut('fast', () => {
                            create_filter_spinner.fadeIn('slow', () => {

                                fetch(`${wpApiShare.root}disciple-tools-ai/v1/dt-ai-create-filter`, {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-WP-Nonce': settings.nonce // Include the nonce in the headers
                                    },
                                    body: JSON.stringify({
                                        prompt: data,
                                        post_type: settings.post_type
                                    })
                                })
                                .then(response => response.json())
                                .then(response => {
                                    console.log(response);

                                    /**
                                     * Swap spinning widget back to execution button.
                                     */

                                    create_filter_spinner.fadeOut('fast', () => {
                                        $(dt_ai_filter_prompt_button).fadeIn('slow');
                                    });

                                    /**
                                     * Assuming valid fields have been generated and required shared
                                     * functions are present, proceed with custom filter creation and
                                     * list refresh.
                                     */

                                    if (response?.fields && window.SHAREDFUNCTIONS?.add_custom_filter && window.SHAREDFUNCTIONS?.reset_split_by_filters) {

                                        /**
                                         * First, attempt to identify labels to be used based on returned
                                         * fields shape; otherwise, labels shall remain blank.
                                         */

                                        let labels = [];
                                        if (Array.isArray(response.fields) && window.SHAREDFUNCTIONS?.create_name_value_label) {
                                            response.fields.forEach((field) => {
                                                for (const [key, filters] of Object.entries(field)) {

                                                    if (key && Array.isArray(filters)) {
                                                        filters.forEach((filter) => {

                                                            const {newLabel} = window.SHAREDFUNCTIONS?.create_name_value_label(key, filter, isNaN(filter) ? filter : '', window?.list_settings);
                                                            if (newLabel) {
                                                                labels.push(newLabel);
                                                            }

                                                        });
                                                    }
                                                }
                                            });
                                        }

                                        /**
                                         * Determine status field to be appended to filter fields.
                                         */

                                        if ( window.SHAREDFUNCTIONS.get_json_from_local_storage && settings.settings?.status_field?.status_key && settings.settings?.status_field?.archived_key ) {

                                            // Determine if archived records are to be shown.
                                            const show_archived_records = window.SHAREDFUNCTIONS.get_json_from_local_storage(
                                                'list_archived_switch_status',
                                                false,
                                                settings.post_type
                                            );

                                            // Package archived records status flag.
                                            let status = {};
                                            status[settings.settings.status_field.status_key] = [ `${show_archived_records ? '' : '-'}${settings.settings.status_field.archived_key}` ];

                                            // Finally append to filter fields.
                                            if ( Array.isArray( response.fields ) ) {
                                                response.fields.push( status );
                                            }
                                        }

                                        /**
                                         * Proceed with Custom AI Filter creation and list refresh.
                                         */

                                        window.SHAREDFUNCTIONS.reset_split_by_filters();
                                        window.SHAREDFUNCTIONS.add_custom_filter(
                                            settings.translations['custom_filter'],
                                            'custom-filter',
                                            {
                                                fields: response.fields
                                            },
                                            labels
                                        );
                                    }
                                })
                                .catch(error => {
                                    console.error('Error:', error);

                                    create_filter_spinner.fadeOut('fast', () => {
                                        $(dt_ai_filter_prompt_button).fadeIn('slow');
                                        if ( window.SHAREDFUNCTIONS?.empty_list ) {
                                            window.SHAREDFUNCTIONS.empty_list();
                                        }
                                    });
                                });

                            });
                        });
                    }
                });
            });
        </script>
        <?php
    }
}
Disciple_Tools_AI_Tile::instance();
