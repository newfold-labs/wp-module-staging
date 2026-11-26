import { test, expect } from '@playwright/test';
import {
	FIXTURES,
	SELECTORS,
	STAGING_API_ROUTES,
	mockStagingApi,
	setupDeployAllMocks,
	setupAndNavigate,
	confirmModalAction,
	expectNotification,
	assertStagingRequests,
	assertActionRequestSequence,
} from '../helpers/index.js';

test.describe( 'Staging Page - Staging Environment', { tag: '@env-local' }, () => {
	test( 'Displays staging environment properly', async ( { page } ) => {
		const tracker = await mockStagingApi( page, FIXTURES.stagingStaging );
		await setupAndNavigate( page );

		await expect( page.locator( SELECTORS.productionToggle ) ).not.toBeChecked();
		const prodSection = page.locator( SELECTORS.stagingProd );
		await expect( prodSection.locator( 'h3' ) ).toContainText(
			'Production Site'
		);
		await expect(
			prodSection.locator( SELECTORS.productionToggleLabel )
		).toContainText( 'Not currently editing' );

		await expect( page.locator( SELECTORS.stagingToggle ) ).toBeChecked();
		const stagingSection = page.locator( SELECTORS.stagingStaging );
		await expect( stagingSection.locator( 'h3' ) ).toContainText(
			'Staging Site'
		);
		await expect(
			stagingSection.locator( SELECTORS.stagingToggleLabel )
		).toContainText( 'Currently editing' );

		await expect( page.locator( SELECTORS.cloneButton ) ).toBeDisabled();
		await expect( page.locator( SELECTORS.deleteButton ) ).toBeDisabled();
		await expect( page.locator( SELECTORS.deployButton ) ).not.toBeDisabled();

		assertStagingRequests( tracker, [
			{ method: 'GET', route: STAGING_API_ROUTES.root, count: 1 },
		] );
	} );

	test( 'Deploy Works', async ( { page } ) => {
		const {
			tracker,
			filesAdvanceCalls,
			state,
			waitForDeployAllComplete,
		} = await setupDeployAllMocks( page, { dbPollDelayMs: 2000 } );
		await setupAndNavigate( page );

		await page.locator( SELECTORS.deployButton ).click();

		const modal = page.locator( SELECTORS.modal );
		await expect( modal.locator( 'h1' ) ).toContainText(
			'Confirm Deployment'
		);
		await expect( modal.locator( '.nfd-button--error' ) ).toContainText(
			'Cancel'
		);
		await expect( modal.locator( '.nfd-button--primary' ) ).toContainText(
			'Deploy'
		);

		await confirmModalAction( page, 'Confirm Deployment', 'Deploy' );
		await expectNotification( page, 'Working' );

		await waitForDeployAllComplete( 60000 );
		expect( state.filesDeployComplete ).toBe( true );
		expect( state.dbDeployComplete ).toBe( true );
		expect( state.dbPollCalls ).toBeGreaterThanOrEqual( 2 );
		await expect(
			page.locator( SELECTORS.stagingPage )
		).not.toHaveClass( /is-thinking/, { timeout: 30000 } );
		await expectNotification( page, 'Deployed' );

		expect( filesAdvanceCalls() ).toBe( 2 );

		assertStagingRequests( tracker, [
			{ method: 'GET', route: STAGING_API_ROUTES.root, count: 1 },
			{
				method: 'POST',
				route: STAGING_API_ROUTES.copyAdvance,
				count: 2,
			},
			{
				method: 'POST',
				route: STAGING_API_ROUTES.deploy,
				count: 1,
				params: { type: 'db' },
			},
			{
				method: 'GET',
				route: STAGING_API_ROUTES.deploy,
				count: 2,
				params: { type: 'db' },
			},
		] );

		assertActionRequestSequence( tracker, [
			`POST ${ STAGING_API_ROUTES.copyAdvance }`,
			`POST ${ STAGING_API_ROUTES.copyAdvance }`,
			`POST ${ STAGING_API_ROUTES.deploy }`,
			`GET ${ STAGING_API_ROUTES.deploy }`,
			`GET ${ STAGING_API_ROUTES.deploy }`,
		] );
	} );
} );