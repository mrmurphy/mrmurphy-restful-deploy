<?php
/**
 * In-process end-to-end test for mrmurphy-restful-deploy.
 *
 * Run: PKG_PHASE=default|off|limits|admin|forced_off|forced_on|enabled wp eval-file run-tests.php
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

/**
 * Remove the fixture plugin and theme, and clear the caches that point at them.
 *
 * Runs at the start of every phase, so runs are order-independent, and again at
 * the end of the enabled phase, so a finished run leaves the site as it found it.
 */
function pkg_cleanup_fixtures() {
	deactivate_plugins( 'mrmurphy-test-package/mrmurphy-test-package.php', true );

	foreach ( array( WP_PLUGIN_DIR . '/mrmurphy-test-package', get_theme_root() . '/mrmurphy-test-theme' ) as $stale ) {
		if ( is_link( $stale ) ) {
			// Never recurse through a link: that is how a cleanup eats someone's repo.
			unlink( $stale );
			continue;
		}

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
}

/**
 * Put the site back the way the tests found it: no fixtures, no plugin options,
 * no throttle counters, and therefore the default deployments-on state.
 *
 * Called at the start of every phase (so runs are order-independent) and at the
 * end of every phase (so a phase that switched deployments off does not leave
 * the site switched off).
 */
function pkg_tidy_up() {
	pkg_cleanup_fixtures();

	delete_option( 'mrmurphy_restful_deploy_log' );
	delete_option( 'mrmurphy_restful_deploy_refusals' );
	delete_option( MRMurphy_Restful_Deploy_Plugin::OPTION_ENABLED );

	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'mrmurphy_restful_deploy_ops_%'" );

	wp_clean_plugins_cache( false );
}

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

// Start from a clean slate so every phase is order-independent and repeatable.
require_once ABSPATH . 'wp-admin/includes/plugin.php';

pkg_tidy_up();

echo "=== phase: {$phase} ===\n";

if ( 'default' === $phase ) {
	$default_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $default_admins ? (int) $default_admins[0] : 1 );

	add_filter( 'mrmurphy_restful_deploy_ssl_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_app_password_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_max_operations_per_hour', static function () { return 100; } );

	ok( 'no MRMURPHY_RESTFUL_DEPLOY_ENABLED constant is defined', false === defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) );
	ok( 'nothing has ever been switched, so the default applies', null === MRMurphy_Restful_Deploy_Plugin::stored_setting() );
	ok( 'out of the box deployments are ON', true === MRMurphy_Restful_Deploy_Plugin::enabled() );
	ok( 'the state is the default, not a saved setting', 'default' === MRMurphy_Restful_Deploy_Plugin::control_source() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	$data = $resp->get_data();
	ok( 'a fresh install answers 200 with no setup at all', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
	ok( 'the inventory reports them as on', isset( $data['gates']['enabled'] ) && true === $data['gates']['enabled'] );

	// Leave the site in the default, deployments-on state, whatever this phase did.
	pkg_tidy_up();

	tally();
	return;
}

if ( 'off' === $phase ) {
	$off_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $off_admins ? (int) $off_admins[0] : 1 );

	echo '  admin user id: ' . get_current_user_id() . "\n";

	// The gates a PHP process cannot satisfy. The master switch is checked first,
	// so the switched-off assertions below still see the right error code.
	add_filter( 'mrmurphy_restful_deploy_ssl_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_app_password_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_max_operations_per_hour', static function () { return 100; } );

	// The administrator switching deployments off on the settings screen.
	MRMurphy_Restful_Deploy_Plugin::set_enabled( false );
	ok( 'the screen can switch deployments off', false === MRMurphy_Restful_Deploy_Plugin::enabled() );
	ok( 'the screen is reported as the source', 'screen' === MRMurphy_Restful_Deploy_Plugin::control_source() );

	$logged = wp_list_pluck( MRMurphy_Restful_Deploy_Log::all(), 'action' );
	ok( 'switching off is audited', in_array( 'settings_disabled', $logged, true ), implode( ',', array_slice( $logged, 0, 5 ) ) );

	$routes = rest_get_server()->get_routes();
	ok( 'routes stay registered while switched off', isset( $routes['/mrmurphy-restful-deploy/v1/plugins'] ) );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'switched-off API answers 403', 403 === $resp->get_status(), 'status=' . $resp->get_status() );
	ok( 'switched-off API says why', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );

	$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ) ) );
	ok( 'switched-off API refuses installs', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );
	ok( 'nothing was installed while switched off', ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-test-package' ) );

	// And back on again, from the same screen.
	MRMurphy_Restful_Deploy_Plugin::set_enabled( true );
	ok( 'the screen can switch them back on', true === MRMurphy_Restful_Deploy_Plugin::enabled() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'switched back on, the API answers again', 403 !== $resp->get_status(), 'status=' . $resp->get_status() );

	// Leave the site in the default, deployments-on state, whatever this phase did.
	pkg_tidy_up();

	tally();
	return;
}

