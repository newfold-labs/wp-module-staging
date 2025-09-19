<?php
namespace NewfoldLabs\WP\Module\Staging;

use NewfoldLabs\WP\ModuleLoader\Container;
use function NewfoldLabs\WP\ModuleLoader\container;

/**
 * This class adds staging functionality.
 **/
class Staging {

	/**
	 * Dependency injection container.
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Slug used for the Staging module's admin page.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nfd-staging';


	/**
	 * Constructor.
	 *
	 * @param Container $container The module container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;

		// Module functionality goes here
		add_action(
			'rest_api_init',
			function () {
				$instance = new StagingApi( $this->container );
				$instance->register_routes();
			}
		);
		add_action( 'wp_loaded', array( StagingMenu::class, 'init' ), 100 );

		// Mark Safe Mode as confirmed so the banner never re-appears
		add_action( 'init', array( $this, 'confirm_jetpack_safe_mode' ), 20 );

		// add isStaging as computed value to container
		$this->container->set(
			'isStaging',
			$this->container->computed(
				function () {
					return $this->isStaging();
				}
			)
		);

		// add CLI commands
		add_action(
			'cli_init',
			function () {
				\WP_CLI::add_command(
					'newfold staging',
					'NewfoldLabs\WP\Module\Staging\StagingCLI',
					array(
						'shortdesc' => 'Operations for Newfold staging.',
						'longdesc'  => 'Internal commands to handle staging environment.' .
										PHP_EOL . 'Subcommands: create, clone, destroy, sso_staging, deploy, deploy_files,' .
										' deploy_db, deploy_files_db, save_state, restore_state, sso_production',
					)
				);
			}
		);

		add_action( 'init', array( __CLASS__, 'loadTextDomain' ), 100 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'initialize_staging_app' ) );

		new Constants( $container );
	}

	/**
	 * Confirms Jetpack Safe Mode programmatically to suppress the banner.
	 *
	 * When a staging site is detected, this method sets the internal Jetpack option
	 * `safe_mode_confirmed` to `true`, which tells Jetpack that the user has already
	 * acknowledged Safe Mode. This prevents repeated prompts or blocked rendering on admin pages.
	 *
	 * @return void
	 */
	public function confirm_jetpack_safe_mode() {
		if ( $this->isStaging() && class_exists( 'Jetpack_Options' ) ) {
			\Jetpack_Options::update_option( 'safe_mode_confirmed', true );
		}
	}

	/**
	 * Initializes the Staging module by registering and enqueuing its assets.
	 *
	 * @return void
	 */
	public static function initialize_staging_app() {
		self::register_staging_assets();
	}

	/**
	 * Registers and enqueues the JavaScript and CSS assets for the Staging module.
	 *
	 * @return void
	 */
	public static function register_staging_assets() {
		$build_dir  = NFD_STAGING_BUILD_DIR;
		$build_url  = NFD_STAGING_BUILD_URL;
		$asset_file = $build_dir . '/staging/staging.min.asset.php';

		if ( is_readable( $asset_file ) ) {
			$asset = include_once $asset_file;

			wp_register_script(
				self::PAGE_SLUG,
				$build_url . '/staging/staging.min.js',
				$asset['dependencies'],
				$asset['version'],
				true
			);

			wp_register_style(
				self::PAGE_SLUG,
				$build_url . '/staging/staging.min.css',
				array(),
				$asset['version']
			);
		}

		$screen = \get_current_screen();
		if ( isset( $screen->id ) && false !== strpos( $screen->id, self::PAGE_SLUG ) ) {
			wp_enqueue_script( self::PAGE_SLUG );
			wp_enqueue_style( self::PAGE_SLUG );
		}
	}

	/**
	 * Load text domain for Module
	 *
	 * @return void
	 */
	public static function loadTextDomain() {
		\load_plugin_textdomain(
			'wp-module-staging',
			false,
			dirname( plugin_basename( NFD_STAGING_DIR ) ) . '/' . basename( NFD_STAGING_DIR ) . '/languages'
		);
	}

	/**
	 * Get an instance of this class.
	 *
	 * @return Staging
	 */
	public static function getInstance() {
		return new self( container() );
	}

