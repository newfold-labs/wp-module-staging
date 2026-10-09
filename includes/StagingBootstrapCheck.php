<?php

namespace NewfoldLabs\WP\Module\Staging;

/**
 * Probes a WordPress install with WP-CLI before staging operations that boot plugins and themes.
 *
 * This is separate from StagingHealthCheck, which repairs staging_config. A single fatal in an
 * active plugin or theme kills the few WP-CLI commands that do not pass --skip-plugins, and the
 * shell script then has nothing to report except a generic failure.
 */
class StagingBootstrapCheck {

	/**
	 * REST / WP_Error code for a failed bootstrap.
	 *
	 * @var string
	 */
	const ERROR_CODE = 'bootstrap_check';

	/**
	 * Hard cap for the probe. A healthy bootstrap finishes well inside this.
	 *
	 * @var int
	 */
	const TIMEOUT_SECONDS = 10;

	/**
	 * GNU timeout exit status when the probe is killed.
	 *
	 * @var int
	 */
	const TIMEOUT_EXIT = 124;

	/**
	 * Shell exit status when wp or timeout is not installed.
	 *
	 * @var int
	 */
	const MISSING_EXIT = 127;

	/**
	 * Max characters of CLI output written to the staging log.
	 *
	 * @var int
	 */
	const LOG_OUTPUT_LIMIT = 4000;

	/**
	 * Staging log filename under the production nfd-private directory.
	 *
	 * @var string
	 */
	const LOG_FILE = 'nfd-staging.log';

	/**
	 * PHP passed to `wp --exec`. Kept in step with NFD_WP_EXEC in lib/.staging so a broken
	 * object-cache drop-in is not reported as a plugin fatal.
	 *
	 * @var string
	 */
	const WP_CLI_EXEC = "defined('WP_REDIS_DISABLED') || define('WP_REDIS_DISABLED', true); if ( class_exists('WP_CLI') && method_exists('WP_CLI', 'add_wp_hook') ) { WP_CLI::add_wp_hook('enable_loading_object_cache_dropin', function () { return function_exists('wp_cache_init'); }); }";

	/**
	 * Prefix of the line FATAL_REPORTER writes to stderr.
	 *
	 * @var string
	 */
	const FATAL_MARKER = 'NFD_BOOTSTRAP_FATAL:';

	/**
	 * Probe-only PHP passed as a second `wp --exec`.
	 *
	 * With display_errors off, which is common for PHP CLI on shared hosting, WP-CLI prints only
	 * "There has been a critical error on this website." and no file path. error_get_last() still
	 * has the fatal at shutdown, and this runs before WordPress registers its own handler.
	 *
	 * WP_DISABLE_FATAL_ERROR_HANDLER keeps that handler from mailing a recovery link or writing
	 * options. The probe skips the object-cache drop-in, so those writes would land in the database
	 * and leave the live cache stale. The next switch or clone then dies with a generic error
	 * even after the broken file has been restored.
	 *
	 * @var string
	 */
	const FATAL_REPORTER = <<<'PHP'
defined('WP_DISABLE_FATAL_ERROR_HANDLER') || define('WP_DISABLE_FATAL_ERROR_HANDLER', true); register_shutdown_function( function () { $e = error_get_last(); if ( is_array( $e ) && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) { fwrite( STDERR, 'NFD_BOOTSTRAP_FATAL: ' . strtok( (string) $e['message'], "\n" ) . ' in ' . $e['file'] . ':' . $e['line'] . "\n" ); } } );
PHP;

	/**
	 * PHP passed to the cache-eviction `wp --exec`, before WordPress loads.
	 *
	 * @var string
	 */
	const CACHE_EVICTION_BOOT = "defined('WP_DISABLE_FATAL_ERROR_HANDLER') || define('WP_DISABLE_FATAL_ERROR_HANDLER', true);";

	/**
	 * Option keys to drop from the probed install's object cache after the bootstrap.
	 *
	 * Passed to `wp eval`, so it runs once the drop-in is loaded. alloptions is the one that
	 * matters: autoloaded options are served from it. The others are named so a key stored on its
	 * own is dropped too.
	 *
	 * @var string
	 */
	const CACHE_EVICTION = <<<'PHP'
foreach ( array( 'alloptions', 'notoptions', 'staging_auth_token', 'staging_config', 'staging_environment', 'nfd_coming_soon', 'cron', 'recovery_mode_email_last_sent' ) as $key ) { wp_cache_delete( $key, 'options' ); }
PHP;

