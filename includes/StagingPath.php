<?php

namespace NewfoldLabs\WP\Module\Staging;

/**
 * Path and URL utilities for deterministic staging locations.
 *
 * Staging sites live at {production_dir}/staging/{4-digit-id}/ and
 * {production_url}/staging/{4-digit-id}.
 */
class StagingPath {

	/**
	 * Regex matching a staging ABSPATH suffix.
	 *
	 * @var string
	 */
	const STAGING_ABSPATH_PATTERN = '#/staging/(\d{4})/?$#';

	/**
	 * Regex matching a staging URL segment.
	 *
	 * @var string
	 */
	const STAGING_URL_PATTERN = '#/staging/\d{4}(/|$)#';

	/**
	 * Required keys for a valid staging_config option.
	 *
	 * @var string[]
	 */
	const CONFIG_KEYS = array(
		'production_dir',
		'production_url',
		'staging_dir',
		'staging_url',
		'creation_date',
	);

	/**
	 * Whether ABSPATH matches the staging directory pattern.
	 *
	 * @param string $path Filesystem path (defaults to ABSPATH).
	 * @return bool
	 */
	public static function is_staging_abspath( $path = null ) {
		if ( null === $path ) {
			$path = ABSPATH;
		}

		return (bool) preg_match( self::STAGING_ABSPATH_PATTERN, self::normalize_trailing_slash( $path ) );
	}

	/**
	 * Parse production and staging paths from a staging site ABSPATH.
	 *
	 * @param string $abspath Staging site root directory.
	 * @return array|null Keys: production_dir, staging_dir, staging_id; or null.
	 */
	public static function parse_staging_from_abspath( $abspath ) {
		$abspath = self::normalize_trailing_slash( $abspath );

		if ( ! preg_match( self::STAGING_ABSPATH_PATTERN, $abspath, $matches ) ) {
			return null;
		}

		$staging_id     = $matches[1];
		$staging_dir    = $abspath;
		$production_dir = preg_replace( '#/staging/' . preg_quote( $staging_id, '#' ) . '/?$#', '/', $abspath );

		return array(
			'production_dir' => self::normalize_trailing_slash( $production_dir ),
			'staging_dir'    => $staging_dir,
			'staging_id'     => $staging_id,
		);
	}

	/**
	 * Parse production and staging URLs from a staging site URL.
	 *
	 * @param string $url Site URL (e.g. site_url()).
	 * @return array|null Keys: production_url, staging_url, staging_id; or null.
	 */
	public static function parse_staging_from_url( $url ) {
		$parsed = wp_parse_url( $url );
		if ( empty( $parsed['path'] ) ) {
			return null;
		}

		$path = trailingslashit( $parsed['path'] );

		if ( ! preg_match( '#/staging/(\d{4})/?$#', $path, $matches ) ) {
			return null;
		}

		$staging_id      = $matches[1];
		$staging_path    = '/staging/' . $staging_id;
		$production_path = preg_replace( '#' . preg_quote( $staging_path, '#' ) . '/?$#', '/', $path );
		$production_path = ( '/' === $production_path ) ? '' : untrailingslashit( $production_path );

		$scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '';
		$host   = isset( $parsed['host'] ) ? $parsed['host'] : '';
		$port   = isset( $parsed['port'] ) ? ':' . $parsed['port'] : '';

		$base = $scheme . $host . $port;

		return array(
			'production_url' => untrailingslashit( $base . $production_path ),
			'staging_url'    => untrailingslashit( $base . $staging_path ),
			'staging_id'     => $staging_id,
		);
	}

	/**
	 * Normalize a directory path with a trailing slash.
	 *
	 * @param string $path Directory path.
	 * @return string
	 */
	public static function normalize_trailing_slash( $path ) {
		return trailingslashit( wp_normalize_path( $path ) );
	}

	/**
	 * Whether staging_config URLs appear swapped (DEVSUP-135985).
	 *
	 * @param array $config staging_config option value.
	 * @return bool
	 */
	public static function config_has_swapped_urls( array $config ) {
		$staging_url    = isset( $config['staging_url'] ) ? (string) $config['staging_url'] : '';
		$production_url = isset( $config['production_url'] ) ? (string) $config['production_url'] : '';

		if ( '' === $staging_url || '' === $production_url ) {
			return false;
		}

		$staging_has_segment    = (bool) preg_match( self::STAGING_URL_PATTERN, $staging_url );
		$production_has_segment = (bool) preg_match( self::STAGING_URL_PATTERN, $production_url );

		return ! $staging_has_segment && $production_has_segment;
	}

	/**
	 * Swap production and staging URL (and directory) values in config.
	 *
	 * @param array $config staging_config option value.
	 * @return array
	 */
	public static function fix_swapped_urls( array $config ) {
		if ( ! self::config_has_swapped_urls( $config ) ) {
			return $config;
		}

		$swapped = $config;

		if ( isset( $config['production_url'], $config['staging_url'] ) ) {
			$swapped['production_url'] = $config['staging_url'];
			$swapped['staging_url']    = $config['production_url'];
		}

		if ( isset( $config['production_dir'], $config['staging_dir'] ) ) {
			$prod_dir = $config['production_dir'];
			$stg_dir  = $config['staging_dir'];

			$prod_is_staging_path = self::is_staging_abspath( $prod_dir );
			$stg_is_staging_path  = self::is_staging_abspath( $stg_dir );

			if ( $prod_is_staging_path && ! $stg_is_staging_path ) {
				$swapped['production_dir'] = $stg_dir;
				$swapped['staging_dir']    = $prod_dir;
			} elseif ( $prod_is_staging_path || $stg_is_staging_path ) {
				$swapped['production_dir'] = $stg_dir;
				$swapped['staging_dir']    = $prod_dir;
			}
		}

		return $swapped;
	}

