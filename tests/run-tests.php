<?php
/**
 * In-process end-to-end test for mrmurphy-restful-deploy.
 *
 * Run: PKG_PHASE=disabled|enabled wp eval-file test.php
 *
 * Drives the real REST dispatch path (rest_do_request), the real capability
 * gates, the real upgrader and the real filesystem. The only thing relaxed is
 * the application-password / SSL requirement, and that is asserted first.
 *
 * Only ever runs under `wp eval-file`; a direct request exits immediately.
 */

defined( 'ABSPATH' ) || exit;

$phase = getenv( 'PKG_PHASE' ) ?: 'enabled';
$dir   = WP_PLUGIN_DIR . '/mrmurphy-restful-deploy/tests/fixtures';

$GLOBALS['pkg_test'] = array(
	'pass'   => 0,
	'fail'   => 0,
	'failed' => array(),
);

function ok( $label, $cond, $extra = '' ) {
	if ( $cond ) {
		$GLOBALS['pkg_test']['pass']++;
		echo "  PASS  {$label}\n";
		return true;
	}

	$GLOBALS['pkg_test']['fail']++;
	$GLOBALS['pkg_test']['failed'][] = $label;
	echo "  FAIL  {$label}" . ( '' !== $extra ? "  :: {$extra}" : '' ) . "\n";

	return false;
}

function tally() {
	$pass   = $GLOBALS['pkg_test']['pass'];
	$fail   = $GLOBALS['pkg_test']['fail'];
	$failed = $GLOBALS['pkg_test']['failed'];

	echo "\n{$pass} passed, {$fail} failed\n";

	if ( $failed ) {
		echo 'failed: ' . implode( ' | ', $failed ) . "\n";
	}
}

function req( $route, $method = 'POST', $body = array() ) {
	$r = new WP_REST_Request( $method, $route );
	$r->set_header( 'content-type', 'application/json' );
	$r->set_body( wp_json_encode( $body ) );

	return rest_do_request( $r );
}

function err_code( $resp ) {
	$e = $resp->as_error();

	return $e ? $e->get_error_code() : '';
}

/**
 * Request with the parameters in the query string, the way a curl caller
 * sending ?plugin=...&deactivate=1 would deliver them.
 */
function req_query( $route, $method, $query = array() ) {
	$r = new WP_REST_Request( $method, $route );
	$r->set_query_params( $query );

	return rest_do_request( $r );
}

function payload( $file ) {
	return base64_encode( file_get_contents( $file ) );
}

// Start from a clean slate so both phases are order-independent and repeatable.
require_once ABSPATH . 'wp-admin/includes/plugin.php';
delete_option( 'mrmurphy_restful_deploy_log' );
delete_option( 'mrmurphy_restful_deploy_refusals' );
delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_ENABLED );
delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_UNTIL );
delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_EXPIRY_LOGGED );

// The per-user hourly throttle counter is keyed by user and hour; clear them all
// so a previous run's budget cannot make this one fail.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mrmurphy_restful_deploy_ops_%'" );

deactivate_plugins( 'mrmurphy-test-package/mrmurphy-test-package.php', true );
foreach ( array( WP_PLUGIN_DIR . '/mrmurphy-test-package', get_theme_root() . '/mrmurphy-test-theme' ) as $stale ) {
	if ( ! is_dir( $stale ) ) {
		continue;
	}

	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $stale, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);

	foreach ( $items as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}

	rmdir( $stale );
}
wp_clean_plugins_cache( false );

echo "=== phase: {$phase} ===\n";

if ( 'disabled' === $phase ) {
	$admins = get_users(
		array(
			'role'   => 'administrator',
			'number' => 1,
			'fields' => 'ID',
		)
	);
	wp_set_current_user( $admins ? (int) $admins[0] : 1 );

	echo '  admin user id: ' . get_current_user_id() . "\n";

	$routes = rest_get_server()->get_routes();
	ok( 'routes registered even while disabled', isset( $routes['/mrmurphy-restful-deploy/v1/plugins'] ) );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'disabled API answers 403', 403 === $resp->get_status(), 'status=' . $resp->get_status() );
	ok( 'disabled API says why', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );

	$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ) ) );
	ok( 'disabled API refuses installs', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );
	ok( 'nothing was installed while disabled', ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-test-package' ) );

	tally();
	return;
}

