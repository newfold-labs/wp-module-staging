<?php

use function NewfoldLabs\WP\Context\getContext;
use function NewfoldLabs\WP\Module\Features\getFeature;

if ( function_exists( 'add_action' ) ) {

	// update as needed based on context
	add_filter(
		'newfold/features/filter/isEnabled/staging',
		function($value) {
			if ( 'atomic' === getContext( 'platform' ) ) {
				$value = false;
			}
			return $value;
		}
	);

	add_action(
		'after_setup_theme',
		function () {
			if ( 'atomic' === getContext( 'platform' ) ) {
				$stagingFeature = getFeature('staging');
				if ( $stagingFeature ) {
					$stagingFeature->disable();
				}
			}
		}
	);
}