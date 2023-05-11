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
	const unknownErrorMsg = 'An unknown error has occurred.';
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
	const navigate = methods.useNavigate();
	const location = methods.useLocation();


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
		console.log(error);
		setIsLoading( false );
		setIsError(true);
		setErrorMessage(error);
	};

	/**
	 * on mount load staging data from module api
	 */
	methods.useEffect(() => {
		init();
	}, [] );

	const init = () => {
		console.log('Init - Loading Staging Data');
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
						setErrorMessage( response.message );
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
		stagingApiFetch(
			`staging/switch-to?env=${env}`, 
			'GET', 
			(response) => {
				console.log('Switch Callback', response);
				// validate response data
				if ( response && response.hasOwnProperty( 'load_page' ) ) {
					// window.location.href = response.load_page;
					navigate(response.load_page);
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
			`staging/deploy?type=${type}`, 
			'GET', 
			(response) => {
				console.log('Deploy Callback', response);
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
	const stagingApiFetch = (path = '', method = 'GET', thenCallback, errorCallback = setError) => {
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

	const toggleManageStaging = () => {
		setShowManageStaging( !showManageStaging );
	};

	const renderProductionCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Production Site</h3>
				{ isProduction && 
					<Components.Button 
						variant="secondary"
						disabled
						icon="yes"
						className="is-disabled"
					>You are here</Components.Button>
				}
				{ !isProduction && 
					<Components.Button 
						icon="external"
						variant="primary"
						onClick={ () => { switchToEnv( 'production' ) }}
						showTooltip
						label="Load the production site in the browser"
					>Go here</Components.Button>
				}
			</Components.CardHeader>
			<Components.CardBody>
				<div>
					<p>{ constants.productionDescription }</p>
					{ productionUrl &&
						<p>Your site is available at: <strong>{ productionUrl }</strong>.</p>
					}
					<Components.Button
						icon="migrate"
						onClick={ clone }
						variant="primary"
						showTooltip
						disabled={ !isProduction ? true : false }
						label="Copies all Production (data and files) to Staging"
					>
						{ constants.cloneButtonText }
					</Components.Button>
				</div>
			</Components.CardBody>
			<Components.CardFooter>
				{ productionDir &&
					<p>Production Directory<code>{ productionDir }</code></p>
				}
			</Components.CardFooter>
		</Components.Card>;
	};

	const renderStagingCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Staging Site</h3>
				{ hasStaging && !isProduction && 
					<Components.Button 
						variant="secondary"
						icon="yes"
						disabled
						className="is-disabled"
					>You are here</Components.Button>
				}
				{ hasStaging && isProduction && 
					<Components.Button 
						variant="primary"
						icon="external"
						onClick={ () => { switchToEnv( 'staging' ) }}
						showTooltip
						label="Load this Staging Site in the browser"
					>Go here</Components.Button>
				}
			</Components.CardHeader>
			<Components.CardBody>
				{ hasStaging && 
					<div>
						<p>{ constants.stagingDescription }</p>
						<p>Your stating site is available at: { stagingUrl }.</p>
						<Components.Button
							icon={ showManageStaging ? 'arrow-up' : 'arrow-down' }
							onClick={ toggleManageStaging }
							variant="secondary"
							showTooltip
							label="Migrate changes back to Production from Staging"
						>
							Manage
						</Components.Button>
						{ showManageStaging &&
							<div className="manage-staging">
								<p>Once you have finished edits on your staging site, you can copy from Staging back to your Production site (files and/or data)!</p>
								{ isProduction &&
									<p>Some operations must be done from the staging site. You are currenlty on production. You must go to the staging site to deploy changes.</p>
								}
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
								<Components.Button
									isDestructive
									icon="trash"
									onClick={ deleteStaging }
									variant="secondary"
									showTooltip
									label="Delete this Staging environment!"
								>
									Delete
								</Components.Button>
							</div>
						}
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
			<Components.CardFooter>
				{ stagingDir &&
					<p>Staging Directory: <code>{ stagingDir }</code></p>
				}
			</Components.CardFooter>
		</Components.Card>;
	};

	const renderInfoCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Info</h3>
			</Components.CardHeader>
			<Components.CardBody>
			<dl>
				<dt>Creation Date</dt>
				<dd><code>{ creationDate }</code></dd>
				<dt>Current Environment</dt>
				<dd><code>{ isProduction ? 'production' : 'staging' }</code></dd>
				<dt>Production Directory</dt>
				<dd><code>{ productionDir }</code></dd>
				<dt>Production Url</dt>
				<dd><code>{ productionUrl }</code></dd>
				<dt>Staging Exists</dt>
				<dd><code>{ hasStaging ? 'true' : 'false' }</code></dd>
				<dt>Staging Directory</dt>
				<dd><code>{ stagingDir }</code></dd>
				<dt>Staging Url</dt>
				<dd><code>{ stagingUrl }</code></dd>
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
			{ isLoading && 
				renderSkeleton()
			}
			{ isError && 
				renderErrorCard()
			}
			{ !isLoading && !isError &&
				renderCards()
			}
			{
				notice
			}
		</div>
	);

};

export default Staging;