if ( 'toggle' === $phase ) {
	$toggle_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $toggle_admins ? (int) $toggle_admins[0] : 1 );

	add_filter( 'mrmurphy_restful_deploy_ssl_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_app_password_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_max_operations_per_hour', static function () { return 100; } );

	ok( 'no constant defined, so the settings screen decides', false === defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) );
	ok( 'the toggle starts closed', false === MRMurphy_Restful_Deploy_Plugin::enabled() );
	ok( 'control source is the default', 'default' === MRMurphy_Restful_Deploy_Plugin::control_source() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'closed endpoints answer 403', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );

	$armed_until = MRMurphy_Restful_Deploy_Plugin::arm( 30 );
	ok( 'arming sets a window in the future', $armed_until > time() );
	ok( 'armed => enabled', true === MRMurphy_Restful_Deploy_Plugin::enabled() );
	ok( 'control source is the toggle', 'toggle' === MRMurphy_Restful_Deploy_Plugin::control_source() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'armed endpoints answer 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );

	$open_actions = wp_list_pluck( MRMurphy_Restful_Deploy_Log::all(), 'action' );
	ok( 'arming is audited', in_array( 'settings_arm', $open_actions, true ) );

	// The window runs out: no cron, no request needed — the switch closes itself.
	update_option( MRMurphy_Restful_Deploy_Plugin::OPTION_UNTIL, time() - 10 );
	ok( 'an expired window is closed', false === MRMurphy_Restful_Deploy_Plugin::enabled() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'expired window answers 403', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );

	$expired_actions = wp_list_pluck( MRMurphy_Restful_Deploy_Log::all(), 'action' );
	ok( 'the expiry is audited once', 1 === count( array_keys( $expired_actions, 'settings_expired', true ) ), implode( ',', array_slice( $expired_actions, 0, 6 ) ) );

	MRMurphy_Restful_Deploy_Plugin::disarm();
	ok( 'disarm closes the endpoints', false === MRMurphy_Restful_Deploy_Plugin::enabled() );

	$closed_actions = wp_list_pluck( MRMurphy_Restful_Deploy_Log::all(), 'action' );
	ok( 'disarming is audited', in_array( 'settings_disarm', $closed_actions, true ) );

	// An allowlist, so the form cannot ask for an arbitrary window.
	MRMurphy_Restful_Deploy_Plugin::arm( 0 );
	ok( 'arm( 0 ) means until disarmed', 0 === MRMurphy_Restful_Deploy_Plugin::toggle_expires() );
	ok( 'a zero window stays armed', true === MRMurphy_Restful_Deploy_Plugin::enabled() );

	delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_ENABLED );
	delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_UNTIL );
	delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_EXPIRY_LOGGED );
	delete_option( 'mrmurphy_restful_deploy_log' );
	delete_option( 'mrmurphy_restful_deploy_refusals' );

	tally();
	return;
}

if ( 'forced_off' === $phase ) {
	$off_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $off_admins ? (int) $off_admins[0] : 1 );

	add_filter( 'mrmurphy_restful_deploy_ssl_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_app_password_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_max_operations_per_hour', static function () { return 100; } );

	ok( 'the constant is defined as false', true === defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) && false === MRMURPHY_RESTFUL_DEPLOY_ENABLED );
	ok( 'control source is the constant', 'constant-off' === MRMurphy_Restful_Deploy_Plugin::control_source() );

	$armed_until = MRMurphy_Restful_Deploy_Plugin::arm( 30 );
	ok( 'the screen can still record a window', $armed_until > time() );
	ok( 'wp-config.php wins: still closed', false === MRMurphy_Restful_Deploy_Plugin::enabled() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'forced-off endpoints answer 403', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );

	delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_ENABLED );
	delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_UNTIL );
	delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_EXPIRY_LOGGED );
	delete_option( 'mrmurphy_restful_deploy_log' );
	delete_option( 'mrmurphy_restful_deploy_refusals' );

	tally();
	return;
}

$original_stylesheet = get_stylesheet();

$admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
ok( 'found an administrator to test with', ! empty( $admins ), 'no administrator in this install' );
wp_set_current_user( $admins ? (int) $admins[0] : 1 );

// The hourly operation throttle is real; give this process a fresh budget.
$ops_key = 'mrmurphy_restful_deploy_ops_' . get_current_user_id() . '_' . gmdate( 'YmdH' );
delete_transient( $ops_key );
delete_option( $ops_key );

/* -------------------------------------------------------------------- */
/*  1. The gates must bite BEFORE anything is relaxed                    */
/* -------------------------------------------------------------------- */

$gates = MRMurphy_Restful_Deploy_Plugin::gate_status();
ok( 'enabled via MRMURPHY_RESTFUL_DEPLOY_ENABLED constant', true === $gates['enabled'] );
ok( 'app passwords required by default', true === $gates['app_password_required'] );

$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
ok( 'gated by default', 403 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ) ) );
ok( 'installs refused while gated', 403 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
ok( 'nothing installed while gated', ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-test-package' ) );

// Relax SSL only, so the application-password gate is the next one in line.
add_filter( 'mrmurphy_restful_deploy_ssl_required', '__return_false' );

$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
ok( 'app-password gate bites on its own', 'mrmurphy_restful_deploy_requires_application_password' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ) ) );
ok( 'app-password gate blocks installs', 'mrmurphy_restful_deploy_requires_application_password' === err_code( $resp ), err_code( $resp ) );
ok( 'nothing installed while app-pass gated', ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-test-package' ) );

// Capability gate: relax auth first, then take install_plugins away.
add_filter( 'mrmurphy_restful_deploy_app_password_required', '__return_false' );

$deny_caps = static function ( $allcaps ) {
	$allcaps['install_plugins']  = false;
	$allcaps['activate_plugins'] = false;

	return $allcaps;
};
add_filter( 'user_has_cap', $deny_caps );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ) ) );
ok( 'admin without capabilities is refused', 'rest_cannot_manage_plugins' === err_code( $resp ), err_code( $resp ) );

remove_filter( 'user_has_cap', $deny_caps );

add_filter(
	'mrmurphy_restful_deploy_max_operations_per_hour',
	static function () {
		return 100;
	}
);

$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
ok( 'gates relaxed => inventory 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );

/* -------------------------------------------------------------------- */
/*  2. Validation: bad archives refused, nothing written                 */
/* -------------------------------------------------------------------- */

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ), 'dry_run' => true ) );
$data = $resp->get_data();
ok( 'dry_run 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
ok( 'dry_run detects plugin', isset( $data['package']['detected_type'] ) && 'plugin' === $data['package']['detected_type'] );
ok( 'dry_run reads name', isset( $data['package']['name'] ) && 'MrMurphy Test Package' === $data['package']['name'], isset( $data['package']['name'] ) ? $data['package']['name'] : '' );
ok( 'dry_run reads version', isset( $data['package']['version'] ) && '0.0.1' === $data['package']['version'], isset( $data['package']['version'] ) ? $data['package']['version'] : '' );
ok( 'dry_run finds the single top level folder', isset( $data['package']['top_level_dir'] ) && 'mrmurphy-test-package' === $data['package']['top_level_dir'] );
ok( 'dry_run counts __MACOSX out of entries', isset( $data['package']['entry_count'] ) && 2 === $data['package']['entry_count'], isset( $data['package']['entry_count'] ) ? (string) $data['package']['entry_count'] : '' );
ok( 'dry_run installed nothing', false === $data['installed'] && ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-test-package' ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/flat.zip' ) ) );
ok( 'flat zip refused (no top level folder)', 'mrmurphy_restful_deploy_flat_archive' === err_code( $resp ), err_code( $resp ) );
ok( 'flat zip wrote nothing', ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-flat' ) && ! file_exists( WP_PLUGIN_DIR . '/mrmurphy-flat.php' ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/traversal.zip' ) ) );
ok( 'path traversal refused', 'mrmurphy_restful_deploy_unsafe_archive_path' === err_code( $resp ), err_code( $resp ) );
ok( 'traversal wrote nothing outside', ! file_exists( WP_PLUGIN_DIR . '/escaped.php' ) && ! file_exists( ABSPATH . 'escaped.php' ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/junk.zip' ) ) );
ok( 'non-package zip refused', 'mrmurphy_restful_deploy_unknown_package' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-theme.zip' ) ) );
ok( 'theme zip refused by /plugins', 'mrmurphy_restful_deploy_wrong_package_type' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => 'not base64!!' ) );
ok( 'garbage base64 refused', 'mrmurphy_restful_deploy_invalid_base64' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array() );
ok( 'empty request refused', 'mrmurphy_restful_deploy_missing_package' === err_code( $resp ), err_code( $resp ) );

/* -------------------------------------------------------------------- */
/*  3. Plugin install + activate + deactivate + overwrite                */
/* -------------------------------------------------------------------- */

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ) ) );
$data = $resp->get_data();
ok( 'plugin install 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
ok( 'plugin file reported', isset( $data['plugin'] ) && 'mrmurphy-test-package/mrmurphy-test-package.php' === $data['plugin'], isset( $data['plugin'] ) ? $data['plugin'] : '' );
ok( 'plugin main file on disk', is_file( WP_PLUGIN_DIR . '/mrmurphy-test-package/mrmurphy-test-package.php' ) );
ok( 'plugin subdirectory on disk', is_file( WP_PLUGIN_DIR . '/mrmurphy-test-package/inc/helper.php' ) );
ok( 'activate=false left it inactive', ! is_plugin_active( 'mrmurphy-test-package/mrmurphy-test-package.php' ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ) ) );
ok( 'existing plugin refused with 409', 409 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
ok( 'existing plugin error says the folder exists', 'folder_exists' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ), 'overwrite' => true ) );
ok( 'overwrite refused by default (fails closed)', 'mrmurphy_restful_deploy_overwrite_disabled' === err_code( $resp ), err_code( $resp ) );

