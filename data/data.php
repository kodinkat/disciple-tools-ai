<?php

if ( !defined( 'ABSPATH' ) ){
    exit;
} // Exit if accessed directly

/**
 * Class Disciple_Tools_AI_Data
 *
 * @since  1.11.0
 */
class Disciple_Tools_AI_Data {

    /**
     * The single instance of Disciple_Tools_AI_Data.
     *
     * @var    object
     * @access private
     * @since  1.11.0
     */
    private static $_instance = null;

    /**
     * Main Disciple_Tools_AI_Data Instance
     *
     * Ensures only one instance of Disciple_Tools_AI_Data is loaded or can be loaded.
     *
     * @return Disciple_Tools_AI_Data instance
     * @since  1.11.0
     */
    public static function instance() {
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * Disciple_Tools_AI_Data constructor.
     */
    public function __construct() {
        add_action( 'wp_loaded', [ $this, 'build_filter_query_specs' ], 20 );
    }

    private function get_data( $path, $escape = true ): array {
        $contents = [];

        $handle = fopen( $path, 'r' );
        if ( $handle !== false ) {
            while ( !feof( $handle ) ) {
                $line = fgets( $handle );
                if ( $line && !empty( trim( $line ) ) ) {
                    $contents[] = ( $escape ) ? addslashes( trim( $line ) ) : $line;
                }
            }

            fclose( $handle );
        }

        return $contents;
    }

    private function reshape_examples( $examples, $add_header = true, $delimiter = '==//==' ): array {
        $reshaped = [];

        if ( $add_header ) {
            $reshaped[] = 'Examples';
        }

        foreach ( $examples as $example ) {
            $exploded_example = explode( $delimiter, $example );

            $query = trim( $exploded_example[0] );
            $output = trim( $exploded_example[1] );

            $reshaped[] = 'User Query:\n'. $query .'\nOutput:\n'. $output;
        }

        return $reshaped;
    }

    private function generate_record_post_type_specs( $folder ): array {
        $contents = [];
        $ignored_post_types = [];

        /**
         * First, capture some initial verbiage, detailing what is about
         * to take place here...! ;-)
         */

        $contents[] = 'Record Post Types & Field Type Mappings:';
        $contents[] = 'Below is a detailed description of available record post types and their associated fields, labels and field types.';

        /**
         * Next, capture the various field types.
         */

        $field_types = DT_Posts::get_field_types();

        $contents[] = '--- field-type mapping details ---';
        foreach ( $field_types as $field_type_key => $field_type ) {
            $contents[] = '- field-type: ' . $field_type_key . ' has the label: ' . $field_type['label'] . ' and the description: ' . $field_type['description'];
        }

        /**
         * Next, proceed with building/capturing the post type details.
         */

        foreach ( DT_Posts::get_post_types() ?? [] as $post_type ) {
            if ( !in_array( $post_type, $ignored_post_types ) ) {
                $post_type_settings = DT_Posts::get_post_settings( $post_type, false );

                /**
                 * Create corresponding file, if it does not already exist.
                 */

                $path = $folder . $post_type . '.txt';
                if ( !file_exists( $path ) ) {
                    $handle = fopen( $path, 'a' );
                    if ( $handle !== false ) {

                        fwrite( $handle, '--- ' . $post_type . ' mapping details ---' . PHP_EOL );
                        fwrite( $handle, PHP_EOL );

                        fwrite( $handle, 'post type key or id: ' . $post_type . PHP_EOL );
                        fwrite( $handle, 'post type singular label: ' . $post_type_settings['label_singular'] . PHP_EOL );
                        fwrite( $handle, 'post type plural label: ' . $post_type_settings['label_plural'] . PHP_EOL );

                        fwrite( $handle, PHP_EOL );
                        fwrite( $handle, $post_type . ' post type fields and their associated field-types:' . PHP_EOL );
                        fwrite( $handle, PHP_EOL );

                        foreach ( $post_type_settings['fields'] ?? [] as $field_key => $field ) {
                            $line = '- field-key: ' . $field_key . ' has the label: ' . $field['name'] . ' and the field-type: ' . $field['type'] . PHP_EOL;
                            fwrite( $handle, $line );

                            // If available, also capture options....
                            if ( isset( $field['default'] ) && is_array( $field['default'] ) && count( $field['default'] ) > 0 ) {
                                fwrite( $handle, 'associated options and labels:' . PHP_EOL );
                                foreach ( $field['default'] as $option => $defaults ) {
                                    fwrite( $handle, 'option: ' . $option . ' label: ' . ( $defaults['label'] ?? '' ) . PHP_EOL );
                                }
                            }
                        }

                        fwrite( $handle, PHP_EOL . PHP_EOL );

                    }

                    fclose( $handle );
                }

                /**
                 * Load post type text file data contents....
                 */

                $contents = array_merge( $contents, $this->get_data( $path ) );
            }
        }

        return $contents;
    }

    public function build_filter_query_specs(): void {

        /**
         * Load the various parts which will eventually be used
         * to construct the filter generation model specification.
         */

        $filters_dir = __DIR__ . '/filters/';

        $brief = $this->get_data( $filters_dir . '0-brief/brief.txt' );
        $structure = $this->get_data( $filters_dir . '1-structure/structure.txt' );
        $instructions = $this->get_data( $filters_dir . '3-instructions/instructions.txt' );
        $considerations = $this->get_data( $filters_dir . '5-considerations/considerations.txt' );

        /**
         * If required, create record post type txt files, before extracting
         * specification details.
         */

        $post_types = $this->generate_record_post_type_specs( $filters_dir . '2-record-types/' );

        /**
         * The extraction of examples will require additional logic, in
         * order to work into the desired shape.
         */

        $examples = [];
        $examples[] = 'Examples';
        foreach ( DT_Posts::get_field_types() as $field_type_key => $field_type ) {
            $path = $filters_dir . '4-examples/'. $field_type_key .'/examples.txt';
            if ( file_exists( $path ) ) {
                $examples = array_merge( $examples, $this->reshape_examples( $this->get_data( $path ), false ) );
            }
        }

        /**
         * Finally, build and persist required specification shape.
         */

        $model_specs = implode( '\n', $brief ) .'\n';
        $model_specs.= implode( '\n', $structure ) .'\n';
        $model_specs.= implode( '\n', $post_types ) .'\n';
        $model_specs.= implode( '\n', $instructions ) .'\n';
        $model_specs.= implode( '\n', $considerations ) .'\n';
        $model_specs.= implode( '\n', $examples );

        update_option( 'DT_AI_llm_model_specs_filters', $model_specs );
    }
}

Disciple_Tools_AI_Data::instance();
