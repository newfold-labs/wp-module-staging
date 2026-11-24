/**
 * Staging Module Test Helpers for Playwright
 */
import { expect } from '@playwright/test';
import { createRequire } from 'module';
import { join } from 'path';
import { readFileSync } from 'fs';

const pluginDir = process.env.PLUGIN_DIR || process.cwd();
const requireFromPlugin = createRequire( join( pluginDir, 'package.json' ) );
const rawPluginHelpers = requireFromPlugin(
	'./tests/playwright/helpers/index.js'
);
const pluginHelpers =
	rawPluginHelpers?.auth !== undefined
		? rawPluginHelpers
		: rawPluginHelpers?.default;

const requiredHostHelpers = [ 'auth', 'wordpress', 'newfold', 'a11y', 'utils' ];
const missingHostHelpers = requiredHostHelpers.filter(
	( name ) => pluginHelpers?.[ name ] === undefined
);
if ( missingHostHelpers.length > 0 ) {
	throw new Error(
		`Staging Playwright host helpers missing: ${ missingHostHelpers.join( ', ' ) }`
	);
}

export const { auth, wordpress, newfold, a11y, utils } = pluginHelpers;

export const pluginId = process.env.PLUGIN_ID || 'bluehost';

const fixturesPath = join( __dirname, '../fixtures' );

export const loadFixture = ( name ) => {
	return JSON.parse(
		readFileSync( join( fixturesPath, `${ name }.json` ), 'utf8' )
	);
};

export const FIXTURES = {
	stagingInit: loadFixture( 'stagingInit' ),
	stagingClone: loadFixture( 'stagingClone' ),
	stagingDelete: loadFixture( 'stagingDelete' ),
	stagingCreate: loadFixture( 'stagingCreate' ),
	stagingSwitch: loadFixture( 'stagingSwitch' ),
	stagingStaging: loadFixture( 'stagingStaging' ),
	stagingDeploy: loadFixture( 'stagingDeploy' ),
};

export const SELECTORS = {
	stagingPage: '.newfold-staging-page',
	stagingProd: '.newfold-staging-prod',
	stagingStaging: '.newfold-staging-staging',
	productionToggle: '#newfold-production-toggle',
	stagingToggle: '#newfold-staging-toggle',
	productionToggleLabel: 'label[for="newfold-production-toggle"]',
	stagingToggleLabel: 'label[for="newfold-staging-toggle"]',
	cloneButton: '#staging-clone-button',
	deleteButton: '#staging-delete-button',
	deployButton: '#staging-deploy-button',
	createButton: '#staging-create-button',
	modal: '.nfd-modal',
	modalTitle: '.nfd-modal h1',
	modalPrimaryButton: '.nfd-modal .nfd-button--primary',
	modalErrorButton: '.nfd-modal .nfd-button--error',
	notifications: '.nfd-notifications',
	notificationError: '.nfd-notification--error',
	notificationButton: '.nfd-notification button',
};

const STAGING_NAMESPACE = 'newfold-staging/v1';

export const STAGING_API_ROUTES = {
	root: `/${ STAGING_NAMESPACE }/staging`,
	clone: `/${ STAGING_NAMESPACE }/staging/clone`,
	copyAdvance: `/${ STAGING_NAMESPACE }/staging/copy/advance`,
	deploy: `/${ STAGING_NAMESPACE }/staging/deploy`,
	switchTo: `/${ STAGING_NAMESPACE }/staging/switch-to`,
};

const INFRA_QUERY_PARAMS = new Set( [ '_locale', '_wpnonce' ] );

const routeKey = ( { method, route } ) => `${ method } ${ route }`;

function normalizeRoutePath( routePath ) {
	if ( ! routePath ) {
		return null;
	}
	const withLeading = routePath.startsWith( '/' ) ? routePath : `/${ routePath }`;
	if ( withLeading.length > 1 && withLeading.endsWith( '/' ) ) {
		return withLeading.slice( 0, -1 );
	}
	return withLeading;
}

/**
 * @param {string} rawUrl
 * @param {string} [method]
 * @returns {{ method: string, route: string, params: URLSearchParams } | null}
 */