// An operator opting in to overwrites.
add_filter( 'mrmurphy_restful_deploy_overwrite_allowed', '__return_true' );
$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ), 'overwrite' => true ) );
ok( 'overwrite=true allowed when the operator opts in', 200 === $resp->get_status(), 'status=' . $resp->get_status() );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins/activate', 'POST', array( 'plugin' => 'mrmurphy-test-package/mrmurphy-test-package.php' ) );
$data = $resp->get_data();
ok( 'activate 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
ok( 'plugin is active', is_plugin_active( 'mrmurphy-test-package/mrmurphy-test-package.php' ) );
ok( 'response says activated', isset( $data['activated'] ) && true === $data['activated'] );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins/activate', 'POST', array( 'plugin' => 'mrmurphy-test-package/mrmurphy-test-package.php' ) );
$data = $resp->get_data();
ok( 'activating twice is idempotent', isset( $data['already_active'] ) && true === $data['already_active'] );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins/activate', 'POST', array( 'plugin' => 'nope/nope.php' ) );
ok( 'activating a missing plugin 404s', 'mrmurphy_restful_deploy_plugin_not_found' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins/deactivate', 'POST', array( 'plugin' => 'mrmurphy-test-package/mrmurphy-test-package.php' ) );
ok( 'deactivate 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
ok( 'plugin is inactive', ! is_plugin_active( 'mrmurphy-test-package/mrmurphy-test-package.php' ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ), 'activate' => true, 'overwrite' => true ) );
$data = $resp->get_data();
ok( 'install+activate in one call', 200 === $resp->get_status() && isset( $data['activated'] ) && true === $data['activated'], 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
ok( 'install+activate really active', is_plugin_active( 'mrmurphy-test-package/mrmurphy-test-package.php' ) );

/* -------------------------------------------------------------------- */
/*  4. Theme install + activate + restore                                */
/* -------------------------------------------------------------------- */

$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-theme.zip' ) ) );
$data = $resp->get_data();
ok( 'theme install 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
ok( 'stylesheet reported', isset( $data['stylesheet'] ) && 'mrmurphy-test-theme' === $data['stylesheet'], isset( $data['stylesheet'] ) ? $data['stylesheet'] : '' );
ok( 'style.css on disk', is_file( get_theme_root() . '/mrmurphy-test-theme/style.css' ) );
ok( 'theme not switched yet', $original_stylesheet === get_stylesheet() );

$resp = req( '/mrmurphy-restful-deploy/v1/themes/activate', 'POST', array( 'stylesheet' => 'mrmurphy-test-theme' ) );
$data = $resp->get_data();
ok( 'theme activate 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
ok( 'theme is active', 'mrmurphy-test-theme' === get_stylesheet() );
ok( 'response names the theme', isset( $data['name'] ) && 'MrMurphy Test Theme' === $data['name'], isset( $data['name'] ) ? $data['name'] : '' );

$resp = req( '/mrmurphy-restful-deploy/v1/themes/activate', 'POST', array( 'stylesheet' => 'mrmurphy-test-theme' ) );
$data = $resp->get_data();
ok( 're-activating is idempotent', isset( $data['already_active'] ) && true === $data['already_active'] );

$resp = req( '/mrmurphy-restful-deploy/v1/themes/activate', 'POST', array( 'stylesheet' => 'does-not-exist' ) );
ok( 'unknown theme 404s', 'mrmurphy_restful_deploy_theme_not_found' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/themes/activate', 'POST', array( 'stylesheet' => '../etc' ) );
ok( 'bad stylesheet refused', 'mrmurphy_restful_deploy_invalid_stylesheet' === err_code( $resp ), err_code( $resp ) );

switch_theme( $original_stylesheet );
ok( 'original theme restored', $original_stylesheet === get_stylesheet() );

/* -------------------------------------------------------------------- */
/*  5. Uninstall                                                         */
/* -------------------------------------------------------------------- */

$fixture_plugin = 'mrmurphy-test-package/mrmurphy-test-package.php';

// Deleting an active plugin is refused unless the caller says "deactivate".
$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'DELETE', array( 'plugin' => $fixture_plugin ) );
ok( 'active plugin refused with 409', 409 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
ok( 'active plugin refusal names the reason', 'rest_cannot_delete_active_plugin' === err_code( $resp ), err_code( $resp ) );
ok( 'refused delete left the files alone', is_file( WP_PLUGIN_DIR . '/mrmurphy-test-package/mrmurphy-test-package.php' ) );

// Over the query string instead of a JSON body, then with deactivate=true.
$resp = req_query(
	'/mrmurphy-restful-deploy/v1/plugins',
	'DELETE',
	array(
		'plugin'     => $fixture_plugin,
		'deactivate' => '1',
	)
);
$data = $resp->get_data();
ok( 'uninstall over query params works', 200 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
ok( 'uninstall reports the deletion', isset( $data['deleted'] ) && true === $data['deleted'] );
ok( 'uninstall reports it deactivated first', isset( $data['deactivated_first'] ) && true === $data['deactivated_first'] );
ok( 'uninstall reports the version removed', isset( $data['version'] ) && '0.0.1' === $data['version'], isset( $data['version'] ) ? $data['version'] : '' );
ok( 'plugin directory is gone', ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-test-package' ) );
ok( 'plugin is no longer in get_plugins()', ! isset( get_plugins()[ $fixture_plugin ] ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'DELETE', array( 'plugin' => $fixture_plugin ) );
ok( 'deleting a missing plugin 404s', 'mrmurphy_restful_deploy_plugin_not_found' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'DELETE', array( 'plugin' => '../wp-config.php' ) );
ok( 'traversal in "plugin" refused', in_array( err_code( $resp ), array( 'mrmurphy_restful_deploy_invalid_plugin', 'mrmurphy_restful_deploy_plugin_not_found' ), true ), err_code( $resp ) );
ok( 'traversal deleted nothing', file_exists( ABSPATH . 'wp-config.php' ) );

$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'DELETE', array( 'plugin' => 'mrmurphy-restful-deploy/mrmurphy-restful-deploy.php' ) );
ok( 'refuses to delete itself', 'mrmurphy_restful_deploy_cannot_delete_self' === err_code( $resp ), err_code( $resp ) );
ok( 'self is still installed', is_file( WP_PLUGIN_DIR . '/mrmurphy-restful-deploy/mrmurphy-restful-deploy.php' ) );

// Themes: the active theme must survive, other themes must go.
$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'DELETE', array( 'stylesheet' => $original_stylesheet ) );
ok( 'active theme refused with 409', 409 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
ok( 'active theme refusal names the reason', 'mrmurphy_restful_deploy_theme_is_active' === err_code( $resp ), err_code( $resp ) );
ok( 'active theme still on disk', is_dir( trailingslashit( get_theme_root() ) . $original_stylesheet ) );

$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'DELETE', array( 'stylesheet' => 'mrmurphy-test-theme' ) );
$data = $resp->get_data();
ok( 'unused theme uninstalled', 200 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
ok( 'theme deletion reported', isset( $data['deleted'] ) && true === $data['deleted'] );
ok( 'theme directory is gone', ! is_dir( trailingslashit( get_theme_root() ) . 'mrmurphy-test-theme' ) );
ok( 'original theme untouched', $original_stylesheet === get_stylesheet() );

$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'DELETE', array( 'stylesheet' => 'mrmurphy-test-theme' ) );
ok( 'deleting a missing theme 404s', 'mrmurphy_restful_deploy_theme_not_found' === err_code( $resp ), err_code( $resp ) );

$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'DELETE', array( 'stylesheet' => '../uploads' ) );
ok( 'traversal in "stylesheet" refused', 'mrmurphy_restful_deploy_invalid_stylesheet' === err_code( $resp ), err_code( $resp ) );

$log_actions = wp_list_pluck( MRMurphy_Restful_Deploy_Log::all(), 'action' );
ok( 'plugin uninstall is audited', in_array( 'uninstall_plugin', $log_actions, true ) );
ok( 'theme uninstall is audited', in_array( 'uninstall_theme', $log_actions, true ) );

$log_errors = wp_list_pluck(
	array_filter(
		MRMurphy_Restful_Deploy_Log::all(),
		static function ( $entry ) {
			return isset( $entry['status'] ) && 'error' === $entry['status'];
		}
	),
	'action'
);
ok( 'refused uninstalls are audited as errors', in_array( 'uninstall_plugin', $log_errors, true ) && in_array( 'uninstall_theme', $log_errors, true ) );

// Put both fixtures back so the inventory section below still sees them.
$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ), 'activate' => true ) );
ok( 'fixture plugin reinstalled after uninstall', 200 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-theme.zip' ) ) );
ok( 'fixture theme reinstalled after uninstall', 200 === $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );

/* -------------------------------------------------------------------- */
/*  6. Inventory, audit log, temp file hygiene                           */
/* -------------------------------------------------------------------- */

$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
$data = $resp->get_data();
$files = wp_list_pluck( isset( $data['plugins'] ) ? $data['plugins'] : array(), 'plugin' );
$index = array_search( 'mrmurphy-test-package/mrmurphy-test-package.php', $files, true );
ok( 'inventory 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
ok( 'inventory lists the fixture plugin', false !== $index );
ok( 'inventory reports it active', false !== $index && 'active' === $data['plugins'][ $index ]['status'], false !== $index ? $data['plugins'][ $index ]['status'] : '' );
$styles = wp_list_pluck( isset( $data['themes'] ) ? $data['themes'] : array(), 'stylesheet' );
ok( 'inventory lists the fixture theme', in_array( 'mrmurphy-test-theme', $styles, true ) );
ok( 'inventory exposes gates', isset( $data['gates']['enabled'] ) && true === $data['gates']['enabled'] );

$resp    = req( '/mrmurphy-restful-deploy/v1/log', 'GET' );
$data    = $resp->get_data();
$actions = wp_list_pluck( isset( $data['entries'] ) ? $data['entries'] : array(), 'action' );
ok( 'log 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
ok( 'log recorded operations', count( $actions ) > 5, count( $actions ) . ' entries' );
ok( 'log recorded installs', in_array( 'install_plugin', $actions, true ) );
ok( 'log recorded activations', in_array( 'activate_plugin', $actions, true ) );

$leftovers = glob( trailingslashit( get_temp_dir() ) . 'mrmurphy-restful-deploy-*.zip' );
ok( 'all temp packages cleaned up', empty( $leftovers ), implode( ', ', (array) $leftovers ) );

/* -------------------------------------------------------------------- */
/*  7. Security regressions                                              */
/* -------------------------------------------------------------------- */

// Hostile names must never reach core's path-concatenating delete.
foreach ( array( '..', '.', '...', '.hidden', 'evil.' ) as $hostile ) {
	$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'DELETE', array( 'stylesheet' => $hostile ) );
	ok(
		'stylesheet "' . $hostile . '" refused',
		in_array( err_code( $resp ), array( 'mrmurphy_restful_deploy_invalid_stylesheet', 'mrmurphy_restful_deploy_theme_not_found' ), true ),
		err_code( $resp )
	);

	$resp = req( '/mrmurphy-restful-deploy/v1/themes/activate', 'POST', array( 'stylesheet' => $hostile ) );
	ok(
		'activating stylesheet "' . $hostile . '" refused',
		in_array( err_code( $resp ), array( 'mrmurphy_restful_deploy_invalid_stylesheet', 'mrmurphy_restful_deploy_theme_not_found' ), true ),
		err_code( $resp )
	);
}
ok( 'wp-content survived the hostile names', is_dir( WP_CONTENT_DIR . '/themes' ) && file_exists( ABSPATH . 'wp-config.php' ) );

// The active theme cannot be deleted by re-casing its name (macOS/Windows resolve that).
$recased = strtoupper( $original_stylesheet );
if ( $recased !== $original_stylesheet && file_exists( trailingslashit( get_theme_root() ) . $recased ) ) {
	$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'DELETE', array( 'stylesheet' => $recased ) );
	ok( 'active theme refused under a different case', 'mrmurphy_restful_deploy_theme_is_active' === err_code( $resp ), err_code( $resp ) );
	ok( 'active theme still on disk', is_dir( trailingslashit( get_theme_root() ) . $original_stylesheet ) );
} else {
	ok( 'case-insensitive fixture not applicable on this filesystem', true );
}

// A hostile top level folder name inside the archive. '..' and '...' are both
// caught even earlier, by the per-entry path check, which is fine — what
// matters is that the archive is refused and nothing is written.
foreach ( array( 'dotdot-name.zip' => '..', 'triple-dot-name.zip' => '...' ) as $file => $name ) {
	$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'POST', array( 'zip_base64' => payload( $dir . '/' . $file ) ) );
	ok(
		'archive folder "' . $name . '" refused',
		in_array( err_code( $resp ), array( 'mrmurphy_restful_deploy_bad_top_level', 'mrmurphy_restful_deploy_unsafe_archive_path' ), true ),
		err_code( $resp )
	);
}

// The same names must also be refused as destination names.
$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/self-name.zip' ) ) );
ok( 'a package may not claim the API\'s own directory name', 'mrmurphy_restful_deploy_cannot_overwrite_self' === err_code( $resp ) || 403 === $resp->get_status(), err_code( $resp ) );

// A lone file is not a folder.
$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/single-file.zip' ) ) );
ok( 'single flat file refused', 'mrmurphy_restful_deploy_flat_archive' === err_code( $resp ), err_code( $resp ) );

// Non-plugin paths must not reach core.
foreach ( array( '..', '.', '../wp-config.php' ) as $bad ) {
	$resp = req( '/mrmurphy-restful-deploy/v1/plugins/activate', 'POST', array( 'plugin' => $bad ) );
	ok( 'activating "' . $bad . '" refused', 'mrmurphy_restful_deploy_plugin_not_found' === err_code( $resp ), err_code( $resp ) );

	$resp = req( '/mrmurphy-restful-deploy/v1/plugins/deactivate', 'POST', array( 'plugin' => $bad ) );
	ok( 'deactivating "' . $bad . '" refused', 'mrmurphy_restful_deploy_plugin_not_found' === err_code( $resp ), err_code( $resp ) );
}
$log_ok_targets = wp_list_pluck(
	array_filter(
		MRMurphy_Restful_Deploy_Log::all(),
		static function ( $entry ) {
			return isset( $entry['status'] ) && 'ok' === $entry['status'];
		}
	),
	'target'
);
ok( 'no bogus deactivate success entry in the log', ! in_array( '..', $log_ok_targets, true ) && ! in_array( '.', $log_ok_targets, true ), implode( ',', array_slice( $log_ok_targets, 0, 8 ) ) );

// The API must not overwrite itself.
$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/self-name.zip' ), 'overwrite' => true, 'activate' => true ) );
ok( 'refuses to overwrite itself', 'mrmurphy_restful_deploy_cannot_overwrite_self' === err_code( $resp ), err_code( $resp ) );
ok( 'self still on disk after the attempt', is_file( WP_PLUGIN_DIR . '/mrmurphy-restful-deploy/mrmurphy-restful-deploy.php' ) );

// Overwriting running code needs an explicit acknowledgement.
$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ), 'overwrite' => true ) );
ok( 'overwriting an active plugin refused without activate', 'mrmurphy_restful_deploy_overwrite_active_plugin' === err_code( $resp ), err_code( $resp ) );
ok( 'refused overwrite left the plugin in place', is_file( WP_PLUGIN_DIR . '/mrmurphy-test-package/mrmurphy-test-package.php' ) );

