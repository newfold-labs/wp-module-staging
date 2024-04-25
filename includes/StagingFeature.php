<?php

namespace NewfoldLabs\WP\Module\Staging;

use NewfoldLabs\WP\ModuleLoader\Container;
use NewfoldLabs\WP\Module\Staging\Staging;
use function NewfoldLabs\WP\ModuleLoader\register;

/**
 * Child class for a feature
 * 
 * Child classes should define a name property as the feature name for all API calls. This name will be used in the registry.
 * Child class naming convention is {FeatureName}Feature.
 */
class StagingFeature extends \NewfoldLabs\WP\Module\Features\Feature {
    /**
     * The feature name.
     *
     * @var string
     */
    protected $name = 'staging';
    protected $value = true; // default to on

    /**
     * Initialize staging feature
     * 
     */
    public function initialize() {
        if ( function_exists( 'add_action' ) ) {

            add_action(
                'plugins_loaded',
                function () {

                    register(
                        array(
                            'name'     => 'staging',
                            'label'    => __( 'Staging', 'newfold-staging-module' ),
                            'callback' => function ( Container $container ) {
                                return new Staging( $container );
                            },
                            'isActive' => true,
                            'isHidden' => true,
                        )
                    );

                }
            );

        }
    }

}