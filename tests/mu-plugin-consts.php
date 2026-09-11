<?php
/**
 * Test fixture for tests/run-tests.php — NOT part of the plugin.
 *
 * Copy this file to wp-content/mu-plugins/zz-mrmurphy-restful-deploy-test-consts.php
 * when you want to run the `enabled` or `forced_off` phases, and delete it again
 * when you are done testing:
 *
 *   cp tests/mu-plugin-consts.php \
 *      wp-content/mu-plugins/zz-mrmurphy-restful-deploy-test-consts.php
 *
 * It defines the wp-config.php constant from the environment, because a PHP
 * constant cannot be defined twice and the harness needs both branches. With
 * neither variable set it defines nothing, which is what lets the `toggle`
 * phase see the true "no constant" default.
 *
 * Leaving this file in mu-plugins is a bad idea: whoever can set an environment
 * variable for the web server could then arm the deployment endpoints.
 */

if ( getenv( 'MRMURPHY_RESTFUL_DEPLOY_TEST_ENABLE' ) ) {
	define( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED', true );
} elseif ( getenv( 'MRMURPHY_RESTFUL_DEPLOY_TEST_FORCE_OFF' ) ) {
	define( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED', false );
}
