<?php

use function NewfoldLabs\WP\Context\getContext;

if ( function_exists( 'add_action' ) ) {

	add_action(
		'newfold/features/filter/isEnabled/staging',
		function($value) {
			if ( 'atomic' === getContext( 'platform' ) ) {
				$value = false;
			}
			return $value;
		}
	);

}
