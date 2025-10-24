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
		// handle staging redirect from old url
		add_action( 'admin_init', array( __CLASS__, 'handle_staging_redirect' ) );
		// add tools page
		add_action( 'admin_menu', array( __CLASS__, 'add_management_page' ) );
	}

	/**
	 * Add to the Newfold subnav.
	 *
	 * @param array $subnav The nav array.
	 * @return array The filtered nav array
	 */
	public static function add_nfd_subnav( $subnav ) {
		$staging = array(
			'route'    => container()->plugin()->id . '#/settings/staging',
			'title'    => _x( 'Staging', 'Menu item text', 'wp-module-staging' ),
			'priority' => 62,
		);
		array_push( $subnav, $staging );
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
					'href'  => apply_filters( 'nfd_build_url', admin_url( 'admin.php?page=' . container()->plugin()->id . '#/staging' ) ),
					'title' => '<div style="background-color: #ce0000; padding: 0 10px;color:#fff;">' . esc_html__( 'Staging Environment', 'wp-module-staging' ) . '</div>',
					'meta'  => array(
						'title' => esc_attr__( 'Staging Actions', 'wp-module-staging' ),
					),
				);
				$admin_bar->add_menu( $args );
			}
		}
	}

	/**
	 * Adds the Page to the WordPress Tools menu.
	 *
	 * @return void
	 */
	public static function add_management_page() {

		add_management_page(
			__( 'Staging', 'wp-module-staging' ),
			__( 'Staging', 'wp-module-staging' ),
			'manage_options',
			Staging::PAGE_SLUG,
			array( __CLASS__, 'tools_staging' )
		);
	}

	/**
	 * Redirects to the plugin staging page.
	 * This is the callback for the tools staging page.
	 *
	 * @return void
	 */
	public static function tools_staging() {
		$new_url = admin_url( 'admin.php?page=' . container()->plugin()->id . '#/settings/staging' );
		wp_safe_redirect( $new_url );
		exit;
	}

	/**
	 * Handle staging redirect from old URL.
	 * This runs on admin_init to catch the redirect before headers are sent.
	 *
	 * @return void
	 */
	public static function handle_staging_redirect() {
		if (
			is_admin() &&
			isset( $_GET['page'] ) &&
			Staging::PAGE_SLUG === $_GET['page']
		) {
			$new_url = admin_url( 'admin.php?page=' . container()->plugin()->id . '#/settings/staging' );
			wp_safe_redirect( $new_url );
			exit;
		}
	}
}