export function parseStagingRequestFromUrl( rawUrl, method = 'GET' ) {
	let parsedUrl;

	try {
		parsedUrl = new URL( rawUrl );
	} catch {
		return null;
	}

	const upperMethod = method.toUpperCase();
	let routePath = null;
	const params = new URLSearchParams();

	if ( parsedUrl.pathname.includes( '/wp-json/' ) ) {
		const idx = parsedUrl.pathname.indexOf( '/wp-json/' );
		routePath = parsedUrl.pathname.slice( idx + '/wp-json'.length );
		parsedUrl.searchParams.forEach( ( value, key ) => {
			params.append( key, value );
		} );
	} else if ( parsedUrl.searchParams.has( 'rest_route' ) ) {
		const restRoute = parsedUrl.searchParams.get( 'rest_route' ) || '';
		routePath = restRoute;
		parsedUrl.searchParams.forEach( ( value, key ) => {
			if ( key !== 'rest_route' ) {
				params.append( key, value );
			}
		} );
	}

	const normalizedRoute = normalizeRoutePath( routePath );
	if ( ! normalizedRoute || ! normalizedRoute.includes( STAGING_NAMESPACE ) ) {
		return null;
	}

	return { method: upperMethod, route: normalizedRoute, params };
}

/**
 * WordPress api-fetch (http-v1) sends DELETE/PUT/PATCH as POST + X-HTTP-Method-Override.
 *
 * @param {import('@playwright/test').Request} request
 */
export function parseStagingRequest( request ) {
	let method = request.method();
	const override =
		request.headers()[ 'x-http-method-override' ] ||
		request.headers()[ 'X-HTTP-Method-Override' ];
	if ( override ) {
		method = override;
	}
	return parseStagingRequestFromUrl( request.url(), method );
}

export function isStagingApiUrl( url ) {
	return parseStagingRequestFromUrl( url ) !== null;
}

/**
 * Playwright route matcher: encoded rest_route and subdirectory installs decode via URL.
 *
 * @param {URL} url
 */
export function stagingApiRouteMatcher( url ) {
	return parseStagingRequestFromUrl( url.href ) !== null;
}

export function createStagingRequestTracker() {
	return {
		setupRequests: [],
		actionRequests: [],
		unexpected: [],
		counts: {},
	};
}

function recordRequest( tracker, parsed, phase ) {
	const key = routeKey( parsed );
	tracker.counts[ key ] = ( tracker.counts[ key ] || 0 ) + 1;

	const entry = {
		method: parsed.method,
		route: parsed.route,
		params: Object.fromEntries( parsed.params.entries() ),
		phase,
	};

	if ( phase === 'setup' ) {
		tracker.setupRequests.push( entry );
	} else {
		tracker.actionRequests.push( entry );
	}
}

function recordUnexpected( tracker, parsed, reason = null ) {
	tracker.unexpected.push( {
		method: parsed.method,
		route: parsed.route,
		params: Object.fromEntries( parsed.params.entries() ),
		reason,
	} );
}

function unexpectedQueryParams( parsed, allowed = [] ) {
	const unexpected = [];
	for ( const [ key ] of parsed.params.entries() ) {
		if ( INFRA_QUERY_PARAMS.has( key ) ) {
			continue;
		}
		if ( ! allowed.includes( key ) ) {
			unexpected.push( key );
		}
	}
	return unexpected;
}

/**
 * @returns {string | null}
 */
