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
        $this->build_filter_query_specs();
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

    private function build_filter_query_specs(): void {

        /**
         * Load the various parts which will eventually be used
         * to construct the filter generation model specification.
         */

        $filters_dir = __DIR__ . '/filters/';

        $brief = $this->get_data( $filters_dir . '0-brief/brief.txt' );
        $structure = $this->get_data( $filters_dir . '1-structure/structure.txt' );
        $instructions = $this->get_data( $filters_dir . '2-instructions/instructions.txt' );
        $considerations = $this->get_data( $filters_dir . '4-considerations/considerations.txt' );

        /**
         * The extraction of examples will require additional logic, in
         * order to work into the desired shape.
         */

        $assigned_to_with_status_examples = $this->reshape_examples( $this->get_data( $filters_dir . '3-examples/assigned-to-with-status/examples.txt' ) );  ;

        /**
         * Finally, build and persist required specification shape.
         */

        $model_specs = implode( '\n', $brief ) .'\n';
        $model_specs.= implode( '\n', $structure ) .'\n';
        $model_specs.= implode( '\n', $instructions ) .'\n';
        $model_specs.= implode( '\n', $considerations ) .'\n';
        $model_specs.= implode( '\n', $assigned_to_with_status_examples );

        update_option( 'DT_AI_llm_model_specs_filters', $model_specs );
    }
}

Disciple_Tools_AI_Data::instance();
