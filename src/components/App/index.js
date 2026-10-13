// Newfold
import '../../styles/styles.css';

import '../../store';

import {
	Container,
	Root,
	Page,
	Modal,
	Button,
} from '@newfold/ui-component-library';
import classNames from 'classnames';
// Components
import ProductionSite from '../../sections/ProductionSite';
import StagingSite from '../../sections/StagingSite';
import NotificationFeed from '../NotificationFeed';

import getAppText from './getAppText';
import { CheckIcon, XMarkIcon } from '@heroicons/react/24/outline';

import { useState, useEffect } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import apiFetch from '@wordpress/api-fetch';
import { NewfoldRuntime } from '@newfold/wp-module-runtime';

import { STORE_NAME } from '../../data/constants';

const App = () => {
	const {
		cancelSite,
		cloneNoticeCompleteText,
		cloneNoticeStartText,
		createNoticeCompleteText,
		createNoticeStartText,
		deleteNoticeCompleteText,
		deleteNoticeStartText,
		deployNoticeCompleteText,
		deployNoticeStartText,
		errorLabel,
		pageDescription,
		pageTitle,
		proceed,
		switchSite,
		switchToProductionTitle,
		switchToProductionDescription,
		switchToProductionNoticeCompleteText,
		switchToProductionNoticeStartText,
		switchToStagingTitle,
		switchToStagingDescription,
		switchToStagingNoticeCompleteText,
		switchToStagingNoticeStartText,
		switching,
		unknownErrorMessage,
		working,
	} = getAppText();

	const apiNamespace = '/newfold-staging/v1/';
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isThinking, setIsThinking ] = useState( false );
	const [ isError, setIsError ] = useState( false );
	const [ hasStaging, setHasStaging ] = useState( null );
	const [ isProduction, setIsProduction ] = useState( true );
	const [ creationDate, setCreationDate ] = useState( null );
	const [ productionDir, setProductionDir ] = useState( null );
	const [ productionUrl, setProductionUrl ] = useState( null );
	const [ stagingDir, setStagingDir ] = useState( null );
	const [ stagingUrl, setStagingUrl ] = useState( null );
	const [ modalChildren, setModalChildren ] = useState( <div /> );
	const [ modalOpen, setModalOpen ] = useState( false );
	//let notify = methods.useNotification();

	const makeNotice = (
		id,
		title,
		description,
		variant = 'success',
		duration = 4000
	) => {
		pushNotification( id, {
			title,
			description,
			variant,
			autoDismiss: duration,
		} );
	};

	const { pushNotification } = useDispatch( STORE_NAME );

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

	const setError = ( msgError ) => {
		// console.log('setError', error);
		setIsLoading( false );
		setIsThinking( false );
		setIsError( true );
		makeNotice( 'error', errorLabel, msgError, 'error' );
	};

	const catchError = ( error ) => {
		if ( error.hasOwnProperty( 'message' ) ) {
			setError( error.message );
		} else if ( error.hasOwnProperty( 'code' ) ) {
			setError( error.code );
		} else if ( error.hasOwnProperty( 'status' ) ) {
			setError( error.status );
		} else if (
			error.hasOwnProperty( 'data' ) &&
			error.data.hasOwnProperty( 'status' )
		) {
			setError( error.data.status );
		} else {
			setError( unknownErrorMessage );
		}
	};

	/**
	 * on mount load staging data from module api
	 */
	useEffect( () => {
		init();
	}, [] );

	const init = () => {
		// console.log('Init - Loading Staging Data');
		setIsError( false );
		setIsLoading( true );
		stagingApiFetch( 'staging', null, 'GET', ( response ) => {
			// console.log('Init Staging Data:', response);
			// validate response data
			if ( response.hasOwnProperty( 'currentEnvironment' ) ) {
				//setup with fresh data
				setup( response );
			} else if (
				response.hasOwnProperty( 'code' ) &&
				response.code === 'error_response'
			) {
				setError( response.message ); // report known error
			} else {
				setError( unknownErrorMsg ); // report unknown error
			}
			setIsThinking( false );
			setIsLoading( false );
		} );
	};

	const createStaging = () => {
		//console.log('create staging');
		makeNotice( 'creating', working, createNoticeStartText, 'info', 8000 );
		// setIsCreatingStaging(true);
		stagingApiFetch( 'staging', null, 'POST', ( response ) => {
			// console.log('Create Staging Callback', response);
			if ( response.hasOwnProperty( 'status' ) ) {
				if ( response.status === 'success' ) {
					//setup with fresh data
					setup( response );
					makeNotice(
						'created',
						createNoticeCompleteText,
						response.message
					);
				} else {
					setError( response.message ); // report known error
				}
			} else {
				setError( unknownErrorMsg ); // report unknown error
			}
			setIsThinking( false );
			// setIsCreatingStaging(false);
		} );
	};

	const deleteStaging = () => {
		// console.log('delete staging');
		makeNotice( 'deleting', working, deleteNoticeStartText, 'info', 8000 );
		stagingApiFetch( 'staging', null, 'DELETE', ( response ) => {
			// console.log('Delete staging callback', response);
			// validate response data
			if ( response.hasOwnProperty( 'status' ) ) {
				if ( response.status === 'success' ) {
					// setup with fresh data
					setHasStaging( false );
					makeNotice(
						'deleted',
						deleteNoticeCompleteText,
						response.message
					);
				} else {
					setError( response.message );
				}
			} else {
				setError( unknownErrorMsg ); // report unknown error
			}
			setIsThinking( false );
		} );
	};

	const clone = () => {
		// console.log('clone production to staging');
		makeNotice( 'cloning', working, cloneNoticeStartText, 'info', 8000 );
		stagingApiFetch( 'staging/clone', null, 'POST', ( response ) => {
			// console.log('Clone Callback', response);
			// validate response data
			if ( response.hasOwnProperty( 'status' ) ) {
				// setup with fresh data
				if ( response.status === 'success' ) {
					setHasStaging( true );
					makeNotice(
						'cloned',
						cloneNoticeCompleteText,
						response.message
					);
				} else {
					setError( response.message );
					setHasStaging( false );
				}
			} else {
				setError( unknownErrorMsg ); // report unknown error
				setHasStaging( false );
			}
			setIsThinking( false );
		} );
	};

	const switchToStaging = () => {
		if ( ! isProduction ) {
			// console.log('Already on staging.');
		} else {
			setModal(
				switchToStagingTitle,
				switchToStagingDescription,
				switchToEnv,
				'staging',
				switchSite
			);
		}
	};

	const switchToProduction = () => {
		if ( isProduction ) {
			// console.log('Already on production.');
		} else {
			setModal(
				switchToProductionTitle,
				switchToProductionDescription,
				switchToEnv,
				'production',
				switchSite
			);
		}
	};

	/**
	 *
	 * @param {string} env One of 'staging' or 'production'
	 */
	const switchToEnv = ( env ) => {
		// console.log('switching to', env, `/switch-to?env=${ env }`);
		// setSwitchingTo( env );
		setIsThinking( true );
		if ( env === 'production' ) {
			makeNotice(
				'switching',
				working,
				switchToProductionNoticeStartText,
				'info',
				8000
			);
		} else {
			makeNotice(
				'switching',
				working,
				switchToStagingNoticeStartText,
				'info',
				8000
			);
		}

		stagingApiFetch( 'staging/switch-to', { env }, 'GET', ( response ) => {
			// console.log('Switch Callback', response);
			// validate response data
			if ( response.hasOwnProperty( 'load_page' ) ) {
				window.location.href = response.load_page;
				// navigate(response.load_page);
				const notifyMessageText =
					env === 'production'
						? switchToProductionNoticeCompleteText
						: switchToStagingNoticeCompleteText;
				makeNotice(
					'redirecting',
					switching,
					notifyMessageText,
					'success',
					8000
				);
			} else if (
				response.hasOwnProperty( 'status' ) &&
				response.status === 'error'
			) {
				setError( response.message );
			} else {
				setError( unknownErrorMsg ); // report unknown error
			}
		} );
	};

	const sleep = ( ms ) =>
		new Promise( ( resolve ) => {
			setTimeout( resolve, ms );
		} );

	const isCloudflareTimeout = ( error ) => {
		const data = error?.data;
		if ( data?.cloudflare_error || data?.error_code === 524 ) {
			return true;
		}
		if ( error?.status === 524 || error?.code === 524 ) {
			return true;
		}
		const message = String( error?.message || '' );
		return (
			message.includes( '524' ) ||
			message.includes( 'origin_response_timeout' ) ||
			message.includes( 'timed out' )
		);
	};

	const pollDeployStatus = async ( deployTypeParam, attempt = 0 ) => {
		const maxAttempts = 180;
		const intervalMs = 5000;

		if ( attempt >= maxAttempts ) {
			setError( unknownErrorMsg );
			setIsThinking( false );
			return false;
		}

		if ( attempt > 0 ) {
			await sleep( intervalMs );
		}

		try {
			const response = await apiFetch( {
				url: NewfoldRuntime.createApiUrl(
					apiNamespace + 'staging/deploy',
					{ type: deployTypeParam }
				),
				method: 'GET',
			} );

			if (
				response.status === 'running' ||
				response.status === 'unknown'
			) {
				return pollDeployStatus( deployTypeParam, attempt + 1 );
			}
			if ( response.status === 'success' ) {
				makeNotice(
					'deployed',
					deployNoticeCompleteText,
					response.message
				);
				setIsThinking( false );
				return true;
			}
			if ( response.status === 'error' ) {
				setError( response.message );
				setIsThinking( false );
				return false;
			}
			return pollDeployStatus( deployTypeParam, attempt + 1 );
		} catch ( error ) {
			if ( isCloudflareTimeout( error ) && attempt < maxAttempts ) {
				return pollDeployStatus( deployTypeParam, attempt + 1 );
			}
			catchError( error );
			return false;
		}
	};

	const handleDeployResponse = async ( response, deployTypeParam ) => {
		if ( ! response?.hasOwnProperty( 'status' ) ) {
			setError( unknownErrorMsg );
			setIsThinking( false );
			return false;
		}
		if ( response.status === 'running' || response.status === 'unknown' ) {
			return pollDeployStatus( deployTypeParam );
		}
		if ( response.status === 'success' ) {
			makeNotice(
				'deployed',
				deployNoticeCompleteText,
				response.message
			);
			setIsThinking( false );
			return true;
		}
		setError( response.message );
		setIsThinking( false );
		return false;
	};

	// Between content-copy bursts. Each advance() call does a bounded unit of work server-side (the
	// engine's ~10s budget or one bash bookend), so the client just paces the poll; it never waits on a
	// single long request. Short enough to keep the deploy moving, long enough not to hammer the box.
	const COPY_ADVANCE_INTERVAL_MS = 1500;

	// Hard ceiling on burst calls so a server that never reaches a terminal status cannot loop forever.
	// The engine guarantees forward progress per call, so even a very large deploy finishes well under
	// this; it is a safety net, not an expected bound.
	const COPY_MAX_BURSTS = 100000;

	const advanceContentCopy = ( restart ) =>
		apiFetch( {
			url: NewfoldRuntime.createApiUrl(
				apiNamespace + 'staging/copy/advance',
				{
					type: 'deploy_files',
					// rest_sanitize_boolean reads '1'/'0'; only the first call restarts (discards any prior
					// terminal job state), later calls resume the in-flight job.
					restart: restart ? '1' : '0',
				}
			),
			method: 'POST',
		} );

	/**
	 * Drive the stepped, chunked files deploy to completion by repeatedly POSTing the advance route,
	 * one bounded burst per call, until the server reports a terminal status. Replaces the single
	 * fire-and-forget POST + long poll, so no request runs long enough to trip the box's CPU/process
	 * limits or a proxy read timeout.
	 *
	 * @return {Promise<boolean>} Whether the files deploy completed successfully.
	 */
	const deployFilesStepped = async () => {
		setIsThinking( true );
		let restart = true;
		// Tolerate a bounded run of transient request errors (a 502, a dropped connection, a proxy
		// blip). The server job is durable and the WP-cron fallback keeps advancing it, so a single
		// hiccup must not make the UI declare the whole deploy failed and (under "all") skip the DB
		// step. Only give up after several consecutive failures with no successful burst between them.
		const maxConsecutiveErrors = 10;
		let consecutiveErrors = 0;

		for ( let burst = 0; burst < COPY_MAX_BURSTS; burst++ ) {
			let response;
			try {
				response = await advanceContentCopy( restart );
			} catch ( error ) {
				// Each burst is short, so a 524 here is unusual, but the burst still ran server-side and
				// resumes from its cursor. Keep restart unchanged (so the first real call still restarts)
				// and retry after a pace. Other transient errors are retried the same bounded way.
				consecutiveErrors += 1;
				if (
					isCloudflareTimeout( error ) ||
					consecutiveErrors < maxConsecutiveErrors
				) {
					await sleep( COPY_ADVANCE_INTERVAL_MS );
					continue;
				}
				catchError( error );
				return false;
			}

			// A call returned: from here on resume the in-flight job, never restart it, and reset the
			// transient-error run.
			restart = false;
			consecutiveErrors = 0;

			if ( ! response?.hasOwnProperty( 'status' ) ) {
				setError( unknownErrorMsg );
				setIsThinking( false );
				return false;
			}
			if ( response.status === 'success' ) {
				makeNotice(
					'deployed',
					deployNoticeCompleteText,
					response.message
				);
				setIsThinking( false );
				return true;
			}
			if ( response.status === 'error' ) {
				setError( response.message );
				setIsThinking( false );
				return false;
			}

			// Still running: surface progress (if the engine reported a percent) and pace the next burst.
			const percent = response?.progress?.percent;
			if ( typeof percent === 'number' ) {
				makeNotice(
					'deploying',
					working,
					`${ deployNoticeStartText } (${ percent }%)`,
					'info',
					8000
				);
			}
			await sleep( COPY_ADVANCE_INTERVAL_MS );
		}

		setError( unknownErrorMsg );
		setIsThinking( false );
		return false;
	};

	/**
	 * @param {string} deployTypeParam One of 'all', 'files', or 'db'
	 * @return {Promise<boolean>} Whether the deploy step completed successfully.
	 */
	const deployType = async ( deployTypeParam ) => {
		// Files deploy runs through the stepped, chunked content-copy engine; db stays on the legacy
		// single-request path until it is chunked too.
		if ( deployTypeParam === 'files' ) {
			return deployFilesStepped();
		}

		setIsThinking( true );
		try {
			const response = await apiFetch( {
				url: NewfoldRuntime.createApiUrl(
					apiNamespace + 'staging/deploy',
					{ type: deployTypeParam }
				),
				method: 'POST',
			} );
			return handleDeployResponse( response, deployTypeParam );
		} catch ( error ) {
			if ( isCloudflareTimeout( error ) ) {
				return pollDeployStatus( deployTypeParam );
			}
			catchError( error );
			return false;
		}
	};

	const deployStaging = async ( type ) => {
		makeNotice( 'deploying', working, deployNoticeStartText, 'info', 8000 );
		if ( type === 'all' ) {
			const filesOk = await deployType( 'files' );
			if ( ! filesOk ) {
				return;
			}
			await deployType( 'db' );
		} else {
			await deployType( type );
		}
	};

	/**
	 * Wrapper method to interface with staging endpoints
	 *
	 * @param path          append to the end of the apiNamespace
	 * @param qs
	 * @param method        GET or POST, default GET
	 * @param thenCallback  method to call in promise then
	 * @param passError     setter for the error in component
	 * @param errorCallback
	 * @return apiFetch promise
	 */
	const stagingApiFetch = (
		path = '',
		qs = {},
		method = 'GET',
		thenCallback,
		errorCallback = catchError
	) => {
		setIsThinking( true );
		return apiFetch( {
			url: NewfoldRuntime.createApiUrl( apiNamespace + path, qs ),
			method,
		} )
			.then( ( response ) => {
				thenCallback( response );
			} )
			.catch( ( error ) => {
				errorCallback( error );
			} );
	};
	const modalClose = () => {
		setModalOpen( false );
	};
	const setModal = (
		title,
		description,
		callback,
		callbackParams = null,
		ctaText = proceed
	) => {
		setModalChildren(
			<Modal.Panel>
				<Modal.Title className="nfd-text-2xl nfd-font-medium nfd-text-title">
					{ title }
				</Modal.Title>
				<Modal.Description className="nfd-mt-8 nfd-mb-8">
					{ description }
				</Modal.Description>
				<div className="nfd-flex nfd-justify-between nfd-items-center nfd-flex-wrap nfd-gap-3">
					<Button
						variant="error"
						onClick={ () => {
							setModalOpen( false );
						} }
					>
						<XMarkIcon /> { cancelSite }
					</Button>
					<Button
						variant="primary"
						onClick={ () => {
							setModalOpen( false );
							callback( callbackParams );
						} }
					>
						<CheckIcon /> { ctaText }
					</Button>
				</div>
			</Modal.Panel>
		);
		setModalOpen( true );
	};

	const getClasses = () => {
		let theclasses = '';
		if ( isLoading ) {
			theclasses = 'is-loading';
		} else if ( isThinking ) {
			theclasses = 'is-thinking';
		} else if ( isError ) {
			theclasses = 'is-error';
		}
		return theclasses;
	};

	// methods to pass to module
	const methods = {
		apiFetch,
		classNames,
		useState,
		useEffect,
		NewfoldRuntime,
	};

	return (
		<Root context={ { isRTL: false } }>
			<NotificationFeed />
			<Page
				id='wppbh-app"'
				title={ pageTitle }
				className={ classNames(
					[ 'newfold-staging-page', 'wppbh', 'wppbh_app' ],
					getClasses()
				) }
			>
				<Container className="newfold-staging-container">
					<Container.Header
						title={ pageTitle }
						description={ pageDescription }
						className="newfold-staging-header"
					/>

					<Container.Block separator className="newfold-staging-prod">
						<ProductionSite
							hasStaging={ hasStaging }
							isProduction={ isProduction }
							productionUrl={ productionUrl }
							cloneMe={ clone }
							switchToMe={ switchToProduction }
							setModal={ setModal }
						/>
					</Container.Block>

					<Container.Block className="newfold-staging-staging">
						<StagingSite
							getAppText={ getAppText }
							isProduction={ isProduction }
							hasStaging={ hasStaging }
							createMe={ createStaging }
							deleteMe={ deleteStaging }
							deployMe={ deployStaging }
							switchToMe={ switchToStaging }
							stagingUrl={ stagingUrl }
							creationDate={ creationDate }
							setModal={ setModal }
						/>
					</Container.Block>
					<Modal
						isOpen={ modalOpen }
						onClose={ modalClose }
						children={ modalChildren }
					/>
				</Container>
			</Page>
		</Root>
	);
};

export default App;