export function validateStagingOperativeParams( parsed ) {
	const { method, route, params } = parsed;

	for ( const key of new Set( params.keys() ) ) {
		if ( ! INFRA_QUERY_PARAMS.has( key ) && params.getAll( key ).length > 1 ) {
			return `duplicate operative param: ${ key }`;
		}
	}

	if ( route === STAGING_API_ROUTES.root ) {
		if ( method === 'GET' ) {
			return unexpectedQueryParams( parsed, [] ).length
				? `unexpected params: ${ unexpectedQueryParams( parsed, [] ).join( ', ' ) }`
				: null;
		}
		if ( method === 'DELETE' ) {
			return unexpectedQueryParams( parsed, [] ).length
				? `unexpected params: ${ unexpectedQueryParams( parsed, [] ).join( ', ' ) }`
				: null;
		}
		if ( method === 'POST' ) {
			return unexpectedQueryParams( parsed, [] ).length
				? `unexpected params: ${ unexpectedQueryParams( parsed, [] ).join( ', ' ) }`
				: null;
		}
		return `unsupported method for staging root: ${ method }`;
	}

	if ( route === STAGING_API_ROUTES.clone ) {
		if ( method !== 'POST' ) {
			return 'clone requires POST';
		}
		return unexpectedQueryParams( parsed, [] ).length
			? `unexpected params: ${ unexpectedQueryParams( parsed, [] ).join( ', ' ) }`
			: null;
	}

	if ( route === STAGING_API_ROUTES.copyAdvance ) {
		if ( method !== 'POST' ) {
			return 'copy/advance requires POST';
		}
		if ( params.get( 'type' ) !== 'deploy_files' ) {
			return 'copy/advance requires type=deploy_files';
		}
		const restart = params.get( 'restart' );
		if ( restart !== '0' && restart !== '1' ) {
			return 'copy/advance requires restart=0 or restart=1';
		}
		return unexpectedQueryParams( parsed, [ 'type', 'restart' ] ).length
			? `unexpected params: ${ unexpectedQueryParams( parsed, [ 'type', 'restart' ] ).join( ', ' ) }`
			: null;
	}

	if ( route === STAGING_API_ROUTES.deploy ) {
		if ( method !== 'POST' && method !== 'GET' ) {
			return `deploy requires GET or POST, got ${ method }`;
		}
		if ( params.get( 'type' ) !== 'db' ) {
			return 'deploy contract expects type=db';
		}
		return unexpectedQueryParams( parsed, [ 'type' ] ).length
			? `unexpected params: ${ unexpectedQueryParams( parsed, [ 'type' ] ).join( ', ' ) }`
			: null;
	}

	if ( route === STAGING_API_ROUTES.switchTo ) {
		if ( method !== 'GET' ) {
			return 'switch-to requires GET';
		}
		const env = params.get( 'env' );
		if ( env !== 'staging' && env !== 'production' ) {
			return 'switch-to requires env=staging or env=production';
		}
		return unexpectedQueryParams( parsed, [ 'env' ] ).length
			? `unexpected params: ${ unexpectedQueryParams( parsed, [ 'env' ] ).join( ', ' ) }`
			: null;
	}

	return `unsupported staging route ${ route }`;
}

function jsonFulfill( route, body, status = 200 ) {
	return route.fulfill( {
		status,
		contentType: 'application/json',
		body: JSON.stringify( body ),
	} );
}

const failUnexpected = async ( route, tracker, parsed, reason = null ) => {
	recordUnexpected( tracker, parsed, reason );
	await jsonFulfill(
		route,
		{
			code: 'unexpected_staging_request',
			message: reason
				? `Unexpected staging request (${ reason }): ${ parsed.method } ${ parsed.route }`
				: `Unexpected staging request: ${ parsed.method } ${ parsed.route }`,
		},
		501
	);
};

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ tracker: ReturnType<typeof createStagingRequestTracker>, handler: Function, allowUnexpectedContinue?: boolean, validateParams?: boolean }} options
 */
export async function registerStagingRoutes(
	page,
	{ tracker, handler, allowUnexpectedContinue = false, validateParams = true }
) {
	await page.route( stagingApiRouteMatcher, async ( route ) => {
		const parsed = parseStagingRequest( route.request() );
		if ( ! parsed ) {
			await route.continue();
			return;
		}

		if ( validateParams ) {
			const validationError = validateStagingOperativeParams( parsed );
			if ( validationError ) {
				await failUnexpected( route, tracker, parsed, validationError );
				return;
			}
		}

		const decision = await handler( parsed, route );
		if ( ! decision ) {
			await failUnexpected( route, tracker, parsed );
			return;
		}

		if ( decision.record !== false ) {
			recordRequest( tracker, parsed, decision.phase || 'action' );
		}

		if ( decision.continue ) {
			await route.continue();
			return;
		}
		if ( decision.fulfill ) {
			await jsonFulfill( route, decision.fulfill.body, decision.fulfill.status );
			return;
		}
		if ( allowUnexpectedContinue ) {
			await route.continue();
			return;
		}

		await failUnexpected( route, tracker, parsed );
	} );
}

export function assertActionRequestSequence( tracker, expectedKeys ) {
	const sequence = tracker.actionRequests.map( ( entry ) =>
		routeKey( entry )
	);
	expect( sequence ).toEqual( expectedKeys );
}

/**
 * @param {ReturnType<typeof createStagingRequestTracker>} tracker
 * @param {Array<{ method: string, route: string, count?: number, params?: Record<string, string> }>} expected
 */
