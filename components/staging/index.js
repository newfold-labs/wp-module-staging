// import { default as StagingIsLoading } from '../stagingIsLoading/';

/**
 * Staging Module
 * For use in brand plugin apps to display staging page
 * 
 * @param {*} props 
 * @returns 
 */
const Staging = ({methods, constants, Components, ...props}) => {
	const apiNamespace = '/newfold-staging/v1/';
	const unknownErrorMsg = constants.unknownErrorMsg ? constants.unknownErrorMsg : 'An unknown error has occurred.';
	const [ isLoading, setIsLoading ] = methods.useState( true );
	const [ isError, setIsError ] = methods.useState( false );
	const [ notice, setNotice ] = methods.useState( '' );
	const [ showManageStaging, setShowManageStaging ] = methods.useState( false );
	const [ errorMessage, setErrorMessage ] = methods.useState( null );
	const [ isCreatingStaging, setIsCreatingStaging ] = methods.useState( false );
	const [ hasStaging, setHasStaging ] = methods.useState( null );
	const [ isProduction, setIsProduction ] = methods.useState( null );
	const [ creationDate, setCreationDate ] = methods.useState( null );
	const [ productionDir, setProductionDir ] = methods.useState( null );
	const [ productionUrl, setProductionUrl ] = methods.useState( null );
	const [ stagingDir, setStagingDir ] = methods.useState( null );
	const [ stagingUrl, setStagingUrl ] = methods.useState( null );
	const [ switchingTo, setSwitchingTo ] = methods.useState( '' );

	/**
	 * render staging preloader
	 * 
	 * @returns React Component
	 */
	 const renderSkeleton = () => {
		// render default skeleton
		return <Components.Spinner />;
	}

	// Setup values from response
	const setup = ( response ) => {

		if ( response.hasOwnProperty( 'stagingExists' ) ) {
			setHasStaging( response.stagingExists );
		}
		if ( response.hasOwnProperty( 'currentEnvironment' ) ) {
			setIsProduction( response.currentEnvironment === 'production' );
		}
		if ( response.hasOwnProperty( 'productionDir' ) ) {
			setProductionDir( response.productionDir );
		}
		if ( response.hasOwnProperty( 'productionUrl' ) ) {
			setProductionUrl( response.productionUrl );
		}
		if ( response.hasOwnProperty( 'stagingDir' ) ) {
			setStagingDir( response.stagingDir );
		}
		if ( response.hasOwnProperty( 'stagingUrl' ) ) {
			setStagingUrl( response.stagingUrl );
		}
		if ( response.hasOwnProperty( 'creationDate' ) ) {
			setCreationDate( response.creationDate );
		}

	};

	const setError = ( error ) => {
		console.log('setError', error);
		setIsLoading( false );
		setIsError(true);
		setErrorMessage(error);
	};

	const catchError = (error) => {
		if ( error.hasOwnProperty( 'message' ) ) {
			setError(error.message);
		} else if ( error.hasOwnProperty( 'code' ) ) {
			setError(error.code);
		} else if ( error.hasOwnProperty( 'status' ) ) {
			setError(error.status);
		} else if ( error.hasOwnProperty( 'data' ) && error.data.hasOwnProperty('status') ) {
			setError(error.data.status);
		} else {
			setError(unknownErrorMsg);
		}

	};

	/**
	 * on mount load staging data from module api
	 */
	methods.useEffect(() => {
		init();
	}, [] );

	const init = () => {
		console.log('Init - Loading Staging Data');
		setIsError(false);
		setIsLoading(true);
		stagingApiFetch(
			'staging/', 
			'GET', 
			(response) => {
				console.log('Init callback', response);
				// validate response data
				if ( response.hasOwnProperty('currentEnvironment') ) {
					//setup with fresh data
					setup( response );
				} else if ( response.hasOwnProperty('code') && response.code === 'error_response' ) {
					// report error received
					setError( response.message );
				} else {
					// report unknown error
					setError( unknownErrorMsg );
				}
				setIsLoading( false );
			}
		);
	}

	const createStaging = () => {
		console.log('create staging');
		setIsCreatingStaging(true);
		stagingApiFetch(
			'staging/', 
			'POST', 
			(response) => {
				console.log('Create Staging Callback', response);
				// validate response data
				if ( response.hasOwnProperty('currentEnvironment') ) {
					//setup with fresh data
					setup( response );
				} else if ( response.hasOwnProperty('status') ) {
					//setup with fresh data
					if ( response.status === 'success' ){
						setup( response );
						setNotice( response.message );
					} else {
						setError( response.message );
					}
				} else {
					// report unknown error
					setError( unknownErrorMsg );
				}
				setIsLoading( false );
				setIsCreatingStaging(false);
			}
		);
	};

	const deleteStaging = () => {
		console.log('delete staging');
		stagingApiFetch(
			'staging/', 
			'DELETE', 
			(response) => {
				console.log('Delete staging callback', response);
				// validate response data
				if ( response.hasOwnProperty('status') ) {
					// setup with fresh data
					if ( response.status === 'success' ){
						setHasStaging( false );
						setNotice( response.message );
					} else {
						setError( response.message );
					}
				} else {
					// report unknown error
					setError( unknownErrorMsg );
				}
				setIsLoading( false );
			}
		);
	};

	const clone = () => {
		console.log('clone production to staging');
		stagingApiFetch(
			'staging/clone/', 
			'POST', 
			(response) => {
				console.log('Clone Callback', response);
				// validate response data
				if ( response.hasOwnProperty('status') ) {
					// setup with fresh data
					if ( response.status === 'success' ){
						setHasStaging( true );
						setNotice( response.message );
					} else {
						setError( response.message );
					}
				} else {
					// report unknown error
					setError( unknownErrorMsg );
				}
				setIsLoading( false );
			}
		);
	};

	const switchToEnv = ( env ) => {
		console.log('switching to', env, `/switch-to?env=${ env }`);
		setSwitchingTo( env );
		setNotice('Switching to '+env);
		stagingApiFetch(
			`staging/switch-to&env=${env}`, 
			'GET', 
			(response) => {
				console.log('Switch Callback', response);
				// validate response data
				if ( response.hasOwnProperty( 'load_page' ) ) {
					window.location.href = response.load_page;
					// navigate(response.load_page);
				} else if ( response.hasOwnProperty('status') && response.status === 'error' ) {
					setError(response.message);
				} else {
					// report unknown error
					setError( unknownErrorMsg );
				}
			}
		);
	};

	/**
	 * 
	 * @param {string} type One of 'all', 'files', or 'db'
	 */
	const deploy = ( type ) => {
		console.log('Deploy', type);
		stagingApiFetch(
			`staging/deploy&type=${type}`, 
			'POST', 
			(response) => {
				console.log('Deploy Callback', response);
				// validate response data
				if ( response.hasOwnProperty('status') ) {
					// setup with fresh data
					if ( response.status === 'success' ){
						setNotice( response.message );
					} else {
						setError( response.message );
					}
				} else {
					// report unknown error
					setError( unknownErrorMsg );
				}
				setIsLoading( false );
			}
		);
	};

	/**
	 * Wrapper method to interface with staging endpoints
	 *
	 * @param path append to the end of the apiNamespace
	 * @param method GET or POST, default GET
	 * @param thenCallback method to call in promise then
	 * @param passError setter for the error in component
	 * @return apiFetch promise
	 */
	const stagingApiFetch = (path = '', method = 'GET', thenCallback, errorCallback = catchError) => {
		// setIsError( false );
		setIsLoading( true );
		return methods.apiFetch({
			url: constants.resturl + apiNamespace + path,
			method,
			beforeSend: (xhr) => {
				xhr.setRequestHeader( 'X-WP-Nonce', constants.restnonce );
			},
		}).then( (response) => {
			thenCallback( response );
		}).catch( (error) => {
			errorCallback( error );
		})
	};

	const renderProductionCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Production Site</h3>
				<Components.Icon icon="admin-site" />
			</Components.CardHeader>
			<Components.CardBody>
				<p>{ constants.productionDescription }</p>
				{ productionUrl &&
					<p>Your site is available at: <strong>{ productionUrl }</strong></p>
				}
				<p>Manage your staging site from production.</p>
				{ !isProduction || !hasStaging &&
					<p>Some operations must be done from the production site. You must be on the production site to clone to staging or delete staging.</p>
				}
				<Components.ButtonGroup>
					<Components.Button
						disabled={ !isProduction && hasStaging ? true : false }
						icon="cloud-saved"
						label="Copies all Production (data and files) to Staging"
						onClick={ clone }
						showTooltip
						variant={ isProduction ? "primary" : "secondary" }
						>
						<Components.Icon icon="database-view" />
						{ constants.cloneButtonText }
					</Components.Button>
					<Components.Button
						disabled={ !isProduction && hasStaging ? true : false }
						icon="trash"
						isDestructive
						label="Delete this Staging environment!"
						onClick={ deleteStaging }
						showTooltip
						variant="secondary"
						>
						Delete Staging
					</Components.Button>
				</Components.ButtonGroup>
			</Components.CardBody>
			<Components.CardFooter justify="center">
				{ isProduction && 
					<Components.Button 
						variant="secondary"
						showTooltip
						label="You are here"
						>
						<div style={{color: 'green'}}>
							<Components.Icon icon="yes" />
						</div>
						Production Site Loaded
					</Components.Button>
				}
				{ !isProduction && 
					<Components.Button 
						icon="external"
						variant="primary"
						onClick={ () => { switchToEnv( 'production' ) }}
						showTooltip
						label="Load the production site in the browser"
					>Load Production Environment</Components.Button>
				}
			</Components.CardFooter>
		</Components.Card>;
	};

	const renderStagingCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Staging Site</h3>
				<Components.Icon icon="hammer" />
			</Components.CardHeader>
			<Components.CardBody>
				{ hasStaging && 
					<div>
						<p>{ constants.stagingDescription }</p>
						<p>Your stating site is available at: <strong>{ stagingUrl }</strong></p>
						<p>Once you have finished edits on your staging site, you can copy from Staging back to your Production site (files and/or data)!</p>
						{ isProduction &&
							<p>Some operations must be done from the staging site. You are currenlty on production. You must go to the staging site to deploy changes.</p>
						}
						<Components.ButtonGroup>
						<Components.Button
							disabled={ isProduction ? true : false }
							icon="cloud-upload"
							onClick={ () => { deploy( 'files' ) }}
							variant="secondary"
							showTooltip
							label="Copy files only from Staging to Production"
						>
							Deploy Only Files
						</Components.Button>
						<Components.Button
							disabled={ isProduction ? true : false }
							icon="database-export"
							onClick={ () => { deploy( 'db' ) }}
							variant="secondary"
							showTooltip
							label="Copy database only from Staging to Production"
						>
							Deploy Only Database
						</Components.Button>
						<Components.Button
							disabled={ isProduction ? true : false }
							icon="cloud-upload"
							onClick={ () => { deploy( 'all' ) }}
							variant="secondary"
							showTooltip
							label="Copy files and database from Staging to Production"
						>
							<Components.Icon icon="database-export" />
							Deploy Both Files and Database
						</Components.Button>
						</Components.ButtonGroup>

					</div>
				}
				{ !hasStaging &&
					<div>
						<p>{ constants.stagingLongDescription }</p>
						<Components.Button 
							onClick={ createStaging }
							className="button-primary"
							icon="migrate"
							showTooltip
							label="Create a new Stating site from Production"
						>
							Create Staging Site
						</Components.Button>
					</div>
				}
			</Components.CardBody>
			<Components.CardFooter justify="center">
				{ hasStaging && !isProduction && 
					<Components.Button 
						variant="secondary"
						showTooltip
						label="You are here"
						>
						<div style={{color: 'green'}}>
							<Components.Icon icon="yes" />
						</div>
						Staging Site Loaded
					</Components.Button>
				}
				{ hasStaging && isProduction && 
					<Components.Button 
						variant="primary"
						icon="external"
						onClick={ () => { switchToEnv( 'staging' ) }}
						showTooltip
						label="Load this Staging Site in the browser"
					>Load Staging Site</Components.Button>
				}
			</Components.CardFooter>
		</Components.Card>;
	};

	const renderInfoCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Staging Data</h3>
				<Components.Icon icon="info" />
			</Components.CardHeader>
			<Components.CardBody>
			<dl>
				<dt>Status</dt>
				<dd><code>{ notice }</code></dd>
				<dt>Current Environment</dt>
				<dd><code>{ isProduction ? 'production' : 'staging' }</code></dd>
				<dt>Production Url</dt>
				<dd><code>{ productionUrl }</code></dd>
				<dt>Production Directory</dt>
				<dd><code>{ productionDir }</code></dd>
				<dt>Staging Exists</dt>
				<dd><code>{ hasStaging ? 'true' : 'false' }</code></dd>
				<dt>Creation Date</dt>
				<dd><code>{ creationDate }</code></dd>
				<dt>Staging Url</dt>
				<dd><code>{ stagingUrl }</code></dd>
				<dt>Staging Directory</dt>
				<dd><code>{ stagingDir }</code></dd>
			</dl>
			</Components.CardBody>
			<Components.CardFooter>

			</Components.CardFooter>
		</Components.Card>;
	};

	const renderErrorCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Error</h3>
			</Components.CardHeader>
			<Components.CardBody>
				<p>Oops, there was an error loading the staging data, please try again later or contact support.</p>
				<Components.Button
					onClick={ init }
					icon="update"
					variant="primary"
				>
					Refresh
				</Components.Button>
			</Components.CardBody>
			<Components.CardFooter>
				{ errorMessage && 
					<p>{errorMessage}</p>
				}
			</Components.CardFooter>
		</Components.Card>;
	};

	const renderCards = () => {
		return (
			<div className="staging-cards grid col2">
				{ renderProductionCard() }
				{ renderStagingCard() }
				{ renderInfoCard() }
				{ switchingTo && 
					<p>Switching to: { switchingTo }</p>
				}
			</div>
		);

	};

	return (
		<div className={methods.classnames('newfold-staging-wrapper')}>
			{ notice }
			{ isLoading && 
				renderSkeleton()
			}
			{ isError && 
				renderErrorCard()
			}
			{ !isLoading && !isError &&
				renderCards()
			}
		</div>
	);

};

export default Staging;