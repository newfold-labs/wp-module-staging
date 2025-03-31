<?php

namespace NewfoldLabs\WP\Module\Staging;

use function NewfoldLabs\WP\ModuleLoader\container;

/**
 * Class StagingMenu
 */
class StagingMenu {
	/**
	 * Initialize.
	 */
	public static function init() {
		// add admin menu
		add_action( 'admin_bar_menu', array( __CLASS__, 'add_staging_toolbar_items' ) );
		add_filter( 'nfd_plugin_subnav', array( __CLASS__, 'add_nfd_subnav' ) );
	}

	/**
	 * Add to the Newfold subnav.
	 *
	 * @param array $subnav The nav array.
	 * @return array The filtered nav array
	 */
	public static function add_nfd_subnav( $subnav ) {
		$brand       = container()->get( 'plugin' )['id'];
		$performance = array(
			'route'    => $brand . '#/staging',
			'title'    => __( 'Staging', 'wp-module-staging' ),
			'priority' => 50,
		);
		array_push( $subnav, $performance );
		return $subnav;
	}

	/**
	 * Customize the admin bar.
	 *
	 * @param \WP_Admin_Bar $admin_bar An instance of the WP_Admin_Bar class.
	 */
	public static function add_staging_toolbar_items( \WP_Admin_Bar $admin_bar ) {
		if ( current_user_can( 'manage_options' ) ) {

			if ( container()->get( 'isStaging' ) ) {
				$args = array(
					'id'    => 'newfold-staging',
					'href'  => admin_url( 'admin.php?page=' . container()->plugin()->id . '#/staging' ),
					'title' => '<div style="background-color: #ce0000; padding: 0 10px;color:#fff;">' . esc_html__( 'Staging Environment', 'wp-module-staging' ) . '</div>',
					'meta'  => array(
						'title' => esc_attr__( 'Staging Actions', 'wp-module-staging' ),
					),
				);
				$admin_bar->add_menu( $args );
			}
		}
	}
}
