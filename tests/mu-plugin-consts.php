<?php
/**
 * Test fixture for tests/run-tests.php — NOT part of the plugin.
 *
 * Copy this file to wp-content/mu-plugins/zz-mrmurphy-restful-deploy-test-consts.php
 * to run the `forced_off` and `forced_on` phases, and delete it again when you are
 * done testing. The other phases run without it.
 *
 * It defines the wp-config.php constant from the environment, because a PHP
 * constant cannot be defined twice and the harness needs both branches. With
 * neither variable set it defines nothing, which is what lets the `default` and
 * `off` phases see the true "no constant" state.
 *
 * Leaving this file in mu-plugins is a bad idea: whoever can set an environment
 * variable for the web server could then pin the master switch.
 */

if ( getenv( 'MRMURPHY_RESTFUL_DEPLOY_TEST_ENABLE' ) ) {
	define( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED', true );
} elseif ( getenv( 'MRMURPHY_RESTFUL_DEPLOY_TEST_FORCE_OFF' ) ) {
	define( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED', false );
}
