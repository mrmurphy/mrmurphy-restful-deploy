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

	/** @var string Option holding whether the deployment endpoints are armed. */
	const OPTION_ENABLED = 'mrmurphy_restful_deploy_enabled';

	/** @var string Option holding the expiry of an armed window (0 = none). */
	const OPTION_UNTIL = 'mrmurphy_restful_deploy_until';

	/** @var string Bookkeeping, so an expiry is logged exactly once. */
	const OPTION_EXPIRY_LOGGED = 'mrmurphy_restful_deploy_expiry_logged';

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
				'Plugin activated while the deployment endpoints are closed: no MRMURPHY_RESTFUL_DEPLOY_ENABLED constant and no armed window. Open them on Settings → Restful Deploy when you need them.'
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
	 *   1. `MRMURPHY_RESTFUL_DEPLOY_ENABLED` in wp-config.php, if it is defined
	 *      at all — true forces the endpoints on, and false forces them OFF even
	 *      if the settings screen has armed them.
	 *   2. the settings-screen toggle, which can carry an expiry so a deployment
	 *      window closes itself.
	 *   3. otherwise: off.
	 *
	 * @return bool
	 */
	public static function enabled() {
		if ( defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) ) {
			$enabled = ( false !== MRMURPHY_RESTFUL_DEPLOY_ENABLED );
		} else {
			$enabled = self::toggle_on();
		}

		/**
		 * Filters whether the deployment API is enabled.
		 *
		 * @param bool $enabled Whether the API responds to requests.
		 */
		return (bool) apply_filters( 'mrmurphy_restful_deploy_enabled', $enabled );
	}

	/**
	 * Is the settings-screen toggle armed, and still inside its window?
	 *
	 * @return bool
	 */
	public static function toggle_on() {
		if ( ! get_option( self::OPTION_ENABLED, false ) ) {
			return false;
		}

		$until = self::toggle_expires();

		if ( $until > 0 && time() >= $until ) {
			self::note_expiry( $until );

			return false;
		}

		return true;
	}

	/**
	 * When an armed window closes itself, as a Unix timestamp. 0 means it stays
	 * armed until someone turns it off.
	 *
	 * @return int
	 */
	public static function toggle_expires() {
		return (int) get_option( self::OPTION_UNTIL, 0 );
	}

	/**
	 * Where the current state comes from, for the settings screen.
	 *
	 * @return string 'constant-on', 'constant-off', 'toggle' or 'default'.
	 */
	public static function control_source() {
		if ( defined( 'MRMURPHY_RESTFUL_DEPLOY_ENABLED' ) ) {
			return ( false !== MRMURPHY_RESTFUL_DEPLOY_ENABLED ) ? 'constant-on' : 'constant-off';
		}

		return get_option( self::OPTION_ENABLED, false ) ? 'toggle' : 'default';
	}

	/**
	 * Arm the deployment endpoints.
	 *
	 * @param int $minutes Minutes until it disarms itself; 0 to stay armed.
	 * @return int Expiry timestamp, or 0 when it stays armed.
	 */
	public static function arm( $minutes = 0 ) {
		$minutes = max( 0, (int) $minutes );
		$until   = $minutes > 0 ? time() + ( $minutes * MINUTE_IN_SECONDS ) : 0;

		update_option( self::OPTION_ENABLED, true, false );
		update_option( self::OPTION_UNTIL, $until, false );

		MRMurphy_Restful_Deploy_Log::add(
			'settings_arm',
			'routes',
			'ok',
			$until
				? sprintf( 'Deployment endpoints armed for %d minutes.', $minutes )
				: 'Deployment endpoints armed until they are turned off.',
			array( 'until' => $until )
		);

		return $until;
	}

	/**
	 * Disarm the deployment endpoints.
	 *
	 * @param string $reason 'manual' or 'expired'.
	 */
	public static function disarm( $reason = 'manual' ) {
		update_option( self::OPTION_ENABLED, false, false );
		update_option( self::OPTION_UNTIL, 0, false );

		MRMurphy_Restful_Deploy_Log::add(
			'settings_disarm',
			'routes',
			'ok',
			'manual' === $reason
				? 'Deployment endpoints disarmed from the settings screen.'
				: 'Deployment endpoints disarmed.',
			array( 'reason' => $reason )
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
			'controlled_by'              => self::control_source(),
			'armed_until'                => self::toggle_expires(),
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
			$message = __( '<strong>MrMurphy Restful Deploy is switched off by wp-config.php.</strong> Its REST routes answer 403. Remove <code>define( \'MRMURPHY_RESTFUL_DEPLOY_ENABLED\', false );</code> to let the settings screen control it.' );
		} elseif ( 'default' === $source ) {
			$message = __( '<strong>MrMurphy Restful Deploy is not armed.</strong> Its REST routes answer 403. Arm it from the settings screen, or define <code>MRMURPHY_RESTFUL_DEPLOY_ENABLED</code> as true in <code>wp-config.php</code>.' );
		} else {
			$message = __( '<strong>MrMurphy Restful Deploy closed itself.</strong> Its deployment window expired and its REST routes answer 403 again.' );
		}

		printf( '<div class="notice notice-warning"><p>%s%s</p></div>', wp_kses_post( $message ), wp_kses_post( $link ) );
	}
}