	/**
	 * Bootstrap WordPress at $path by running `wp eval`.
	 *
	 * Missing wp/timeout, a disabled exec(), a directory that is not there, a probe that exceeds
	 * the timeout, or a non-zero exit that is not a PHP fatal all fail open: the staging script
	 * already reports those. Only a PHP fatal is returned to the user.
	 *
	 * @param string $path             Production or staging directory.
	 * @param bool   $load_extensions  Whether to load regular plugins and themes. Must-use plugins,
	 *                                 drop-ins, and wp-config.php load either way.
	 * @return true|\WP_Error
	 */
	public function check( $path, $load_extensions = true ) {
		$path = is_string( $path ) ? $path : '';
		if ( '' === $path || ! is_dir( $path ) ) {
			return true;
		}

		if ( $this->exec_disabled() ) {
			return true;
		}

		$command = $this->build_command( $path, $load_extensions );
		$output  = array();
		$status  = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $status );

		$text   = implode( "\n", $output );
		$status = (int) $status;

		/*
		 * The probe skipped the object-cache drop-in, and a full or partial boot may have written
		 * options straight to the database. Drop the cached copies on this install before returning,
		 * or the next command that loads the drop-in (switch's `wp newfold sso`) reads the stale copy.
		 * A missing wp binary never booted WordPress, so there is nothing to drop.
		 */
		if ( ! $this->is_tool_missing( $status, $text ) ) {
			$this->evict_object_cache( $path );
		}

		// Exit 0 means eval finished. A BOM or extra echoed text must not look like a fatal.
		if ( 0 === $status ) {
			return true;
		}

		if ( $this->is_tool_missing( $status, $text ) || self::TIMEOUT_EXIT === $status || ! $this->output_has_fatal( $text ) ) {
			$this->log_output( $path, 'WARN', $text, $status );
			return true;
		}

		$this->log_output( $path, 'ERROR', $text, $status );

