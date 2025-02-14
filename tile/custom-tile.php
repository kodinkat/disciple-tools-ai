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
        if ( $post_type === 'contacts' || $post_type === 'starter_post_type' ){
            $tiles['disciple_tools_ai'] = [ 'label' => __( 'Disciple Tools AI', 'disciple-tools-ai' ) ];
            $tiles['a_beautiful_tile'] = [ 'label' => __( 'A Beautiful Tile', 'disciple-tools-ai' ) ];
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
        if ( ( $post_type === 'contacts' || $post_type === 'starter_post_type' ) && $section === 'disciple_tools_ai' ){
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

                        prepareDataForLLM( window.commentsSettings.comments.comments, window.commentsSettings.activity.activity, nonce );

                    });
                });

                function prepareDataForLLM(commentData, activityData, nonce) {

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

                    var prompt = "Summarize the following activities and comments:\n\n";
                    combinedData.forEach(function(item){
                        prompt += item.date + " - " + item.type + ": " + item.content + "\n";
                    });

                    fetch(`${wpApiShare.root}disciple-tools-ai/v1/dt-ai-summarize`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': nonce // Include the nonce in the headers
                        },
                        body: JSON.stringify({ prompt: prompt })
                    })
                    .then(response => response.json())
                    .then(data => {
                        let summaryContainer = document.querySelector('#dt-ai-summary')

                        document.querySelector('#dt-ai-summary-button').classList.remove('loading');

                        summaryContainer.innerText = data;
                        $('.grid').masonry('layout');
                    })
                    .catch(error => {
                        console.error('Error:', error);
                    });
                };
            </script>
            <!--
            @todo you can add HTML content to this section.
            -->
            <div class="cell small-12 medium-4">
                <!-- @todo remove this notes section-->
                <div class="dt-tile">
                    <div class="dt-tile-header">
                        <h3><?php esc_html_e( 'Summary', 'disciple-tools-ai' ) ?></h3>
                    </div>
                    <div class="dt-tile-content">
                        <button id="dt-ai-summary-button" class="button loader"><?php esc_html_e( 'Summarize This Contact', 'disciple-tools-ai' ) ?></button>
                        <p id="dt-ai-summary"></p>
                    </div>
            </div>

        <?php }
    }
}
Disciple_Tools_AI_Tile::instance();
