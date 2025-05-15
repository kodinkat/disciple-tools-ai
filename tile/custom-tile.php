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
        if ( get_option( 'DT_AI_list_filter_enabled', 1 ) == 0 ) {
            return;
        }
        ?>
        <style>
            /* ===== Search & Filter ===== */
            #ai-search-filter {
            }

            #ai-search-filter:not(:has(.filters)) {
            }

            #ai-search-filter:has(.filters.hidden) {
            }

            #ai-search-bar {
                width: 100%;
            }

            #ai-search-filter,
            #ai-search-bar {
                background: #fff;
            }

            #ai-search-bar {
                position: relative;
                display: flex;
                flex-wrap: wrap;
                z-index: 1;
            }

            #ai-search {
                margin: var(--search-margin-block);
                flex-grow: 1;
                min-width: 1rem;
                font-size: 1.25rem;
                padding-left: 10px;
                padding-right: 10px;
                height: var(--search-input-height);
            }

            #ai-search-bar button.ai-clear-button {
                position: absolute;
                inset-inline-end: 1.75rem;
                top: 0.4rem;
                border: 0;
                background-color: #FFFFFF;
                padding: 2px;
                height: var(--search-input-height);
            }

            #ai-search-bar button.ai-filter-button {
                position: absolute;
                inset-inline-end: 0.5rem;
                top: 0.4rem;
                border: 2px;
                background-color: #FFFFFF;
                padding: 2px;
                height: var(--search-input-height);
            }
            /* ===== Search & Filter ===== */
        </style>

        <div id="ai-search-filter">
            <div id="ai-search-bar">
                <input type="text" id="ai-search" placeholder="<?php esc_html_e( 'Describe list to show...', 'disciple-tools-ai' ); ?>" />
                <button id="ai-clear-button" style="display: none;" class="ai-clear-button mdi mdi-close" onclick="clear_ai_filter();"></button>
                <button class="ai-filter-button" onclick="create_ai_filter();">
                  <i id="ai_filter_icon" class="mdi mdi-star-four-points-outline"></i>
                  <span id="ai_filter_spinner" style="display: none; height: 16px; width: 16px" class="loading-spinner active"></span>
                </button>
            </div>
        </div>

        <script>
            jQuery(document).ready(function ($) {

                let settings = [<?php echo json_encode([
                    'post_type' => $post_type,
                    'settings' => DT_Posts::get_post_settings( $post_type, false ),
                    'root' => esc_url_raw( rest_url() ),
                    'nonce' => wp_create_nonce( 'wp_rest' ),
                    'translations' => [
                        'custom_filter' => __( 'Custom AI Filter', 'disciple-tools-ai' ),
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
                ]) ?>][0]

                /**
                 * Proceed with AI filter prompt setup.
                 */

                document.getElementById('ai-search').addEventListener('keyup', function(e) {
                    e.preventDefault();

                    if (e.key === 'Enter') { // Enter key pressed.
                        create_ai_filter();

                    } else { // Manage field clearing option.
                        show_ai_filter_clear_option();
                    }
                });

                window.show_ai_filter_clear_option = () => {
                    const text = document.getElementById('ai-search').value;
                    const clear_button = document.getElementById('ai-clear-button');

                    if (!text && clear_button.style.display === 'block') {
                        clear_button.setAttribute('style', 'display: none;');

                    } else if (text && clear_button.style.display === 'none') {
                        clear_button.setAttribute('style', 'display: block;');
                    }
                }

                window.clear_ai_filter = () => {
                    document.getElementById('ai-search').value = '';
                }

                window.create_ai_filter = () => {
                    const text = document.getElementById('ai-search').value;

                    if (!text) {
                        return;
                    }

                    const dt_ai_filter_prompt_spinner = $('#ai_filter_spinner');
                    const dt_ai_filter_prompt_button = $('#ai_filter_icon');

                    dt_ai_filter_prompt_button.fadeOut('fast', () => {
                        dt_ai_filter_prompt_spinner.fadeIn('slow', () => {

                            fetch(`${wpApiShare.root}disciple-tools-ai/v1/dt-ai-create-filter`, {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-WP-Nonce': settings.nonce // Include the nonce in the headers
                                },
                                body: JSON.stringify({
                                    prompt: text,
                                    post_type: settings.post_type
                                })
                            })
                            .then(response => response.json())
                            .then(response => {
                                console.log(response);

                                /**
                                 * Pause the flow accordingly, if multiple connection options are available.
                                 * If so, then display modal with connection options.
                                 */

                                if ((response?.status === 'multiple_options_detected') && (response?.multiple_options)) {
                                    window.show_multiple_options_modal(response.multiple_options);

                                } else if ((response?.status === 'success') && (response?.filter)) {

                                    create_custom_filter(response.filter);

                                    // Stop spinning....
                                    document.getElementById('ai_filter_spinner').style.display = 'none';
                                    document.getElementById('ai_filter_icon').style.display = 'inline-block';
                                }
                            })
                            .catch(error => {
                                console.error('Error:', error);

                                dt_ai_filter_prompt_spinner.fadeOut('fast', () => {
                                    dt_ai_filter_prompt_button.fadeIn('slow');
                                    if ( window.SHAREDFUNCTIONS?.empty_list ) {
                                        window.SHAREDFUNCTIONS.empty_list();
                                    }
                                });
                            });

                        });
                    });
                }

                window.show_multiple_options_modal = (multiple_options) => {
                    const modal = $('#modal-small');
                    if (modal) {

                        $(modal).find('#modal-small-title').html(`${window.lodash.escape(settings.translations.multiple_options.title)}`);

                        /**
                         * Location Options.
                         */

                        let locations_html = '';
                        if (multiple_options?.locations && multiple_options.locations.length > 0) {

                            locations_html += `
                                <h4>${window.lodash.escape(settings.translations.multiple_options.locations)}</h4>
                                <table class="widefat striped">
                                    <tbody class="ai-locations">
                              `;

                            multiple_options.locations.forEach((location) => {
                                if (location?.prompt && location?.options) {
                                    locations_html += `
                                        <tr>
                                          <td style="vertical-align: top;">
                                            ${window.lodash.escape(location.prompt)}
                                            <input class="prompt" type="hidden" value="${location.prompt}" />
                                          </td>
                                          <td>
                                            <select class="options">`;

                                    locations_html += `<option value="ignore">${window.lodash.escape(settings.translations.multiple_options.ignore_option)}</option>`;

                                    location.options.forEach((option) => {
                                        if (option?.id && option?.label) {
                                            locations_html += `<option value="${window.lodash.escape(option.id)}">${window.lodash.escape(option.label)}</option>`;
                                        }
                                    });

                                    locations_html += `</select>
                                      </td>
                                    </tr>
                                    `;
                                }
                            });

                            locations_html += `
                                </tbody>
                            </table>
                            `;
                        }

                        /**
                         * User Options.
                         */

                        let users_html = '';
                        if (multiple_options?.users && multiple_options.users.length > 0) {

                            users_html += `
                                <h4>${window.lodash.escape(settings.translations.multiple_options.users)}</h4>
                                <table class="widefat striped">
                                    <tbody class="ai-users">
                            `;

                            multiple_options.users.forEach((user) => {
                                if (user?.prompt && user?.options) {
                                    users_html += `
                                        <tr>
                                          <td style="vertical-align: top;">
                                            ${window.lodash.escape(user.prompt)}
                                            <input class="prompt" type="hidden" value="${user.prompt}" />
                                          </td>
                                          <td>
                                            <select class="options">`;

                                    users_html += `<option value="ignore">${window.lodash.escape(settings.translations.multiple_options.ignore_option)}</option>`;

                                    user.options.forEach((option) => {
                                        if (option?.id && option?.label) {
                                            users_html += `<option value="${window.lodash.escape(option.id)}">${window.lodash.escape(option.label)}</option>`;
                                        }
                                    });

                                    users_html += `</select>
                                        </td>
                                    </tr>
                                    `;
                                }
                            });

                            users_html += `
                                </tbody>
                            </table>
                            `;
                        }

                        /**
                         * Post Options.
                         */

                        let posts_html = '';
                        if (multiple_options?.posts && multiple_options.posts.length > 0) {

                            posts_html += `
                                <h4>${window.lodash.escape(settings.translations.multiple_options.posts)}</h4>
                                <table class="widefat striped">
                                    <tbody class="ai-posts">
                            `;

                            multiple_options.posts.forEach((post) => {
                                if (post?.prompt && post?.options) {
                                    posts_html += `
                                        <tr>
                                          <td style="vertical-align: top;">
                                            ${window.lodash.escape(post.prompt)}
                                            <input class="prompt" type="hidden" value="${post.prompt}" />
                                          </td>
                                          <td>
                                            <select class="options">`;

                                    posts_html += `<option value="ignore">${window.lodash.escape(settings.translations.multiple_options.ignore_option)}</option>`;

                                    post.options.forEach((option) => {
                                        if (option?.id && option?.label) {
                                            posts_html += `<option value="${window.lodash.escape(option.id)}">${window.lodash.escape(option.label)}</option>`;
                                        }
                                    });

                                    posts_html += `</select>
                                        </td>
                                    </tr>
                                    `;
                                }
                            });

                            posts_html += `
                                    </tbody>
                                </table>
                            `;
                        }

                        let html = `
                            <br>
                            ${locations_html}
                            <br>
                            ${users_html}
                            <br>
                            ${posts_html}
                            <br>
                            <button class="button" aria-label="submit" type="button" id="multiple_options_submit">
                                <span aria-hidden="true">${window.lodash.escape(settings.translations.multiple_options.submit_but)}</span>
                            </button>
                            <button class="button" data-close aria-label="submit" type="button">
                                <span aria-hidden="true">${window.lodash.escape(settings.translations.multiple_options.close_but)}</span>
                            </button>
                        `;

                        $(modal).find('#modal-small-content').html(html);

                        $(modal).foundation('open');
                        $(modal).css('top', '150px');

                        $(document).on('closed.zf.reveal', '[data-reveal]', function (evt) {
                            document.getElementById('ai_filter_spinner').style.display = 'none';
                            document.getElementById('ai_filter_icon').style.display = 'inline-block';

                            // Remove click event listener, to avoid a build-up and duplication of modal selection submissions.
                            $(document).off('click', '#multiple_options_submit');
                        });

                        $(document).on('click', '#multiple_options_submit', function (evt) {
                            window.handle_multiple_options_submit(modal);
                        });
                    }
                }

                window.handle_multiple_options_submit = (modal) => {

                    // Re-submit query, with specified selections.
                    const payload = {
                        "prompt": document.getElementById('ai-search').value,
                        "post_type": settings.post_type,
                        "selections": window.package_multiple_options_selections()
                    };

                    // Close modal and proceed with re-submission.
                    $(modal).foundation('close');

                    // Ensure spinner is still spinning.
                    document.getElementById('ai_filter_spinner').style.display = 'inline-block';
                    document.getElementById('ai_filter_icon').style.display = 'none';

                    // Submit selections.
                    jQuery.ajax({
                        type: "POST",
                        data: JSON.stringify(payload),
                        contentType: "application/json; charset=utf-8",
                        dataType: "json",
                        url: `${wpApiShare.root}disciple-tools-ai/v1/dt-ai-create-filter`,
                        beforeSend: function(xhr) {
                            xhr.setRequestHeader('X-WP-Nonce', settings.nonce);
                        }
                    })
                    .done(function (data) {
                        console.log(data);

                        // If successful, load points.
                        if ((data?.status === 'success') && (data?.filter)) {
                            create_custom_filter(data.filter);
                        }

                        // Stop spinning....
                        document.getElementById('ai_filter_spinner').style.display = 'none';
                        document.getElementById('ai_filter_icon').style.display = 'inline-block';

                    })
                    .fail(function (err) {
                        console.log('error')
                        console.log(err)

                        document.getElementById('ai_filter_spinner').style.display = 'none';
                        document.getElementById('ai_filter_icon').style.display = 'inline-block';
                    });
                }

                window.package_multiple_options_selections = () => {
                    let selections = {};

                    /**
                     * Locations.
                     */

                    const locations = $('tbody.ai-locations');
                    if (locations) {
                        selections['locations'] = [];
                        $(locations).find('tr').each((idx, tr) => {
                            const prompt = $(tr).find('input.prompt').val();
                            const selected_opt_id = $(tr).find('select.options option:selected').val();
                            const selected_opt_label = $(tr).find('select.options option:selected').text();

                            selections['locations'].push({
                                'prompt': prompt,
                                'id': selected_opt_id,
                                'label': selected_opt_label
                            });
                        });
                    }

                    /**
                     * Users.
                     */

                    const users = $('tbody.ai-users');
                    if (users) {
                        selections['users'] = [];
                        $(users).find('tr').each((idx, tr) => {
                            const prompt = $(tr).find('input.prompt').val();
                            const selected_opt_id = $(tr).find('select.options option:selected').val();
                            const selected_opt_label = $(tr).find('select.options option:selected').text();

                            selections['users'].push({
                                'prompt': prompt,
                                'id': selected_opt_id,
                                'label': selected_opt_label
                            });
                         });
                    }

                    /**
                     * Posts.
                     */

                    const posts = $('tbody.ai-posts');
                    if (posts) {
                        selections['posts'] = [];
                        $(posts).find('tr').each((idx, tr) => {
                            const prompt = $(tr).find('input.prompt').val();
                            const selected_opt_id = $(tr).find('select.options option:selected').val();
                            const selected_opt_label = $(tr).find('select.options option:selected').text();

                            selections['posts'].push({
                                'prompt': prompt,
                                'id': selected_opt_id,
                                'label': selected_opt_label
                            });
                        });
                    }

                    return selections;
                }

                window.create_custom_filter = (filter) => {

                    /**
                     * Assuming valid fields have been generated and required shared
                     * functions are present, proceed with custom filter creation and
                     * list refresh.
                     */

                    if (filter?.fields && window.SHAREDFUNCTIONS?.add_custom_filter && window.SHAREDFUNCTIONS?.reset_split_by_filters) {

                        /**
                         * First, attempt to identify labels to be used based on returned
                         * fields shape; otherwise, labels shall remain blank.
                         */

                        let labels = [];
                        if (Array.isArray(filter.fields) && window.SHAREDFUNCTIONS?.create_name_value_label) {
                            filter.fields.forEach((field) => {
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
                            if ( Array.isArray( filter.fields ) ) {
                                filter.fields.push( status );
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
                                fields: filter.fields
                            },
                            labels
                        );
                    }
                }
            });
        </script>
        <?php
    }
}
Disciple_Tools_AI_Tile::instance();
