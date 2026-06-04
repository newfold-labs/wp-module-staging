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

		add_action( 'admin_menu', array( $this, 'add_log_admin_page' ) );

		add_action( 'init', array( $this, 'clean_log' ) );

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
		$asset_file = $build_dir . '/staging/bundle.asset.php';

		if ( is_readable( $asset_file ) ) {
			$asset = include_once $asset_file;

			wp_register_script(
				self::PAGE_SLUG,
				$build_url . '/staging/bundle.js',
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
		if (
			isset( $screen->id ) &&
			(
				false !== strpos( $screen->id, self::PAGE_SLUG ) ||
				false !== strpos( $screen->id, container()->plugin()->id )
			)
		) {
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
		return $this->getConfigValue( 'staging_dir' );
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
		return ! empty( $stagingDir ) && file_exists( $stagingDir );
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

		return $this->runCommand( 'clone' );
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

		return $this->runCommand( 'create' );
	}

	/**
	 * Deploy changes from staging to production.
	 *
	 * Long-running deploys run asynchronously so the HTTP response returns before
	 * Cloudflare or other proxies hit their read timeout (often 120 seconds).
	 *
	 * @param string $type Deployment type. One of `db`, `files`, or `all`.
	 *
	 * @return array|\WP_Error
	 */
	public function deployToProduction( $type = 'all' ) {
		return $this->runCommand( $this->getDeployCommandForType( $type ) );
	}

	/**
	 * Get the current status of a deploy job (for polling after async start).
	 *
	 * @param string $type Deployment type. One of `db`, `files`, or `all`.
	 *
	 * @return array
	 */
	public function getDeployStatus( $type = 'all' ) {
		return $this->getDeployCommandStatus( $this->getDeployCommandForType( $type ) );
	}

	/**
	 * Map REST deploy type to the staging CLI command name.
	 *
	 * @param string $type Deployment type.
	 *
	 * @return string
	 */
	protected function getDeployCommandForType( $type ) {
		switch ( $type ) {
			case 'db':
				return 'deploy_db';
			case 'files':
				return 'deploy_files';
			default:
				return 'deploy_files_db';
		}
	}

	/**
	 * Whether the command is a long-running deploy operation.
	 *
	 * @param string $command CLI command name.
	 *
	 * @return bool
	 */
	protected function isDeployCommand( $command ) {
		return in_array( $command, array( 'deploy_db', 'deploy_files', 'deploy_files_db' ), true );
	}

	/**
	 * Path to the JSON file that stores async deploy progress/result.
	 *
	 * @return string
	 */
	protected function getDeployResultPath() {
		return trailingslashit( $this->getProductionDir() ) . 'nfd-private/nfd-staging-deploy-result.json';
	}

	/**
	 * Whether a deploy is already running.
	 *
	 * @return bool
	 */
	protected function isDeployInProgress() {
		$result = $this->readDeployResult();
		if ( is_array( $result ) && isset( $result['status'] ) && 'running' === $result['status'] ) {
			return true;
		}

		return (bool) get_transient( 'nfd_staging_lock' );
	}

	/**
	 * Persist async deploy progress or result to disk.
	 *
	 * @param array $data Result payload.
	 */
	protected function writeDeployResult( array $data ) {
		$path = $this->getDeployResultPath();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $path, wp_json_encode( $data ) );
	}

	/**
	 * Read async deploy progress or result from disk.
	 *
	 * @return array|null
	 */
	protected function readDeployResult() {
		$path = $this->getDeployResultPath();
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Parse the timestamp prefix from a staging log line.
	 *
	 * @param string $line Log line.
	 *
	 * @return int|null Unix timestamp, or null when not parseable.
	 */
	protected function parseLogLineTimestamp( $line ) {
		if ( preg_match( '/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/', $line, $matches ) ) {
			$parsed = strtotime( $matches[1] );
			return $parsed ? $parsed : null;
		}

		return null;
	}

	/**
	 * Resolve deploy status from the staging log (fallback when the HTTP request timed out).
	 *
	 * @param string   $command          CLI command name.
	 * @param int|null $since_timestamp  Only consider log lines at or after this Unix time.
	 *
	 * @return array|null
	 */
	protected function getDeployStatusFromLog( $command, $since_timestamp = null ) {
		$log_file = trailingslashit( $this->getProductionDir() ) . 'nfd-private/nfd-staging.log';
		if ( ! file_exists( $log_file ) ) {
			return null;
		}

		$success_step = $this->getDeploySuccessLogStep( $command );
		if ( ! $success_step ) {
			return null;
		}

		$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $lines ) ) {
			return null;
		}

		$lines = array_reverse( $lines );
		foreach ( $lines as $line ) {
			$line_time = $this->parseLogLineTimestamp( $line );
			if ( $since_timestamp && $line_time && $line_time < ( $since_timestamp - 30 ) ) {
				continue;
			}

			// Only treat deploy_* step errors as failures (ignore rsync warnings on prepare_new_content_dirs).
			if ( preg_match( '/\[ERROR\]\s+\[deploy_/', $line ) ) {
				return array(
					'status'  => 'error',
					'command' => $command,
					'message' => __( 'Deployment failed. Check the staging log for details.', 'wp-module-staging' ),
				);
			}
			if ( false !== strpos( $line, '[SUCCESS]' ) && false !== strpos( $line, '[' . $success_step . ']' ) ) {
				return array(
					'status'  => 'success',
					'command' => $command,
					'message' => $this->getDeploySuccessMessage( $command ),
				);
			}
		}

		return null;
	}

	/**
	 * Log step name that indicates a successful deploy for the given command.
	 *
	 * @param string $command CLI command name.
	 *
	 * @return string
	 */
	protected function getDeploySuccessLogStep( $command ) {
		$map = array(
			'deploy_files'    => 'deploy_files:end',
			'deploy_db'       => 'deploy_db:end',
			'deploy_files_db' => 'deploy_files_db:end',
		);

		return isset( $map[ $command ] ) ? $map[ $command ] : '';
	}

	/**
	 * User-facing success message for a completed deploy command.
	 *
	 * @param string $command CLI command name.
	 *
	 * @return string
	 */
	protected function getDeploySuccessMessage( $command ) {
		$messages = array(
			'deploy_files'    => __( 'Files deployed successfully.', 'wp-module-staging' ),
			'deploy_db'       => __( 'Database deployed successfully.', 'wp-module-staging' ),
			'deploy_files_db' => __( 'Files and Database deployed successfully.', 'wp-module-staging' ),
		);

		return isset( $messages[ $command ] ) ? $messages[ $command ] : __( 'Deployment completed successfully.', 'wp-module-staging' );
	}

	/**
	 * Resolve the current status of a deploy command.
	 *
	 * @param string $command CLI command name.
	 *
	 * @return array
	 */
	protected function getDeployCommandStatus( $command ) {
		$result       = $this->readDeployResult();
		$since        = is_array( $result ) && ! empty( $result['started_at'] ) ? (int) $result['started_at'] : null;
		$same_command = is_array( $result ) && ( empty( $result['command'] ) || $result['command'] === $command );

		if ( $same_command && is_array( $result ) ) {
			if ( 'running' !== $result['status'] ) {
				return $result;
			}
			$since = ! empty( $result['started_at'] ) ? (int) $result['started_at'] : $since;
		}

		if ( $this->isDeployInProgress() ) {
			return array(
				'status'  => 'running',
				'command' => $command,
				'message' => __( 'Deployment in progress. This may take several minutes.', 'wp-module-staging' ),
			);
		}

		$log_status = $this->getDeployStatusFromLog( $command, $since );
		if ( $log_status ) {
			if ( 'success' === $log_status['status'] ) {
				$this->writeDeployResult( $log_status );
			}
			return $log_status;
		}

		return array(
			'status'  => 'running',
			'command' => $command,
			'message' => __( 'Deployment in progress. This may take several minutes.', 'wp-module-staging' ),
		);
	}

	/**
	 * Start deploy in a shutdown handler so the REST response can return immediately.
	 *
	 * @param string $script  Path to the staging shell script.
	 * @param string $command Escaped CLI argument string.
	 *
	 * @return array|\WP_Error
	 */
	protected function startAsyncDeploy( $script, $command ) {
		if ( $this->isDeployInProgress() ) {
			return $this->getDeployCommandStatus( $command );
		}

		$started_at = time();

		$this->writeDeployResult(
			array(
				'status'     => 'running',
				'command'    => $command,
				'started_at' => $started_at,
				'message'    => __( 'Deployment in progress. This may take several minutes.', 'wp-module-staging' ),
			)
		);

		$instance = $this;
		add_action(
			'shutdown',
			static function () use ( $instance, $script, $command, $started_at ) {
				if ( function_exists( 'fastcgi_finish_request' ) ) {
					fastcgi_finish_request();
				}
				ignore_user_abort( true );
				set_time_limit( 0 );

				$result = $instance->executeStagingScript( $script, $command );

				if ( is_wp_error( $result ) ) {
					$log_status = $instance->getDeployStatusFromLog( $command, $started_at );
					if ( is_array( $log_status ) && 'success' === $log_status['status'] ) {
						$instance->writeDeployResult(
							array_merge(
								$log_status,
								array( 'started_at' => $started_at )
							)
						);
						wp_delete_file( trailingslashit( $instance->getProductionDir() ) . 'nfd-private/nfd-staging.log' );
						return;
					}

					$instance->writeDeployResult(
						array(
							'status'     => 'error',
							'command'    => $command,
							'started_at' => $started_at,
							'message'    => $result->get_error_message(),
						)
					);
					return;
				}

				$instance->writeDeployResult(
					array_merge(
						(array) $result,
						array(
							'command'    => $command,
							'started_at' => $started_at,
						)
					)
				);
				wp_delete_file( trailingslashit( $instance->getProductionDir() ) . 'nfd-private/nfd-staging.log' );
			},
			0
		);

		return array(
			'status'     => 'running',
			'command'    => $command,
			'started_at' => $started_at,
			'message'    => __( 'Deployment in progress. This may take several minutes.', 'wp-module-staging' ),
		);
	}

	/**
	 * Run the staging shell script and parse its JSON stdout.
	 *
	 * @param string $script  Path to the staging shell script.
	 * @param string $command Escaped CLI argument string.
	 *
	 * @return array|\WP_Error
	 */
	protected function executeStagingScript( $script, $command ) {
		do_action( 'newfold_staging_command', $command ); // bh_staging_command

		$json = exec( "{$script} {$command}" ); // phpcs:ignore

		return $this->parseStagingScriptResponse( $json );
	}

	/**
	 * Decode JSON stdout from the staging shell script.
	 *
	 * @param string|false|null $json Raw stdout from the staging script.
	 *
	 * @return array|\WP_Error
	 */
	protected function parseStagingScriptResponse( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return new \WP_Error( 'json_decode', __( 'Something gone wrong, please get in touch with our support.', 'wp-module-staging' ) );
		}

		$response = json_decode( $json, true );

		if ( ! is_array( $response ) ) {
			$cloudflare = json_decode( $json, true );
			if ( is_array( $cloudflare ) && ! empty( $cloudflare['cloudflare_error'] ) ) {
				return new \WP_Error(
					'origin_timeout',
					__( 'The deployment is still running. Please wait and check again shortly.', 'wp-module-staging' ),
					array( 'status' => 524 )
				);
			}

			return new \WP_Error( 'json_decode', __( 'Something gone wrong, please get in touch with our support.', 'wp-module-staging' ) );
		}

		if ( isset( $response['status'], $response['message'] ) && 'error' === $response['status'] ) {
			return new \WP_Error( 'error_response', $response['message'] );
		}

		return $response;
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

			$config = array(
				'creation_date'  => gmdate( 'M j, Y' ),
				'production_dir' => ABSPATH,
				'production_url' => get_option( 'siteurl' ),
				'staging_dir'    => ABSPATH . 'staging/' . $uniqueId,
				'staging_url'    => get_option( 'siteurl' ) . '/staging/' . $uniqueId,
			);

			update_option( 'staging_config', $config );

		}

		$token = wp_generate_password( 32, false );
		set_transient( 'staging_auth_token', $token, 300 );

		$plugin_basename = explode( '/', container()->plugin()->basename );

		$plugin_slug = is_array( $plugin_basename ) && ! empty( $plugin_basename ) ? $plugin_basename[0] : null;

		$command = array(
			$command,
			$token,
			$config['production_dir'],
			$config['staging_dir'],
			$config['production_url'],
			$config['staging_url'],
			get_current_user_id(),
			container()->plugin()->id,
			$plugin_slug,
			container()->plugin()->name,
		);

		if ( $args && is_array( $args ) ) {
			$command = array_merge( $command, array_values( $args ) );
		}

		$command = implode( ' ', array_map( 'escapeshellarg', $command ) );

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

		if ( $this->isDeployCommand( $command ) ) {
			return $this->startAsyncDeploy( $script, $command );
		}

		$response = $this->executeStagingScript( $script, $command );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		wp_delete_file( ABSPATH . '/nfd-private/nfd-staging.log' );

		return $response;
	}

	/**
	 * Add the log admin page to the Tools menu.
	 */
	public function add_log_admin_page() {
		$hook = add_submenu_page(
			'nfd-staging-log',
			__( 'Log Staging', 'wp-module-staging' ),
			'',
			'manage_options',
			'nfd-staging-log',
			array( $this, 'render_log_admin_page' )
		);
		remove_menu_page( $hook );
	}

	/**
	 * Render the log admin page.
	 */
	public function render_log_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( "Don't have capabilities to access this page", 'wp-module-staging' ) );
		}

		$log_file = $this->getProductionDir() . '/nfd-private/nfd-staging.log';

		$logs        = array();
		$filter_date = isset( $_GET['log_date'] ) ? sanitize_text_field( $_GET['log_date'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per_page    = isset( $_GET['per_page'] ) ? max( 1, intval( $_GET['per_page'] ) ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page        = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( file_exists( $log_file ) ) {
			$lines = file( $log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			foreach ( $lines as $line ) {
				$log_date = substr( $line, 0, 19 );
				if ( $filter_date ) {
					if ( strpos( $log_date, $filter_date ) === 0 ) {
						$logs[] = $line;
					}
				} else {
					$logs[] = $line;
				}
			}
		}

		$total_logs   = count( $logs );
		$total_pages  = $per_page > 0 ? ceil( $total_logs / $per_page ) : 1;
		$start        = ( $page - 1 ) * $per_page;
		$logs_to_show = array_slice( $logs, $start, $per_page );
		$instance     = $this;

		include __DIR__ . '/../views/staging-log.php';
	}

	/**
	 * Clean up old log file if the plugin has been upgraded from an older version
	 */
	public function clean_log() {
		if ( file_exists( ABSPATH . '/nfd-staging.log' ) ) {
			wp_delete_file( ABSPATH . '/nfd-staging.log' );
		}
	}
}
