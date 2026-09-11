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

/**
 * Main plugin loader.
 */
final class MRMurphy_Restful_Deploy_Plugin {

	/** @var self|null */
	private static $instance = null;

	/** @var MRMurphy_Restful_Deploy_REST */
	public $rest;

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
				'Plugin activated while disabled: MRMURPHY_RESTFUL_DEPLOY_ENABLED is not defined true in wp-config.php.'
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/*  Gates                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Master switch. The plugin does nothing at all unless this is true.
	 *
	 * Any defined value other than the boolean false enables the API, so
	 * `define( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED', 1 )` behaves the way an operator
	 * expects. Undefined stays disabled.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$enabled = defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) && ( false !== MRMURPHY_RESTFUL_DEPLOY_ENABLED );

		/**
		 * Filters whether the package API is enabled.
		 *
		 * @param bool $enabled Whether the API responds to requests.
		 */
		return (bool) apply_filters( 'mrmurphy_restful_deploy_enabled', $enabled );
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
	 * @return int
	 */
	public static function max_operations_per_hour() {
		$default = 12;
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
	 * Tell an admin, on the Plugins screen, that the API is inert.
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

		printf(
			'<div class="notice notice-warning"><p><strong>MrMurphy Restful Deploy is disabled.</strong> Its REST routes answer 403 until you add <code>define( \'MRMURPHY_RESTFUL_DEPLOY_ENABLED\', true );</code> to <code>wp-config.php</code>.</p></div>'
		);
	}
}
