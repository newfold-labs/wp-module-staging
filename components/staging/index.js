// import { default as StagingIsLoading } from '../stagingIsLoading/';

/**
 * Staging Module
 * For use in brand plugin apps to display staging page
 * 
 * @param {*} props 
 * @returns 
 */
 const Staging = ({methods, constants, Components, ...props}) => {
	const apiRootPath = '/newfold-staging/v1/staging';
	const unknownErrorMsg = 'An unknown error has occurred.';
	const [ isLoading, setIsLoading ] = methods.useState( true );
	const [ isError, setIsError ] = methods.useState( false );
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

	/**
	 * on mount load all staging data from module api
	 */
	methods.useEffect(() => {
		console.log('Loading Staging Data');
		callApi();

	}, [] );

	const createStaging = () => {
		console.log('create staging');
		callApi( '', 'POST' );
	};

	const cloneEnv = () => {
		console.log('clone production to staging');
		callApi( '/clone', 'POST' );
	};

	const callApi = (path = '', method = 'GET') => {
		let url = `${constants.resturl}${apiRootPath}${path}`;
		let options = {
			url,
			method,
			beforeSend: (xhr) => {
				xhr.setRequestHeader( 'X-WP-Nonce', constants.restnonce );
			},
		}
		
		setIsError( false );
		setIsLoading( true );
		// setNotice( null );

		methods.apiFetch(
			options
		).then( (response ) => {
			console.log(response);
			setIsLoading( false );
			if ( 
				! response.hasOwnProperty('stagingExists') ||
				response.hasOwnProperty('code') && response.code === 'error_response'
			) {
				setIsError( true );
				setErrorMessage( response.message );
			} else {
				//set data
				setup( response );
			}
		}).catch( (error) => {
			console.log(error);
		});

	};

	const renderProductionCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Production Site</h3>
			</Components.CardHeader>
			<Components.CardBody>
				<div>
					<p>{ constants.productionDescription }</p>
					{ productionUrl &&
						<p>Your site is available at: <strong>{ productionUrl }</strong>.</p>
					}
					<Components.Button onClick={ () => cloneEnv() } className="button-primary">
						{ constants.cloneButtonText }
					</Components.Button>
				</div>
			</Components.CardBody>
			<Components.CardFooter>
				{ productionDir &&
					<p>Production Directory: <code>{ productionDir }</code></p>
				}
			</Components.CardFooter>
		</Components.Card>;
	};

	const renderStagingCard = () => {
		return <Components.Card>
			<Components.CardHeader>
				<h3>Staging Site</h3>
			</Components.CardHeader>
			<Components.CardBody>
				{ hasStaging && 
					<div>
						<p>{ constants.stagingDescription }</p>
						<p>Your stating site is available at: { stagingUrl }.</p>
						<Components.Button>
							Manage
						</Components.Button>
						<Components.Button>
							Delete
						</Components.Button>
					</div>
				}
				{ !hasStaging &&
					<div>
						<p>{ constants.stagingLongDescription }</p>
						<Components.Button onClick={ () => createStaging() } className="button-primary">
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

	const renderCards = () => {
		return (
			<div className="staging-cards grid col2">
				{ renderProductionCard() }
				{ renderStagingCard() }
			</div>
		);

	};

	return (
		<div className={methods.classnames('newfold-staging-wrapper')}>
			{ isLoading && 
				renderSkeleton()
			}
			{ isError && 
				<h3>Oops, there was an error loading the staging data, please try again later or contact support.</h3>
			}
			{ !isLoading && !isError &&
				renderCards()
			}
		</div>
	);

};

export default Staging;