	/**
	 * Get the staging configuration.
	 *
	 * @param bool $cache Whether or not to hit the cached config on this function call.
	 *
	 * @return array
	 */
	public function getConfig( $cache = true ) {
		static $config;

		if ( ! isset( $config ) || false === $cache ) {
			$config = get_option( 'staging_config', array() );
		}

		return $config;
	}

	/**
	 * Get a specific staging configuration value.
	 *
	 * Allowed keys:
	 *  - production_dir
	 *  - production_url
	 *  - staging_dir
	 *  - staging_url
	 *  - creation_date
	 *
	 * @param string $key     Configuration name.
	 * @param string $std Return default value if key doesn't exist.
	 *
	 * @return string
	 */
	public function getConfigValue( $key, $std = '' ) {
		$config = $this->getConfig();

		return isset( $config[ $key ] ) ? $config[ $key ] : $std;
	}

	/**
	 * Get the production directory
	 *
	 * @return string
	 */
	public function getProductionDir() {
		return $this->getConfigValue( 'production_dir', ABSPATH );
	}

	/**
	 * Get the production URL
	 *
	 * @return string
	 */
	public function getProductionUrl() {
		return $this->getConfigValue( 'production_url', site_url() );
	}

	/**
	 * Get the staging directory
	 *
	 * @return string
	 */
	public function getStagingDir() {
		$staging_dir = $this->getConfigValue( 'staging_dir' );

		// Validate the staging directory path for security
		if ( ! empty( $staging_dir ) && ! $this->isValidStagingPath( $staging_dir ) ) {
			error_log( 'Invalid staging directory path in configuration: ' . $staging_dir );
			return '';
		}

		return $staging_dir;
	}

	/**
	 * Get the staging URL
	 *
	 * @return string
	 */
	public function getStagingUrl() {
		return $this->getConfigValue( 'staging_url' );
	}

	/**
	 * Get the staging creation date
	 *
	 * @return string
	 */
	public function getCreationDate() {
		return $this->getConfigValue( 'creation_date' );
	}

	/**
	 * Get the name of the current environment.
	 *
	 * @return string|false
	 */
	public function getEnvironment() {
		return get_option( 'staging_environment', 'production' );
	}

	/**
	 * Get production screenshot URL.
	 *
	 * @return string
	 */
	public function getProductionScreenshotUrl() {
		return '';
	}

	/**
	 * Get staging screenshot URL.
	 *
	 * @return string
	 */
	public function getStagingScreenshotUrl() {
		return '';
	}

	/**
	 * Check if the current environment matches a specific value.
	 *
	 * @param string $env Environment name (production or staging).
	 *
	 * @return bool
	 */
	public function isEnvironment( $env ) {
		return $this->getEnvironment() === $env;
	}

	/**
	 * Check if the current environment is staging.
	 *
	 * @return bool
	 */
	public function isStaging() {
		return $this->isEnvironment( 'staging' );
	}

	/**
	 * Check if the current environment is production.
	 *
	 * @return bool
	 */
	public function isProduction() {
		return $this->isEnvironment( 'production' );
	}

	/**
	 * Check if the staging exists
	 *
	 * @return bool
	 */
	public function stagingExists() {
		$stagingDir = $this->getStagingDir();
		return ! empty( $stagingDir ) && file_exists( $stagingDir ) && $this->isValidStagingPath( $stagingDir );
	}

	/**
	 * Clone production environment to staging.
	 *
	 * @return array|\WP_Error
	 */
	public function cloneProductionToStaging() {
		if ( ! $this->isProduction() ) {
			return new \WP_Error(
				'invalid_environment',
				__( 'Cloning can only be done from the production environment.', 'wp-module-staging' )
			);
		}

		$response = $this->runCommand( 'clone' );

		// If clone succeeded, ensure staging site's wp-config.php sets environment type.
		if ( is_array( $response ) && isset( $response['status'] ) && 'success' === $response['status'] ) {
			$this->getConfig( false ); // Refresh cache
			$this->waitForOptionWrite(); // Efficient wait for option to be written
			$this->setWpEnvironmentTypeForStagingSite();
		}

		return $response;
	}

	/**
	 * Run a compatibility check to see if the environment supports staging.
	 *
	 * @return array|\WP_Error
	 */
	public function compatibilityCheck() {
		return $this->runCommand( 'compat_check' );
	}