	/**
	 * Whether staging_config contains all required keys with plausible values.
	 *
	 * @param array $config staging_config option value.
	 * @return bool
	 */
	public static function is_valid_staging_config( array $config ) {
		foreach ( self::CONFIG_KEYS as $key ) {
			if ( empty( $config[ $key ] ) || ! is_string( $config[ $key ] ) ) {
				return false;
			}
		}

		if ( ! preg_match( self::STAGING_URL_PATTERN, $config['staging_url'] ) ) {
			return false;
		}

		if ( preg_match( self::STAGING_URL_PATTERN, $config['production_url'] ) ) {
			return false;
		}

		if ( ! self::is_staging_abspath( $config['staging_dir'] ) ) {
			return false;
		}

		if ( self::is_staging_abspath( $config['production_dir'] ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Discover valid staging directories under a production root.
	 *
	 * @param string $production_dir Production ABSPATH.
	 * @return string[] Normalized staging directory paths.
	 */
	public static function discover_staging_directories( $production_dir ) {
		$production_dir = self::normalize_trailing_slash( $production_dir );
		$staging_parent = $production_dir . 'staging/';

		if ( ! is_dir( $staging_parent ) ) {
			return array();
		}

		$found = array();

		$entries = glob( $staging_parent . '[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR );
		if ( false === $entries ) {
			return array();
		}

		foreach ( $entries as $dir ) {
			if ( file_exists( $dir . '/wp-config.php' ) ) {
				$found[] = self::normalize_trailing_slash( $dir );
			}
		}

		return $found;
	}

	/**
	 * Pick the best staging directory when multiple exist on disk.
	 *
	 * @param string   $production_dir Production ABSPATH.
	 * @param string[] $directories    Candidate staging directories.
	 * @param array    $config         Current staging_config (may be partial).
	 * @return string|null
	 */
	public static function select_staging_directory( $production_dir, array $directories, array $config = array() ) {
		if ( empty( $directories ) ) {
			return null;
		}

		if ( 1 === count( $directories ) ) {
			return $directories[0];
		}

		if ( ! empty( $config['staging_dir'] ) ) {
			$preferred = self::normalize_trailing_slash( $config['staging_dir'] );
			if ( in_array( $preferred, $directories, true ) ) {
				return $preferred;
			}
		}

		usort(
			$directories,
			function ( $a, $b ) {
				return filemtime( $b ) - filemtime( $a );
			}
		);

		return $directories[0];
	}

	/**
	 * Build a full staging_config array for a staging site from path and URL.
	 *
	 * @param string $abspath   Staging ABSPATH.
	 * @param string $site_url  Current site URL.
	 * @param array  $existing  Existing config to preserve creation_date when possible.
	 * @return array|null
	 */
	public static function build_config_for_staging_site( $abspath, $site_url, array $existing = array() ) {
		$path_data = self::parse_staging_from_abspath( $abspath );
		if ( null === $path_data ) {
			return null;
		}

		$url_data = self::parse_staging_from_url( $site_url );
		if ( null === $url_data ) {
			$url_data = array(
				'production_url' => untrailingslashit( $site_url ),
				'staging_url'    => untrailingslashit( $site_url ),
				'staging_id'     => $path_data['staging_id'],
			);

			$staging_segment = '/staging/' . $path_data['staging_id'];
			if ( false !== strpos( $site_url, $staging_segment ) ) {
				$url_data['production_url'] = str_replace( $staging_segment, '', untrailingslashit( $site_url ) );
				$url_data['staging_url']    = untrailingslashit( $site_url );
			} else {
				$url_data['staging_url']    = $url_data['production_url'] . $staging_segment;
				$url_data['production_url'] = untrailingslashit( str_replace( $staging_segment, '', $site_url ) );
			}
		}

		$dir_mtime     = filemtime( $path_data['staging_dir'] );
		$creation_date = ! empty( $existing['creation_date'] )
			? $existing['creation_date']
			: gmdate( 'M j, Y', false !== $dir_mtime ? $dir_mtime : time() );

		return array(
			'creation_date'  => $creation_date,
			'production_dir' => $path_data['production_dir'],
			'production_url' => $url_data['production_url'],
			'staging_dir'    => $path_data['staging_dir'],
			'staging_url'    => $url_data['staging_url'],
		);
	}

	/**
	 * Build staging_config for a production site from an on-disk staging directory.
	 *
	 * @param string $staging_dir    Staging directory path.
	 * @param string $production_dir Production ABSPATH.
	 * @param string $production_url Production site URL.
	 * @param array  $existing       Existing config to preserve creation_date when possible.
	 * @return array|null
	 */
	public static function build_config_for_production_site( $staging_dir, $production_dir, $production_url, array $existing = array() ) {
		$staging_dir    = self::normalize_trailing_slash( $staging_dir );
		$production_dir = self::normalize_trailing_slash( $production_dir );

		$path_data = self::parse_staging_from_abspath( $staging_dir );
		if ( null === $path_data ) {
			return null;
		}

		$staging_id  = $path_data['staging_id'];
		$staging_url = untrailingslashit( $production_url ) . '/staging/' . $staging_id;

		$dir_mtime     = filemtime( $staging_dir );
		$creation_date = ! empty( $existing['creation_date'] )
			? $existing['creation_date']
			: gmdate( 'M j, Y', false !== $dir_mtime ? $dir_mtime : time() );

		return array(
			'creation_date'  => $creation_date,
			'production_dir' => $production_dir,
			'production_url' => untrailingslashit( $production_url ),
			'staging_dir'    => $staging_dir,
			'staging_url'    => $staging_url,
		);
	}
}
