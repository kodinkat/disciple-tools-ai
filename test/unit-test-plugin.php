<?php

class PluginTest extends TestCase
{
    public function test_plugin_installed() {
        activate_plugin( 'disciple-tools-ai/disciple-tools-ai.php' );

        $this->assertContains(
            'disciple-tools-ai/disciple-tools-ai.php',
            get_option( 'active_plugins' )
        );
    }
}