	/**
	 * Create a staging environment.
	 *
	 * @return array|\WP_Error
	 */
	public function createStaging() {
		if ( $this->stagingExists() ) {
			return new \WP_Error(
				'environment_exists',
				__( 'Staging environment already exists!', 'wp-module-staging' )
			);
		}

		$response = $this->runCommand( 'create' );

		// If creation succeeded, set WP_ENVIRONMENT_TYPE in the staging site's wp-config.php via WP-CLI.
		if ( is_array( $response ) && isset( $response['status'] ) && 'success' === $response['status'] ) {
			$this->getConfig( false ); // Refresh cache
			$this->waitForOptionWrite(); // Efficient wait for option to be written
			$this->setWpEnvironmentTypeForStagingSite();
		}

		return $response;
	}

	/**
	 * Wait for WordPress option to be written with efficient polling.
	 *
	 * Uses a configurable timeout with short intervals to avoid blocking delays
	 * while ensuring the option is properly written before proceeding.
	 *
	 * @param int $max_wait_seconds Maximum time to wait in seconds (default: 3).
	 * @param int $interval_ms Interval between checks in milliseconds (default: 100).
	 * @return bool True if option is ready, false if timeout exceeded.
	 */
	protected function waitForOptionWrite( $max_wait_seconds = 3, $interval_ms = 100 ) {
		$max_iterations = ( $max_wait_seconds * 1000 ) / $interval_ms;
		$iterations     = 0;

		while ( $iterations < $max_iterations ) {
			// Check if the staging directory exists and is accessible
			$staging_dir = $this->getStagingDir();
			if ( ! empty( $staging_dir ) && is_dir( $staging_dir ) ) {
				// Check if wp-config.php exists in staging directory
				$wp_config_path = $staging_dir . '/wp-config.php';
				if ( file_exists( $wp_config_path ) && is_readable( $wp_config_path ) ) {
					return true;
				}
			}

			// Small delay before next check
			usleep( $interval_ms * 1000 ); // Convert ms to microseconds
			++$iterations;
		}

		return false;
	}

