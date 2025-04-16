// Newfold
import {Container, Root, Page, Modal, Button} from '@newfold/ui-component-library';
import classNames from "classnames";
// Components
import ProductionSite from '../../sections/ProductionSite';
import StagingSite from '../../sections/StagingSite';
import NotificationFeed from '../NotificationFeed';

import getAppText from './getAppText';
import {CheckIcon, XMarkIcon} from "@heroicons/react/24/outline";

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
		pageDescription,
		pageTitle,
		proceed,
		switchSite,
		switchToProductionDescription,
		switchToProductionNoticeCompleteText,
		switchToProductionNoticeStartText,
		switchToStagingDescription,
		switchToStagingNoticeCompleteText,
		switchToStagingNoticeStartText,
		switching,
		unknownErrorMessage,
		working
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
		duration = false
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

	const setError = ( error ) => {
		// console.log('setError', error);
		setIsLoading( false );
		setIsThinking( false );
		setIsError(true);
		makeNotice( 'error', error, error, 'error' );
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
			setError(unknownErrorMessage);
		}

	};


	/**
	 * on mount load staging data from module api
	 */
	useEffect(() => {
		init();
	}, [] );

	const init = () => {
		// console.log('Init - Loading Staging Data');
		setIsError(false);
		setIsLoading(true);
		stagingApiFetch(
			'staging',
			null,
			'GET',
			(response) => {
				// console.log('Init Staging Data:', response);
				// validate response data
				if ( response.hasOwnProperty('currentEnvironment') ) {
					//setup with fresh data
					setup( response );
				} else if ( response.hasOwnProperty('code') && response.code === 'error_response' ) {
					setError( response.message ); // report known error
				} else {
					setError( unknownErrorMsg ); // report unknown error
				}
				setIsThinking( false );
				setIsLoading( false );
			}
		);
	}

	const createStaging = () => {
		//console.log('create staging');
		makeNotice( 'creating', working, createNoticeStartText, 'info', 8000 );
		// setIsCreatingStaging(true);
		stagingApiFetch(
			'staging',
			null,
			'POST',
			(response) => {
				// console.log('Create Staging Callback', response);
				if ( response.hasOwnProperty('status') ) {
					if ( response.status === 'success' ){
						//setup with fresh data
						setup( response );
						makeNotice( 'created', createNoticeCompleteText, response.message );
					} else {
						setError( response.message ); // report known error
					}
				} else {
					setError( unknownErrorMsg ); // report unknown error
				}
				setIsThinking( false );
				// setIsCreatingStaging(false);
			}
		);
	};

	const deleteStaging = () => {
		// console.log('delete staging');
		makeNotice( 'deleting', working, deleteNoticeStartText, 'info', 8000 );
		stagingApiFetch(
			'staging',
			null,
			'DELETE',
			(response) => {
				// console.log('Delete staging callback', response);
				// validate response data
				if ( response.hasOwnProperty('status') ) {
					if ( response.status === 'success' ){
						// setup with fresh data
						setHasStaging( false );
						makeNotice( 'deleted', deleteNoticeCompleteText, response.message );
					} else {
						setError( response.message );
					}
				} else {
					setError( unknownErrorMsg ); // report unknown error
				}
				setIsThinking( false );
			}
		);
	};

	const clone = () => {
		// console.log('clone production to staging');
		makeNotice( 'cloning', working, cloneNoticeStartText, 'info', 8000 );
		stagingApiFetch(
			'staging/clone',
			null,
			'POST',
			(response) => {
				// console.log('Clone Callback', response);
				// validate response data
				if ( response.hasOwnProperty('status') ) {
					// setup with fresh data
					if ( response.status === 'success' ){
						setHasStaging( true );
						makeNotice( 'cloned', cloneNoticeCompleteText, response.message );
					} else {
						setError( response.message );
						setHasStaging( false );
					}
				} else {
					setError( unknownErrorMsg ); // report unknown error
					setHasStaging( false );
				}
				setIsThinking( false );
			}
		);
	};

	const switchToStaging = () => {
		if ( !isProduction ) {
			// console.log('Already on staging.');
		} else {
			setModal(
				switchToStaging,
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
				switchToProduction,
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
			makeNotice( 'switching', working, switchToProductionNoticeStartText, 'info', 8000 );
		} else {
			makeNotice( 'switching', working, switchToStagingNoticeStartText, 'info', 8000 );
		}

		stagingApiFetch(
			'staging/switch-to',
			{'env': env},
			'GET',
			(response) => {
				// console.log('Switch Callback', response);
				// validate response data
				if ( response.hasOwnProperty( 'load_page' ) ) {
					window.location.href = response.load_page;
					// navigate(response.load_page);
					const notifyMessageText = env === "production" ? switchToProductionNoticeCompleteText : switchToStagingNoticeCompleteText;
					makeNotice( 'redirecting', switching, notifyMessageText, 'success', 8000 );
				} else if ( response.hasOwnProperty('status') && response.status === 'error' ) {
					setError(response.message);
				} else {
					setError( unknownErrorMsg ); // report unknown error
				}
			}
		);
	};

	/**
	 *
	 * @param {string} type One of 'all', 'files', or 'db'
	 */
	const deployStaging = ( type ) => {
		// console.log('Deploy', type);
		makeNotice( 'deploying', working, deployNoticeStartText, 'info', 8000 );
		stagingApiFetch(
			'staging/deploy',
			{'type': type},
			'POST',
			(response) => {
				// console.log('Deploy Callback', response);
				// validate response data
				if ( response.hasOwnProperty('status') ) {
					// setup with fresh data
					if ( response.status === 'success' ){
						makeNotice( 'deployed', deployNoticeCompleteText, response.message );
					} else {
						setError( response.message );
					}
				} else {
					setError( unknownErrorMsg ); // report unknown error
				}
				setIsThinking( false );
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
	const stagingApiFetch = (
		path = '',
		qs = {},
		method = 'GET',
		thenCallback,
		errorCallback = catchError
	) => {
		setIsThinking( true );
		return apiFetch({
			url: NewfoldRuntime.createApiUrl( apiNamespace + path, qs),
			method,
		}).then( (response) => {
			thenCallback( response );
		}).catch( (error) => {
			errorCallback( error );
		})
	};
	const modalClose = () => {
		setModalOpen(false);
	}
	const setModal = (title, description, callback, callbackParams=null, ctaText=proceed) => {
		setModalChildren(
			<Modal.Panel>
				<Modal.Title className="nfd-text-2xl nfd-font-medium nfd-text-title">{title}</Modal.Title>
				<Modal.Description className="nfd-mt-8 nfd-mb-8">{description}</Modal.Description>
				<div className="nfd-flex nfd-justify-between nfd-items-center nfd-flex-wrap nfd-gap-3">
					<Button
						variant="error"
						onClick={ () => { setModalOpen(false); }}
					>
						<XMarkIcon /> {cancelSite}
					</Button>
					<Button
						variant="primary"
						onClick={ () => {
							setModalOpen(false);
							callback(callbackParams);
						}}
					>
						<CheckIcon /> {ctaText}
					</Button>
				</div>
			</Modal.Panel>
		);
		setModalOpen(true);
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
			<Page title={ pageTitle } className={classNames('newfold-staging-page',  getClasses())}>
				<Container className='newfold-staging-container'>
					<Container.Header
						title={ pageTitle }
						description={ pageDescription }
						className="newfold-staging-header"
					/>

					<Container.Block separator className="newfold-staging-prod" >
						<ProductionSite
							hasStaging={hasStaging}
							isProduction={isProduction}
							productionUrl={productionUrl}
							cloneMe={clone}
							switchToMe={switchToProduction}
							setModal={setModal}
						/>
					</Container.Block>

					<Container.Block className="newfold-staging-staging">
						<StagingSite
							getAppText={getAppText}
							isProduction={isProduction}
							hasStaging={hasStaging}
							createMe={createStaging}
							deleteMe={deleteStaging}
							deployMe={deployStaging}
							switchToMe={switchToStaging}
							stagingUrl={stagingUrl}
							creationDate={creationDate}
							setModal={setModal}
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
