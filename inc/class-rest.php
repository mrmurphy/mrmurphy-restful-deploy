<?php
/**
 * REST routes for package management.
 *
 * Namespace: mrmurphy-restful-deploy/v1
 *
 *   GET    /inventory          Installed plugins and themes, plus active gates.
 *   GET    /log                Audit log, newest first.
 *   POST   /plugins            Install a plugin ZIP (base64 or multipart), optionally activate.
 *   POST   /plugins/activate   Activate an installed plugin.
 *   POST   /plugins/deactivate Deactivate an installed plugin.
 *   DELETE /plugins            Uninstall a plugin (optionally deactivating it first).
 *   POST   /themes             Install a theme ZIP, optionally activate.
 *   POST   /themes/activate    Switch the active theme.
 *   DELETE /themes             Uninstall a theme.
 *
 * Installing from the WordPress.org directory by slug is already core:
 * POST /wp/v2/plugins with {"slug":"akismet","status":"active"}.
 *
 * @package MrMurphyRestfulDeploy
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST controller.
 */
final class MRMurphy_Restful_Deploy_REST {

	/** @var string Route namespace. */
	private $namespace = MRMURPHY_RESTFUL_DEPLOY_NAMESPACE;

	/**
	 * Hook the routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( $this, 'count_failed_response' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ */
	/*  Routing                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Register every route.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/inventory',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_inventory' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/log',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_log' ),
					'permission_callback' => array( $this, 'check_read_permission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/plugins',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_plugin' ),
					'permission_callback' => array( $this, 'check_plugin_permission' ),
					'args'                => $this->package_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'uninstall_plugin' ),
					'permission_callback' => array( $this, 'check_plugin_delete_permission' ),
					'args'                => array(
						'plugin'     => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Plugin file relative to wp-content/plugins, e.g. "akismet/akismet.php".', 'mrmurphy-restful-deploy' ),
						),
						'deactivate' => array(
							'required'    => false,
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Deactivate the plugin first when it is active. Refused without this.', 'mrmurphy-restful-deploy' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/plugins/activate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate_plugin' ),
					'permission_callback' => array( $this, 'check_plugin_permission' ),
					'args'                => array(
						'plugin'       => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Plugin file relative to wp-content/plugins, e.g. "akismet/akismet.php".', 'mrmurphy-restful-deploy' ),
						),
						'network_wide' => array(
							'required'    => false,
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Multisite only: activate for the whole network.', 'mrmurphy-restful-deploy' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/plugins/deactivate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'deactivate_plugin' ),
					'permission_callback' => array( $this, 'check_plugin_permission' ),
					'args'                => array(
						'plugin' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Plugin file relative to wp-content/plugins, e.g. "akismet/akismet.php".', 'mrmurphy-restful-deploy' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/themes',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_theme' ),
					'permission_callback' => array( $this, 'check_theme_permission' ),
					'args'                => $this->package_args(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'uninstall_theme' ),
					'permission_callback' => array( $this, 'check_theme_delete_permission' ),
					'args'                => array(
						'stylesheet' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Theme directory name, e.g. "twentytwentyfive".', 'mrmurphy-restful-deploy' ),
						),
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/themes/activate',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'activate_theme' ),
					'permission_callback' => array( $this, 'check_theme_permission' ),
					'args'                => array(
						'stylesheet' => array(
							'required'    => true,
							'type'        => 'string',
							'description' => __( 'Theme directory name, e.g. "twentytwentyfive".', 'mrmurphy-restful-deploy' ),
						),
					),
				),
			)
		);
	}

	/**
	 * Shared arguments for the two install endpoints.
	 *
	 * @return array
	 */
	private function package_args() {
		return array(
			'zip_base64' => array(
				'required'    => false,
				'type'        => 'string',
				'description' => __( 'The package ZIP, base64 encoded (a data: URL is accepted too).', 'mrmurphy-restful-deploy' ),
			),
			'filename'   => array(
				'required'    => false,
				'type'        => 'string',
				'description' => __( 'Optional original filename, used in messages only.', 'mrmurphy-restful-deploy' ),
			),
			'activate'   => array(
				'required'    => false,
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Activate the package right after installing it.', 'mrmurphy-restful-deploy' ),
			),
			'overwrite'  => array(
				'required'    => false,
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Replace an existing directory of the same name. Off by default, and additionally requires MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE in wp-config.php; refused for an active package unless activate is also true.', 'mrmurphy-restful-deploy' ),
			),
			'dry_run'    => array(
				'required'    => false,
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Validate and describe the archive without installing anything.', 'mrmurphy-restful-deploy' ),
			),
			'network_wide' => array(
				'required'    => false,
				'type'        => 'boolean',
				'default'     => false,
				'description' => __( 'Multisite only: activate the installed plugin for the whole network.', 'mrmurphy-restful-deploy' ),
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Permission callbacks                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Gates shared by every route, applied before any capability check.
	 *
	 * @param WP_REST_Request|null $request Current request.
	 * @return true|WP_Error
	 */
	private function check_common_gates( $request = null ) {
		if ( ! MRMurphy_Restful_Deploy_Plugin::enabled() ) {
			// An anonymous caller learns nothing beyond "no such route", so the
			// endpoint cannot be fingerprinted from outside; a logged-in admin
			// gets the message that tells them how to switch it on.
			if ( ! is_user_logged_in() ) {
				return new WP_Error(
					'rest_no_route',
					'No route was found matching the URL and request method.',
					array( 'status' => 404 )
				);
			}

			return $this->refuse(
				'mrmurphy_restful_deploy_disabled',
				'This API is disabled. Define MRMURPHY_RESTFUL_DEPLOY_ENABLED as true in wp-config.php to enable it.',
				403
			);
		}

		if ( ! is_user_logged_in() ) {
			return $this->refuse( 'mrmurphy_restful_deploy_not_authenticated', 'You must authenticate to use this API.', 401 );
		}

		if ( MRMurphy_Restful_Deploy_Plugin::ssl_required() && ! is_ssl() ) {
			return $this->refuse(
				'mrmurphy_restful_deploy_requires_ssl',
				'Package installs are only accepted over HTTPS. Define MRMURPHY_RESTFUL_DEPLOY_REQUIRE_SSL as false to override.',
				403
			);
		}

		if ( MRMurphy_Restful_Deploy_Plugin::app_password_required() ) {
			$uuid    = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
			$present = $this->request_used_basic_auth( $request );

			// Both halves are needed. Core records the application password in
			// a process-wide global, which stays set for every later request
			// served by the same PHP worker — so the global alone would let a
			// cookie-only request ride on an earlier request's app password.
			if ( ! $uuid || ! $present ) {
				return $this->refuse(
					'mrmurphy_restful_deploy_requires_application_password',
					'This API only accepts Application Password authentication (Basic auth), because a cookie + nonce session can be replayed by any script that can read the nonce. Create one under Users → Profile → Application Passwords.',
					403
				);
			}
		}

		return true;
	}

	/**
	 * Did THIS request carry Basic credentials?
	 *
	 * Core authenticates application passwords from the Authorization header or
	 * from PHP_AUTH_USER; if neither is present on the current request, it
	 * cannot have been authenticated with an application password.
	 *
	 * @param WP_REST_Request|null $request Current request.
	 * @return bool
	 */
	private function request_used_basic_auth( $request ) {
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			return true;
		}

		if ( ! $request instanceof WP_REST_Request ) {
			return false;
		}

		return 0 === stripos( ltrim( (string) $request->get_header( 'authorization' ) ), 'basic ' );
	}

	/**
	 * Build a gate-refusal error and count it.
	 *
	 * Refusals are counted rather than logged one-per-request: an operator
	 * still gets to see that the endpoint is being probed, but a caller who is
	 * already refused cannot push entries through a capped audit log.
	 *
	 * @param string $code    Error code.
	 * @param string $message Message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private function refuse( $code, $message, $status ) {
		MRMurphy_Restful_Deploy_Log::count_refusal( $code );

		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Count every failed response on this namespace, for authenticated callers.
	 *
	 * Hooked to rest_post_dispatch so validation failures, capability refusals
	 * and gate refusals are all covered without touching each callback.
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public function count_failed_response( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! is_user_logged_in() ) {
			return $response;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/' . $this->namespace ) ) {
			return $response;
		}

		if ( $response->get_status() < 400 ) {
			return $response;
		}

		$data = $response->get_data();
		$code = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : (string) $response->get_status();

		MRMurphy_Restful_Deploy_Log::count_refusal( $code );

		return $response;
	}

	/**
	 * Read-only routes: any plugin manager.
	 *
	 * @return true|WP_Error
	 */
	public function check_read_permission( $request = null ) {
		$gates = $this->check_common_gates( $request );

		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		if ( ! current_user_can( 'install_plugins' ) ) {
			return $this->refuse( 'rest_cannot_view_plugins', 'Sorry, you are not allowed to manage plugins for this site.', rest_authorization_required_code() );
		}

		if ( is_multisite() && ! current_user_can( 'manage_network_plugins' ) ) {
			return $this->refuse( 'mrmurphy_restful_deploy_requires_network_admin', 'On multisite, package operations are restricted to network administrators.', rest_authorization_required_code() );
		}

		return true;
	}

	/**
	 * Plugin routes: install + activate, and network-wide on multisite.
	 *
	 * @return true|WP_Error
	 */
	public function check_plugin_permission( $request = null ) {
		$gates = $this->check_common_gates( $request );

		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
			return $this->refuse( 'rest_cannot_manage_plugins', 'Sorry, you are not allowed to install and activate plugins for this site.', rest_authorization_required_code() );
		}

		if ( is_multisite() && ! current_user_can( 'manage_network_plugins' ) ) {
			return $this->refuse( 'mrmurphy_restful_deploy_requires_network_admin', 'On multisite, package operations are restricted to network administrators.', rest_authorization_required_code() );
		}

		return true;
	}

	/**
	 * Theme routes: install + switch.
	 *
	 * @return true|WP_Error
	 */
	public function check_theme_permission( $request = null ) {
		$gates = $this->check_common_gates( $request );

		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		if ( ! current_user_can( 'install_themes' ) || ! current_user_can( 'switch_themes' ) ) {
			return $this->refuse( 'rest_cannot_manage_themes', 'Sorry, you are not allowed to install and switch themes for this site.', rest_authorization_required_code() );
		}

		if ( is_multisite() && ! current_user_can( 'manage_network_themes' ) ) {
			return $this->refuse( 'mrmurphy_restful_deploy_requires_network_admin', 'On multisite, package operations are restricted to network administrators.', rest_authorization_required_code() );
		}

		return true;
	}

	/**
	 * Plugin deletion: deactivation plus delete_plugins, matching what core's
	 * own DELETE /wp/v2/plugins/<plugin> requires.
	 *
	 * @return true|WP_Error
	 */
	public function check_plugin_delete_permission( $request = null ) {
		$gates = $this->check_common_gates( $request );

		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		if ( ! current_user_can( 'activate_plugins' ) || ! current_user_can( 'delete_plugins' ) ) {
			return $this->refuse( 'rest_cannot_manage_plugins', 'Sorry, you are not allowed to delete plugins for this site.', rest_authorization_required_code() );
		}

		if ( is_multisite() && ! current_user_can( 'manage_network_plugins' ) ) {
			return $this->refuse( 'mrmurphy_restful_deploy_requires_network_admin', 'On multisite, package operations are restricted to network administrators.', rest_authorization_required_code() );
		}

		return true;
	}

	/**
	 * Theme deletion: switch_themes plus delete_themes.
	 *
	 * @return true|WP_Error
	 */
	public function check_theme_delete_permission( $request = null ) {
		$gates = $this->check_common_gates( $request );

		if ( is_wp_error( $gates ) ) {
			return $gates;
		}

		if ( ! current_user_can( 'switch_themes' ) || ! current_user_can( 'delete_themes' ) ) {
			return $this->refuse( 'rest_cannot_manage_themes', 'Sorry, you are not allowed to delete themes for this site.', rest_authorization_required_code() );
		}

		if ( is_multisite() && ! current_user_can( 'manage_network_themes' ) ) {
			return $this->refuse( 'mrmurphy_restful_deploy_requires_network_admin', 'On multisite, package operations are restricted to network administrators.', rest_authorization_required_code() );
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/*  Read routes                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Installed plugins and themes.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_inventory() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$update_plugins = get_site_transient( 'update_plugins' );
		$plugins        = array();

		foreach ( get_plugins() as $file => $data ) {
			$new_version = null;

			if ( is_object( $update_plugins ) && isset( $update_plugins->response[ $file ]->new_version ) ) {
				$new_version = (string) $update_plugins->response[ $file ]->new_version;
			}

			$plugins[] = array(
				'plugin'           => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'status'           => is_plugin_active_for_network( $file ) ? 'network-active' : ( is_plugin_active( $file ) ? 'active' : 'inactive' ),
				'network_only'     => is_network_only_plugin( $file ),
				'update_available' => $new_version,
			);
		}

		usort(
			$plugins,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$theme_updates = get_site_transient( 'update_themes' );
		$themes        = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$new_version = null;

			if ( is_object( $theme_updates ) && isset( $theme_updates->response[ $stylesheet ]['new_version'] ) ) {
				$new_version = (string) $theme_updates->response[ $stylesheet ]['new_version'];
			}

			$errors = $theme->errors();

			$themes[] = array(
				'stylesheet'       => $stylesheet,
				'name'             => $theme->get( 'Name' ),
				'version'          => $theme->get( 'Version' ),
				'status'           => get_stylesheet() === $stylesheet ? 'active' : 'inactive',
				'parent'           => $theme->get( 'Template' ) ? $theme->get_template() : null,
				'update_available' => $new_version,
				'errors'           => is_wp_error( $errors ) && $errors->has_errors() ? array( 'code' => $errors->get_error_code(), 'message' => wp_strip_all_tags( $errors->get_error_message() ) ) : null,
			);
		}

		usort(
			$themes,
			static function ( $a, $b ) {
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return rest_ensure_response(
			array(
				'gates'     => MRMurphy_Restful_Deploy_Plugin::gate_status(),
				'wordpress' => array(
					'version' => get_bloginfo( 'version' ),
					'php'     => PHP_VERSION,
					'multisite' => is_multisite(),
				),
				'plugin_count' => count( $plugins ),
				'theme_count'  => count( $themes ),
				'plugins'      => $plugins,
				'themes'       => $themes,
			)
		);
	}

	/**
	 * Audit log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_log( $request ) {
		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit > 0 ? min( $limit, MRMurphy_Restful_Deploy_Log::MAX_ENTRIES ) : 50;

		$log = MRMurphy_Restful_Deploy_Log::all();

		return rest_ensure_response(
			array(
				'total'     => count( $log ),
				'entries'   => array_slice( $log, 0, $limit ),
				'refusals'  => MRMurphy_Restful_Deploy_Log::refusals(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Write routes                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * Install (and optionally activate) a plugin package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_plugin( $request ) {
		$rate = $this->enforce_rate_limit();

		if ( is_wp_error( $rate ) ) {
			return $this->fail( 'install_plugin', '', $rate );
		}

		$started   = microtime( true );
		$installer = new MRMurphy_Restful_Deploy_Installer();

		try {
			$staged = $this->stage_package( $installer, $request );

			if ( is_wp_error( $staged ) ) {
				return $this->fail( 'install_plugin', '', $staged );
			}

			$info = $installer->inspect_zip( $staged['path'], 'plugin' );

			if ( is_wp_error( $info ) ) {
				return $this->fail( 'install_plugin', $staged['filename'], $info, array( 'bytes' => $staged['bytes'] ) );
			}

			if ( $request->get_param( 'dry_run' ) ) {
				MRMurphy_Restful_Deploy_Log::add( 'dry_run_plugin', $info['name'], 'ok', 'Archive validated, nothing installed.', $info );

				return rest_ensure_response(
					array(
						'ok'        => true,
						'dry_run'   => true,
						'package'   => $info,
						'installed' => false,
					)
				);
			}

			$overwrite = $this->overwrite_flag( $request );

			if ( is_wp_error( $overwrite ) ) {
				return $this->fail( 'install_plugin', $info['name'], $overwrite );
			}

			$result = $installer->install_plugin( $staged['path'], $overwrite, (bool) $request->get_param( 'activate' ) );

			if ( is_wp_error( $result ) ) {
				return $this->fail( 'install_plugin', $info['name'], $result, $info );
			}

			// Written before anything the package itself can run: an activation
			// hook can delete the log or end the request, and the install has to
			// be on the record before it does.
			$install_audit = MRMurphy_Restful_Deploy_Log::add( 'install_plugin', $result['plugin'], 'ok', sprintf( '%s %s written to disk', $info['name'], $info['version'] ), $info );

			$response = array(
				'ok'         => true,
				'dry_run'    => false,
				'package'    => $info,
				'installed'  => true,
				'plugin'     => $result['plugin'],
				'audit'      => $install_audit,
			);

			if ( $request->get_param( 'activate' ) ) {
				$activation = $installer->activate_plugin( $result['plugin'], (bool) $request->get_param( 'network_wide' ) );

				if ( is_wp_error( $activation ) ) {
					$this->log_activation_failure( $result['plugin'], $activation );

					return $this->fail( 'activate_plugin', $result['plugin'], $activation, array( 'installed' => true, 'plugin' => $result['plugin'] ) );
				}

				$response['activated']       = (bool) $activation['active'];
				$response['network_active']  = isset( $activation['network_active'] ) ? (bool) $activation['network_active'] : false;
				$response['already_active']  = (bool) $activation['already_active'];
				$response['activation_output'] = $activation['output'];
			}

			$response['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( $request->get_param( 'activate' ) ) {
				$response['activation_audit'] = MRMurphy_Restful_Deploy_Log::add( 'activate_plugin', $result['plugin'], 'ok', 'Activated after install.', array() );
			}

			return rest_ensure_response( $response );
		} finally {
			$installer->cleanup();
		}
	}

	/**
	 * Install (and optionally activate) a theme package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_theme( $request ) {
		$rate = $this->enforce_rate_limit();

		if ( is_wp_error( $rate ) ) {
			return $this->fail( 'install_theme', '', $rate );
		}

		$started   = microtime( true );
		$installer = new MRMurphy_Restful_Deploy_Installer();

		try {
			$staged = $this->stage_package( $installer, $request );

			if ( is_wp_error( $staged ) ) {
				return $this->fail( 'install_theme', '', $staged );
			}

			$info = $installer->inspect_zip( $staged['path'], 'theme' );

			if ( is_wp_error( $info ) ) {
				return $this->fail( 'install_theme', $staged['filename'], $info, array( 'bytes' => $staged['bytes'] ) );
			}

			if ( $request->get_param( 'dry_run' ) ) {
				MRMurphy_Restful_Deploy_Log::add( 'dry_run_theme', $info['name'], 'ok', 'Archive validated, nothing installed.', $info );

				return rest_ensure_response(
					array(
						'ok'        => true,
						'dry_run'   => true,
						'package'   => $info,
						'installed' => false,
					)
				);
			}

			$overwrite = $this->overwrite_flag( $request );

			if ( is_wp_error( $overwrite ) ) {
				return $this->fail( 'install_theme', $info['name'], $overwrite );
			}

			$result = $installer->install_theme( $staged['path'], $overwrite, (bool) $request->get_param( 'activate' ) );

			if ( is_wp_error( $result ) ) {
				return $this->fail( 'install_theme', $info['name'], $result, $info );
			}

			// Written before anything the package itself can run.
			$install_audit = MRMurphy_Restful_Deploy_Log::add( 'install_theme', $result['stylesheet'], 'ok', sprintf( '%s %s written to disk', $info['name'], $info['version'] ), $info );

			$response = array(
				'ok'        => true,
				'dry_run'   => false,
				'package'   => $info,
				'installed' => true,
				'stylesheet' => $result['stylesheet'],
				'audit'     => $install_audit,
			);

			if ( $request->get_param( 'activate' ) ) {
				$activation = $installer->activate_theme( $result['stylesheet'] );

				if ( is_wp_error( $activation ) ) {
					$this->log_activation_failure( $result['stylesheet'], $activation );

					return $this->fail( 'activate_theme', $result['stylesheet'], $activation, array( 'installed' => true, 'stylesheet' => $result['stylesheet'] ) );
				}

				$response['activated']      = (bool) $activation['active'];
				$response['already_active'] = (bool) $activation['already_active'];
			}

			$response['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

			if ( $request->get_param( 'activate' ) ) {
				$response['activation_audit'] = MRMurphy_Restful_Deploy_Log::add( 'activate_theme', $result['stylesheet'], 'ok', 'Activated after install.', array() );
			}

			return rest_ensure_response( $response );
		} finally {
			$installer->cleanup();
		}
	}

	/**
	 * Activate an installed plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate_plugin( $request ) {
		$rate = $this->enforce_rate_limit();

		if ( is_wp_error( $rate ) ) {
			return $this->fail( 'activate_plugin', (string) $request->get_param( 'plugin' ), $rate );
		}

		$installer  = new MRMurphy_Restful_Deploy_Installer();
		$plugin     = (string) $request->get_param( 'plugin' );
		$activation = $installer->activate_plugin( $plugin, (bool) $request->get_param( 'network_wide' ) );

		if ( is_wp_error( $activation ) ) {
			return $this->fail( 'activate_plugin', $plugin, $activation );
		}

		return rest_ensure_response(
			array(
				'ok'               => true,
				'plugin'           => $activation['plugin'],
				'activated'        => (bool) $activation['active'],
				'network_active'   => (bool) $activation['network_active'],
				'already_active'   => (bool) $activation['already_active'],
				'activation_output' => $activation['output'],
				'audit'            => MRMurphy_Restful_Deploy_Log::add( 'activate_plugin', $activation['plugin'], 'ok', '', array() ),
			)
		);
	}

	/**
	 * Deactivate an installed plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deactivate_plugin( $request ) {
		$rate = $this->enforce_rate_limit();

		if ( is_wp_error( $rate ) ) {
			return $this->fail( 'deactivate_plugin', (string) $request->get_param( 'plugin' ), $rate );
		}

		$installer  = new MRMurphy_Restful_Deploy_Installer();
		$plugin     = (string) $request->get_param( 'plugin' );
		$deactivate = $installer->deactivate_plugin( $plugin );

		if ( is_wp_error( $deactivate ) ) {
			return $this->fail( 'deactivate_plugin', $plugin, $deactivate );
		}

		return rest_ensure_response(
			array(
				'ok'                => true,
				'plugin'            => $deactivate['plugin'],
				'active'            => (bool) $deactivate['active'],
				'already_inactive'  => (bool) $deactivate['already_inactive'],
				'activation_output' => $deactivate['output'],
				'audit'             => MRMurphy_Restful_Deploy_Log::add( 'deactivate_plugin', $deactivate['plugin'], 'ok', '', array() ),
			)
		);
	}

	/**
	 * Switch the active theme.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function activate_theme( $request ) {
		$rate = $this->enforce_rate_limit();

		if ( is_wp_error( $rate ) ) {
			return $this->fail( 'activate_theme', (string) $request->get_param( 'stylesheet' ), $rate );
		}

		$installer  = new MRMurphy_Restful_Deploy_Installer();
		$stylesheet = (string) $request->get_param( 'stylesheet' );
		$activation = $installer->activate_theme( $stylesheet );

		if ( is_wp_error( $activation ) ) {
			return $this->fail( 'activate_theme', $stylesheet, $activation );
		}

		return rest_ensure_response(
			array(
				'ok'             => true,
				'stylesheet'     => $activation['stylesheet'],
				'name'           => $activation['name'],
				'activated'      => (bool) $activation['active'],
				'already_active' => (bool) $activation['already_active'],
				'audit'          => MRMurphy_Restful_Deploy_Log::add( 'activate_theme', $activation['stylesheet'], 'ok', $activation['name'], array() ),
			)
		);
	}

	/**
	 * Uninstall a plugin.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function uninstall_plugin( $request ) {
		$rate = $this->enforce_rate_limit();

		if ( is_wp_error( $rate ) ) {
			return $this->fail( 'uninstall_plugin', (string) $request->get_param( 'plugin' ), $rate );
		}

		$installer = new MRMurphy_Restful_Deploy_Installer();
		$plugin    = (string) $request->get_param( 'plugin' );
		$result    = $installer->uninstall_plugin( $plugin, (bool) $request->get_param( 'deactivate' ) );

		if ( is_wp_error( $result ) ) {
			return $this->fail( 'uninstall_plugin', $plugin, $result );
		}

		return rest_ensure_response(
			array(
				'ok'                => true,
				'deleted'           => true,
				'plugin'            => $result['plugin'],
				'name'              => $result['name'],
				'version'           => $result['version'],
				'deactivated_first' => (bool) $result['deactivated_first'],
				'activation_output' => $result['output'],
				'audit'             => MRMurphy_Restful_Deploy_Log::add(
					'uninstall_plugin',
					$result['plugin'],
					'ok',
					sprintf( '%s %s deleted%s', $result['name'], $result['version'], $result['deactivated_first'] ? ' (deactivated first)' : '' ),
					array( 'deactivated_first' => (bool) $result['deactivated_first'] )
				),
			)
		);
	}

	/**
	 * Uninstall a theme.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function uninstall_theme( $request ) {
		$rate = $this->enforce_rate_limit();

		if ( is_wp_error( $rate ) ) {
			return $this->fail( 'uninstall_theme', (string) $request->get_param( 'stylesheet' ), $rate );
		}

		$installer  = new MRMurphy_Restful_Deploy_Installer();
		$stylesheet = (string) $request->get_param( 'stylesheet' );
		$result     = $installer->uninstall_theme( $stylesheet );

		if ( is_wp_error( $result ) ) {
			return $this->fail( 'uninstall_theme', $stylesheet, $result );
		}

		return rest_ensure_response(
			array(
				'ok'         => true,
				'deleted'    => true,
				'stylesheet' => $result['stylesheet'],
				'name'       => $result['name'],
				'version'    => $result['version'],
				'audit'      => MRMurphy_Restful_Deploy_Log::add(
					'uninstall_theme',
					$result['stylesheet'],
					'ok',
					sprintf( '%s %s deleted', $result['name'], $result['version'] ),
					array()
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Internals                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Get the uploaded/staged package to a temp file.
	 *
	 * Prefers a multipart upload, falls back to a base64 JSON field.
	 *
	 * @param MRMurphy_Restful_Deploy_Installer $installer Installer.
	 * @param WP_REST_Request                $request   Request.
	 * @return array|WP_Error { @type string $path, @type string $filename, @type int $bytes }
	 */
	private function stage_package( $installer, $request ) {
		$files = $request->get_file_params();
		$file  = null;

		if ( is_array( $files ) ) {
			if ( isset( $files['file'] ) ) {
				$file = $files['file'];
			} elseif ( $files ) {
				$file = reset( $files );
			}
		}

		if ( $file ) {
			$path = $installer->stage_upload( $file );
		} else {
			$path = $installer->stage_base64( (string) $request->get_param( 'zip_base64' ), (string) $request->get_param( 'filename' ) );
		}

		if ( is_wp_error( $path ) ) {
			return $path;
		}

		return array(
			'path'     => $path,
			'filename' => $file && isset( $file['name'] ) ? sanitize_file_name( (string) $file['name'] ) : (string) $request->get_param( 'filename' ),
			'bytes'    => (int) filesize( $path ),
		);
	}

	/**
	 * Resolve the overwrite flag against the site-wide gate.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	private function overwrite_flag( $request ) {
		if ( ! $request->get_param( 'overwrite' ) ) {
			return false;
		}

		if ( ! MRMurphy_Restful_Deploy_Plugin::overwrite_allowed() ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_overwrite_disabled',
				'Overwriting installed packages is disabled by default. Define MRMURPHY_RESTFUL_DEPLOY_ALLOW_OVERWRITE as true in wp-config.php to allow it.',
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Per-user hourly operation throttle.
	 *
	 * The counter is bumped with one atomic statement BEFORE the check, so the
	 * cap cannot be exceeded by parallel requests (a read-then-write transient
	 * loses updates). The request that takes the counter past the limit is
	 * refused and its slot is spent — the safe direction. Where the database
	 * cannot run that statement the throttle degrades to best-effort, and
	 * gate_status()["rate_limit_mode"] says which implementation is in force.
	 *
	 * @return true|WP_Error
	 */
	private function enforce_rate_limit() {
		global $wpdb;

		$max = MRMurphy_Restful_Deploy_Plugin::max_operations_per_hour();
		$key = 'mrmurphy_restful_deploy_ops_' . get_current_user_id() . '_' . gmdate( 'YmdH' );

		$updated = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no') ON DUPLICATE KEY UPDATE option_value = option_value + 1",
				$key
			)
		);

		if ( false === $updated ) {
			update_option( 'mrmurphy_restful_deploy_rate_mode', 'transient', false );

			$count = $this->bump_transient_counter( $key );
		} else {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $key ) );
		}