if ( 'forced_off' === $phase ) {
	$kill_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $kill_admins ? (int) $kill_admins[0] : 1 );

	add_filter( 'mrmurphy_restful_deploy_ssl_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_app_password_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_max_operations_per_hour', static function () { return 100; } );

	ok( 'the constant is defined as false', true === defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) && false === MRMURPHY_RESTFUL_DEPLOY_ENABLED );
	ok( 'wp-config.php is reported as the source', 'constant-off' === MRMurphy_Restful_Deploy_Plugin::control_source() );

	// Stand in for an administrator who switches deployments back on in the UI:
	// the stored setting says on, and the kill switch still wins.
	MRMurphy_Restful_Deploy_Plugin::set_enabled( true );
	ok( 'the screen can still store "on"', true === MRMurphy_Restful_Deploy_Plugin::setting_on() );
	ok( 'the kill switch beats the screen', false === MRMurphy_Restful_Deploy_Plugin::enabled() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'a killed API answers 403', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );

	$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => payload( $dir . '/mrmurphy-test-package.zip' ) ) );
	ok( 'a killed API refuses installs', 'mrmurphy_restful_deploy_disabled' === err_code( $resp ), err_code( $resp ) );
	ok( 'nothing was installed while killed', ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-test-package' ) );

	// Leave the site in the default, deployments-on state, whatever this phase did.
	pkg_tidy_up();

	tally();
	return;
}

if ( 'forced_on' === $phase ) {
	$pin_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $pin_admins ? (int) $pin_admins[0] : 1 );

	add_filter( 'mrmurphy_restful_deploy_ssl_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_app_password_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_max_operations_per_hour', static function () { return 100; } );

	ok( 'the constant is defined as true', true === defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) && false !== MRMURPHY_RESTFUL_DEPLOY_ENABLED );

	// Stand in for an administrator who switches deployments off in the UI.
	MRMurphy_Restful_Deploy_Plugin::set_enabled( false );
	ok( 'the screen can still store "off"', false === MRMurphy_Restful_Deploy_Plugin::setting_on() );
	ok( 'the constant pins them on anyway', true === MRMurphy_Restful_Deploy_Plugin::enabled() );
	ok( 'wp-config.php is reported as the source', 'constant-on' === MRMurphy_Restful_Deploy_Plugin::control_source() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'a pinned-on API answers 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );

	// Leave the site in the default, deployments-on state, whatever this phase did.
	pkg_tidy_up();

	tally();
	return;
}

if ( 'limits' === $phase ) {
	$limit_admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	wp_set_current_user( $limit_admins ? (int) $limit_admins[0] : 1 );

	// The shipped default, asserted before any filter gets involved. A deploy the
	// way the docs recommend — validate (free), then install+activate in one call
	// (1) — costs a single operation, so 30 an hour is comfortable headroom and
	// still stops a looping agent inside a couple of minutes.
	ok( 'the default cap is 30 operations per user per hour', 30 === MRMurphy_Restful_Deploy_Plugin::max_operations_per_hour(), (string) MRMurphy_Restful_Deploy_Plugin::max_operations_per_hour() );

	add_filter( 'mrmurphy_restful_deploy_ssl_required', '__return_false' );
	add_filter( 'mrmurphy_restful_deploy_app_password_required', '__return_false' );

	// A small cap, so the behaviour is observable in a handful of requests.
	add_filter( 'mrmurphy_restful_deploy_max_operations_per_hour', static function () { return 3; } );

	$key   = 'mrmurphy_restful_deploy_ops_' . get_current_user_id() . '_' . gmdate( 'YmdH' );
	$zip   = payload( $dir . '/mrmurphy-test-package.zip' );
	$count = static function () use ( $key ) {
		// Read with SQL, the way enforce_rate_limit() writes it: the counter is
		// bumped by a raw statement, so get_option() would hand back the cached
		// miss it saw the first time this option name was asked for.
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
	};

	ok( 'the counter is keyed per user and per hour', 1 === preg_match( '/^mrmurphy_restful_deploy_ops_\d+_\d{10}$/', $key ), $key );
	ok( 'the counter starts empty', 0 === $count() );

	// Validation and reads are free: they must not spend the budget.
	$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => $zip, 'dry_run' => true ) );
	ok( 'dry_run answers 200', 200 === $resp->get_status(), 'status=' . $resp->get_status() );
	ok( 'dry_run spends no budget', 0 === $count(), 'count=' . $count() );

	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'reads spend no budget', 200 === $resp->get_status() && 0 === $count(), 'status=' . $resp->get_status() . ' count=' . $count() );

	// A real write costs one, whatever it goes on to do with it.
	req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => $zip ) );
	ok( 'an install costs exactly one operation', 1 === $count(), 'count=' . $count() );

	// Three allowed, the fourth refused — and the refusal names the cap.
	req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => $zip ) );
	req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => $zip ) );
	$resp = req( '/mrmurphy-restful-deploy/v1/plugins', 'POST', array( 'zip_base64' => $zip ) );
	ok( 'past the cap answers 429', 'mrmurphy_restful_deploy_rate_limited' === err_code( $resp ), err_code( $resp ) );
	ok( 'the refusal quotes the cap', false !== strpos( (string) $resp->as_error()->get_error_message(), '3' ), (string) $resp->as_error()->get_error_message() );

	// Reads still work with the budget spent, so an agent can always look around.
	$resp = req( '/mrmurphy-restful-deploy/v1/inventory', 'GET' );
	ok( 'reads still answer 200 at the cap', 200 === $resp->get_status(), 'status=' . $resp->get_status() );

	// Leave the site in the default, deployments-on state, whatever this phase did.
	pkg_tidy_up();

	tally();
	return;
}