export function assertStagingRequests( tracker, expected ) {
	expect( tracker.unexpected, 'unexpected staging API calls' ).toEqual( [] );

	const expectedTotals = {};
	for ( const spec of expected ) {
		const key = routeKey( spec );
		expectedTotals[ key ] =
			( expectedTotals[ key ] || 0 ) + ( spec.count ?? 1 );
	}

	expect(
		tracker.counts,
		'staging API call counts must match expected contract exactly'
	).toEqual( expectedTotals );

	const recordedTotal =
		tracker.setupRequests.length + tracker.actionRequests.length;
	const expectedTotal = Object.values( expectedTotals ).reduce(
		( sum, count ) => sum + count,
		0
	);
	expect( recordedTotal, 'recorded request count' ).toBe( expectedTotal );

	for ( const spec of expected ) {
		if ( ! spec.params ) {
			continue;
		}
		const matching = [
			...tracker.setupRequests,
			...tracker.actionRequests,
		].filter(
			( entry ) =>
				entry.method === spec.method && entry.route === spec.route
		);
		expect( matching.length ).toBeGreaterThan( 0 );
		const last = matching[ matching.length - 1 ];
		for ( const [ paramKey, paramValue ] of Object.entries(
			spec.params
		) ) {
			expect( last.params[ paramKey ] ).toBe( paramValue );
		}
	}
}

export async function navigateToStagingPage( page ) {
	await page.goto( `wp-admin/admin.php?page=${ pluginId }#/settings/staging` );
}

export async function mockStagingApi( page, fixture ) {
	const tracker = createStagingRequestTracker();

	await registerStagingRoutes( page, {
		tracker,
		handler: async ( parsed ) => {
			if (
				parsed.method === 'GET' &&
				parsed.route === STAGING_API_ROUTES.root
			) {
				return { phase: 'setup', fulfill: { body: fixture } };
			}
			if (
				parsed.method === 'POST' &&
				parsed.route === STAGING_API_ROUTES.clone
			) {
				return { fulfill: { body: FIXTURES.stagingClone } };
			}
			return null;
		},
	} );

	return tracker;
}

/**
 * @param {import('@playwright/test').Page} page
 * @param {{ dbPollDelayMs?: number }} [options]
 */
export async function setupDeployAllMocks( page, options = {} ) {
	const dbPollDelayMs = options.dbPollDelayMs ?? 1500;
	const tracker = createStagingRequestTracker();
	const state = {
		filesAdvanceCalls: 0,
		dbDeployPost: false,
		dbPollCalls: 0,
		filesDeployComplete: false,
		dbDeployComplete: false,
	};

	await registerStagingRoutes( page, {
		tracker,
		handler: async ( parsed ) => {
			if (
				parsed.method === 'GET' &&
				parsed.route === STAGING_API_ROUTES.root
			) {
				return { phase: 'setup', fulfill: { body: FIXTURES.stagingStaging } };
			}

			if (
				parsed.method === 'POST' &&
				parsed.route === STAGING_API_ROUTES.copyAdvance
			) {
				state.filesAdvanceCalls += 1;
				const restart = parsed.params.get( 'restart' );
				if ( state.filesAdvanceCalls === 1 ) {
					expect( restart ).toBe( '1' );
					return {
						fulfill: {
							body: {
								status: 'running',
								phase: 'copy',
								progress: { percent: 50 },
							},
						},
					};
				}
				expect( restart ).toBe( '0' );
				state.filesDeployComplete = true;
				return {
					fulfill: {
						body: {
							status: 'success',
							message: 'Files deployed successfully.',
						},
					},
				};
			}

			if (
				parsed.method === 'POST' &&
				parsed.route === STAGING_API_ROUTES.deploy
			) {
				state.dbDeployPost = true;
				return {
					fulfill: {
						body: {
							status: 'running',
							message: 'Deploying database...',
						},
					},
				};
			}

			if (
				parsed.method === 'GET' &&
				parsed.route === STAGING_API_ROUTES.deploy
			) {
				expect( state.dbDeployPost ).toBe( true );
				state.dbPollCalls += 1;
				if ( state.dbPollCalls === 1 ) {
					await new Promise( ( resolve ) =>
						setTimeout( resolve, dbPollDelayMs )
					);
					return {
						fulfill: {
							body: {
								status: 'running',
								message: 'Deploying database...',
							},
						},
					};
				}
				state.dbDeployComplete = true;
				return {
					fulfill: {
						body: FIXTURES.stagingDeploy,
					},
				};
			}

			return null;
		},
	} );

	const waitForDeployAllComplete = async ( timeout = 60000 ) => {
		await expect
			.poll(
				() => state.filesDeployComplete && state.dbDeployComplete,
				{ timeout, intervals: [ 250, 500, 1000 ] }
			)
			.toBe( true );
	};

	return {
		tracker,
		state,
		filesAdvanceCalls: () => state.filesAdvanceCalls,
		waitForDeployAllComplete,
	};
}

