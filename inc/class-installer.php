<?php
/**
 * Package staging, inspection, installation and activation.
 *
 * Nothing here trusts caller input: every archive is copied to a private temp
 * file, opened and validated (entry paths, size, bomb ratio, nesting, package
 * type) before the WordPress upgrader is ever handed the path.
 *
 * @package MrMurphyRestfulDeploy
 */

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class MRMurphy_Restful_Deploy_Installer {

	/** @var string[] Temp files created during this request. */
	private $temp_files = array();

	/**
	 * Constructor.
	 *
	 * A shutdown handler is registered in addition to __destruct(): a package
	 * whose activation or uninstall hook calls exit() ends the request without
	 * unwinding objects, and the staged ZIP would otherwise be left behind in
	 * the system temp directory (or, worse, in an extracted form under the
	 * web root — see sweep_working_dir()).
	 */
	public function __construct() {
		register_shutdown_function( array( $this, 'cleanup' ) );
	}

	/**
	 * Remove temp files when the object goes away.
	 */
	public function __destruct() {
		$this->cleanup();
	}

	/**
	 * Delete every temp file created by this instance.
	 */
	public function cleanup() {
		foreach ( $this->temp_files as $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				@unlink( $path );
			}
		}

		$this->temp_files = array();
	}

	/* ------------------------------------------------------------------ */
	/*  Environment                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * WordPress filesystem transport in use.
	 *
	 * @return string
	 */
	public static function filesystem_method() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! function_exists( 'get_filesystem_method' ) ) {
			return 'unknown';
		}

		return (string) get_filesystem_method();
	}

	/**
	 * Make sure the upgrader will be able to write files.
	 *
	 * The upgrader connects on its own, but on hosts that need FTP/SSH
	 * credentials every install fails with a generic error. Failing here with
	 * the actual method named makes the cause obvious.
	 *
	 * @return true|WP_Error
	 */
	public function filesystem_ready() {
		global $wp_filesystem;

		require_once ABSPATH . 'wp-admin/includes/file.php';

		$method = get_filesystem_method();

		if ( ! in_array( $method, array( 'direct', 'ssh2' ), true ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_filesystem_unavailable',
				sprintf(
					'The WordPress filesystem is not writable by PHP (method: %s). Define FS_METHOD as "direct" in wp-config.php, or install over SFTP / WP-CLI instead.',
					$method
				),
				array( 'status' => 500 )
			);
		}

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			WP_Filesystem( false, false, true );
		}

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return new WP_Error(
				'unable_to_connect_to_filesystem',
				'Unable to connect to the filesystem. Please confirm your credentials.',
				array( 'status' => 500 )
			);
		}

		if ( is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
			return new WP_Error(
				'unable_to_connect_to_filesystem',
				$wp_filesystem->errors->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/* ------------------------------------------------------------------ */
	/*  Staging                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Write a base64 payload to a private temp file.
	 *
	 * @param string $encoded  Base64 (optionally a data: URL).
	 * @param string $filename Optional original filename, for messages only.
	 * @return string|WP_Error Temp file path.
	 */
	public function stage_base64( $encoded, $filename = '' ) {
		$encoded = trim( (string) $encoded );

		if ( '' === $encoded ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_missing_package',
				'No package supplied. Send "zip_base64" (JSON) or a "file" upload (multipart).',
				array( 'status' => 400 )
			);
		}

		if ( preg_match( '#^data:[^;,]*;base64,#i', $encoded ) ) {
			$encoded = substr( $encoded, strpos( $encoded, ',' ) + 1 );
		}

		// Bound the payload before copying or decoding it. base64 is ~4/3 of
		// the decoded size, so this can only over-estimate: nothing larger than
		// the cap is ever copied or decoded.
		$max_bytes   = MRMurphy_Restful_Deploy_Plugin::max_bytes();
		$approx_size = (int) ( strlen( $encoded ) * 0.75 );

		if ( $approx_size > $max_bytes ) {
			return $this->too_large_error( $approx_size );
		}

		// Only pay for a second full copy when whitespace was actually sent.
		if ( preg_match( '/\s/', $encoded ) ) {
			$encoded = preg_replace( '/\s+/', '', $encoded );
		}

		$binary = base64_decode( $encoded, true );

		if ( false === $binary ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_invalid_base64',
				'"zip_base64" is not valid base64.',
				array( 'status' => 400 )
			);
		}

		return $this->write_temp_zip( $binary, $filename );
	}

	/**
	 * Move an uploaded file (multipart form) to a private temp file.
	 *
	 * @param array $file $_FILES-style entry.
	 * @return string|WP_Error Temp file path.
	 */
	public function stage_upload( $file ) {
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_missing_package',
				'No file uploaded. Send the ZIP as the "file" form field, or as "zip_base64" in a JSON body.',
				array( 'status' => 400 )
			);
		}

		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_OK !== $error ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_upload_failed',
				sprintf( 'Upload failed: %s.', $this->upload_error_message( $error ) ),
				array( 'status' => 400 )
			);
		}

		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_upload_untrusted',
				'The uploaded file is not a valid HTTP upload.',
				array( 'status' => 400 )
			);
		}

		$size = (int) filesize( $file['tmp_name'] );

		if ( $size > MRMurphy_Restful_Deploy_Plugin::max_bytes() ) {
			return $this->too_large_error( $size );
		}

		$binary = file_get_contents( $file['tmp_name'] );

		if ( false === $binary ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_upload_unreadable',
				'Could not read the uploaded file.',
				array( 'status' => 500 )
			);
		}

		return $this->write_temp_zip( $binary, isset( $file['name'] ) ? (string) $file['name'] : '' );
	}

	/**
	 * Validate an archive and identify what it contains.
	 *
	 * @param string $path          Temp zip path.
	 * @param string $expected_type "plugin" or "theme".
	 * @return array|WP_Error {
	 *     @type string $detected_type      "plugin" or "theme".
	 *     @type string $name               Package name from its header.
	 *     @type string $version            Version from its header.
	 *     @type string $top_level_dir      Single top-level directory.
	 *     @type string $main_file          Plugin file or style.css, relative to the zip root.
	 *     @type int    $entry_count        File entries.
	 *     @type int    $uncompressed_bytes Total uncompressed size.
	 * }
	 */
	public function inspect_zip( $path, $expected_type ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_no_ziparchive',
				'The PHP zip extension (ZipArchive) is not available on this server.',
				array( 'status' => 500 )
			);
		}

		$zip    = new ZipArchive();
		$opened = $zip->open( $path, ZipArchive::CHECKCONS );

		if ( true !== $opened ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_incompatible_archive',
				'Sorry, this file could not be opened as a ZIP archive.',
				array( 'status' => 400 )
			);
		}

		$max_uncompressed = MRMurphy_Restful_Deploy_Plugin::max_uncompressed_bytes();
		$uncompressed     = 0;
		$compressed       = 0;
		$entry_count      = 0;
		$top_level_dirs   = array();
		$nested_entries   = 0;
		$plugin_headers   = array();
		$theme_headers    = null;
		$theme_main_file  = '';
		$php_scanned      = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$info = $zip->statIndex( $i );

			if ( ! $info ) {
				$zip->close();

				return new WP_Error(
					'mrmurphy_restful_deploy_archive_unreadable',
					'Could not read an entry from the archive.',
					array( 'status' => 400 )
				);
			}

			$name = (string) $info['name'];

			if ( 0 === strpos( $name, '__MACOSX/' ) ) {
				continue;
			}

			// A name that starts with '/' is absolute on some platforms and is
			// never a valid archive entry; reject it rather than relying on
			// core's later path concatenation to contain it.
			if ( 0 === strpos( $name, '/' ) ) {
				$zip->close();

				return new WP_Error(
					'mrmurphy_restful_deploy_unsafe_archive_path',
					sprintf( 'Archive entry "%s" is not a safe relative path.', $name ),
					array( 'status' => 400 )
				);
			}

			// Rejects '../' patterns and Windows drive letters. Note that
			// validate_file() does NOT reject absolute paths or bare '..', so
			// the checks around it are what actually hold the line.
			if ( 0 !== validate_file( $name ) ) {
				$zip->close();

				return new WP_Error(
					'mrmurphy_restful_deploy_unsafe_archive_path',
					sprintf( 'Archive entry "%s" is not a safe relative path.', $name ),
					array( 'status' => 400 )
				);
			}

			$entry_count++;
			$uncompressed += (int) $info['size'];
			$compressed   += (int) $info['comp_size'];

			if ( $uncompressed > $max_uncompressed ) {
				$zip->close();

				return new WP_Error(
					'mrmurphy_restful_deploy_archive_too_large',
					sprintf(
						'The archive expands to more than the allowed %s bytes.',
						number_format_i18n( $max_uncompressed )
					),
					array( 'status' => 413 )
				);
			}

			$is_dir_entry = ( substr( $name, -1 ) === '/' );
			$parts        = explode( '/', $is_dir_entry ? trim( $name, '/' ) : $name );
			$top_level    = $parts[0];

			if ( '' !== $top_level ) {
				if ( ! in_array( $top_level, $top_level_dirs, true ) ) {
					$top_level_dirs[] = $top_level;
				}

				// An entry nested below the top level (or the directory entry
				// itself) proves the top level is a directory, not a lone file.
				if ( count( $parts ) > 1 || $is_dir_entry ) {
					$nested_entries++;
				}
			}

			if ( substr( $name, -1 ) === '/' ) {
				continue;
			}

			$depth    = count( $parts );
			$basename = $parts[ $depth - 1 ];

			if ( 'style.css' === $basename && $depth <= 2 && null === $theme_headers ) {
				$head = (string) $zip->getFromIndex( $i, 8192 );

				if ( preg_match( '/^[ \t\/*#@]*Theme Name:(.*)$/mi', $head ) ) {
					$theme_headers   = $this->parse_headers( $head, array( 'Theme Name', 'Version', 'Requires at least', 'Requires PHP', 'Template' ) );
					$theme_main_file = $name;
				}
			}

			if ( $depth <= 3 && substr( $basename, -4 ) === '.php' && $php_scanned < 300 ) {
				$php_scanned++;
				$head = (string) $zip->getFromIndex( $i, 8192 );

				if ( preg_match( '/^[ \t\/*#@]*Plugin Name:(.*)$/mi', $head ) ) {
					$plugin_headers[] = array(
						'depth'   => $depth,
						'file'    => $name,
						'headers' => $this->parse_headers( $head, array( 'Plugin Name', 'Version', 'Requires at least', 'Requires PHP' ) ),
					);
				}
			}
		}

		$zip->close();

		// A zip that expands 200x is a decompression bomb, not a plugin.
		if ( $compressed > 0 && $uncompressed > 10 * 1024 * 1024 && ( $uncompressed / $compressed ) > 200 ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_compression_ratio',
				'The archive has an implausible compression ratio and was refused.',
				array( 'status' => 413 )
			);
		}

		if ( 0 === $entry_count ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_empty_archive',
				'The archive contains no files.',
				array( 'status' => 400 )
			);
		}

		if ( ! $top_level_dirs ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_bad_top_level',
				'The archive has no usable top level entry. Re-zip the plugin/theme folder itself, e.g. "zip -r my-plugin.zip my-plugin".',
				array( 'status' => 400 )
			);
		}

		if ( count( $top_level_dirs ) > 1 || 0 === $nested_entries ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_flat_archive',
				sprintf(
					'The archive must contain exactly one top level folder (found %d: %s). A zip of loose files, or of a single file, is refused. Re-zip the plugin/theme folder itself, e.g. "zip -r my-plugin.zip my-plugin".',
					count( $top_level_dirs ),
					implode( ', ', array_slice( $top_level_dirs, 0, 5 ) )
				),
				array( 'status' => 400 )
			);
		}

		$top_level_dir = (string) $top_level_dirs[0];

		if ( ! $this->is_safe_name( $top_level_dir ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_bad_top_level',
				sprintf( 'The archive\'s top level folder name ("%s") is not usable as a directory name.', $top_level_dir ),
				array( 'status' => 400 )
			);
		}

		$detected = null;

		if ( $plugin_headers ) {
			usort(
				$plugin_headers,
				static function ( $a, $b ) {
					return $a['depth'] <=> $b['depth'];
				}
			);

			$detected = array(
				'type'     => 'plugin',
				'name'     => $plugin_headers[0]['headers']['Plugin Name'],
				'version'  => $plugin_headers[0]['headers']['Version'],
				'requires' => $plugin_headers[0]['headers']['Requires at least'],
				'requires_php' => $plugin_headers[0]['headers']['Requires PHP'],
				'main_file'    => $plugin_headers[0]['file'],
			);
		} elseif ( null !== $theme_headers ) {
			$detected = array(
				'type'     => 'theme',
				'name'     => $theme_headers['Theme Name'],
				'version'  => $theme_headers['Version'],
				'requires' => $theme_headers['Requires at least'],
				'requires_php' => $theme_headers['Requires PHP'],
				'main_file'    => $theme_main_file,
			);
		}

		if ( null === $detected ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_unknown_package',
				'The archive contains neither a plugin file with a "Plugin Name:" header nor a theme style.css with a "Theme Name:" header.',
				array( 'status' => 400 )
			);
		}

		if ( $expected_type !== $detected['type'] ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_wrong_package_type',
				sprintf(
					'This archive contains a %s ("%s") but a %s was expected. Use the /%ss endpoint instead.',
					$detected['type'],
					$detected['name'],
					$expected_type,
					$expected_type
				),
				array( 'status' => 400 )
			);
		}

		return array(
			'detected_type'      => $detected['type'],
			'name'               => $detected['name'],
			'version'            => $detected['version'],
			'requires'           => $detected['requires'],
			'requires_php'       => $detected['requires_php'],
			'top_level_dir'      => $top_level_dir,
			'main_file'          => $detected['main_file'],
			'entry_count'        => $entry_count,
			'uncompressed_bytes' => $uncompressed,
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Install                                                           */
	/* ------------------------------------------------------------------ */

	/**
	 * Install a plugin ZIP into wp-content/plugins.
	 *
	 * @param string $zip_path       Temp zip path.
	 * @param bool   $overwrite      Replace an existing plugin directory.
	 * @param bool   $confirm_active Confirm replacing a plugin that is already
	 *                               running. Without it, an overwrite aimed at
	 *                               an active plugin is refused, because the
	 *                               new code takes effect on the next request
	 *                               with no activation step.
	 * @return array|WP_Error
	 */
	public function install_plugin( $zip_path, $overwrite = false, $confirm_active = false ) {
		$ready = $this->filesystem_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$this->load_upgrader_dependencies();

		$top = $this->archive_top_level( $zip_path );

		// Checked before the destination guard: this plugin is normally installed
		// as a symlink into a checkout, and the symlink guard would otherwise
		// answer for it and hide the more accurate reason. Compares resolved
		// paths, so a symlink alias for this plugin is caught too.
		if ( $this->is_safe_name( $top ) && $this->is_self_path( trailingslashit( WP_PLUGIN_DIR ) . $top ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_cannot_overwrite_self',
				'This archive would replace the plugin handling the request. Deploy it over SFTP or from the Plugins screen instead.',
				array( 'status' => 403 )
			);
		}

		$dest = $this->guard_destination( WP_PLUGIN_DIR, $top );

		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		if ( $overwrite && ! $confirm_active && $this->plugin_dir_is_active( $top ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_overwrite_active_plugin',
				'Refusing to overwrite a plugin that is currently active. The replacement code would start running on the next request with no activation step. Re-issue with "activate": true to confirm.',
				array( 'status' => 409 )
			);
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );

		$result = $upgrader->install(
			$zip_path,
			array(
				'overwrite_package'  => (bool) $overwrite,
				'clear_update_cache' => true,
			)
		);

		$failure = $this->upgrader_failure( $skin, $result );

		if ( is_wp_error( $failure ) ) {
			$this->sweep_working_dir( $zip_path );

			return $failure;
		}

		$file = $upgrader->plugin_info();

		if ( ! $file || 0 !== validate_file( $file ) || ! file_exists( WP_PLUGIN_DIR . '/' . $file ) ) {
			return new WP_Error(
				'unable_to_determine_installed_plugin',
				'Unable to determine what plugin was installed.',
				array( 'status' => 500 )
			);
		}

		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );

		return array(
			'plugin'     => $file,
			'name'       => isset( $data['Name'] ) ? $data['Name'] : '',
			'version'    => isset( $data['Version'] ) ? $data['Version'] : '',
			'overwritten' => (bool) $overwrite,
			'messages'   => $skin->get_error_messages(),
		);
	}

	/**
	 * Install a theme ZIP into wp-content/themes.
	 *
	 * @param string $zip_path       Temp zip path.
	 * @param bool   $overwrite      Replace an existing theme directory.
	 * @param bool   $confirm_active Confirm replacing the theme that is
	 *                               currently rendering the site.
	 * @return array|WP_Error
	 */
	public function install_theme( $zip_path, $overwrite = false, $confirm_active = false ) {
		$ready = $this->filesystem_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$this->load_upgrader_dependencies();

		$top = $this->archive_top_level( $zip_path );

		// Refuses symbolic links and anything that does not resolve to a direct
		// child of the theme root. An overwrite through a symlink would delete
		// the link target's contents and write the archive's files outside
		// wp-content — and core's Theme_Upgrader does exactly that by default.
		$dest = $this->guard_destination( get_theme_root(), $top );

		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		$theme_is_active = $this->same_path( $dest, get_stylesheet_directory() ) || $this->same_path( $dest, get_template_directory() );

		if ( $overwrite && ! $confirm_active && $theme_is_active ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_overwrite_active_theme',
				'Refusing to overwrite the theme that is currently active. Re-issue with "activate": true to confirm.',
				array( 'status' => 409 )
			);
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );

		$result = $upgrader->install(
			$zip_path,
			array(
				'overwrite_package'  => (bool) $overwrite,
				'clear_update_cache' => true,
			)
		);

		$failure = $this->upgrader_failure( $skin, $result );

		if ( is_wp_error( $failure ) ) {
			$this->sweep_working_dir( $zip_path );

			return $failure;
		}

		$stylesheet = isset( $upgrader->result['destination_name'] ) ? (string) $upgrader->result['destination_name'] : '';

		if ( '' === $stylesheet ) {
			$theme = $upgrader->theme_info();

			if ( $theme ) {
				$stylesheet = (string) $theme->get_stylesheet();
			}
		}

		if ( '' === $stylesheet || ! $this->is_safe_name( $stylesheet ) ) {
			return new WP_Error(
				'unable_to_determine_installed_theme',
				'Unable to determine what theme was installed.',
				array( 'status' => 500 )
			);
		}

		$theme = wp_get_theme( $stylesheet );

		return array(
			'stylesheet'  => $stylesheet,
			'name'        => $theme->exists() ? $theme->get( 'Name' ) : '',
			'version'     => $theme->exists() ? $theme->get( 'Version' ) : '',
			'overwritten' => (bool) $overwrite,
			'messages'    => $skin->get_error_messages(),
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Activation                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Activate an installed plugin.
	 *
	 * @param string $plugin_file   Plugin file relative to WP_PLUGIN_DIR.
	 * @param bool   $network_wide  Activate across a multisite network.
	 * @return array|WP_Error
	 */
	public function activate_plugin( $plugin_file, $network_wide = false ) {
		$this->load_upgrader_dependencies();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = plugin_basename( trim( (string) $plugin_file ) );

		if ( '' === $plugin_file || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) || ! isset( get_plugins()[ $plugin_file ] ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_plugin_not_found',
				sprintf( 'No installed plugin matches "%s".', $plugin_file ),
				array( 'status' => 404 )
			);
		}

		$network = (bool) $network_wide && is_multisite();

		if ( is_plugin_active( $plugin_file ) || ( $network && is_plugin_active_for_network( $plugin_file ) ) ) {
			return array(
				'plugin'         => $plugin_file,
				'active'         => true,
				'network_active' => is_multisite() && is_plugin_active_for_network( $plugin_file ),
				'already_active' => true,
				'output'         => '',
			);
		}

		wp_clean_plugins_cache( false );

		// Activation hooks do print things; keep that out of the JSON body.
		ob_start();
		$result = activate_plugin( $plugin_file, '', $network, false );
		$output = (string) ob_get_clean();

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		wp_clean_plugins_cache( false );

		return array(
			'plugin'         => $plugin_file,
			'active'         => is_plugin_active( $plugin_file ) || ( $network && is_plugin_active_for_network( $plugin_file ) ),
			'network_active' => is_multisite() && is_plugin_active_for_network( $plugin_file ),
			'already_active' => false,
			'output'         => trim( $output ),
		);
	}

	/**
	 * Deactivate an installed plugin.
	 *
	 * @param string $plugin_file  Plugin file relative to WP_PLUGIN_DIR.
	 * @return array|WP_Error
	 */
	public function deactivate_plugin( $plugin_file ) {
		$this->load_upgrader_dependencies();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = plugin_basename( trim( (string) $plugin_file ) );

		if ( '' === $plugin_file || ! file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) || ! isset( get_plugins()[ $plugin_file ] ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_plugin_not_found',
				sprintf( 'No installed plugin matches "%s".', $plugin_file ),
				array( 'status' => 404 )
			);
		}

		$active = is_plugin_active( $plugin_file ) || ( is_multisite() && is_plugin_active_for_network( $plugin_file ) );

		if ( ! $active ) {
			return array(
				'plugin'           => $plugin_file,
				'active'           => false,
				'already_inactive' => true,
				'output'           => '',
			);
		}

		ob_start();
		deactivate_plugins( $plugin_file, false, null );
		$output = (string) ob_get_clean();

		wp_clean_plugins_cache( false );

		return array(
			'plugin'           => $plugin_file,
			'active'           => is_plugin_active( $plugin_file ),
			'already_inactive' => false,
			'output'           => trim( $output ),
		);
	}

	/**
	 * Switch the active theme.
	 *
	 * switch_theme() calls wp_die() on a broken or incompatible theme, which
	 * would end the request with an HTML page instead of JSON, so every
	 * condition core checks is checked here first.
	 *
	 * @param string $stylesheet Theme directory name.
	 * @return array|WP_Error
	 */
	public function activate_theme( $stylesheet ) {
		$stylesheet = trim( (string) $stylesheet );

		if ( '' === $stylesheet || ! $this->is_safe_name( $stylesheet ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_invalid_stylesheet',
				'Supplied "stylesheet" is not a valid theme directory name.',
				array( 'status' => 400 )
			);
		}

		if ( $this->same_path( trailingslashit( get_theme_root() ) . $stylesheet, get_stylesheet_directory() ) ) {
			$theme = wp_get_theme( $stylesheet );

			return array(
				'stylesheet'     => $stylesheet,
				'name'           => $theme->exists() ? $theme->get( 'Name' ) : '',
				'active'         => true,
				'already_active' => true,
			);
		}

		$theme = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_theme_not_found',
				sprintf( 'No installed theme matches "%s".', $stylesheet ),
				array( 'status' => 404 )
			);
		}

		$errors = $theme->errors();

		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return $errors;
		}

		if ( function_exists( 'validate_theme_requirements' ) ) {
			$requirements = validate_theme_requirements( $stylesheet );

			if ( is_wp_error( $requirements ) ) {
				return $requirements;
			}
		}

		$parent = $theme->parent();

		if ( $parent ) {
			$parent_errors = $parent->errors();

			if ( is_wp_error( $parent_errors ) && $parent_errors->has_errors() ) {
				return $parent_errors;
			}
		}

		switch_theme( $stylesheet );

		$theme = wp_get_theme( $stylesheet );

		return array(
			'stylesheet'     => $stylesheet,
			'name'           => $theme->exists() ? $theme->get( 'Name' ) : '',
			'version'        => $theme->exists() ? $theme->get( 'Version' ) : '',
			'active'         => get_stylesheet() === $stylesheet,
			'already_active' => false,
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Uninstall                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Delete an installed plugin, optionally deactivating it first.
	 *
	 * Mirrors wp-admin: delete_plugins() runs the plugin's own uninstall
	 * routine (uninstall.php or a registered uninstall hook), so this removes
	 * the plugin's data as well as its files — the same thing the Plugins
	 * screen's Delete link does, no more.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @param bool   $deactivate  Deactivate first when it is active.
	 * @return array|WP_Error
	 */
	public function uninstall_plugin( $plugin_file, $deactivate = false ) {
		$ready = $this->filesystem_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$this->load_upgrader_dependencies();
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = plugin_basename( trim( (string) $plugin_file ) );

		if ( '' === $plugin_file || 0 !== validate_file( $plugin_file ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_invalid_plugin',
				sprintf( '"%s" is not a valid plugin path.', $plugin_file ),
				array( 'status' => 400 )
			);
		}

		$installed = get_plugins();

		if ( ! isset( $installed[ $plugin_file ] ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_plugin_not_found',
				sprintf( 'No installed plugin matches "%s".', $plugin_file ),
				array( 'status' => 404 )
			);
		}

		if ( plugin_basename( MRMURPHY_RESTFUL_DEPLOY_FILE ) === $plugin_file || $this->is_self_path( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_cannot_delete_self',
				'This API will not delete itself: removing the handler mid-request leaves a half-deleted plugin. Remove it over SFTP or from the Plugins screen.',
				array( 'status' => 403 )
			);
		}

		$plugin_dir = dirname( $plugin_file );

		if ( '.' !== $plugin_dir ) {
			$plugin_dir_guard = $this->guard_destination( WP_PLUGIN_DIR, $plugin_dir );

			if ( is_wp_error( $plugin_dir_guard ) ) {
				return $plugin_dir_guard;
			}
		}

		$data           = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
		$network_active = is_multisite() && is_plugin_active_for_network( $plugin_file );
		$active         = is_plugin_active( $plugin_file ) || $network_active;

		if ( $active && ! $deactivate ) {
			return new WP_Error(
				'rest_cannot_delete_active_plugin',
				'Cannot delete an active plugin. Pass "deactivate": true to deactivate and delete in one call, or deactivate it first.',
				array( 'status' => 409 )
			);
		}

		$deactivated_first = false;
		$output            = '';

		if ( $active ) {
			// Logged before the destructive call: a package whose own uninstall
			// routine ends the request would otherwise leave the deactivation
			// (a real state change) with no audit trail at all.
			MRMurphy_Restful_Deploy_Log::add( 'deactivate_plugin', $plugin_file, 'ok', 'Deactivated ahead of deletion.', array() );

			ob_start();
			deactivate_plugins( $plugin_file, false, null );
			$output = (string) ob_get_clean();

			$deactivated_first = true;

			wp_clean_plugins_cache( false );
		}

		$deleted = delete_plugins( array( $plugin_file ) );

		wp_clean_plugins_cache( false );

		if ( is_wp_error( $deleted ) ) {
			$this->add_status( $deleted );

			return $deleted;
		}

		if ( null === $deleted ) {
			return new WP_Error(
				'unable_to_connect_to_filesystem',
				'Filesystem credentials are required to delete plugin files on this host.',
				array( 'status' => 500 )
			);
		}

		if ( false === $deleted ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_delete_failed',
				sprintf( 'WordPress refused to delete "%s".', $plugin_file ),
				array( 'status' => 400 )
			);
		}

		if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_delete_incomplete',
				sprintf( 'The plugin file "%s" still exists after deletion.', $plugin_file ),
				array( 'status' => 500 )
			);
		}

		return array(
			'plugin'            => $plugin_file,
			'name'              => isset( $data['Name'] ) ? $data['Name'] : '',
			'version'           => isset( $data['Version'] ) ? $data['Version'] : '',
			'deactivated_first' => $deactivated_first,
			'output'            => trim( $output ),
		);
	}

	/**
	 * Delete an installed theme.
	 *
	 * delete_theme() itself has no active-theme guard — it concatenates the
	 * stylesheet straight into a path and deletes it — so every guard lives
	 * here: strict name validation, not the active theme, no active child.
	 *
	 * @param string $stylesheet Theme directory name.
	 * @return array|WP_Error
	 */
	public function uninstall_theme( $stylesheet ) {
		$ready = $this->filesystem_ready();

		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$this->load_upgrader_dependencies();

		$stylesheet = trim( (string) $stylesheet );

		if ( '' === $stylesheet || ! $this->is_safe_name( $stylesheet ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_invalid_stylesheet',
				'Supplied "stylesheet" is not a valid theme directory name.',
				array( 'status' => 400 )
			);
		}

		$theme = wp_get_theme( $stylesheet );

		if ( ! $theme->exists() ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_theme_not_found',
				sprintf( 'No installed theme matches "%s".', $stylesheet ),
				array( 'status' => 404 )
			);
		}

		// Refuses symbolic links and anything that does not resolve to a direct
		// child of the theme root. delete_theme() has no such check and hands
		// the name straight to a recursive filesystem delete.
		$theme_dir = $this->guard_destination( get_theme_root(), $stylesheet );

		if ( is_wp_error( $theme_dir ) ) {
			return $theme_dir;
		}

		if ( $this->same_path( $theme_dir, get_stylesheet_directory() ) || $this->same_path( $theme_dir, get_template_directory() ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_theme_is_active',
				'Cannot delete the active theme. Switch to another theme first.',
				array( 'status' => 409 )
			);
		}

		$active_stylesheet = get_stylesheet();
		$active_theme      = wp_get_theme( $active_stylesheet );

		if ( $active_theme->exists() && $this->same_path( trailingslashit( get_theme_root() ) . $active_theme->get_template(), $theme_dir ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_theme_has_active_child',
				sprintf( '"%s" is the parent of the active theme ("%s") and cannot be deleted.', $stylesheet, $active_stylesheet ),
				array( 'status' => 409 )
			);
		}

		$name    = $theme->get( 'Name' );
		$version = $theme->get( 'Version' );

		$deleted = delete_theme( $stylesheet );

		wp_clean_themes_cache( false );

		if ( is_wp_error( $deleted ) ) {
			$this->add_status( $deleted );

			return $deleted;
		}

		if ( null === $deleted ) {
			return new WP_Error(
				'unable_to_connect_to_filesystem',
				'Filesystem credentials are required to delete theme files on this host.',
				array( 'status' => 500 )
			);
		}

		if ( ! $deleted ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_delete_failed',
				sprintf( 'WordPress refused to delete the theme "%s".', $stylesheet ),
				array( 'status' => 400 )
			);
		}

		if ( is_dir( trailingslashit( get_theme_root() ) . $stylesheet ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_delete_incomplete',
				sprintf( 'The theme directory "%s" still exists after deletion.', $stylesheet ),
				array( 'status' => 500 )
			);
		}

		return array(
			'stylesheet' => $stylesheet,
			'name'       => (string) $name,
			'version'    => (string) $version,
		);
	}

	/* ------------------------------------------------------------------ */
	/*  Guards                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Is this string usable as a single path component (a plugin or theme
	 * directory name)?
	 *
	 * Rejects the traversal names, a leading dot or dash, a trailing dot or
	 * space (Win32 strips those, so "evil." resolves to "evil"), anything
	 * containing a separator, and Windows reserved device names.
	 *
	 * @param string $name Candidate name.
	 * @return bool
	 */
	private function is_safe_name( $name ) {
		if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 100 ) {
			return false;
		}

		if ( ! preg_match( '#^[A-Za-z0-9_][A-Za-z0-9._-]*$#', $name ) ) {
			return false;
		}

		if ( '.' === substr( $name, -1 ) || ' ' === substr( $name, -1 ) ) {
			return false;
		}

		if ( preg_match( '/^(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\..*)?$/i', $name ) ) {
			return false;
		}

		return basename( $name ) === $name;
	}

	/**
	 * Do two paths point at the same place?
	 *
	 * Resolved paths are compared case-insensitively when both resolve, so a
	 * differently-cased name for the active theme — which macOS and Windows
	 * happily resolve — is recognised as the same directory.
	 *
	 * @param string $a First path.
	 * @param string $b Second path.
	 * @return bool
	 */
	private function same_path( $a, $b ) {
		$ra = realpath( (string) $a );
		$rb = realpath( (string) $b );

		if ( false !== $ra && false !== $rb ) {
			return 0 === strcasecmp( $ra, $rb );
		}

		return 0 === strcasecmp( trailingslashit( (string) $a ), trailingslashit( (string) $b ) );
	}

	/**
	 * Validate a destination inside a WordPress package root.
	 *
	 * Refuses symbolic links and anything that does not resolve to a direct
	 * child of the root. This is the guard that stops an overwrite or a delete
	 * from being re-pointed at a repo or any other directory outside
	 * wp-content: WP_Filesystem's recursive delete follows symlinks, and core's
	 * own delete_theme() does not check for this at all.
	 *
	 * @param string $root Package root (WP_PLUGIN_DIR, or the theme root).
	 * @param string $name Directory name inside that root.
	 * @return string|WP_Error Absolute destination path when safe.
	 */
	private function guard_destination( $root, $name ) {
		if ( ! $this->is_safe_name( $name ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_bad_destination',
				sprintf( '"%s" is not usable as a package directory name.', $name ),
				array( 'status' => 400 )
			);
		}

		$root      = trailingslashit( $root );
		$dest      = $root . $name;
		$real_root = realpath( untrailingslashit( $root ) );

		if ( false === $real_root ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_bad_root',
				sprintf( 'Cannot resolve the package root "%s".', $root ),
				array( 'status' => 500 )
			);
		}

		if ( is_link( $dest ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_destination_is_symlink',
				sprintf(
					'"%s" is a symbolic link. Refusing to write to or delete through it, because WordPress would follow the link and modify files outside %s. Remove the link, or deploy that package over SFTP instead.',
					$name,
					untrailingslashit( $root )
				),
				array( 'status' => 409 )
			);
		}

		if ( file_exists( $dest ) ) {
			$real_dest = realpath( $dest );

			if ( false === $real_dest || dirname( $real_dest ) !== $real_root ) {
				return new WP_Error(
					'mrmurphy_restful_deploy_destination_outside_root',
					sprintf( '"%s" does not resolve to a direct child of %s.', $name, untrailingslashit( $root ) ),
					array( 'status' => 409 )
				);
			}
		}

		return $dest;
	}

	/**
	 * Is this path — or anything inside it — the plugin running this code?
	 *
	 * Compares resolved paths, so a second name for the same directory (a
	 * symlink alias, a symlinked plugins directory) cannot slip past a
	 * basename-only comparison.
	 *
	 * @param string $path Candidate path.
	 * @return bool
	 */
	private function is_self_path( $path ) {
		$self   = realpath( untrailingslashit( MRMURPHY_RESTFUL_DEPLOY_DIR ) );
		$target = realpath( (string) $path );

		if ( false === $self || false === $target ) {
			return false;
		}

		return $target === $self || 0 === strpos( trailingslashit( $target ), trailingslashit( $self ) );
	}

	/**
	 * Top level folder name of a staged archive, or '' when unreadable.
	 *
	 * @param string $zip_path Staged zip path.
	 * @return string
	 */
	private function archive_top_level( $zip_path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return '';
		}

		$zip    = new ZipArchive();
		$opened = $zip->open( $zip_path, ZipArchive::CHECKCONS );

		if ( true !== $opened ) {
			return '';
		}

		$top = '';

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$info = $zip->statIndex( $i );

			if ( ! $info ) {
				continue;
			}

			$name = (string) $info['name'];

			if ( 0 === strpos( $name, '__MACOSX/' ) || 0 === strpos( $name, '/' ) || 0 !== validate_file( $name ) ) {
				continue;
			}

			$parts = explode( '/', $name );

			if ( ! empty( $parts[0] ) ) {
				$top = (string) $parts[0];
				break;
			}
		}

		$zip->close();

		return $top;
	}

	/**
	 * Delete whatever the upgrader left behind in wp-content/upgrade.
	 *
	 * WP_Upgrader::run() extracts into wp-content/upgrade/<staged basename>/
	 * BEFORE any destination validation, and only removes that directory on the
	 * success path. After a failure it survives — under the web root, where its
	 * PHP files are reachable by URL and execute with no install and no
	 * activation.
	 *
	 * @param string $zip_path Staged zip path.
	 */
	private function sweep_working_dir( $zip_path ) {
		global $wp_filesystem;

		$base = basename( (string) $zip_path, '.zip' );

		if ( '' === $base ) {
			return;
		}

		$paths = glob( trailingslashit( WP_CONTENT_DIR ) . 'upgrade/' . $base . '*' );

		if ( ! is_array( $paths ) ) {
			return;
		}

		foreach ( $paths as $path ) {
			// Never recurse through a link, even though nothing this pipeline
			// extracts can create one.
			if ( is_link( $path ) ) {
				@unlink( $path );
				continue;
			}

			if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
				$wp_filesystem->delete( $path, true );
			}
		}
	}

	/**
	 * Would installing into this plugin directory replace code that is already
	 * running?
	 *
	 * @param string $dir_name Plugin directory name inside WP_PLUGIN_DIR.
	 * @return bool
	 */
	private function plugin_dir_is_active( $dir_name ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( $this->is_self_path( trailingslashit( WP_PLUGIN_DIR ) . $dir_name ) ) {
			return true;
		}

		foreach ( get_plugins() as $file => $data ) {
			if ( dirname( $file ) !== $dir_name ) {
				continue;
			}

			if ( is_plugin_active( $file ) || ( is_multisite() && is_plugin_active_for_network( $file ) ) ) {
				return true;
			}
		}

		return false;
	}

	/* ------------------------------------------------------------------ */
	/*  Internals                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Common failure extraction from a completed upgrader run.
	 *
	 * @param WP_Ajax_Upgrader_Skin $skin   Skin that captured upgrader errors.
	 * @param mixed                 $result Value returned by install().
	 * @return true|WP_Error
	 */
	private function upgrader_failure( $skin, $result ) {
		global $wp_filesystem;

		if ( is_wp_error( $result ) ) {
			$this->add_status( $result );

			return $result;
		}

		if ( is_wp_error( $skin->result ) ) {
			$this->add_status( $skin->result );

			return $skin->result;
		}

		if ( $skin->get_errors()->has_errors() ) {
			$error = $skin->get_errors();
			$this->add_status( $error );

			return $error;
		}

		if ( is_null( $result ) ) {
			if ( $wp_filesystem instanceof WP_Filesystem_Base
				&& is_wp_error( $wp_filesystem->errors )
				&& $wp_filesystem->errors->has_errors()
			) {
				return new WP_Error(
					'unable_to_connect_to_filesystem',
					$wp_filesystem->errors->get_error_message(),
					array( 'status' => 500 )
				);
			}

			return new WP_Error(
				'unable_to_connect_to_filesystem',
				'Unable to connect to the filesystem. Please confirm your credentials.',
				array( 'status' => 500 )
			);
		}

		return true;
	}

	/**
	 * Attach an HTTP status to an upgrader error when it has none.
	 *
	 * @param WP_Error $error Error to annotate.
	 */
	private function add_status( $error ) {
		$data = $error->get_error_data();

		if ( is_array( $data ) && isset( $data['status'] ) ) {
			return;
		}

		$known = array(
			'folder_exists'                       => 409,
			'source_destination_same'             => 409,
			'incompatible_archive'                => 400,
			'incompatible_archive_no_plugins'     => 400,
			'incompatible_archive_theme_no_style' => 400,
			'incompatible_archive_empty'          => 400,
			'fs_unavailable'                      => 500,
		);

		$code = $error->get_error_code();

		$error->add_data( array( 'status' => isset( $known[ $code ] ) ? $known[ $code ] : 500 ) );
	}

	/**
	 * Write binary to a 0600 temp file with a .zip suffix.
	 *
	 * @param string $binary   Archive bytes.
	 * @param string $filename Original filename, for messages only.
	 * @return string|WP_Error
	 */
	private function write_temp_zip( $binary, $filename = '' ) {
		$size = strlen( $binary );

		if ( 0 === $size ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_empty_payload',
				'The uploaded package is empty.',
				array( 'status' => 400 )
			);
		}

		if ( $size > MRMurphy_Restful_Deploy_Plugin::max_bytes() ) {
			return $this->too_large_error( $size );
		}

		$dir = trailingslashit( get_temp_dir() );

		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			return new WP_Error(
				'mrmurphy_restful_deploy_temp_unwritable',
				'The temporary directory is not writable, so the package cannot be staged.',
				array( 'status' => 500 )
			);
		}

		$path    = $dir . 'mrmurphy-restful-deploy-' . wp_generate_password( 16, false, false ) . $this->temp_suffix( $filename );
		$written = file_put_contents( $path, $binary );

		if ( false === $written || $written !== $size ) {
			@unlink( $path );

			return new WP_Error(
				'mrmurphy_restful_deploy_temp_write_failed',
				'Could not write the package to a temporary file.',
				array( 'status' => 500 )
			);
		}

		@chmod( $path, 0600 );

		$this->temp_files[] = $path;

		unset( $binary, $written );

		return $path;
	}

	/**
	 * Build a safe, self-describing suffix from a caller-supplied filename.
	 *
	 * Randomness lives in the prefix, so nothing the caller sends can steer
	 * the path; the basename is kept only to make temp files identifiable.
	 *
	 * @param string $filename Original filename.
	 * @return string
	 */
	private function temp_suffix( $filename ) {
		$basename = $filename ? sanitize_file_name( basename( (string) $filename ) ) : '';

		if ( '' !== $basename ) {
			$basename = '-' . substr( $basename, 0, 60 );
		}

		return $basename . '.zip';
	}

	/**
	 * Size-limit error with the effective limit in the message.
	 *
	 * @param int $size Observed size in bytes.
	 * @return WP_Error
	 */
	private function too_large_error( $size ) {
		return new WP_Error(
			'mrmurphy_restful_deploy_package_too_large',
			sprintf(
				'The package is %s bytes, above the %s byte limit (MRMURPHY_RESTFUL_DEPLOY_MAX_BYTES). Note PHP\'s own upload_max_filesize / post_max_size limits also apply to multipart uploads.',
				number_format_i18n( $size ),
				number_format_i18n( MRMurphy_Restful_Deploy_Plugin::max_bytes() )
			),
			array( 'status' => 413 )
		);
	}

	/**
	 * Human-readable PHP upload error.
	 *
	 * @param int $code UPLOAD_ERR_* constant.
	 * @return string
	 */
	private function upload_error_message( $code ) {
		switch ( $code ) {
			case UPLOAD_ERR_INI_SIZE:
				return 'the file exceeds upload_max_filesize';
			case UPLOAD_ERR_FORM_SIZE:
				return 'the file exceeds MAX_FILE_SIZE';
			case UPLOAD_ERR_PARTIAL:
				return 'only part of the file was uploaded';
			case UPLOAD_ERR_NO_FILE:
				return 'no file was received';
			case UPLOAD_ERR_NO_TMP_DIR:
				return 'no temporary directory is configured';
			case UPLOAD_ERR_CANT_WRITE:
				return 'the server could not write the file to disk';
			case UPLOAD_ERR_EXTENSION:
				return 'a PHP extension stopped the upload';
			default:
				return 'unknown upload error';
		}
	}

	/**
	 * Pull selected headers out of a file header block.
	 *
	 * @param string   $content First 8KB of the file.
	 * @param string[] $keys    Header names.
	 * @return array Header name => value.
	 */
	private function parse_headers( $content, $keys ) {
		$out = array();

		foreach ( $keys as $key ) {
			$out[ $key ] = '';

			if ( preg_match( '/^[ \t\/*#@]*' . preg_quote( $key, '/' ) . ':(.*)$/mi', $content, $m ) ) {
				$out[ $key ] = trim( preg_replace( '/\s*(?:\*\/|\?>).*/', '', $m[1] ) );
			}
		}

		return $out;
	}

	/**
	 * Load the admin files the upgraders need.
	 */
	private function load_upgrader_dependencies() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
	}
}