		if ( 1 === $count ) {
			$this->prune_rate_limit_rows();
		}

		if ( $count > $max ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_rate_limited',
				sprintf( 'Rate limit reached: at most %d package operations per hour per user.', $max ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	/**
	 * Best-effort fallback counter for hosts without the atomic statement.
	 *
	 * @param string $key Counter key.
	 * @return int New count.
	 */
	private function bump_transient_counter( $key ) {
		$ops = (int) get_transient( $key );

		set_transient( $key, $ops + 1, 2 * HOUR_IN_SECONDS );

		return $ops + 1;
	}

	/**
	 * Drop counter rows from previous hours, keeping the table tidy.
	 */
	private function prune_rate_limit_rows() {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s",
				'mrmurphy\_package\_api\_ops\_%',
				'%' . gmdate( 'YmdH' )
			)
		);
	}

	/**
	 * Log a failed operation and hand the error back to the REST server.
	 *
	 * @param string   $action  Action name.
	 * @param string   $target  Target package.
	 * @param WP_Error $error   The error to return.
	 * @param array    $context Extra context.
	 * @return WP_Error
	 */
	private function fail( $action, $target, $error, $context = array() ) {
		// A refused request must not be able to grow — or evict entries from —
		// the capped audit log. Throttled traffic costs no budget, so it must
		// cost no log entries either; the refusal counter records it instead.
		if ( 'mrmurphy_restful_deploy_rate_limited' !== $error->get_error_code() ) {
			MRMurphy_Restful_Deploy_Log::add( $action, (string) $target, 'error', $error->get_error_message(), $context );
		}

		return $error;
	}

	/**
	 * Log an activation failure separately, so a successful install that could
	 * not be activated is not recorded as one event.
	 *
	 * @param string   $target Package.
	 * @param WP_Error $error  Error.
	 */
	private function log_activation_failure( $target, $error ) {
		MRMurphy_Restful_Deploy_Log::add( 'activate_package', (string) $target, 'error', $error->get_error_message(), array() );
	}
}
