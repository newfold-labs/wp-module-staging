import './styles/styles.css';

import domReady from '@wordpress/dom-ready';
import { createRoot, createPortal, useEffect, useState } from '@wordpress/element';

import { NewfoldRuntime } from '@newfold/wp-module-runtime';

import App from './components/App';

import './store';
import { NFD_STAGING_ELEMENT_ID } from './data/constants';

domReady( () => {
	const mountNode = document.getElementById( NFD_STAGING_ELEMENT_ID );

	const brand = NewfoldRuntime.sdk?.plugin?.brand;
	if ( brand ) {
		document.body.classList.add( `nfd-brand--${ brand }` );
	}

	if ( mountNode ) {
		const root = createRoot( mountNode );
		root.render( <App /> );
	}
} );


/**
 * Staging Portal App Setup
 */
const WP_STAGING_FILL_ELEMENT = 'nfd-staging-portal'; // DOM Element ID for staging app
// the portal id is staging-portal and set in the plugin settings and connected to the portal in the registry
let root = null; // Root for detecting if app is already rendered

const StagingPortalAppRender = () => {
	const DOM_ELEMENT = document.getElementById( WP_STAGING_FILL_ELEMENT );
	if ( null !== DOM_ELEMENT ) {
		if ( 'undefined' !== typeof createRoot ) {
			if ( ! root ) {
				root = createRoot( DOM_ELEMENT );
			}
			root.render( <StagingPortalApp /> );
		}
	}
};

export const StagingPortalApp = () => {
	const [ container, setContainer ] = useState( null );

	useEffect( () => {
		const registry = window.NFDPortalRegistry;
		// Check for required registry
		if ( ! registry ) {
			return;
		}

		const updateContainer = ( el ) => {
			setContainer( el );
		};

		const clearContainer = () => {
			setContainer( null );
		};

		// Subscribe to portal readiness updates
		registry.onReady( 'staging', updateContainer );
		registry.onRemoved( 'staging', clearContainer );

		// Immediately try to get the container if already registered
		const current = registry.getElement( 'staging' );
		if ( current ) {
			updateContainer( current );
		}
	}, [ container ] );

	if ( ! container ) {
		return null;
	}

	return createPortal(
		<div className="staging-fill">
			<App />
		</div>,
		container
	);
};

// Render (hidden)App on Page Load - but portal only kicks in when/if DOM element is available
domReady( StagingPortalAppRender );