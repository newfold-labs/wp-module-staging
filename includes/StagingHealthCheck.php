<?php

namespace NewfoldLabs\WP\Module\Staging;

/**
 * Detects and repairs inconsistent staging metadata (wp_options vs filesystem).
 */
class StagingHealthCheck {

	/**
	 * Repair log filename under nfd-private.
	 *
	 * @var string
	 */
	const REPAIR_LOG = 'nfd-staging-repair.log';

	/**
	 * Transient key shown once as an admin notice after repair.
	 *
	 * @var string
	 */
	const NOTICE_TRANSIENT = 'nfd_staging_repair_ran';

	/**
	 * Staging service instance.
	 *
	 * @var Staging
	 */
	protected $staging;

	/**
	 * Whether any repair was applied during this request.
	 *
	 * @var bool
	 */
	protected $repaired = false;

	/**
	 * Constructor.
	 *
	 * @param Staging $staging Staging module instance.
	 */
	public function __construct( Staging $staging ) {
		$this->staging = $staging;
	}

	/**
	 * Run health check and apply fixes when needed.
	 *
	 * @return bool True if any option was updated or deleted.
	 */
	public function maybe_repair() {
		$this->repaired = false;

		if ( StagingPath::is_staging_abspath( ABSPATH ) ) {
			$this->repair_staging_site();
		} else {
			$this->repair_production_site();
		}

		if ( $this->repaired ) {
			$this->schedule_admin_notice();
		}

		return $this->repaired;
	}

	/**
	 * Transient key for the admin notice.
	 *
	 * @return string
	 */
	public static function get_notice_transient_key() {
		return self::NOTICE_TRANSIENT;
	}

	/**
	 * Repair metadata on a staging installation.
	 *
	 * @return void
	 */
	protected function repair_staging_site() {
		$config   = $this->staging->getConfig( false );
		$site_url = site_url();
		$env      = $this->staging->getEnvironment();
		$wp_env   = defined( 'WP_ENVIRONMENT_TYPE' ) ? WP_ENVIRONMENT_TYPE : null;

		$this->log(
			'INFO',
			'staging_check',
			sprintf(
				'Staging site detected. ABSPATH=%s environment=%s WP_ENVIRONMENT_TYPE=%s',
				ABSPATH,
				$env,
				$wp_env ? $wp_env : 'undefined'
			)
		);

		if ( 'staging' !== $env ) {
			$this->update_environment( 'staging', 'staging_environment was missing or incorrect' );
		}

		$expected = StagingPath::build_config_for_staging_site( ABSPATH, $site_url, $config );

		if ( null === $expected ) {
			$this->log( 'WARN', 'staging_check', 'Unable to derive config from ABSPATH.' );
			return;
		}

		if ( empty( $config ) || ! StagingPath::is_valid_staging_config( $config ) ) {
			$this->save_config( $expected, 'reconstructed staging_config from ABSPATH and site URL' );
			$config = $expected;
		} else {
			$merged = $this->merge_config_with_expected( $config, $expected );
			if ( $merged !== $config ) {
				$this->save_config( $merged, 'aligned staging_config with ABSPATH-derived values' );
				$config = $merged;
			}
		}

		if ( StagingPath::config_has_swapped_urls( $config ) ) {
			$fixed = StagingPath::fix_swapped_urls( $config );
			$this->save_config( $fixed, 'fixed swapped production and staging URLs' );
			$config = $fixed;
		}

		if ( $wp_env && 'staging' === $wp_env && 'production' === $this->staging->getEnvironment() ) {
			$this->update_environment( 'staging', 'WP_ENVIRONMENT_TYPE is staging; corrected staging_environment' );
		}

		if ( ! $this->repaired ) {
			$this->log( 'INFO', 'staging_check', 'No repairs required.' );
		}
	}

	/**
	 * Repair metadata on a production installation.
	 *
	 * @return void
	 */
	protected function repair_production_site() {
		$config         = $this->staging->getConfig( false );
		$production_dir = StagingPath::normalize_trailing_slash( ABSPATH );
		$production_url = site_url();
		$env            = $this->staging->getEnvironment();
		$wp_env_staging = defined( 'WP_ENVIRONMENT_TYPE' ) && 'staging' === WP_ENVIRONMENT_TYPE;

		$this->log(
			'INFO',
			'production_check',
			sprintf(
				'Production site check. ABSPATH=%s environment=%s config_empty=%s',
				ABSPATH,
				$env,
				empty( $config ) ? 'yes' : 'no'
			)
		);

		if ( 'production' !== $env && ! $wp_env_staging ) {
			$this->update_environment( 'production', 'staging_environment was incorrect on production' );
		}

		if ( ! empty( $config ) && StagingPath::config_has_swapped_urls( $config ) ) {
			$fixed = StagingPath::fix_swapped_urls( $config );
			$this->save_config( $fixed, 'fixed swapped production and staging URLs' );
			$config = $fixed;
		}

		if ( ! empty( $config ) ) {
			$staging_dir = isset( $config['staging_dir'] ) ? $config['staging_dir'] : '';

			if ( ! empty( $staging_dir ) && ! file_exists( $staging_dir ) ) {
				$this->log(
					'INFO',
					'orphan_cleanup',
					sprintf( 'staging_dir missing on disk: %s', $staging_dir )
				);
				delete_option( 'staging_config' );
				$this->repaired = true;
				$config         = array();
				$this->log( 'FIX', 'orphan_cleanup', 'Deleted orphaned staging_config.' );
			}
		}

		if ( empty( $config ) ) {
			$directories = StagingPath::discover_staging_directories( $production_dir );

			if ( ! empty( $directories ) ) {
				$selected = StagingPath::select_staging_directory( $production_dir, $directories, $config );

				if ( count( $directories ) > 1 ) {
					$this->log(
						'WARN',
						'discover',
						sprintf(
							'Multiple staging directories found (%d); using %s',
							count( $directories ),
							$selected
						)
					);
				}

				$rebuilt = StagingPath::build_config_for_production_site(
					$selected,
					$production_dir,
					$production_url,
					$config
				);

				if ( null !== $rebuilt ) {
					$this->save_config( $rebuilt, 'reconstructed staging_config from on-disk staging directory' );
				}
			}
		} elseif ( ! StagingPath::is_valid_staging_config( $config ) ) {
			$staging_dir = isset( $config['staging_dir'] ) ? $config['staging_dir'] : '';

			if ( ! empty( $staging_dir ) && file_exists( $staging_dir ) ) {
				$rebuilt = StagingPath::build_config_for_production_site(
					$staging_dir,
					$production_dir,
					$production_url,
					$config
				);

				if ( null !== $rebuilt ) {
					$this->save_config( $rebuilt, 'rebuilt invalid staging_config from existing staging_dir' );
				}
			}
		}

		if ( ! $this->repaired ) {
			$this->log( 'INFO', 'production_check', 'No repairs required.' );
		}
	}