if ( 'admin' === $phase ) {
	require_once ABSPATH . 'wp-admin/includes/screen.php';
	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	$admins   = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$admin_id = $admins ? (int) $admins[0] : 1;
	wp_set_current_user( $admin_id );

	$page = new MRMurphy_Restful_Deploy_Admin();
	$page->add_page();

	$hook = get_plugin_page_hookname( MRMurphy_Restful_Deploy_Admin::PAGE, 'options-general.php' );
	set_current_screen( $hook );

	// The script must load here and nowhere else.
	$page->enqueue( 'edit.php' );
	ok( 'the copy script does not load on other screens', ! wp_script_is( 'mrmurphy-restful-deploy-admin', 'enqueued' ) );

	$page->enqueue( $hook );
	ok( 'the copy script loads on this screen', wp_script_is( 'mrmurphy-restful-deploy-admin', 'enqueued' ) );

	$registered = wp_scripts()->registered['mrmurphy-restful-deploy-admin'];
	ok( 'the script points at the file that ships', false !== strpos( (string) $registered->src, 'assets/admin-copy.js' ), (string) $registered->src );
	ok( 'the script file exists on disk', file_exists( MRMURPHY_RESTFUL_DEPLOY_DIR . 'assets/admin-copy.js' ) );

	// Registration: the right menu, the right capability.
	global $submenu;
	$capability = '';

	foreach ( (array) $submenu['options-general.php'] as $row ) {
		if ( isset( $row[2] ) && MRMurphy_Restful_Deploy_Admin::PAGE === $row[2] ) {
			$capability = (string) $row[1];
		}
	}

	ok( 'the screen is registered under Settings with manage_options', 'manage_options' === $capability, $capability );
	ok( 'an administrator has that capability', user_can( $admin_id, 'manage_options' ) );

	$subscribers = get_users( array( 'role' => 'subscriber', 'number' => 1, 'fields' => 'ID' ) );

	if ( $subscribers ) {
		ok( 'a subscriber does not, so the menu is not there for them', ! user_can( (int) $subscribers[0], 'manage_options' ) );
	} else {
		ok( 'no subscriber account to test the capability against', true );
	}

	// The screen itself.
	ob_start();
	$page->render();
	$html = ob_get_clean();

	ok( 'the screen renders', strlen( $html ) > 3000, strlen( $html ) . ' bytes' );
	ok( 'no PHP warnings in the rendered screen', 0 === substr_count( $html, 'Warning' ) );
	ok( 'it shows the on/off state', false !== strpos( $html, '>ON<' ) || false !== strpos( $html, '>OFF<' ) );
	ok( 'it shows the switch', false !== strpos( $html, 'Switch deployments off' ) || false !== strpos( $html, 'Switch deployments on' ) );
	ok( 'it shows the throttle', false !== strpos( $html, 'operations per user per hour' ) );
	ok( 'it lists every route', substr_count( $html, '<code>/' ) >= 9, substr_count( $html, '<code>/' ) . ' route cells' );

	// The agent brief lives on the page, in a field you can copy from.
	ok( 'the paste block is a textarea on the page', false !== strpos( $html, 'id="mrmurphy-paste-block"' ) );
	ok( 'the full brief is a textarea on the page', false !== strpos( $html, 'id="mrmurphy-full-brief"' ) );
	ok( 'both are read-only', 2 === substr_count( $html, 'readonly="readonly"' ), substr_count( $html, 'readonly="readonly"' ) . ' read-only fields' );
	ok( 'each has a copy button', 2 === substr_count( $html, 'data-mrmurphy-copy=' ), substr_count( $html, 'data-mrmurphy-copy=' ) . ' copy buttons' );
	ok( 'the page does not send anyone to the repository for it', false === strpos( $html, 'AGENT-INSTRUCTIONS.md in the repository' ) );

	// Personalised for this site, and never for credentials.
	ok( 'the brief carries this site\'s own base URL', false !== strpos( $html, rest_url( MRMURPHY_RESTFUL_DEPLOY_NAMESPACE ) ) );
	// esc_textarea() escapes quotes, so the rendered HTML holds &quot; not " —
	// asserting the escaped form also proves the field really went through it.
	ok( 'the brief carries the reading user\'s name', false !== strpos( $html, 'username &quot;' . wp_get_current_user()->user_login . '&quot;' ) );
	ok( 'the password placeholder is left as a placeholder', false !== strpos( $html, '&lt;APPLICATION PASSWORD&gt;' ) );
	ok( 'no credential is rendered into the page', false === strpos( $html, '<APPLICATION PASSWORD>' ) );

	// Switching off changes the page, not the brief.
	MRMurphy_Restful_Deploy_Plugin::set_enabled( false );

	ob_start();
	$page->render();
	$off_html = ob_get_clean();

	ok( 'switched off, the screen says OFF', false !== strpos( $off_html, '>OFF<' ) );
	ok( 'switched off, it offers to switch on', false !== strpos( $off_html, 'Switch deployments on' ) );
	ok( 'the brief is still offered while deployments are off', false !== strpos( $off_html, 'id="mrmurphy-paste-block"' ) );
	ok( 'still no warnings when switched off', 0 === substr_count( $off_html, 'Warning' ) );

	// Leave the site in the default, deployments-on state, whatever this phase did.
	pkg_tidy_up();

	tally();
	return;
}

