<?php

namespace NewfoldLabs\WP\Module\Staging;

use NewfoldLabs\WP\ModuleLoader\Container;
use function NewfoldLabs\WP\ModuleLoader\container;

/**
 * Class StagingApi
 */
class StagingApi extends \WP_REST_Controller {

	/**
	 * The namespace of this controller's route.
	 *
	 * @var string
	 */
	protected $namespace = 'newfold-staging/v1';

	/**
	 * An instance of the Staging class.
	 *
	 * @var Staging
	 */
	protected $staging;

	/**
	 * Staging_Controller constructor.
	 *
	 * @param Container $container An instance of the Container class.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
		$this->staging   = new Staging( $this->container );
	}

	/**
	 * Registers the routes for the objects of the controller.
	 */
	public function register_routes() {

		register_rest_route(
			$this->namespace,
			'/staging',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getStagingDetails' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'createStaging' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'destroyStaging' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/staging/clone',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'cloneProductionToStaging' ),
					'permission_callback' => array( $this, 'checkPermission' ),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/staging/deploy',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'deployToProduction' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->getDeployTypeArgs(),
				),
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getDeployStatus' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->getDeployTypeArgs(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/staging/copy/advance',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'advanceContentCopy' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->getCopyTypeArgs(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/staging/copy/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'getContentCopyStatus' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => $this->getCopyTypeArgs(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/staging/switch-to',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'switchTo' ),
					'permission_callback' => array( $this, 'checkPermission' ),
					'args'                => array(
						'env' => array(
							'required'          => true,
							'enum'              => array( 'staging', 'production' ),
							'validate_callback' => function ( $value ) {
								return in_array( $value, array( 'staging', 'production' ), true );
							},
						),
					),
				),
			)
		);
	}

	/**
	 * Check permissions for routes.
	 *
	 * @return bool
	 */
	public function checkPermission() {
		return (bool) current_user_can( 'manage_options' );
	}

	/**
	 * Create a staging environment.
	 *
	 * @return \WP_REST_Response
	 */
	public function createStaging() {

		$payload = $this->staging->createStaging();
		if ( ! is_wp_error( $payload ) ) {
			$this->staging->getConfig( false );
			$payload = array(
				'creationDate'           => $this->staging->getCreationDate(),
				'currentEnvironment'     => $this->staging->getEnvironment(),
				'message'                => $payload['message'],
				'productionDir'          => $this->staging->getProductionDir(),
				'productionThumbnailUrl' => $this->staging->getProductionScreenshotUrl(),
				'productionUrl'          => $this->staging->getProductionUrl(),
				'stagingDir'             => $this->staging->getStagingDir(),
				'stagingExists'          => $this->staging->stagingExists(),
				'stagingThumbnailUrl'    => $this->staging->getStagingScreenshotUrl(),
				'stagingUrl'             => $this->staging->getStagingUrl(),
				'status'                 => $payload['status'],
			);
		}

		return rest_ensure_response( $payload );
	}

	/**
	 * Clone production environment to staging.
	 *
	 * @return \WP_REST_Response
	 */
	public function cloneProductionToStaging() {
		return rest_ensure_response( $this->staging->cloneProductionToStaging() );
	}

	/**
	 * Deploy files and/or database from staging to production.
	 *
	 * @param \WP_REST_Request $request WordPress HTTP request model.
	 *
	 * @return \WP_REST_Response
	 */
	public function deployToProduction( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->staging->deployToProduction( $request->get_param( 'type' ) ) );
	}

	/**
	 * Poll deploy progress (used when deploy runs asynchronously).
	 *
	 * @param \WP_REST_Request $request WordPress HTTP request model.
	 *
	 * @return \WP_REST_Response
	 */
	public function getDeployStatus( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->staging->getDeployStatus( $request->get_param( 'type' ) ) );
	}

	/**
	 * Advance the stepped content-copy deploy by one bounded unit.
	 *
	 * @param \WP_REST_Request $request WordPress HTTP request model.
	 *
	 * @return \WP_REST_Response
	 */
	public function advanceContentCopy( \WP_REST_Request $request ) {
		return rest_ensure_response(
			$this->staging->advanceContentCopy( $request->get_param( 'type' ), (bool) $request->get_param( 'restart' ) )
		);
	}

	/**
	 * Poll the stepped content-copy deploy progress without advancing it.
	 *
	 * @param \WP_REST_Request $request WordPress HTTP request model.
	 *
	 * @return \WP_REST_Response
	 */
	public function getContentCopyStatus( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->staging->getContentCopyStatus( $request->get_param( 'type' ) ) );
	}

	/**
	 * Shared REST args for copy type on the content-copy advance and status routes.
	 *
	 * @return array
	 */
	protected function getCopyTypeArgs() {
		return array(
			'type'    => array(
				'default'           => 'deploy_files',
				'enum'              => array( 'deploy_files' ),
				'validate_callback' => function ( $value ) {
					return in_array( $value, array( 'deploy_files' ), true );
				},
			),
			// Advance only: true on the first call of a new deploy to discard any prior job's state so a
			// previous run's terminal 'done'/'error' does not short-circuit it. Ignored by status.
			'restart' => array(
				'default'           => false,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Shared REST args for deploy type on POST and GET deploy routes.
	 *
	 * @return array
	 */
	protected function getDeployTypeArgs() {
		return array(
			'type' => array(
				'default'           => 'all',
				'enum'              => array( 'all', 'db', 'files' ),
				'validate_callback' => function ( $value ) {
					return in_array( $value, array( 'all', 'db', 'files' ), true );
				},
			),
		);
	}

	/**
	 * Destroy the staging environment.
	 *
	 * @return \WP_REST_Response
	 */
	public function destroyStaging() {
		return rest_ensure_response( $this->staging->destroyStaging() );
	}

	/**
	 * Get all staging details.
	 *
	 * @return \WP_REST_Response
	 */
	public function getStagingDetails() {
		$health = new StagingHealthCheck( $this->staging );
		if ( $health->maybe_repair() ) {
			$this->staging->getConfig( false );
		}

		return rest_ensure_response(
			array(
				'creationDate'           => $this->staging->getCreationDate(),
				'currentEnvironment'     => $this->staging->getEnvironment(),
				'productionDir'          => $this->staging->getProductionDir(),
				'productionThumbnailUrl' => $this->staging->getProductionScreenshotUrl(),
				'productionUrl'          => $this->staging->getProductionUrl(),
				'stagingDir'             => $this->staging->getStagingDir(),
				'stagingExists'          => $this->staging->stagingExists(),
				'stagingThumbnailUrl'    => $this->staging->getStagingScreenshotUrl(),
				'stagingUrl'             => $this->staging->getStagingUrl(),
			)
		);
	}

	/**
	 * Switch to the production environment.
	 *
	 * @param \WP_REST_Request $request WordPress HTTP request model.
	 *
	 * @return \WP_REST_Response
	 */
	public function switchTo( \WP_REST_Request $request ) {
		return rest_ensure_response( $this->staging->switchTo( $request->get_param( 'env' ) ) );
	}
}
