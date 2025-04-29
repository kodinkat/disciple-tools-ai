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
        add_filter( 'dt_ai_filter_specs', [ $this, 'dt_ai_filter_specs' ], 10, 2 );
        add_filter( 'dt_ai_connection_specs', [ $this, 'dt_ai_connection_specs' ], 10, 1 );
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

    private function generate_record_post_type_specs( $post_type ): array {
        $post_type_settings = DT_Posts::get_post_settings( $post_type, false );

        if ( is_wp_error( $post_type_settings ) ) {
            return [];
        }

        $specs = [
            'post-type-key' => $post_type,
            'post-type-singular-label' => $post_type_settings['label_singular'],
            'post-type-plural-label' => $post_type_settings['label_plural'],
            'fields' => []
        ];
        foreach ( $post_type_settings['fields'] ?? [] as $field_key => $field ){
            $spec = [
                'field-key' => $field_key,
                'label' => $field['name'],
                'type' => $field['type']
            ];

            // If available, also capture options....
            if ( isset( $field['default'] ) && is_array( $field['default'] ) && count( $field['default'] ) > 0 ) {
                $spec['options'] = [];
                foreach ( $field['default'] as $option => $defaults ) {
                    $spec['options'][] = [
                        'option-key' => $option,
                        'label' => $defaults['label'] ?? ''
                    ];
                }
            }

            $specs['fields'][] = $spec;
        }

        return $specs;
    }

    public function dt_ai_filter_specs( $filter_specs, $post_type ): array {

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
         * Create record post type specification details for given post type.
         */

        $post_type_specs = $this->generate_record_post_type_specs( $post_type );

        /**
         * The extraction of examples will require additional logic, in
         * order to work into the desired shape.
         */

        $examples = [];
        $examples[] = 'Examples';
        foreach ( DT_Posts::get_field_types() as $field_type_key => $field_type ) {
            $path = $filters_dir . '3-examples/'. $field_type_key .'/examples.txt';
            if ( file_exists( $path ) ) {
                $examples = array_merge( $examples, $this->reshape_examples( $this->get_data( $path ), false ) );
            }
        }

        /**
         * Finally, build and return required specification shape.
         */

        $filter_specs['filters'] = [
            'brief' => $brief,
            'structure' => $structure,
            'post_type_specs' => $post_type_specs,
            'instructions' => $instructions,
            'considerations' => $considerations,
            'examples' => $examples
        ];

        return $filter_specs;
    }

    public function dt_ai_connection_specs( $connection_specs ): array {

        /**
         * Load the various parts which will eventually be used
         * to construct the connection generation model specification.
         */

        $connections_dir = __DIR__ . '/connections/';

        $brief = $this->get_data( $connections_dir . '0-brief/brief.txt' );
        $instructions = $this->get_data( $connections_dir . '1-instructions/instructions.txt' );
        $considerations = $this->get_data( $connections_dir . '3-considerations/considerations.txt' );

        /**
         * The extraction of examples will require additional logic, in
         * order to work into the desired shape.
         */

        $examples = [];
        $examples[] = 'Examples';
        foreach ( ['connections', 'locations', 'communication_channels'] as $connection_type ) {
            $path = $connections_dir . '2-examples/'. $connection_type .'/examples.txt';
            if ( file_exists( $path ) ) {
                $examples = array_merge( $examples, $this->reshape_examples( $this->get_data( $path ), false ) );
            }
        }

        /**
         * Finally, build and return required specification shape.
         */

        $connection_specs['connections'] = [
            'brief' => $brief,
            'instructions' => $instructions,
            'considerations' => $considerations,
            'examples' => $examples
        ];

        return $connection_specs;
    }
}

Disciple_Tools_AI_Data::instance();
