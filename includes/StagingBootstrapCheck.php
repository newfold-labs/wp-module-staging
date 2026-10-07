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
	 * @var string
	 */
	const FATAL_REPORTER = <<<'PHP'
register_shutdown_function( function () { $e = error_get_last(); if ( is_array( $e ) && in_array( $e['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) { fwrite( STDERR, 'NFD_BOOTSTRAP_FATAL: ' . strtok( (string) $e['message'], "\n" ) . ' in ' . $e['file'] . ':' . $e['line'] . "\n" ); } } );
PHP;

	/**
	 * Bootstrap WordPress at $path by running `wp eval` with plugins and themes loaded.
	 *
	 * Missing wp/timeout, a disabled exec(), or a directory that is not there fail open: the
	 * staging script already reports those. A fatal, or a probe that exceeds the timeout, is
	 * returned to the user.
	 *
	 * @param string $path Production or staging directory.
	 * @return true|\WP_Error
	 */
	public function check( $path ) {
		$path = is_string( $path ) ? $path : '';
		if ( '' === $path || ! is_dir( $path ) ) {
			return true;
		}

		if ( $this->exec_disabled() ) {
			return true;
		}

		$command = $this->build_command( $path );
		$output  = array();
		$status  = 0;

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $command, $output, $status );

		$text   = implode( "\n", $output );
		$status = (int) $status;

		if ( 0 === $status && $this->output_is_ok( $text ) ) {
			return true;
		}

		if ( $this->is_tool_missing( $status, $text ) ) {
			$this->log_output( $path, 'WARN', $text );
			return true;
		}

		$this->log_output( $path, 'ERROR', $text );

		if ( self::TIMEOUT_EXIT === $status ) {
			return new \WP_Error( self::ERROR_CODE, $this->generic_message( array() ) );
		}

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

		if ( false !== stripos( $output, 'create_function' ) ) {
			return $this->create_function_message( $components );
		}

		if ( false !== stripos( $output, 'Cannot redeclare' ) ) {
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
		$lines    = preg_split( '/\R/', (string) $output );
		$lines    = is_array( $lines ) ? $lines : array();
		$reported = array();
		$relevant = array();

		foreach ( $lines as $line ) {
			if ( false !== strpos( $line, self::FATAL_MARKER ) ) {
				$reported[] = $line;
			} elseif ( preg_match( '/Fatal error|Parse error|Cannot redeclare|create_function\s*\(|^\s*thrown in\b/i', $line ) ) {
				$relevant[] = $line;
			}
		}

		if ( ! empty( $reported ) ) {
			$relevant = $reported;
		}

		$found = array();
		$seen  = array();

		foreach ( $relevant as $line ) {
			if ( ! preg_match_all( '#/(plugins|themes)/([A-Za-z0-9_-]+)(?:/|\.php\b)#', $line, $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				$key = $match[1] . ':' . $match[2];
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}

				$seen[ $key ] = true;
				$found[]      = array(
					'type' => 'themes' === $match[1] ? 'theme' : 'plugin',
					'slug' => $match[2],
				);
			}
		}

		return $found;
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
				__( 'The plugin or theme "%1$s" conflicts with "%2$s" (Cannot redeclare). Deactivate one of them, then try again.', 'wp-module-staging' ),
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
	 * Message for any other fatal, including a probe that timed out.
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

		return sprintf(
			/* translators: %s: plugin directory name. */
			__( 'The plugin "%s" caused a fatal error while loading WordPress. Deactivate it, or deactivate third-party plugins and switch to a default theme such as Twenty Twenty-Five, then try again.', 'wp-module-staging' ),
			$component['slug']
		);
	}

	/**
	 * Whether the probe printed the expected token on its own line.
	 *
	 * A substring check would treat notices that merely contain those letters as success.
	 *
	 * @param string $text CLI output.
	 * @return bool
	 */
	protected function output_is_ok( $text ) {
		return (bool) preg_match( '/(^|\R)ok(\R|$)/', $text );
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
	 * @param string $path Directory to pass to WP-CLI --path.
	 * @return string
	 */
	protected function build_command( $path ) {
		return sprintf(
			'timeout %d wp --exec=%s --exec=%s eval %s --path=%s --quiet 2>&1',
			self::TIMEOUT_SECONDS,
			escapeshellarg( self::WP_CLI_EXEC ),
			escapeshellarg( self::FATAL_REPORTER ),
			escapeshellarg( 'echo "ok";' ),
			escapeshellarg( untrailingslashit( $path ) )
		);
	}

	/**
	 * Append a single truncated line to the production staging log.
	 *
	 * @param string $path    Directory that was probed.
	 * @param string $level   WARN or ERROR.
	 * @param string $message Raw CLI output.
	 * @return void
	 */
	protected function log_output( $path, $level, $message ) {
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
			"%s [%s] [%s] %s\n",
			gmdate( 'Y-m-d H:i:s' ),
			$level,
			'bootstrap_check',
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
