<?php
/**
 * Append-only audit log for every package operation.
 *
 * Plugin and theme installs are remote code execution by design, so the only
 * way to answer "who put that file there" after the fact is a log written at
 * the moment of the action. Entries are stored in a capped option (newest
 * first) and never contain credentials.
 *
 * @package MrMurphyRestfulDeploy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Audit log.
 */
final class MRMurphy_Restful_Deploy_Log {

	/** @var string Option name holding the log. */
	const OPTION = 'mrmurphy_restful_deploy_log';

	/** @var int Maximum retained entries. */
	const MAX_ENTRIES = 200;

	/** @var string Option name holding the bounded refusal counters. */
	const REFUSAL_OPTION = 'mrmurphy_restful_deploy_refusals';

	/**
	 * Count a refusal without writing an audit entry.
	 *
	 * Refusals (gate denials, capability denials, validation failures) are
	 * counted per clock hour under a fixed set of reason codes. Counting them
	 * instead of logging them means a caller who is already being refused
	 * cannot flood — and therefore cannot evict — the capped audit log, while
	 * an operator can still see that the endpoint is being probed.
	 *
	 * @param string $reason Machine-readable reason (an error code).
	 */
	public static function count_refusal( $reason ) {
		$reason = substr( preg_replace( '/[^A-Za-z0-9_]/', '', (string) $reason ), 0, 64 );

		if ( '' === $reason ) {
			$reason = 'unknown';
		}

		$bucket = gmdate( 'YmdH' );
		$counts = self::refusals();

		if ( ! isset( $counts[ $bucket ] ) || ! is_array( $counts[ $bucket ] ) ) {
			$counts = array( $bucket => array() );
		}

		$counts[ $bucket ][ $reason ] = ( isset( $counts[ $bucket ][ $reason ] ) ? (int) $counts[ $bucket ][ $reason ] : 0 ) + 1;
		$counts[ $bucket ]['_total']  = ( isset( $counts[ $bucket ]['_total'] ) ? (int) $counts[ $bucket ]['_total'] : 0 ) + 1;

		update_option( self::REFUSAL_OPTION, $counts, false );
	}

	/**
	 * Refusal counters, keyed by hour then reason.
	 *
	 * @return array
	 */
	public static function refusals() {
		$counts = get_option( self::REFUSAL_OPTION, array() );

		return is_array( $counts ) ? $counts : array();
	}

	/**
	 * Record an operation.
	 *
	 * @param string $action  Machine-readable action, e.g. "install_plugin".
	 * @param string $target  What was acted on, e.g. "akismet/akismet.php".
	 * @param string $status  "ok" or "error".
	 * @param string $message Human-readable detail.
	 * @param array  $context Optional extra structure (sizes, detected type, ...).
	 * @return array The stored entry.
	 */
	public static function add( $action, $target, $status, $message = '', $context = array() ) {
		$user = wp_get_current_user();

		$entry = array(
			'time'       => gmdate( 'c' ),
			'action'     => (string) $action,
			'target'     => (string) $target,
			'status'     => (string) $status,
			'message'    => (string) $message,
			'user_id'    => (int) get_current_user_id(),
			'user_login' => $user && $user->exists() ? (string) $user->user_login : '',
			'auth'       => self::auth_method(),
			'auth_uuid'  => self::auth_uuid(),
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 200 ) : '',
			'context'    => is_array( $context ) ? $context : array(),
		);

		$log = self::all();
		array_unshift( $log, $entry );

		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_ENTRIES );
		}

		update_option( self::OPTION, $log, false );

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log(
				sprintf(
					'[mrmurphy-restful-deploy] %s action=%s target=%s user=%s message=%s',
					$status,
					$entry['action'],
					$entry['target'],
					$entry['user_login'] ? $entry['user_login'] : '(none)',
					$entry['message']
				)
			);
		}

		return $entry;
	}

	/**
	 * All retained entries, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		$log = get_option( self::OPTION, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * How the current request was authenticated.
	 *
	 * @return string
	 */
	public static function auth_method() {
		if ( function_exists( 'rest_get_authenticated_app_password' ) && rest_get_authenticated_app_password() ) {
			return 'application_password';
		}

		if ( is_user_logged_in() ) {
			return 'cookie';
		}

		return 'none';
	}

	/**
	 * Which application password was used, so a leaked one can be identified
	 * and revoked without guessing.
	 *
	 * @return string
	 */
	public static function auth_uuid() {
		if ( ! function_exists( 'rest_get_authenticated_app_password' ) ) {
			return '';
		}

		$uuid = rest_get_authenticated_app_password();

		return $uuid ? (string) $uuid : '';
	}
}