	/**
	 * Merge stored config with path-derived values when paths disagree.
	 *
	 * @param array $config   Current config.
	 * @param array $expected Expected config from ABSPATH.
	 * @return array
	 */
	protected function merge_config_with_expected( array $config, array $expected ) {
		$merged = $config;

		foreach ( array( 'production_dir', 'staging_dir', 'production_url', 'staging_url' ) as $key ) {
			if ( ! isset( $expected[ $key ] ) ) {
				continue;
			}

			if ( ! isset( $merged[ $key ] ) || $merged[ $key ] !== $expected[ $key ] ) {
				if ( 'production_dir' === $key && StagingPath::is_staging_abspath( $merged[ $key ] ?? '' ) ) {
					$merged[ $key ] = $expected[ $key ];
					continue;
				}

				if ( 'staging_dir' === $key && ! StagingPath::is_staging_abspath( $merged[ $key ] ?? '' ) ) {
					$merged[ $key ] = $expected[ $key ];
					continue;
				}

				if ( in_array( $key, array( 'production_url', 'staging_url' ), true ) ) {
					if ( 'production_url' === $key && preg_match( StagingPath::STAGING_URL_PATTERN, $merged[ $key ] ?? '' ) ) {
						$merged[ $key ] = $expected[ $key ];
					} elseif ( 'staging_url' === $key && ! preg_match( StagingPath::STAGING_URL_PATTERN, $merged[ $key ] ?? '' ) ) {
						$merged[ $key ] = $expected[ $key ];
					}
				} else {
					$merged[ $key ] = $expected[ $key ];
				}
			}
		}

		return $merged;
	}

	/**
	 * Persist staging_config and log the change.
	 *
	 * @param array  $config  New config value.
	 * @param string $reason  Human-readable reason for the log.
	 * @return void
	 */
	protected function save_config( array $config, $reason ) {
		$before = $this->staging->getConfig( false );
		update_option( 'staging_config', $config );
		$this->repaired = true;
		$this->log(
			'FIX',
			'save_config',
			sprintf(
				'%s. before=%s after=%s',
				$reason,
				wp_json_encode( $before ),
				wp_json_encode( $config )
			)
		);
	}

	/**
	 * Update staging_environment and log the change.
	 *
	 * @param string $value  production|staging.
	 * @param string $reason Log message.
	 * @return void
	 */
	protected function update_environment( $value, $reason ) {
		$before = get_option( 'staging_environment', 'production' );
		update_option( 'staging_environment', $value );
		$this->repaired = true;
		$this->log(
			'FIX',
			'update_environment',
			sprintf( '%s. before=%s after=%s', $reason, $before, $value )
		);
	}

	/**
	 * Set transient so Staging can show a one-time admin notice.
	 *
	 * @return void
	 */
	protected function schedule_admin_notice() {
		set_transient( self::NOTICE_TRANSIENT, 1, HOUR_IN_SECONDS );
	}

	/**
	 * Write a line to the repair log under production nfd-private.
	 *
	 * @param string $level Log level (INFO, WARN, FIX).
	 * @param string $step  Step identifier.
	 * @param string $message Log message.
	 * @return void
	 */
	protected function log( $level, $step, $message ) {
		$production_dir = StagingPath::is_staging_abspath( ABSPATH )
			? StagingPath::parse_staging_from_abspath( ABSPATH )['production_dir']
			: StagingPath::normalize_trailing_slash( ABSPATH );

		$log_dir  = $production_dir . 'nfd-private';
		$log_file = $log_dir . '/' . self::REPAIR_LOG;

		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
		}

		$htaccess = $log_dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $htaccess, "Order allow,deny\nDeny from all\n" );
		}

		$line = sprintf(
			"%s [%s] [%s] %s\n",
			gmdate( 'Y-m-d H:i:s' ),
			$level,
			$step,
			$message
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );
	}
}
