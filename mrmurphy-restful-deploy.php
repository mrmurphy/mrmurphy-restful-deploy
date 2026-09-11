<?php
/**
 * Plugin Name:       MrMurphy Restful Deploy
 * Plugin URI:        https://github.com/mrmurphy/mrmurphy-restful-deploy
 * Description:       Deploy plugins and themes over the REST API — upload, install, activate and uninstall ZIP packages without SFTP or SSH. Admin-only, authenticated with an application password, with an audit log. Built for scripts and AI agents.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Murphy Randle
 * Author URI:        https://mrmurphy.dev
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       mrmurphy-restful-deploy
 *
 * @package MrMurphyRestfulDeploy
 */

defined( 'ABSPATH' ) || exit;

define( 'MRMURPHY_RESTFUL_DEPLOY_VERSION', '1.0.0' );
define( 'MRMURPHY_RESTFUL_DEPLOY_FILE', __FILE__ );
define( 'MRMURPHY_RESTFUL_DEPLOY_DIR', plugin_dir_path( __FILE__ ) );
define( 'MRMURPHY_RESTFUL_DEPLOY_NAMESPACE', 'mrmurphy-restful-deploy/v1' );

require_once MRMURPHY_RESTFUL_DEPLOY_DIR . 'inc/class-plugin.php';

/**
 * Plugin activation callback.
 */
function mrmurphy_restful_deploy_activate() {
	MRMurphy_Restful_Deploy_Plugin::activate();
}

register_activation_hook( __FILE__, 'mrmurphy_restful_deploy_activate' );

MRMurphy_Restful_Deploy_Plugin::instance();