// Precondition: the full suite runs in the state a fresh install is in — no
// constant above it, deployments on by default, nothing stored. If this site has
// the mu-plugin test fixture installed or a wp-config.php constant defined, the
// run would be exercising something else, so fail once and say why instead of
// failing a hundred times for reasons that look like plugin bugs.
$prep_ok = ! defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) && true === MRMurphy_Restful_Deploy_Plugin::enabled();
ok( 'precondition: no MRMURPHY_RESTFUL_DEPLOY_ENABLED constant, and deployments are on by default', $prep_ok );

if ( ! $prep_ok ) {
	echo "Aborting the enabled phase: this site is not in the default state. The constant fixture is only for the forced_on/forced_off phases — see tests/README.md.\n";
	// Leave the site in the default, deployments-on state, whatever this phase did.
	pkg_tidy_up();

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
// Summed across buckets rather than read from the current hour: the suite takes
// a minute or two, and a run that straddles an hour boundary would otherwise
// fail here for no reason.
$refusal_total = 0;
foreach ( (array) $log_data['refusals'] as $bucket ) {
	if ( is_array( $bucket ) && isset( $bucket['_total'] ) ) {
		$refusal_total += (int) $bucket['_total'];
	}
}
ok( 'refusals were counted', $refusal_total > 0, wp_json_encode( $log_data['refusals'] ) );

$log_size_before = count( MRMurphy_Restful_Deploy_Log::all() );
for ( $i = 0; $i < 25; $i++ ) {
	req( '/mrmurphy-restful-deploy/v1/plugins', 'DELETE', array( 'plugin' => 'nope-' . $i . '/nope.php' ) );
}
ok( 'refused requests did not evict the audit log', count( MRMurphy_Restful_Deploy_Log::all() ) >= $log_size_before, $log_size_before . ' -> ' . count( MRMurphy_Restful_Deploy_Log::all() ) );

/* -------------------------------------------------------------------- */
/*  8. Leave the site as we found it                                     */
/* -------------------------------------------------------------------- */

// The pre-clean does this too, so a run never depends on the one before it.
// Doing it again here means a finished run does not leave a fixture plugin and
// theme on the site, nor a pile of options behind them — nor deployments
// switched off, which is the state the phases above leave things in.
pkg_tidy_up();

ok( 'fixture plugin removed', ! is_dir( WP_PLUGIN_DIR . '/mrmurphy-test-package' ) && ! is_plugin_active( 'mrmurphy-test-package/mrmurphy-test-package.php' ) );
ok( 'fixture theme removed', ! is_dir( trailingslashit( get_theme_root() ) . 'mrmurphy-test-theme' ) );
ok( 'the active theme was left alone', $original_stylesheet === get_stylesheet(), get_stylesheet() );
ok( 'the plugin left no options behind', false === get_option( 'mrmurphy_restful_deploy_log', false ) && false === get_option( 'mrmurphy_restful_deploy_refusals', false ) );

tally();