	/**
	 * Validate staging directory path for security.
	 *
	 * Performs comprehensive validation to prevent command injection and directory traversal attacks.
	 * Validates that the path is within allowed directories and contains no malicious characters.
	 *
	 * @param string $path The staging directory path to validate.
	 * @return bool True if the path is valid, false otherwise.
	 */
	protected function isValidStagingPath( $path ) {
		// Check if path is empty or null
		if ( empty( $path ) || ! is_string( $path ) ) {
			return false;
		}

		// Normalize the path to handle any potential directory traversal
		$normalized_path = realpath( $path );
		if ( false === $normalized_path ) {
			// If realpath fails, check if it's a valid directory that exists
			if ( ! is_dir( $path ) ) {
				return false;
			}
			$normalized_path = $path;
		}

		// Ensure the path is within the WordPress installation directory
		$wp_root = realpath( ABSPATH );
		if ( false === $wp_root ) {
			$wp_root = ABSPATH;
		}

		// Check if the staging directory is within the WordPress root or a subdirectory
		if ( 0 !== strpos( $normalized_path, $wp_root ) ) {
			return false;
		}

		// Additional security checks for malicious characters
		$dangerous_patterns = array(
			'/\.\./',           // Directory traversal
			'/[\x00-\x1f\x7f]/', // Control characters
			'/[;&|`$]/',        // Command injection characters
			'/\/\//',           // Double slashes (potential path manipulation)
		);

		foreach ( $dangerous_patterns as $pattern ) {
			if ( preg_match( $pattern, $path ) ) {
				return false;
			}
		}

		// Ensure the path doesn't contain any spaces that could be exploited
		if ( preg_match( '/\s/', $path ) ) {
			return false;
		}

		// Validate that the path is a directory and is readable
		if ( ! is_dir( $normalized_path ) || ! is_readable( $normalized_path ) ) {
			return false;
		}

		// Check that wp-config.php exists in the staging directory
		$wp_config_path = $normalized_path . '/wp-config.php';
		if ( ! file_exists( $wp_config_path ) || ! is_readable( $wp_config_path ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Sanitize staging directory path to prevent security issues.
	 *
	 * Removes dangerous characters and normalizes the path for safe use.
	 *
	 * @param string $path The staging directory path to sanitize.
	 * @return string The sanitized path.
	 */
	protected function sanitizeStagingPath( $path ) {
		if ( empty( $path ) || ! is_string( $path ) ) {
			return '';
		}

		// Remove any control characters and dangerous characters
		$path = preg_replace( '/[\x00-\x1f\x7f]/', '', $path );
		$path = preg_replace( '/[;&|`$]/', '', $path );

		// Normalize path separators
		$path = str_replace( '\\', '/', $path );
		$path = preg_replace( '/\/+/', '/', $path );

		// Remove any leading/trailing whitespace
		$path = trim( $path );

		// Ensure the path doesn't start with a slash unless it's an absolute path
		if ( ! empty( $path ) && '/' !== $path[0] && ! preg_match( '/^[A-Za-z]:/', $path ) ) {
			$path = '/' . $path;
		}

		return $path;
	}

	/**
	 * Ensure the staging site's wp-config.php defines WP_ENVIRONMENT_TYPE as 'staging'.
	 *
	 * This operates directly on the staging site's wp-config.php using WP_Filesystem
	 * and adds a defensive define if one does not already exist.
	 *
	 * @return void
	 */
	protected function setWpEnvironmentTypeForStagingSite() {
		$stagingDir = \rtrim( $this->getStagingDir(), '/' );
		if ( empty( $stagingDir ) ) {
			return;
		}

		// Validate the staging directory path for security
		if ( ! $this->isValidStagingPath( $stagingDir ) ) {
			error_log( 'Invalid staging directory path detected: ' . $stagingDir );
			return;
		}

		// Build and execute WP-CLI command to set the constant in wp-config.php of staging site.
		$path_arg = \escapeshellarg( $stagingDir );
		$cmd      = 'wp config set WP_ENVIRONMENT_TYPE staging --type=constant --quiet --skip-themes --skip-plugins --path=' . $path_arg;

		// Ensure common PATHs are available for wp binary
		\putenv( 'PATH=' . \getenv( 'PATH' ) . PATH_SEPARATOR . '/usr/local/bin' . PATH_SEPARATOR . '/usr/bin' ); // phpcs:ignore

		// Execute the command and capture output and return code for proper error handling.
		$output      = array();
		$return_code = 0;
		\exec( $cmd . ' 2>&1', $output, $return_code ); // phpcs:ignore

		// Log any errors for debugging purposes.
		if ( 0 !== $return_code ) {
			error_log(
				sprintf(
					'WP-CLI command failed with return code %d: %s. Output: %s',
					$return_code,
					$cmd,
					implode( "\n", $output )
				)
			);
		}
	}

	/**
	 * Deploy changes from staging to production.
	 *
	 * @param string $type Deployment type. One of `db`, `files`, or `all`.
	 *
	 * @return array|\WP_Error
	 */
	public function deployToProduction( $type = 'all' ) {
		switch ( $type ) {
			case 'db':
				return $this->runCommand( 'deploy_db' );
			case 'files':
				return $this->runCommand( 'deploy_files' );
			default:
				return $this->runCommand( 'deploy_files_db' );
		}
	}

	/**
	 * Destroy the staging environment.
	 *
	 * @return array|\WP_Error
	 */
	public function destroyStaging() {
		if ( ! $this->isProduction() ) {
			return new \WP_Error(
				'invalid_environment',
				__( 'You must switch to the production environment before destroying staging.', 'wp-module-staging' )
			);
		}

		return $this->runCommand( 'destroy' );
	}

	/**
	 * Switch to a different environment.
	 *
	 * @param string $env     Environment name (staging or production).
	 * @param int    $user_id User ID to login as.
	 *
	 * @return array|\WP_Error
	 */
	public function switchTo( $env, $user_id = 0 ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( $this->isEnvironment( $env ) ) {
			return new \WP_Error(
				'invalid_environment',
				__( 'Switch to an environment you are already in, you cannot.', 'wp-module-staging' )
			);
		}

		if ( 'staging' === $env ) {
			return $this->runCommand( 'sso_staging', array( $user_id ) );
		}

		return $this->runCommand( 'sso_production', array( $user_id ) );
	}

	/**
	 * Execute a staging CLI command.
	 *
	 * @param string     $command CLI command to be run.
	 * @param array|null $args    CLI command arguments to be passed.
	 *
	 * @return array|\WP_Error
	 */
	protected function runCommand( $command, $args = null ) {

		$allowedCommands = array(
			'clone'           => true,
			'compat_check'    => true,
			'create'          => true,
			'deploy_db'       => true,
			'deploy_files'    => true,
			'deploy_files_db' => true,
			'destroy'         => true,
			'sso_production'  => true,
			'sso_staging'     => true,
		);

		// Check if command is allowed
		if ( ! array_key_exists( $command, $allowedCommands ) ) {
			return new \WP_Error(
				'invalid_command',
				__( 'Invalid staging CLI command.', 'wp-module-staging' )
			);
		}

		$config = $this->getConfig();

		// If config is empty, then we are creating a staging environment.
		if ( empty( $config ) || 'create' === $command ) {

			$uniqueId = wp_rand( 1000, 9999 );

			$staging_dir = ABSPATH . 'staging/' . $uniqueId;
			$config      = array(
				'creation_date'  => gmdate( 'M j, Y' ),
				'production_dir' => ABSPATH,
				'production_url' => get_option( 'siteurl' ),
				'staging_dir'    => $this->sanitizeStagingPath( $staging_dir ),
				'staging_url'    => get_option( 'siteurl' ) . '/staging/' . $uniqueId,
			);

			update_option( 'staging_config', $config );

		}

		$token = wp_generate_password( 32, false );
		set_transient( 'staging_auth_token', $token, 60 );

		$command = array(
			$command,
			$token,
			$config['production_dir'],
			$config['staging_dir'],
			$config['production_url'],
			$config['staging_url'],
			get_current_user_id(),
			container()->plugin()->id,
		);

		if ( $args && is_array( $args ) ) {
			$command = array_merge( $command, array_values( $args ) );
		}

		$command = implode( ' ', array_map( 'escapeshellcmd', $command ) );

		// Check for invalid characters
		$invalidChars = array( ';', '&', '|' );
		foreach ( $invalidChars as $char ) {
			if ( false !== strpos( $command, $char ) ) {
				return new \WP_Error(
					'invalid_character',
					// translators: Invalid character that was entered
					sprintf( __( 'Invalid character (%s) in command.', 'wp-module-staging' ), $char )
				);
			}
		}

		$script = container()->plugin()->dir . 'vendor/newfold-labs/wp-module-staging/lib/.staging';

		$disabled_functions = explode( ',', ini_get( 'disable_functions' ) );
		if ( is_array( $disabled_functions ) && in_array( 'exec', array_map( 'trim', $disabled_functions ), true ) ) {
			return new \WP_Error( 'error_response', __( 'Unable to execute script (disabled_function).', 'wp-module-staging' ) );
		}

		// Verify staging script file permissions using WP_Filesystem API
		global $wp_filesystem;

		if ( ! function_exists( 'request_filesystem_credentials' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$creds = request_filesystem_credentials( '', '', false, false, null );

		if ( false === $creds ) {
			return new \WP_Error( 'error_response', __( 'Filesystem credentials required.', 'wp-module-staging' ) );
		}

		if ( ! WP_Filesystem( $creds ) ) {
			return new \WP_Error( 'error_response', __( 'Unable to initialize WP Filesystem.', 'wp-module-staging' ) );
		}

		if ( $wp_filesystem->exists( $script ) ) {
			if ( $wp_filesystem->is_writable( $script ) ) {
				$wp_filesystem->chmod( $script, 0755 );
			} else {
				return new \WP_Error( 'error_response', __( 'Unable to execute script (permission error).', 'wp-module-staging' ) );
			}
		}

		putenv( 'PATH=' . getenv( 'PATH' ) . PATH_SEPARATOR . '/usr/local/bin' ); // phpcs:ignore

		do_action( 'newfold_staging_command', $command ); // bh_staging_command

		$json = exec( "{$script} {$command}" ); // phpcs:ignore

		// Check if we can properly decode the JSON
		$response = json_decode( $json, true );

		if ( ! $response ) {
			return new \WP_Error( 'json_decode', __( 'Unable to parse JSON', 'wp-module-staging' ) );
		}

		// Check if response is an error response.
		if ( isset( $response->status, $response->message ) && 'error' === $response->status ) {
			return new \WP_Error( 'error_response', $response->message );
		}

		return $response;
	}
}