		return new \WP_Error( self::ERROR_CODE, $this->parse_failure( $text ) );
	}

	/**
	 * Turn WP-CLI fatal output into a message that names the plugin or theme when possible.
	 *
	 * Only the fatal line is read. Stack frames usually start in WordPress core and would name
	 * the wrong extension.
	 *
	 * @param string $output Combined stdout and stderr from the probe.
	 * @return string
	 */
	public function parse_failure( $output ) {
		$output     = (string) $output;
		$components = $this->extract_components( $output );
		$fatal_text = implode( "\n", $this->relevant_lines( $output ) );

		if ( false !== stripos( $fatal_text, 'create_function' ) ) {
			return $this->create_function_message( $components );
		}

		if ( false !== stripos( $fatal_text, 'Cannot redeclare' ) ) {
			return $this->redeclare_message( $components );
		}

		return $this->generic_message( $components );
	}

	/**
	 * Plugin and theme slugs mentioned on the fatal line, in order.
	 *
	 * @param string $output CLI output.
	 * @return array<int, array{type: string, slug: string}>
	 */
	protected function extract_components( $output ) {
		$found = array();
		$seen  = array();

		foreach ( $this->relevant_lines( $output ) as $line ) {
			if ( ! preg_match_all( '#/(mu-plugins|plugins|themes)/([A-Za-z0-9_-]+)(?:/|\.php\b)#', $line, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$key = $match[1] . ':' . $match[2];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$found[]      = array(
					'type' => $this->component_type( $match[1] ),
					'slug' => $match[2],
				);
			}
		}

		return $found;
	}

	/**
	 * Lines that describe the fatal. The shutdown marker wins over PHP's own fatal text.
	 *
	 * A create_function() deprecation is not a fatal line. On PHP 7.x that notice can sit next
	 * to an unrelated fatal and would otherwise name the wrong extension.
	 *
	 * @param string $output CLI output.
	 * @return string[]
	 */
	protected function relevant_lines( $output ) {
		$lines    = preg_split( '/\R/', (string) $output );
		$lines    = is_array( $lines ) ? $lines : array();
		$reported = array();
		$relevant = array();

		foreach ( $lines as $line ) {
			if ( false !== strpos( $line, self::FATAL_MARKER ) ) {
				$reported[] = $line;
			} elseif ( preg_match( '/Fatal error|Parse error|Cannot redeclare|^\s*thrown in\b/i', $line ) ) {
				$relevant[] = $line;
			}
		}

		if ( ! empty( $reported ) ) {
			return $reported;
		}

		return $relevant;
	}

	/**
	 * Map a wp-content directory to the component type used in messages.
	 *
	 * @param string $directory mu-plugins, plugins, or themes.
	 * @return string mu-plugin, plugin, or theme.
	 */
	protected function component_type( $directory ) {
		if ( 'themes' === $directory ) {
			return 'theme';
		}

		if ( 'mu-plugins' === $directory ) {
			return 'mu-plugin';
		}

		return 'plugin';
	}

	/**
	 * Message for a create_function() fatal.
	 *
	 * @param array<int, array{type: string, slug: string}> $components Components on the fatal line.
	 * @return string
	 */
	protected function create_function_message( array $components ) {
		if ( empty( $components ) ) {
			return __( 'A plugin or theme is not compatible with PHP 8 because it uses create_function(), which was removed. Deactivate third-party plugins and switch to a default theme such as Twenty Twenty-Five, then try again.', 'wp-module-staging' );
		}

		$component = $components[0];

		if ( 'theme' === $component['type'] ) {
			return sprintf(
				/* translators: %s: theme directory name. */
				__( 'The theme "%s" is not compatible with PHP 8 because it uses create_function(), which was removed. Deactivate it, then try again.', 'wp-module-staging' ),
				$component['slug']
			);
		}

		if ( 'mu-plugin' === $component['type'] ) {
			return sprintf(
				/* translators: %s: must-use plugin directory or file name. */
				__( 'The must-use plugin "%s" is not compatible with PHP 8 because it uses create_function(), which was removed. Remove it from mu-plugins, then try again.', 'wp-module-staging' ),
				$component['slug']
			);
		}

		return sprintf(
			/* translators: %s: plugin directory name. */
			__( 'The plugin "%s" is not compatible with PHP 8 because it uses create_function(), which was removed. Deactivate it, then try again.', 'wp-module-staging' ),
			$component['slug']
		);
	}

	/**
	 * Message for a Cannot redeclare fatal.
	 *
	 * @param array<int, array{type: string, slug: string}> $components Components on the fatal line.
	 * @return string
	 */
	protected function redeclare_message( array $components ) {
		if ( count( $components ) >= 2 ) {
			return sprintf(
				/* translators: 1: plugin or theme that declared the symbol first, 2: plugin or theme that declared it again. */
				__( 'The plugin or theme "%1$s" conflicts with "%2$s" (Cannot redeclare). Deactivate or remove one of them, then try again.', 'wp-module-staging' ),
				$components[0]['slug'],
				$components[1]['slug']
			);
		}

		if ( 1 === count( $components ) && 'theme' === $components[0]['type'] ) {
			return sprintf(
				/* translators: %s: theme directory name. */
				__( 'The theme "%s" has a code conflict (Cannot redeclare). Deactivate it, then try again.', 'wp-module-staging' ),
				$components[0]['slug']
			);
		}

		if ( 1 === count( $components ) && 'mu-plugin' === $components[0]['type'] ) {
			return sprintf(
				/* translators: %s: must-use plugin directory or file name. */
				__( 'The must-use plugin "%s" has a code conflict (Cannot redeclare). Remove it from mu-plugins, then try again.', 'wp-module-staging' ),
				$components[0]['slug']
			);
		}

		if ( 1 === count( $components ) ) {
			return sprintf(
				/* translators: %s: plugin directory name. */
				__( 'The plugin "%s" has a code conflict (Cannot redeclare). Deactivate it, then try again.', 'wp-module-staging' ),
				$components[0]['slug']
			);
		}

		return __( 'A plugin has a code conflict (Cannot redeclare). Deactivate third-party plugins and switch to a default theme such as Twenty Twenty-Five, then try again.', 'wp-module-staging' );
	}

	/**
	 * Message for any other fatal.
	 *
	 * @param array<int, array{type: string, slug: string}> $components Components on the fatal line.
	 * @return string
	 */
	protected function generic_message( array $components ) {
		if ( empty( $components ) ) {
			return __( 'WordPress could not start because of a fatal error in a plugin or theme. Deactivate third-party plugins and switch to a default theme such as Twenty Twenty-Five, then try again.', 'wp-module-staging' );
		}

		$component = $components[0];

		if ( 'theme' === $component['type'] ) {
			return sprintf(
				/* translators: %s: theme directory name. */
				__( 'The theme "%s" caused a fatal error while loading WordPress. Deactivate it, or deactivate third-party plugins and switch to a default theme such as Twenty Twenty-Five, then try again.', 'wp-module-staging' ),
				$component['slug']
			);
		}

		if ( 'mu-plugin' === $component['type'] ) {
			return sprintf(
				/* translators: %s: must-use plugin directory or file name. */
				__( 'The must-use plugin "%s" caused a fatal error while loading WordPress. Remove it from mu-plugins, then try again.', 'wp-module-staging' ),
				$component['slug']
			);
		}

		return sprintf(
			/* translators: %s: plugin directory name. */
			__( 'The plugin "%s" caused a fatal error while loading WordPress. Deactivate it, or deactivate third-party plugins and switch to a default theme such as Twenty Twenty-Five, then try again.', 'wp-module-staging' ),
			$component['slug']
		);
	}

	/**
	 * Whether the output contains a PHP fatal, as opposed to a WP-CLI or database error.
	 *
	 * @param string $text CLI output.
	 * @return bool
	 */
	protected function output_has_fatal( $text ) {
		return (bool) preg_match(
			'/NFD_BOOTSTRAP_FATAL:|Fatal error|Parse error|There has been a critical error/i',
			(string) $text
		);
	}

	/**
	 * Whether the probe could not be launched, as opposed to WordPress failing to boot.
	 *
	 * @param int    $status Process exit status.
	 * @param string $text   CLI output.
	 * @return bool
	 */
	protected function is_tool_missing( $status, $text ) {
		if ( self::MISSING_EXIT === $status ) {
			return true;
		}

		return false !== stripos( $text, 'command not found' );
	}

	/**
	 * Whether exec() is listed in disable_functions.
	 *
	 * @return bool
	 */
	protected function exec_disabled() {
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );

		return in_array( 'exec', $disabled, true );
	}

	/**
	 * Shell command for one bootstrap. The path is escaped and is never taken from request input.
	 *
	 * /usr/local/bin is where wp lives on the hosts this module targets. runCommand() adds it
	 * only after this probe, and lib/.staging exports it inside the script, so the probe sets it.
	 *
	 * @param string $path            Directory to pass to WP-CLI --path.
	 * @param bool   $load_extensions Whether to load regular plugins and themes.
	 * @return string
	 */
	protected function build_command( $path, $load_extensions = true ) {
		$skip = $load_extensions ? '' : ' --skip-plugins --skip-themes';

		return sprintf(
			'PATH="$PATH:/usr/local/bin" timeout %d wp --exec=%s --exec=%s eval %s --path=%s%s --quiet 2>&1',
			self::TIMEOUT_SECONDS,
			escapeshellarg( self::WP_CLI_EXEC ),
			escapeshellarg( self::FATAL_REPORTER ),
			escapeshellarg( 'echo "ok";' ),
			escapeshellarg( untrailingslashit( $path ) ),
			$skip
		);
	}

	/**
	 * Drop cached options for the install that was just probed.
	 *
	 * Runs with plugins skipped and the object-cache drop-in loaded, which is the opposite of the
	 * probe. wp_cache_delete() then reaches the same Redis keys the next web request or SSO command
	 * will read. A broken drop-in must not turn into a user-facing error, so the result is ignored.
	 *
	 * @param string $path Directory that was probed.
	 * @return void
	 */
	protected function evict_object_cache( $path ) {
		$command = sprintf(
			'PATH="$PATH:/usr/local/bin" timeout %d wp --exec=%s eval %s --path=%s --skip-plugins --skip-themes --quiet 2>/dev/null',
			self::TIMEOUT_SECONDS,
			escapeshellarg( self::CACHE_EVICTION_BOOT ),
			escapeshellarg( self::CACHE_EVICTION ),
			escapeshellarg( untrailingslashit( $path ) )
		);

		$output = array();
		$status = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $status );
	}

	/**
	 * Append a single truncated line to the production staging log.
	 *
	 * @param string $path    Directory that was probed.
	 * @param string $level   WARN or ERROR.
	 * @param string $message Raw CLI output.
	 * @param int    $status  Process exit status.
	 * @return void
	 */
	protected function log_output( $path, $level, $message, $status ) {
		$log_dir  = $this->log_directory( $path ) . 'nfd-private';
		$log_file = $log_dir . '/' . self::LOG_FILE;

		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$htaccess = $log_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n" );
		}

		$flat = preg_replace( '/\s+/', ' ', (string) $message );
		$flat = is_string( $flat ) ? trim( $flat ) : '';
		if ( strlen( $flat ) > self::LOG_OUTPUT_LIMIT ) {
			$flat = substr( $flat, 0, self::LOG_OUTPUT_LIMIT ) . '...';
		}

		$line = sprintf(
			"%s [%s] [%s] status=%d %s\n",
			gmdate( 'Y-m-d H:i:s' ),
			$level,
			'bootstrap_check',
			(int) $status,
			$flat
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Production directory that owns nfd-private, derived from the probed path.
	 *
	 * @param string $path Directory that was probed.
	 * @return string
	 */
	protected function log_directory( $path ) {
		if ( StagingPath::is_staging_abspath( $path ) ) {
			$parsed = StagingPath::parse_staging_from_abspath( $path );
			if ( is_array( $parsed ) && ! empty( $parsed['production_dir'] ) ) {
				return $parsed['production_dir'];
			}
		}

		return StagingPath::normalize_trailing_slash( $path );
	}
}