export async function setupProductionEnvironmentMocks( page ) {
	const tracker = createStagingRequestTracker();
	let currentFixture = FIXTURES.stagingInit;

	await registerStagingRoutes( page, {
		tracker,
		handler: async ( parsed ) => {
			if (
				parsed.method === 'GET' &&
				parsed.route === STAGING_API_ROUTES.root
			) {
				return { phase: 'setup', fulfill: { body: currentFixture } };
			}
			if (
				parsed.method === 'POST' &&
				parsed.route === STAGING_API_ROUTES.clone
			) {
				return { fulfill: { body: FIXTURES.stagingClone } };
			}
			if (
				parsed.method === 'DELETE' &&
				parsed.route === STAGING_API_ROUTES.root
			) {
				currentFixture = {
					...FIXTURES.stagingDelete,
					stagingExists: false,
				};
				return { fulfill: { body: FIXTURES.stagingDelete } };
			}
			if (
				parsed.method === 'POST' &&
				parsed.route === STAGING_API_ROUTES.root
			) {
				currentFixture = FIXTURES.stagingCreate;
				await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );
				return { fulfill: { body: FIXTURES.stagingCreate } };
			}
			if (
				parsed.method === 'GET' &&
				parsed.route === STAGING_API_ROUTES.switchTo
			) {
				await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );
				return { fulfill: { body: FIXTURES.stagingSwitch } };
			}
			return null;
		},
	} );

	return tracker;
}

/** Isolated clone-error path: only POST clone passes through to backend. */
export async function setupCloneErrorMocks( page ) {
	const tracker = createStagingRequestTracker();

	await registerStagingRoutes( page, {
		tracker,
		allowUnexpectedContinue: false,
		handler: async ( parsed ) => {
			if (
				parsed.method === 'GET' &&
				parsed.route === STAGING_API_ROUTES.root
			) {
				return { phase: 'setup', fulfill: { body: FIXTURES.stagingInit } };
			}
			if (
				parsed.method === 'POST' &&
				parsed.route === STAGING_API_ROUTES.clone
			) {
				return { continue: true, record: true };
			}
			return null;
		},
	} );

	return tracker;
}

export async function waitForStagingPage( page ) {
	await page.waitForSelector( SELECTORS.stagingPage, { timeout: 10000 } );
}

export async function closeAllNotifications( page ) {
	const notificationButtons = page.locator( SELECTORS.notificationButton );
	const count = await notificationButtons.count();
	for ( let i = 0; i < count; i++ ) {
		const button = notificationButtons.nth( 0 );
		if ( await button.isVisible() ) {
			await button.click();
			await page.waitForTimeout( 50 );
		}
	}
}

export async function clickModalButton( page, buttonText, isPrimary = true ) {
	const selector = isPrimary
		? SELECTORS.modalPrimaryButton
		: SELECTORS.modalErrorButton;
	const button = page.locator( selector ).filter( { hasText: buttonText } );
	await expect( button ).toBeVisible();
	await button.click();
}

export async function setupAndNavigate( page ) {
	await auth.loginToWordPress( page );
	await navigateToStagingPage( page );
	await waitForStagingPage( page );
}

export async function expectNotification( page, text ) {
	await expect(
		page.locator( SELECTORS.notifications ).filter( { hasText: text } )
	).toContainText( text );
}

export async function confirmModalAction(
	page,
	expectedTitle,
	buttonText,
	isPrimary = true
) {
	const modal = page.locator( SELECTORS.modal );
	await expect( modal.locator( 'h1' ) ).toContainText( expectedTitle );
	await clickModalButton( page, buttonText, isPrimary );
}