// A failed install must not leave extracted PHP under the web root.
$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/deep-header.zip' ) ) );
ok( 'deep-header archive refused by the upgrader', 400 <= $resp->get_status(), 'status=' . $resp->get_status() . ' code=' . err_code( $resp ) );
$upgrade_leftovers = glob( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/mrmurphy-restful-deploy-*' );
ok( 'no extracted tree left in wp-content/upgrade', empty( $upgrade_leftovers ), implode( ', ', (array) $upgrade_leftovers ) );

// Symlinked package directories: overwrite and delete must both refuse.
$link_target = trailingslashit( get_temp_dir() ) . 'pkg-api-symlink-target-' . wp_generate_password( 6, false, false );
mkdir( $link_target, 0755, true );
file_put_contents( $link_target . '/keep-me.txt', 'must survive' );
$link_path = trailingslashit( get_theme_root() ) . 'symlink-target';
$linked    = @symlink( $link_target, $link_path );

if ( $linked ) {
	$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'POST', array( 'zip_base64' => payload( $dir . '/symlink-name.zip' ), 'overwrite' => true, 'activate' => true ) );
	ok( 'overwrite through a symlink refused', 'mrmurphy_restful_deploy_destination_is_symlink' === err_code( $resp ), err_code( $resp ) );

	$resp = req( '/mrmurphy-restful-deploy/v1/themes', 'DELETE', array( 'stylesheet' => 'symlink-target' ) );
	ok( 'deleting a symlinked theme refused', 'mrmurphy_restful_deploy_destination_is_symlink' === err_code( $resp ), err_code( $resp ) );

	ok( 'symlink target untouched', file_exists( $link_target . '/keep-me.txt' ) );

	@unlink( $link_path );
} else {
	ok( 'symlink fixture created (skipped on this platform)', true );
}

// Clean up the target directory either way.
if ( file_exists( $link_target . '/keep-me.txt' ) ) {
	@unlink( $link_target . '/keep-me.txt' );
}
@rmdir( $link_target );

// Refusals are counted, and the audit log cannot be evicted by them.
$resp = req( '/mrmurphy-restful-deploy/v1/log', 'GET' );
$log_data = $resp->get_data();
ok( 'log reports refusal counters', isset( $log_data['refusals'] ) && is_array( $log_data['refusals'] ) );
ok(
	'refusals were counted',
	! empty( $log_data['refusals'] ) && isset( $log_data['refusals'][ gmdate( 'YmdH' ) ]['_total'] ),
	wp_json_encode( $log_data['refusals'] )
);

$log_size_before = count( MRMurphy_Restful_Deploy_Log::all() );
for ( $i = 0; $i < 25; $i++ ) {
	req( '/mrmurphy-restful-deploy/v1/plugins', 'DELETE', array( 'plugin' => 'nope-' . $i . '/nope.php' ) );
}
ok( 'refused requests did not evict the audit log', count( MRMurphy_Restful_Deploy_Log::all() ) >= $log_size_before, $log_size_before . ' -> ' . count( MRMurphy_Restful_Deploy_Log::all() ) );

tally();
