<?php
/**
 * Settings screen: arm and disarm the deployment endpoints, and see what the
 * gates are currently doing.
 *
 * The API deliberately cannot arm itself. Enabling is a deliberate act by a
 * human on a screen that requires manage_options, and every arm, disarm and
 * expiry lands in the audit log.
 *
 * @package MrMurphyRestfulDeploy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screen.
 */
final class MRMurphy_Restful_Deploy_Admin {

	/** @var string Settings page slug. */
	const PAGE = 'mrmurphy-restful-deploy';

	/** @var string Nonce action for the arm/disarm form. */
	const NONCE = 'mrmurphy_restful_deploy_save';

	/**
	 * Arm windows offered on the screen, in minutes. 0 = until disarmed.
	 * An allowlist, so the form cannot ask for an arbitrary window.
	 *
	 * @var int[]
	 */
	private static $windows = array( 15, 30, 60, 240, 0 );

	/**
	 * Hook the screen.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_post_mrmurphy_restful_deploy_save', array( $this, 'handle_save' ) );
	}

	/**
	 * Register the settings page.
	 */
	public function add_page() {
		add_options_page(
			__( 'MrMurphy Restful Deploy', 'mrmurphy-restful-deploy' ),
			__( 'Restful Deploy', 'mrmurphy-restful-deploy' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Who may see and change this screen.
	 *
	 * @return bool
	 */
	private function can_manage() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return false;
		}

		if ( is_multisite() && ! current_user_can( 'manage_network_plugins' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Handle the arm/disarm form.
	 */
	public function handle_save() {
		if ( ! $this->can_manage() ) {
			wp_die(
				esc_html__( 'You are not allowed to change this setting.', 'mrmurphy-restful-deploy' ),
				esc_html__( 'Forbidden', 'mrmurphy-restful-deploy' ),
				array( 'response' => 403 )
			);
		}

		check_admin_referer( self::NONCE );

		$intent = isset( $_POST['intent'] ) ? sanitize_key( wp_unslash( $_POST['intent'] ) ) : '';

		if ( 'arm' === $intent ) {
			$minutes = isset( $_POST['minutes'] ) ? absint( wp_unslash( $_POST['minutes'] ) ) : 0;

			if ( ! in_array( $minutes, self::$windows, true ) ) {
				$minutes = 30;
			}

			MRMurphy_Restful_Deploy_Plugin::arm( $minutes );
		} elseif ( 'disarm' === $intent ) {
			MRMurphy_Restful_Deploy_Plugin::disarm();
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * Human-readable label for the current control source.
	 *
	 * @param string $source Value from control_source().
	 * @return string
	 */
	private function source_label( $source ) {
		switch ( $source ) {
			case 'constant-on':
				return __( 'wp-config.php — forced ON by MRMURPHY_RESTFUL_DEPLOY_ENABLED', 'mrmurphy-restful-deploy' );
			case 'constant-off':
				return __( 'wp-config.php — forced OFF by MRMURPHY_RESTFUL_DEPLOY_ENABLED', 'mrmurphy-restful-deploy' );
			case 'toggle':
				return __( 'This screen', 'mrmurphy-restful-deploy' );
			default:
				return __( 'Nobody — the endpoints have never been armed', 'mrmurphy-restful-deploy' );
		}
	}

	/**
	 * Render the screen.
	 */
	public function render() {
		if ( ! $this->can_manage() ) {
			wp_die(
				esc_html__( 'You are not allowed to view this screen.', 'mrmurphy-restful-deploy' ),
				esc_html__( 'Forbidden', 'mrmurphy-restful-deploy' ),
				array( 'response' => 403 )
			);
		}

		$enabled      = MRMurphy_Restful_Deploy_Plugin::enabled();
		$source       = MRMurphy_Restful_Deploy_Plugin::control_source();
		$until        = MRMurphy_Restful_Deploy_Plugin::toggle_expires();
		$gates        = MRMurphy_Restful_Deploy_Plugin::gate_status();
		$entries      = array_slice( MRMurphy_Restful_Deploy_Log::all(), 0, 10 );
		$refusals     = MRMurphy_Restful_Deploy_Log::refusals();
		$hour         = gmdate( 'YmdH' );
		$counted      = isset( $refusals[ $hour ] ) && is_array( $refusals[ $hour ] ) ? $refusals[ $hour ] : array();
		$base         = rest_url( MRMURPHY_RESTFUL_DEPLOY_NAMESPACE );
		$source_label = $this->source_label( $source );

		// "This screen" + closed can only mean one thing, and it is worth saying.
		if ( 'toggle' === $source && ! $enabled ) {
			$source_label .= ' — ' . __( 'the last window expired', 'mrmurphy-restful-deploy' );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'MrMurphy Restful Deploy', 'mrmurphy-restful-deploy' ); ?></h1>
			<p style="max-width:60em">
				<?php esc_html_e( 'Deploy plugin and theme ZIPs to this site over the REST API — no SFTP, no SSH. This screen arms and disarms those endpoints; the API cannot arm itself.', 'mrmurphy-restful-deploy' ); ?>
			</p>

			<h2><?php esc_html_e( 'Status', 'mrmurphy-restful-deploy' ); ?></h2>
			<table class="widefat striped" style="max-width:60em">
				<tbody>
					<tr>
						<th style="width:14em"><?php esc_html_e( 'Endpoints', 'mrmurphy-restful-deploy' ); ?></th>
						<td>
							<?php if ( $enabled ) : ?>
								<strong style="color:#008a20"><?php esc_html_e( 'ARMED', 'mrmurphy-restful-deploy' ); ?></strong>
							<?php else : ?>
								<strong><?php esc_html_e( 'CLOSED', 'mrmurphy-restful-deploy' ); ?></strong>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Controlled by', 'mrmurphy-restful-deploy' ); ?></th>
						<td><?php echo esc_html( $source_label ); ?></td>
					</tr>
					<?php if ( $enabled && $until > 0 ) : ?>
						<tr>
							<th><?php esc_html_e( 'Closes itself', 'mrmurphy-restful-deploy' ); ?></th>
							<td>
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: date and time, 2: minutes remaining. */
										__( '%1$s — in %2$d minutes', 'mrmurphy-restful-deploy' ),
										wp_date( 'Y-m-d H:i:s', $until ),
										max( 0, (int) round( ( $until - time() ) / 60 ) )
									)
								);
								?>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th><?php esc_html_e( 'Base URL', 'mrmurphy-restful-deploy' ); ?></th>
						<td><code><?php echo esc_html( $base ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<?php if ( 'constant-on' === $source || 'constant-off' === $source ) : ?>
				<div class="notice notice-info inline" style="max-width:60em">
					<p>
						<?php
						printf(
							/* translators: %s: true or false. */
							esc_html__( 'wp-config.php is in charge: MRMURPHY_RESTFUL_DEPLOY_ENABLED is defined as %s. Remove that line if you would rather control the endpoints from this screen.', 'mrmurphy-restful-deploy' ),
							'<code>' . esc_html( 'constant-on' === $source ? 'true' : 'false' ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="mrmurphy_restful_deploy_save" />
					<?php wp_nonce_field( self::NONCE ); ?>
					<?php if ( $enabled ) : ?>
						<input type="hidden" name="intent" value="disarm" />
						<p>
							<?php submit_button( __( 'Disarm now', 'mrmurphy-restful-deploy' ), 'delete', 'submit', false ); ?>
							<span class="description"><?php esc_html_e( 'Closes the endpoints immediately. Nothing can be installed until they are armed again.', 'mrmurphy-restful-deploy' ); ?></span>
						</p>
					<?php else : ?>
						<input type="hidden" name="intent" value="arm" />
						<p>
							<label for="mrmurphy-minutes"><?php esc_html_e( 'Arm the endpoints for', 'mrmurphy-restful-deploy' ); ?></label>
							<select id="mrmurphy-minutes" name="minutes">
								<?php foreach ( self::$windows as $minutes ) : ?>
									<option value="<?php echo esc_attr( (string) $minutes ); ?>" <?php selected( 30, $minutes ); ?>>
										<?php
										echo esc_html(
											0 === $minutes
												? __( 'until I disarm them', 'mrmurphy-restful-deploy' )
												/* translators: %d: minutes. */
												: sprintf( _n( '%d minute', '%d minutes', $minutes, 'mrmurphy-restful-deploy' ), $minutes )
										);
										?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php submit_button( __( 'Arm endpoints', 'mrmurphy-restful-deploy' ), 'primary', 'submit', false ); ?>
						</p>
						<p class="description" style="max-width:60em">
							<?php esc_html_e( 'While armed, anyone with an administrator’s Application Password can install, activate, overwrite and delete plugins and themes on this site. A short window is usually all a deployment needs.', 'mrmurphy-restful-deploy' ); ?>
						</p>
					<?php endif; ?>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Gates', 'mrmurphy-restful-deploy' ); ?></h2>
			<table class="widefat striped" style="max-width:60em">
				<tbody>
					<tr>
						<th style="width:14em"><?php esc_html_e( 'Application password', 'mrmurphy-restful-deploy' ); ?></th>
						<td>
							<?php
							echo $gates['app_password_required']
								? esc_html__( 'Required — cookie + nonce sessions are refused', 'mrmurphy-restful-deploy' )
								: esc_html__( 'NOT required (overridden)', 'mrmurphy-restful-deploy' );
							?>
							<a href="<?php echo esc_url( admin_url( 'profile.php#application-passwords-section' ) ); ?>"><?php esc_html_e( 'Manage yours', 'mrmurphy-restful-deploy' ); ?></a>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'HTTPS', 'mrmurphy-restful-deploy' ); ?></th>
						<td>
							<?php
							echo $gates['ssl_required']
								? esc_html__( 'Required', 'mrmurphy-restful-deploy' )
								: esc_html__( 'NOT required (overridden)', 'mrmurphy-restful-deploy' );
							echo $gates['is_ssl'] ? ' — ' . esc_html__( 'this request is secure', 'mrmurphy-restful-deploy' ) : ' — ' . esc_html__( 'this request is not secure', 'mrmurphy-restful-deploy' );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Overwriting', 'mrmurphy-restful-deploy' ); ?></th>
						<td>
							<?php
							echo $gates['overwrite_allowed']
								? esc_html__( 'Allowed (MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE is set)', 'mrmurphy-restful-deploy' )
								: esc_html__( 'Refused — overwriting needs MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE', 'mrmurphy-restful-deploy' );
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Upload limit', 'mrmurphy-restful-deploy' ); ?></th>
						<td><?php echo esc_html( size_format( (int) $gates['max_bytes'] ) ); ?></td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Hourly throttle', 'mrmurphy-restful-deploy' ); ?></th>
						<td>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: number of operations, 2: counter implementation. */
									__( '%1$d operations per user per hour (%2$s counter)', 'mrmurphy-restful-deploy' ),
									(int) $gates['max_operations_per_hour'],
									$gates['rate_limit_mode']
								)
							);
							?>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Filesystem', 'mrmurphy-restful-deploy' ); ?></th>
						<td><code><?php echo esc_html( (string) $gates['filesystem_method'] ); ?></code></td>
					</tr>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Endpoints', 'mrmurphy-restful-deploy' ); ?></h2>
			<table class="widefat striped" style="max-width:60em">
				<thead>
					<tr>
						<th style="width:6em"><?php esc_html_e( 'Method', 'mrmurphy-restful-deploy' ); ?></th>
						<th style="width:12em"><?php esc_html_e( 'Route', 'mrmurphy-restful-deploy' ); ?></th>
						<th><?php esc_html_e( 'Purpose', 'mrmurphy-restful-deploy' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$routes = array(
						array( 'GET', '/inventory', __( 'Installed plugins and themes, plus the gates above', 'mrmurphy-restful-deploy' ) ),
						array( 'GET', '/log', __( 'Audit log and refusal counters', 'mrmurphy-restful-deploy' ) ),
						array( 'POST', '/plugins', __( 'Install a plugin ZIP, optionally activate it', 'mrmurphy-restful-deploy' ) ),
						array( 'POST', '/plugins/activate', __( 'Activate an installed plugin', 'mrmurphy-restful-deploy' ) ),
						array( 'POST', '/plugins/deactivate', __( 'Deactivate an installed plugin', 'mrmurphy-restful-deploy' ) ),
						array( 'DELETE', '/plugins', __( 'Uninstall a plugin', 'mrmurphy-restful-deploy' ) ),
						array( 'POST', '/themes', __( 'Install a theme ZIP, optionally activate it', 'mrmurphy-restful-deploy' ) ),
						array( 'POST', '/themes/activate', __( 'Switch the active theme', 'mrmurphy-restful-deploy' ) ),
						array( 'DELETE', '/themes', __( 'Uninstall a theme', 'mrmurphy-restful-deploy' ) ),
					);

					foreach ( $routes as $route ) {
						printf(
							'<tr><td><code>%s</code></td><td><code>%s</code></td><td>%s</td></tr>',
							esc_html( $route[0] ),
							esc_html( $route[1] ),
							esc_html( $route[2] )
						);
					}
					?>
				</tbody>
			</table>

			<h2><?php esc_html_e( 'Recent activity', 'mrmurphy-restful-deploy' ); ?></h2>
			<?php if ( $entries ) : ?>
				<table class="widefat striped" style="max-width:60em">
					<thead>
						<tr>
							<th style="width:11em"><?php esc_html_e( 'When (UTC)', 'mrmurphy-restful-deploy' ); ?></th>
							<th style="width:11em"><?php esc_html_e( 'Action', 'mrmurphy-restful-deploy' ); ?></th>
							<th style="width:9em"><?php esc_html_e( 'Who', 'mrmurphy-restful-deploy' ); ?></th>
							<th><?php esc_html_e( 'What', 'mrmurphy-restful-deploy' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( isset( $entry['time'] ) ? (string) $entry['time'] : '' ); ?></td>
								<td><code><?php echo esc_html( isset( $entry['action'] ) ? (string) $entry['action'] : '' ); ?></code></td>
								<td><?php echo esc_html( isset( $entry['user_login'] ) ? (string) $entry['user_login'] : '' ); ?></td>
								<td>
									<?php echo esc_html( isset( $entry['target'] ) ? (string) $entry['target'] : '' ); ?>
									<?php if ( ! empty( $entry['message'] ) ) : ?>
										— <?php echo esc_html( (string) $entry['message'] ); ?>
									<?php endif; ?>
									<?php if ( isset( $entry['status'] ) && 'ok' !== $entry['status'] ) : ?>
										<strong style="color:#b32d2e"><?php echo esc_html( (string) $entry['status'] ); ?></strong>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Nothing yet.', 'mrmurphy-restful-deploy' ); ?></p>
			<?php endif; ?>

			<?php if ( $counted ) : ?>
				<h3><?php esc_html_e( 'Refused requests this hour', 'mrmurphy-restful-deploy' ); ?></h3>
				<p>
					<?php
					$parts = array();

					foreach ( $counted as $code => $count ) {
						$parts[] = sprintf( '%s: %d', '_total' === $code ? 'total' : $code, (int) $count );
					}

					echo esc_html( implode( ' · ', $parts ) );
					?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Using it from an agent', 'mrmurphy-restful-deploy' ); ?></h2>
			<p style="max-width:60em">
				<?php
				printf(
					/* translators: %s: route path. */
					esc_html__( 'Give your agent the base URL above, an Application Password, and the instructions file that ships with the plugin (AGENT-INSTRUCTIONS.md in the repository). It should start with a GET %s so it can see the gates and what is already installed.', 'mrmurphy-restful-deploy' ),
					'<code>' . esc_html( '/inventory' ) . '</code>'
				);
				?>
			</p>
			<p style="max-width:60em">
				<?php esc_html_e( 'There is no route that arms these endpoints — that is deliberate, so a leaked Application Password cannot open the door for itself.', 'mrmurphy-restful-deploy' ); ?>
			</p>
		</div>
		<?php
	}
}
