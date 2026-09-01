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
	 * Seconds the staging auth token stays valid for.
	 *
	 * @var int
	 */
	const AUTH_TOKEN_TTL = 300;

	/**
	 * Seconds a running deploy result may block new work without a live lock.
	 *
	 * @var int
	 */
	const DEPLOY_JOB_TTL = 1800;


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

		add_action( 'admin_init', array( $this, 'run_staging_health_check' ), 5 );
		add_action( 'admin_notices', array( $this, 'render_staging_repair_notice' ) );

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
		$parsed = StagingPath::parse_staging_from_abspath( ABSPATH );
		if ( null !== $parsed ) {
			return $parsed['production_dir'];
		}

		return $this->getConfigValue( 'production_dir', ABSPATH );
	}

	/**
	 * Get the production URL
	 *
	 * @return string
	 */
	public function getProductionUrl() {
		$parsed = StagingPath::parse_staging_from_url( site_url() );
		if ( null !== $parsed ) {
			return $parsed['production_url'];
		}

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
		if ( StagingPath::is_staging_abspath( ABSPATH ) ) {
			return true;
		}

		if ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'staging' === WP_ENVIRONMENT_TYPE ) {
			return true;
		}

		return $this->isEnvironment( 'staging' );
	}

	/**
	 * Check if the current environment is production.
	 *
	 * @return bool
	 */
	public function isProduction() {
		if ( StagingPath::is_staging_abspath( ABSPATH ) ) {
			return false;
		}

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
	 * Path to the file used for the deploy process lock.
	 *
	 * @return string
	 */
	protected function getDeployLockPath() {
		return trailingslashit( $this->getProductionDir() ) . 'nfd-private/nfd-staging-deploy.lock';
	}

	/**
	 * Acquire the exclusive deploy lock.
	 *
	 * @return resource|false|\WP_Error Lock handle on success, false when another deploy owns it.
	 */
	protected function acquireDeployLock() {
		$path = $this->getDeployLockPath();
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error(
				'deploy_lock_unavailable',
				__( 'Unable to create the deployment lock directory.', 'wp-module-staging' )
			);
		}

		$handle = fopen( $path, 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return new \WP_Error(
				'deploy_lock_unavailable',
				__( 'Unable to open the deployment lock file.', 'wp-module-staging' )
			);
		}

		if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return false;
		}

		return $handle;
	}

	/**
	 * Release an acquired deploy lock.
	 *
	 * @param resource $handle Lock handle.
	 *
	 * @return void
	 */
	protected function releaseDeployLock( $handle ) {
		if ( is_resource( $handle ) ) {
			flock( $handle, LOCK_UN ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Whether another process currently owns the deploy lock.
	 *
	 * @return bool
	 */
	protected function isDeployLockHeld() {
		$handle = $this->acquireDeployLock();
		if ( is_wp_error( $handle ) ) {
			return true;
		}
		if ( false === $handle ) {
			return true;
		}

		$this->releaseDeployLock( $handle );
		return false;
	}

	/**
	 * Whether a deploy is already running.
	 *
	 * @return bool
	 */
	protected function isDeployInProgress() {
		if ( $this->isDeployLockHeld() ) {
			return true;
		}

		$result = $this->readDeployResult();
		if ( is_array( $result ) && isset( $result['status'] ) && 'running' === $result['status'] ) {
			$started_at = isset( $result['started_at'] ) ? (int) $result['started_at'] : 0;
			return $started_at > ( time() - self::DEPLOY_JOB_TTL );
		}

		return false;
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
			$parsed = strtotime( $matches[1] . ' UTC' );
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

			// The shell emits this marker only when an operation actually exits through error().
			if ( false !== strpos( $line, '[ERROR] [operation_failed]' ) ) {
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
		$same_command = is_array( $result ) && isset( $result['command'] ) && $result['command'] === $command;

		if ( $same_command && is_array( $result ) ) {
			if ( 'running' !== $result['status'] ) {
				return $result;
			}
			$since = ! empty( $result['started_at'] ) ? (int) $result['started_at'] : $since;
			if ( ! $this->isDeployInProgress() ) {
				$result = array(
					'status'     => 'error',
					'command'    => $command,
					'started_at' => $since,
					'message'    => __( 'The deployment stopped before it completed. Please try again.', 'wp-module-staging' ),
				);
				$this->writeDeployResult( $result );
				return $result;
			}
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
	 * @param string   $script            Path to the staging shell script.
	 * @param string   $command_name      CLI command name (e.g. deploy_db).
	 * @param string   $shell_command     Escaped CLI argument string for exec().
	 * @param string   $auth_token_value  Exact option value written for this command.
	 * @param resource $deploy_lock       Acquired deploy lock handle.
	 *
	 * @return array|\WP_Error
	 */
	protected function startAsyncDeploy( $script, $command_name, $shell_command, $auth_token_value, $deploy_lock ) {
		$started_at = time();

		$this->writeDeployResult(
			array(
				'status'     => 'running',
				'command'    => $command_name,
				'started_at' => $started_at,
				'message'    => __( 'Deployment in progress. This may take several minutes.', 'wp-module-staging' ),
			)
		);

		$instance = $this;
		add_action(
			'shutdown',
			static function () use ( $instance, $script, $command_name, $shell_command, $auth_token_value, $deploy_lock, $started_at ) {
				try {
					if ( function_exists( 'fastcgi_finish_request' ) ) {
						fastcgi_finish_request();
					}
					ignore_user_abort( true );
					set_time_limit( 0 );

					$result = $instance->executeStagingScript( $script, $command_name, $shell_command, $auth_token_value );

					if ( is_wp_error( $result ) ) {
						$log_status = $instance->getDeployStatusFromLog( $command_name, $started_at );
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
								'command'    => $command_name,
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
								'command'    => $command_name,
								'started_at' => $started_at,
							)
						)
					);
					wp_delete_file( trailingslashit( $instance->getProductionDir() ) . 'nfd-private/nfd-staging.log' );
				} finally {
					$instance->releaseDeployLock( $deploy_lock );
				}
			},
			0
		);

		return array(
			'status'     => 'running',
			'command'    => $command_name,
			'started_at' => $started_at,
			'message'    => __( 'Deployment in progress. This may take several minutes.', 'wp-module-staging' ),
		);
	}

	/**
	 * Run the staging shell script and parse its JSON stdout.
	 *
	 * @param string $script            Path to the staging shell script.
	 * @param string $command_name      CLI command name (e.g. deploy_db), used to gate the compat_check exemptions below.
	 * @param string $command           Escaped CLI argument string.
	 * @param string $auth_token_value  Exact option value written for this command.
	 *
	 * @return array|\WP_Error
	 */
	protected function executeStagingScript( $script, $command_name, $command, $auth_token_value ) {
		do_action( 'newfold_staging_command', $command ); // bh_staging_command

		$this->logAuthTokenState( $auth_token_value );

		$output      = array();
		$exit_status = 0;
		exec( "{$script} {$command}", $output, $exit_status ); // phpcs:ignore

		// The script wrote options with the cache bypassed, so put the two back in sync.
		if ( 'compat_check' !== $command_name ) {
			$this->resync_object_cache();
		}

		/*
		 * auth_action() consumes the token, but only on the paths that reach it. compat_check exits
		 * before authenticating, and the script can die earlier than that. Unlike the transient this
		 * replaced, an option does not expire on its own, so an uncollected token would sit in
		 * wp_options in plain text and travel into every database export.
		 */
		$this->deleteAuthToken( $auth_token_value );

		return $this->parseStagingScriptResponse( $output, $exit_status );
	}

	/**
	 * Delete only the auth token written for the command that just finished.
	 *
	 * @param string $auth_token_value Exact stored option value.
	 *
	 * @return void
	 */
	protected function deleteAuthToken( $auth_token_value ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'staging_auth_token',
				$auth_token_value
			)
		);

		if ( $deleted ) {
			wp_cache_delete( 'staging_auth_token', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		}
	}

	/**
	 * Store the single-use auth token and prove it reached the database.
	 *
	 * update_option() cannot be trusted on its own here. When a persistent cache still holds a
	 * value whose row lib/.staging has already deleted, update_option() reads that phantom from
	 * cache, concludes the row exists, issues an UPDATE matching nothing and returns false having
	 * written no row at all. The script then finds no token and the command fails as "Unable to
	 * authenticate the action.", with nothing on either side recording that a write was dropped.
	 *
	 * The row is therefore read straight off the database, bypassing get_option() and whatever
	 * cache sits in front of it. A failed first attempt is retried once after evicting the keys
	 * that produce the phantom, so a cache in this state is repaired rather than merely reported.
	 *
	 * @param string $token Token to store.
	 *
	 * @return string|false Stored "<token>.<expiry>" value, or false when the write did not land.
	 */
	protected function persist_auth_token( $token ) {
		global $wpdb;

		// Options have no TTL of their own, so the expiry rides along in the value.
		$value = $token . '.' . ( time() + self::AUTH_TOKEN_TTL );

		update_option( 'staging_auth_token', $value, false );

		if ( $this->auth_token_row() === $value ) {
			return $value;
		}

		/*
		 * The cache claimed a row that is not there, so stop asking it. Evict the keys that produce
		 * the phantom and write the row directly, because update_option() would just consult the
		 * same cache again and drop the write a second time. Evicting afterwards leaves get_option()
		 * agreeing with what is now on disk.
		 *
		 * 'no' rather than 'off' for autoload: WordPress 6.6 renamed the values but still reads the
		 * old ones, and the module supports installs from before the rename.
		 */
		$this->resync_object_cache();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'staging_auth_token' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => 'staging_auth_token',
				'option_value' => $value,
				'autoload'     => 'no',
			)
		);

		wp_cache_delete( 'staging_auth_token', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		return $this->auth_token_row() === $value ? $value : false;
	}

	/**
	 * Which install this request is running in, for lib/.staging to read the token from.
	 *
	 * The script used to infer this from its own working directory, testing whether the path
	 * contained "/staging/". That is wrong on hosts where the staging site's index.php is a symlink
	 * into production's core: PHP resolves the symlink, so a request to /staging/<id>/index.php
	 * starts in the production directory. The script then read production's staging_auth_token
	 * while this request had written the token to the staging database, and every deploy driven
	 * from the staging dashboard failed as "Unable to authenticate the action.".
	 *
	 * ABSPATH is authoritative because it names the install whose database update_option() just
	 * wrote to, which is exactly what the script has to read back.
	 *
	 * @param array       $config  staging_config values.
	 * @param string|null $abspath Install root to classify. Defaults to ABSPATH.
	 *
	 * @return string Either 'staging' or 'production'.
	 */
	protected function getScriptEnvironment( array $config, $abspath = null ) {
		$abspath = StagingPath::normalize_trailing_slash( null === $abspath ? ABSPATH : $abspath );

		if ( StagingPath::is_staging_abspath( $abspath ) ) {
			return 'staging';
		}

		if ( ! empty( $config['staging_dir'] )
			&& StagingPath::normalize_trailing_slash( $config['staging_dir'] ) === $abspath ) {
			return 'staging';
		}

		return 'production';
	}

	/**
	 * Record whether PHP can read the token row immediately before the script does.
	 *
	 * auth_action() reporting a missing token has several possible causes that look identical from
	 * the shell: the row was never written, it was written to a different database or prefix than
	 * the one WP-CLI resolves from --path, or something consumed it in between. Writing PHP's own
	 * view into the same log, on the same clock, is what separates them. The stored value itself
	 * is never written: the log is also shown on the Tools page.
	 *
	 * @param string $auth_token_value Exact option value written for this command.
	 *
	 * @return void
	 */
	protected function logAuthTokenState( $auth_token_value ) {
		global $wpdb;

		$log_file = trailingslashit( $this->getProductionDir() ) . 'nfd-private/nfd-staging.log';
		$dir      = dirname( $log_file );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return;
		}

		$row     = $this->auth_token_row();
		$message = sprintf(
			'%s [INFO] [auth_token] PHP wrote token into %s prefix=%s; row readable: %s',
			gmdate( 'Y-m-d H:i:s' ),
			defined( 'DB_NAME' ) ? DB_NAME : '<undefined>',
			$wpdb->prefix,
			null === $row ? 'no' : ( $row === $auth_token_value ? 'yes' : 'different value' )
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $log_file, $message . PHP_EOL, FILE_APPEND );
	}

	/**
	 * Read the stored auth token straight from the options table.
	 *
	 * get_option() is deliberately not used: the whole point of the caller is to find out what is
	 * actually on disk, and get_option() answers from the cache that may be lying.
	 *
	 * @return string|null Stored value, or null when the row does not exist.
	 */
	private function auth_token_row() {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'staging_auth_token'
			)
		);
	}

	/**
	 * Decode JSON stdout from the staging shell script.
	 *
	 * @param array $output      Lines of stdout captured from the staging script.
	 * @param int   $exit_status Exit status of the staging script.
	 *
	 * @return array|\WP_Error
	 */
	protected function parseStagingScriptResponse( $output, $exit_status = 0 ) {
		$output = (array) $output;

		/*
		 * Take the last line that actually parses as one of our responses rather than assuming the
		 * script's own output is the last thing on stdout. A drop-in, a PHP notice or any other
		 * stray write would otherwise be handed to json_decode() and turn a working run into the
		 * generic failure below. Every response the script emits carries a "status" key.
		 */
		$response = null;
		for ( $i = count( $output ) - 1; $i >= 0; $i-- ) {
			$decoded = json_decode( trim( $output[ $i ] ), true );
			if ( is_array( $decoded ) && isset( $decoded['status'] ) ) {
				$response = $decoded;
				break;
			}
		}

		if ( null === $response ) {
			$last_line  = end( $output );
			$cloudflare = $last_line ? json_decode( trim( $last_line ), true ) : null;
			if ( is_array( $cloudflare ) && ! empty( $cloudflare['cloudflare_error'] ) ) {
				return new \WP_Error(
					'origin_timeout',
					__( 'The deployment is still running. Please wait and check again shortly.', 'wp-module-staging' ),
					array( 'status' => 524 )
				);
			}

			return new \WP_Error( 'json_decode', __( 'Something gone wrong, please get in touch with our support.', 'wp-module-staging' ) );
		}

		/*
		 * Some commands print their success line and then keep working (deploy_db cleans up its
		 * backup and runs the exit trap afterwards). If the script died during that tail we would
		 * otherwise find the success line and report a run that did not finish as successful, so
		 * the exit status has the final say.
		 */
		if ( 0 !== $exit_status && 'error' !== $response['status'] ) {
			return new \WP_Error( 'error_response', __( 'The staging operation did not complete, please get in touch with our support.', 'wp-module-staging' ) );
		}

		/*
		 * Array access, not object access: json_decode() above is associative, so an object-style
		 * test could never match and error messages from the script would be silently returned to
		 * the caller as if they were successful responses.
		 */
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
	 * Drop the cached copies of every option lib/.staging touches.
	 *
	 * The script runs WP-CLI with the object-cache drop-in skipped so a broken drop-in cannot kill
	 * it, which means its option writes reach the database without evicting the copies a live
	 * persistent cache is still serving. That is not merely stale data. update_option() here reads
	 * the cached value, concludes the row still exists, issues an UPDATE that matches nothing and
	 * returns false, so the write is dropped and the option can never be changed again until the
	 * key is evicted.
	 *
	 * The script cannot always fix this itself: in the case that motivated all of this the drop-in
	 * works for web requests but dies under WP-CLI, so the CLI has no route to the cache while this
	 * request does.
	 *
	 * Known limit: this only reaches the install it runs in. The Newfold drop-in namespaces its
	 * keys by table prefix, so a deploy, which is driven from the staging site, cannot evict
	 * production's keys from here. lib/.staging makes a best effort pass over the production path
	 * for that direction; on a site whose drop-in is broken under WP-CLI both are no-ops and
	 * production's cached options stay stale until they are evicted by other means.
	 *
	 * @return void
	 */
	protected function resync_object_cache() {
		if ( ! wp_using_ext_object_cache() ) {
			return;
		}

		$keys = array(
			'staging_auth_token',
			'staging_config',
			'staging_environment',
			'nfd_coming_soon',
			'alloptions',
			'notoptions',
		);

		foreach ( $keys as $key ) {
			wp_cache_delete( $key, 'options' );
		}
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
		// $command is rebuilt into the escaped argument string below, so keep the name to test against.
		$command_name = $command;

		/*
		 * Before anything reads or writes an option below. A previous run may have left the cache
		 * out of step with the database, and update_option() silently drops writes in that state,
		 * including the staging_config write further down. compat_check is exempt because it writes
		 * nothing and consuming plugins may call it often enough for the eviction cost to matter.
		 */
		if ( 'compat_check' !== $command_name ) {
			$this->resync_object_cache();
		}

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
		if ( ! array_key_exists( $command_name, $allowedCommands ) ) {
			return new \WP_Error(
				'invalid_command',
				__( 'Invalid staging CLI command.', 'wp-module-staging' )
			);
		}

		$config = $this->getConfig();

		// If config is empty, then we are creating a staging environment.
		if ( empty( $config ) || 'create' === $command_name ) {

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

		/*
		 * Handed to lib/.staging, which runs WP-CLI with the object-cache drop-in skipped so a
		 * broken drop-in cannot corrupt the JSON this method parses back. That makes
		 * wp_using_ext_object_cache() false in the script while it is true in this request, and
		 * set_transient() follows that flag: the token would be written to the object cache here
		 * and looked for in wp_options there. An option is database backed on both sides.
		 *
		 * The expiry is carried in the value because options have no TTL. auth_action() in the
		 * script splits on the "." and deletes the option as soon as it reads it, which preserves
		 * the single-use behaviour the transient gave us.
		 */

		$plugin_basename = explode( '/', container()->plugin()->basename );

		$plugin_slug = is_array( $plugin_basename ) && ! empty( $plugin_basename ) ? $plugin_basename[0] : null;

		$command_args = array(
			$command_name,
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
			$command_args = array_merge( $command_args, array_values( $args ) );
		}

		$shell_command = implode( ' ', array_map( 'escapeshellarg', $command_args ) );

		// Check for invalid characters
		$invalidChars = array( ';', '&', '|' );
		foreach ( $invalidChars as $char ) {
			if ( false !== strpos( $shell_command, $char ) ) {
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

		// Read by lib/.staging to pick the install it authenticates against. Set before every exec,
		// including the async deploy, which runs in this same process at shutdown.
		putenv( 'NFD_STAGING_ENV=' . $this->getScriptEnvironment( $config ) ); // phpcs:ignore

		/*
		 * Written last, once every check above has passed. Anything that returns before this point
		 * never reaches the delete below and never runs the script that would consume the token,
		 * so persisting it earlier would strand a live credential in wp_options.
		 */
		$deploy_lock = null;
		if ( $this->isDeployCommand( $command_name ) ) {
			$deploy_lock = $this->acquireDeployLock();
			if ( is_wp_error( $deploy_lock ) ) {
				return $deploy_lock;
			}
			if ( false === $deploy_lock ) {
				return array(
					'status'  => 'running',
					'command' => $command_name,
					'message' => __( 'Deployment in progress. This may take several minutes.', 'wp-module-staging' ),
				);
			}
		} elseif ( $this->isDeployLockHeld() ) {
			return new \WP_Error(
				'staging_locked',
				__( 'A deployment is currently in progress. Please wait for it to finish.', 'wp-module-staging' )
			);
		}

		$auth_token_value = $this->persist_auth_token( $token );
		if ( ! $auth_token_value ) {
			delete_option( 'staging_auth_token' );
			if ( $deploy_lock ) {
				$this->releaseDeployLock( $deploy_lock );
			}

			return new \WP_Error(
				'auth_token_not_persisted',
				__( 'Unable to store the staging authentication token. A persistent object cache may be out of sync with the database, or the database is not accepting writes.', 'wp-module-staging' )
			);
		}

		if ( $this->isDeployCommand( $command_name ) ) {
			return $this->startAsyncDeploy( $script, $command_name, $shell_command, $auth_token_value, $deploy_lock );
		}

		$response = $this->executeStagingScript( $script, $command_name, $shell_command, $auth_token_value );

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

	/**
	 * Run staging health check on relevant admin requests.
	 *
	 * @return void
	 */
	public function run_staging_health_check() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->should_run_health_check_on_this_request() ) {
			return;
		}

		$health = new StagingHealthCheck( $this );
		if ( $health->maybe_repair() ) {
			$this->getConfig( false );
		}
	}

	/**
	 * Whether the current admin request should trigger a health check.
	 *
	 * @return bool
	 */
	protected function should_run_health_check_on_this_request() {
		if ( isset( $_GET['page'] ) && container()->plugin()->id === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		if ( StagingPath::is_staging_abspath( ABSPATH ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Show a one-time notice when auto-repair has run.
	 *
	 * @return void
	 */
	public function render_staging_repair_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_transient( StagingHealthCheck::get_notice_transient_key() ) ) {
			return;
		}

		delete_transient( StagingHealthCheck::get_notice_transient_key() );

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( 'Staging configuration was automatically repaired.', 'wp-module-staging' )
		);
	}
}
