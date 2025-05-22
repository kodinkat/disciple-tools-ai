<?php
if ( !defined( 'ABSPATH' ) ) { exit; } // Exit if accessed directly.

class Disciple_Tools_AI_Charts
{
    private static $_instance = null;
    public static function instance(){
        if ( is_null( self::$_instance ) ) {
            self::$_instance = new self();
        }
        return self::$_instance;
    } // End instance()

    public function __construct(){

        require_once( 'dynamic-ai-maps.php' );
        new Disciple_Tools_AI_Dynamic_Maps();

        /**
         * @todo add other charts like the pattern above here
         */
    } // End __construct
}
Disciple_Tools_AI_Charts::instance();
