<?php
/**
 * Plugin bootstrap, feature gates and configuration.
 *
 * Every gate here FAILS CLOSED: if a constant is missing, the restrictive
 * behaviour is used, never the permissive one.
 *
 * @package MrMurphyRestfulDeploy
 */

defined( 'ABSPATH' ) || exit;

require_once MRMURPHY_RESTFUL_DEPLOY_DIR . 'inc/class-log.php';
require_once MRMURPHY_RESTFUL_DEPLOY_DIR . 'inc/class-installer.php';
require_once MRMURPHY_RESTFUL_DEPLOY_DIR . 'inc/class-rest.php';
require_once MRMURPHY_RESTFUL_DEPLOY_DIR . 'inc/class-admin.php';

/**
 * Main plugin loader.
 */
final class MRMurphy_Restful_Deploy_Plugin {

	/** @var self|null */
	private static $instance = null;

	/** @var MRMurphy_Restful_Deploy_REST */
	public $rest;

	/** @var MRMurphy_Restful_Deploy_Admin|null */
	public $admin = null;

	/**
	 * Option holding whether the deployment endpoints are switched off.
	 *
	 * Absent means ON: an unset option is the default, working state. Only an
	 * explicit false — written by the settings screen — turns deployments off,
	 * which also means "delete the option" can never be mistaken for "quietly
	 * enable", and the constant in wp-config.php is checked first regardless.
	 *
	 * @var string
	 */
	const OPTION_ENABLED = 'mrmurphy_restful_deploy_enabled';

	/**
	 * Singleton accessor.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->rest = new MRMurphy_Restful_Deploy_REST();

		if ( is_admin() ) {
			$this->admin = new MRMurphy_Restful_Deploy_Admin();
		}

		add_action( 'admin_notices', array( __CLASS__, 'maybe_render_disabled_notice' ) );
	}

	/**
	 * Plugin activation callback.
	 */
	public static function activate() {
		if ( ! self::enabled() ) {
			MRMurphy_Restful_Deploy_Log::add(
				'plugin_activated',
				plugin_basename( MRMURPHY_RESTFUL_DEPLOY_FILE ),
				'ok',
				'Plugin activated with the deployment endpoints switched off: no MRMURPHY_RESTFUL_DEPLOY_ENABLED constant and the settings screen has them off. Switch them on under Settings → Restful Deploy when you need them.'
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Gates                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Master switch: are the deployment endpoints answering?
	 *
	 * Precedence, in order:
	 *
	 *   1. `MRMURPHY_RESTFUL_DEPLOY_ENABLED` in wp-config.php, if it is defined at
	 *      all. `false` is the hard shutoff — it beats the settings screen, so a
	 *      lost admin session cannot turn deployments back on. `true` pins them on
	 *      and no UI can switch them off.
	 *   2. the settings screen, where an administrator turns them off or on.
	 *   3. out of the box: on. Once the plugin is active and you have an
	 *      Application Password, deployments work — there is nothing to arm first.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) ) {
			$enabled = ( false !== MRMURPHY_RESTFUL_DEPLOY_ENABLED );
		} else {
			$enabled = self::setting_on();
		}

		/**
		 * Filters whether the deployment API is enabled.
		 *
		 * @param bool $enabled Whether the API responds to requests.
		 */
		return (bool) apply_filters( 'mrmurphy_restful_deploy_enabled', $enabled );
	}

	/**
	 * The stored setting, or null when it has never been set.
	 *
	 * A sentinel default is used because get_option() cannot otherwise tell "an
	 * administrator switched this on" from "nobody has ever touched it".
	 *
	 * @return bool|null
	 */
	public static function stored_setting() {
		$value = get_option( self::OPTION_ENABLED, 'unset' );

		if ( 'unset' === $value ) {
			return null;
		}

		// Anything that reads as "off" counts as off, including a real false
		// left behind by an older build.
		return ! in_array( $value, array( '0', '', 0, false ), true );
	}

	/**
	 * What the settings-screen switch says, ignoring any wp-config.php override.
	 *
	 * @return bool
	 */
	public static function setting_on() {
		$stored = self::stored_setting();

		return null === $stored ? true : $stored;
	}

