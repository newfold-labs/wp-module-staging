<?php

use NewfoldLabs\WP\ModuleLoader\Container;
use NewfoldLabs\WP\Module\Staging\Staging;
use function NewfoldLabs\WP\ModuleLoader\register;
use function NewfoldLabs\WP\Context\getContext;

if ( function_exists( 'add_action' ) ) {

	add_action(
		'plugins_loaded',
		function () {

			register(
				array(
					'name'     => 'staging',
					'label'    => __( 'Staging', 'newfold-staging-module' ),
					'callback' => function ( Container $container ) {
						if ( 'atomic' === getContext( 'platform' ) ) {
							return;
						}
						return new Staging( $container );
					},
					'isActive' => true,
					'isHidden' => true,
				)
			);

		}
	);

}
