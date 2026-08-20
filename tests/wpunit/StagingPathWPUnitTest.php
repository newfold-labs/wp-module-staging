<?php

namespace NewfoldLabs\WP\Module\Staging;

/**
 * Tests for StagingPath utilities.
 *
 * @covers \NewfoldLabs\WP\Module\Staging\StagingPath
 */
class StagingPathWPUnitTest extends \lucatume\WPBrowser\TestCase\WPTestCase {

	/**
	 * Staging ABSPATH is recognized.
	 *
	 * @return void
	 */
	public function test_is_staging_abspath_positive() {
		$this->assertTrue( StagingPath::is_staging_abspath( '/var/www/html/staging/1234/' ) );
		$this->assertTrue( StagingPath::is_staging_abspath( '/var/www/html/staging/5678' ) );
	}

	/**
	 * Production ABSPATH is not recognized as staging.
	 *
	 * @return void
	 */
	public function test_is_staging_abspath_negative() {
		$this->assertFalse( StagingPath::is_staging_abspath( '/var/www/html/' ) );
		$this->assertFalse( StagingPath::is_staging_abspath( '/var/www/html/staging/' ) );
		$this->assertFalse( StagingPath::is_staging_abspath( '/var/www/html/staging/12345/' ) );
	}

	/**
	 * Parses staging ABSPATH into production and staging paths.
	 *
	 * @return void
	 */
	public function test_parse_staging_from_abspath() {
		$result = StagingPath::parse_staging_from_abspath( '/home/user/public_html/staging/4321/' );

		$this->assertNotNull( $result );
		$this->assertSame( '/home/user/public_html/', $result['production_dir'] );
		$this->assertSame( '/home/user/public_html/staging/4321/', $result['staging_dir'] );
		$this->assertSame( '4321', $result['staging_id'] );
	}

	/**
	 * Parses staging site URL into production and staging URLs.
	 *
	 * @return void
	 */
	public function test_parse_staging_from_url() {
		$result = StagingPath::parse_staging_from_url( 'https://example.com/staging/9876' );

		$this->assertNotNull( $result );
		$this->assertSame( 'https://example.com', $result['production_url'] );
		$this->assertSame( 'https://example.com/staging/9876', $result['staging_url'] );
		$this->assertSame( '9876', $result['staging_id'] );
	}

	/**
	 * Detects swapped URLs in staging_config.
	 *
	 * @return void
	 */
	public function test_config_has_swapped_urls() {
		$swapped = array(
			'production_url' => 'https://example.com/staging/1234',
			'staging_url'    => 'https://example.com',
		);
		$valid   = array(
			'production_url' => 'https://example.com',
			'staging_url'    => 'https://example.com/staging/1234',
		);

		$this->assertTrue( StagingPath::config_has_swapped_urls( $swapped ) );
		$this->assertFalse( StagingPath::config_has_swapped_urls( $valid ) );
	}

	/**
	 * Fixes swapped URLs and directories.
	 *
	 * @return void
	 */
	public function test_fix_swapped_urls() {
		$config = array(
			'production_url' => 'https://example.com/staging/1234',
			'staging_url'    => 'https://example.com',
			'production_dir' => '/var/www/html/staging/1234/',
			'staging_dir'    => '/var/www/html/',
		);

		$fixed = StagingPath::fix_swapped_urls( $config );

		$this->assertSame( 'https://example.com', $fixed['production_url'] );
		$this->assertSame( 'https://example.com/staging/1234', $fixed['staging_url'] );
		$this->assertSame( '/var/www/html/', $fixed['production_dir'] );
		$this->assertSame( '/var/www/html/staging/1234/', $fixed['staging_dir'] );
	}

	/**
	 * Validates a complete staging_config structure.
	 *
	 * @return void
	 */
	public function test_is_valid_staging_config() {
		$valid = array(
			'creation_date'  => 'Jan 1, 2025',
			'production_dir' => '/var/www/html/',
			'production_url' => 'https://example.com',
			'staging_dir'    => '/var/www/html/staging/1234/',
			'staging_url'    => 'https://example.com/staging/1234',
		);

		$this->assertTrue( StagingPath::is_valid_staging_config( $valid ) );
		$this->assertFalse( StagingPath::is_valid_staging_config( array() ) );
	}

	/**
	 * Builds config for a staging site from path and URL.
	 *
	 * @return void
	 */
	public function test_build_config_for_staging_site() {
		$config = StagingPath::build_config_for_staging_site(
			'/var/www/html/staging/5555/',
			'https://example.com/staging/5555'
		);

		$this->assertNotNull( $config );
		$this->assertSame( '/var/www/html/', $config['production_dir'] );
		$this->assertSame( 'https://example.com', $config['production_url'] );
		$this->assertSame( '/var/www/html/staging/5555/', $config['staging_dir'] );
		$this->assertSame( 'https://example.com/staging/5555', $config['staging_url'] );
	}
}