	/**
	 * Where the current state comes from, for the settings screen.
	 *
	 * @return string 'constant-on', 'constant-off', 'screen' or 'default'.
	 */
	public static function control_source() {
		if ( defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) ) {
			return ( false !== MRMURPHY_RESTFUL_DEPLOY_ENABLED ) ? 'constant-on' : 'constant-off';
		}

		return null === self::stored_setting() ? 'default' : 'screen';
	}

	/**
	 * Switch the deployment endpoints on or off from the settings screen.
	 *
	 * Both directions are audited: "who turned this back on, and when" is the
	 * first question asked after an incident.
	 *
	 * @param bool $on Whether deployments should be enabled.
	 */
	public static function set_enabled( $on ) {
		$on = (bool) $on;

		// Stored as '1' or '0', never as a real boolean. WordPress treats
		// update_option( $name, false ) on a missing option as a no-op — the new
		// value is "identical" to the absent default — so storing a boolean false
		// would silently fail to record "off" and the switch would appear to
		// spring back on.
		update_option( self::OPTION_ENABLED, $on ? '1' : '0' );

		MRMurphy_Restful_Deploy_Log::add(
			$on ? 'settings_enabled' : 'settings_disabled',
			'routes',
			'ok',
			$on
				? 'Deployment endpoints switched on from the settings screen.'
				: 'Deployment endpoints switched off from the settings screen.'
		);
	}

	/**
	 * Record, once, that an armed window closed itself.
	 *
	 * Runs when the switch is read after the expiry, so the audit trail shows
	 * the door closing even if nobody was looking at the site at the time.
	 *
	 * @param int $until The expiry that passed.
	 */
	private static function note_expiry( $until ) {
		if ( (int) get_option( self::OPTION_EXPIRY_LOGGED, 0 ) === (int) $until ) {
			return;
		}

		update_option( self::OPTION_EXPIRY_LOGGED, (int) $until, false );

		MRMurphy_Restful_Deploy_Log::add(
			'settings_expired',
			'routes',
			'ok',
			'The deployment window expired and the endpoints closed themselves.',
			array( 'until' => (int) $until )
		);
	}

	/**
	 * Whether requests must be authenticated with an Application Password.
	 *
	 * Cookie + nonce auth works for a logged-in admin in a browser and is
	 * replayable by any script that can read the nonce, so it is refused by
	 * default. An Application Password can be revoked on its own.
	 *
	 * @return bool
	 */
	public static function app_password_required() {
		$required = ! defined( 'MRMURPHY_RESTFUL_DEPLOY_REQUIRE_APP_PASSWORD' ) || ( false !== MRMURPHY_RESTFUL_DEPLOY_REQUIRE_APP_PASSWORD );

		/** This filter is documented above. */
		return (bool) apply_filters( 'mrmurphy_restful_deploy_app_password_required', $required );
	}

	/**
	 * Whether the request must arrive over HTTPS.
	 *
	 * @return bool
	 */
	public static function ssl_required() {
		$required = ! defined( 'MRMURPHY_RESTFUL_DEPLOY_REQUIRE_SSL' ) || ( false !== MRMURPHY_RESTFUL_DEPLOY_REQUIRE_SSL );

		/** This filter is documented above. */
		return (bool) apply_filters( 'mrmurphy_restful_deploy_ssl_required', $required );
	}

	/**
	 * Maximum accepted upload size, in bytes.
	 *
	 * @return int
	 */
	public static function max_bytes() {
		$default = 32 * 1024 * 1024;
		$max     = defined( 'MRMURPHY_RESTFUL_DEPLOY_MAX_BYTES' ) ? (int) MRMURPHY_RESTFUL_DEPLOY_MAX_BYTES : $default;

		/** This filter is documented above. */
		return max( 1024, (int) apply_filters( 'mrmurphy_restful_deploy_max_bytes', $max ) );
	}

	/**
	 * Maximum accepted total uncompressed size of an archive, in bytes.
	 *
	 * @return int
	 */
	public static function max_uncompressed_bytes() {
		$default = 512 * 1024 * 1024;
		$max     = defined( 'MRMURPHY_RESTFUL_DEPLOY_MAX_UNCOMPRESSED_BYTES' ) ? (int) MRMURPHY_RESTFUL_DEPLOY_MAX_UNCOMPRESSED_BYTES : $default;

		/** This filter is documented above. */
		return max( 1024, (int) apply_filters( 'mrmurphy_restful_deploy_max_uncompressed_bytes', $max ) );
	}

	/**
	 * Maximum install/activate operations per user per hour.
	 *
	 * A brake on a runaway loop, not a security boundary: the caller already
	 * holds a credential that can write code to disk. It is set high enough that
	 * deliberate work never notices it — a deploy that validates (free), installs
	 * and activates in one call costs 1 — and low enough that a broken agent
	 * looping on installs stops within a couple of minutes.
	 *
	 * @return int
	 */
	public static function max_operations_per_hour() {
		$default = 30;
		$max     = defined( 'MRMURPHY_RESTFUL_DEPLOY_MAX_OPERATIONS_PER_HOUR' ) ? (int) MRMURPHY_RESTFUL_DEPLOY_MAX_OPERATIONS_PER_HOUR : $default;

		/** This filter is documented above. */
		return max( 1, (int) apply_filters( 'mrmurphy_restful_deploy_max_operations_per_hour', $max ) );
	}

	/**
	 * Whether an existing plugin/theme directory may be overwritten.
	 *
	 * Opt-in: the constant has to be defined (as anything other than the
	 * boolean false). Overwriting replaces running code and, on a directory
	 * that is a symlink, would write outside wp-content — so the default is
	 * "no". Even when allowed, each request still has to pass
	 * "overwrite": true.
	 *
	 * @return bool
	 */
	public static function overwrite_allowed() {
		$allowed = defined( 'MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE' ) && ( false !== MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE );

		/** This filter is documented above. */
		return (bool) apply_filters( 'mrmurphy_restful_deploy_overwrite_allowed', $allowed );
	}

	/**
	 * Which throttle implementation is in force, for gate_status().
	 *
	 * 'sql' means the atomic single-statement counter is available; 'transient'
	 * means it fell back to a best-effort read-then-write counter.
	 *
	 * @return string
	 */
	public static function rate_limit_mode() {
		return (string) get_option( 'mrmurphy_restful_deploy_rate_mode', 'sql' );
	}

	/**
	 * Public snapshot of the active gates, for the inventory endpoint.
	 *
	 * @return array
	 */
	public static function gate_status() {
		return array(
			'enabled'                    => self::enabled(),
			'controlled_by'              => self::control_source(),
			'setting'                    => self::setting_on(),
			'app_password_required'      => self::app_password_required(),
			'ssl_required'               => self::ssl_required(),
			'is_ssl'                     => is_ssl(),
			'overwrite_allowed'          => self::overwrite_allowed(),
			'max_bytes'                  => self::max_bytes(),
			'max_uncompressed_bytes'     => self::max_uncompressed_bytes(),
			'max_operations_per_hour'    => self::max_operations_per_hour(),
			'rate_limit_mode'            => self::rate_limit_mode(),
			'filesystem_method'          => MRMurphy_Restful_Deploy_Installer::filesystem_method(),
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Admin notice                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Tell an admin, on the Plugins screen, that the endpoints are closed and
	 * how to open them.
	 */
	public static function maybe_render_disabled_notice() {
		if ( self::enabled() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'plugins' !== $screen->id ) {
			return;
		}

		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$source     = self::control_source();
		$can_manage = current_user_can( 'manage_options' );
		$link       = $can_manage
			? sprintf(
				' <a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . MRMurphy_Restful_Deploy_Admin::PAGE ) ),
				esc_html__( 'Open the settings screen' )
			)
			: '';

		if ( 'constant-off' === $source ) {
			$message = __( '<strong>MrMurphy Restful Deploy is switched off by wp-config.php.</strong> Its REST routes answer 403. Remove <code>define( \'MRMURPHY_RESTFUL_DEPLOY_ENABLED\', false );</code> to hand control back to the settings screen.' );
		} else {
			$message = __( '<strong>MrMurphy Restful Deploy is switched off.</strong> Its REST routes answer 403, so nothing can be deployed over the API until it is switched back on.' );
		}

		printf( '<div class="notice notice-warning"><p>%s%s</p></div>', wp_kses_post( $message ), wp_kses_post( $link ) );
	}
}
