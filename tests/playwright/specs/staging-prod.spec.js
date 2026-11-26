import { test, expect } from '@playwright/test';
import {
	a11y,
	FIXTURES,
	SELECTORS,
	STAGING_API_ROUTES,
	mockStagingApi,
	setupProductionEnvironmentMocks,
	setupCloneErrorMocks,
	setupAndNavigate,
	closeAllNotifications,
	expectNotification,
	confirmModalAction,
	assertStagingRequests,
	assertActionRequestSequence,
} from '../helpers/index.js';

test.describe( 'Staging Page - Production Environment', { tag: '@env-local' }, () => {
	test( 'Is Accessible', async ( { page } ) => {
		const tracker = await mockStagingApi( page, FIXTURES.stagingInit );
		await setupAndNavigate( page );

		await a11y.checkA11y( page, SELECTORS.stagingPage );

		assertStagingRequests( tracker, [
			{ method: 'GET', route: STAGING_API_ROUTES.root, count: 1 },
		] );
	} );

	test( 'Displays in Production Environment Properly', async ( { page } ) => {
		const tracker = await mockStagingApi( page, FIXTURES.stagingInit );
		await setupAndNavigate( page );

		const prodSection = page.locator( SELECTORS.stagingProd );
		await expect( prodSection ).toBeVisible();
		await expect( page.locator( SELECTORS.productionToggle ) ).toBeChecked();
		await expect( prodSection.locator( 'h3' ) ).toContainText(
			'Production Site'
		);
		await expect(
			prodSection.locator( SELECTORS.productionToggleLabel )
		).toContainText( 'Currently editing' );

		const stagingSection = page.locator( SELECTORS.stagingStaging );
		await expect( stagingSection.locator( 'h3' ) ).toContainText(
			'Staging Site'
		);
		await expect(
			stagingSection.locator( SELECTORS.stagingToggleLabel )
		).toContainText( 'Not currently editing' );
		await expect( page.locator( SELECTORS.stagingToggle ) ).not.toBeChecked();
		await expect( stagingSection ).toContainText(
			'https://localhost:8882/staging/1234'
		);
		await expect( stagingSection.locator( 'dd' ) ).toContainText(
			'May 30, 2023'
		);

		await expect( page.locator( SELECTORS.cloneButton ) ).not.toBeDisabled();
		await expect( page.locator( SELECTORS.deleteButton ) ).not.toBeDisabled();
		await expect( page.locator( SELECTORS.deployButton ) ).toBeDisabled();

		assertStagingRequests( tracker, [
			{ method: 'GET', route: STAGING_API_ROUTES.root, count: 1 },
		] );
	} );
} );

test.describe( 'Staging Page - Production clone error', { tag: '@env-local' }, () => {
	test( 'Errors as expected', async ( { page } ) => {
		const tracker = await setupCloneErrorMocks( page );
		await setupAndNavigate( page );

		await page.locator( SELECTORS.cloneButton ).click();
		await confirmModalAction( page, 'Confirm Clone Action', 'Clone' );

		const errorNotification = page.locator( SELECTORS.notificationError );
		await expect( errorNotification ).toBeVisible();
		await expect( errorNotification.locator( 'p' ) ).not.toBeEmpty();

		assertStagingRequests( tracker, [
			{ method: 'GET', route: STAGING_API_ROUTES.root, count: 1 },
			{ method: 'POST', route: STAGING_API_ROUTES.clone, count: 1 },
		] );
	} );
} );

test.describe( 'Staging Page - Production Environment', { tag: '@env-local' }, () => {
	test( 'Clone Works', async ( { page } ) => {
		const tracker = await mockStagingApi( page, FIXTURES.stagingInit );
		await setupAndNavigate( page );

		await page.locator( SELECTORS.cloneButton ).click();
		await confirmModalAction( page, 'Confirm Clone Action', 'Clone' );

		await expectNotification( page, 'Cloned to Staging' );

		assertStagingRequests( tracker, [
			{ method: 'GET', route: STAGING_API_ROUTES.root, count: 1 },
			{ method: 'POST', route: STAGING_API_ROUTES.clone, count: 1 },
		] );
	} );

	test( 'Deleting, Creating and Switch to staging Environment', async ( {
		page,
	} ) => {
		test.slow();

		const tracker = await setupProductionEnvironmentMocks( page );
		await setupAndNavigate( page );

		const stagingSection = page.locator( SELECTORS.stagingStaging );
		const modal = page.locator( SELECTORS.modal );

		await test.step( 'Delete staging site', async () => {
			await page.locator( SELECTORS.deleteButton ).click();
			await confirmModalAction( page, 'Confirm Delete', 'Delete' );
			await expectNotification( page, 'Deleted Staging' );

			await expect( stagingSection.locator( 'h3' ) ).toContainText(
				'Staging Site'
			);
			await expect(
				stagingSection.locator( SELECTORS.stagingToggleLabel )
			).toHaveCount( 0 );
			await expect(
				stagingSection.getByText( 'https://localhost:8882/staging/1234' )
			).toHaveCount( 0 );
			await expect( stagingSection ).toContainText(
				"You don't have a staging site yet"
			);

			const createButton = page.locator( SELECTORS.createButton );
			await expect( createButton ).toBeVisible();
			await expect( createButton ).not.toBeDisabled();
		} );

		await test.step( 'Create new staging site', async () => {
			await page.locator( SELECTORS.createButton ).click();

			await expect( page.locator( SELECTORS.stagingPage ) ).toHaveClass(
				/is-thinking/
			);

			await expect( page.locator( SELECTORS.stagingPage ) ).not.toHaveClass(
				/is-thinking/,
				{ timeout: 30000 }
			);

			await expect( stagingSection.locator( 'h3' ) ).toContainText(
				'Staging Site'
			);
			await expect(
				stagingSection.locator( SELECTORS.stagingToggleLabel )
			).toContainText( 'Not currently editing' );
			await expect( stagingSection ).toContainText(
				'https://localhost:8882/staging/1234'
			);
		} );

		await test.step( 'Switch to staging environment', async () => {
			await closeAllNotifications( page );

			await expect( page.locator( SELECTORS.productionToggle ) ).toBeChecked();
			await expect( page.locator( SELECTORS.stagingToggle ) ).not.toBeChecked();

			await page.locator( SELECTORS.stagingToggle ).click();
			await expect( modal.locator( 'h1' ) ).toContainText(
				'Switch to Staging'
			);
			await confirmModalAction( page, 'Switch to Staging', 'Cancel', false );
			await expect( modal.locator( 'h1' ) ).toHaveCount( 0 );

			await expect( page.locator( SELECTORS.productionToggle ) ).toBeChecked();
			await expect( page.locator( SELECTORS.stagingToggle ) ).not.toBeChecked();

			await page.locator( SELECTORS.stagingToggle ).click();
			await confirmModalAction( page, 'Switch to Staging', 'Proceed' );

			await expectNotification( page, 'Working' );
			await expectNotification( page, 'Switching' );

			await expect
				.poll(
					() =>
						tracker.counts[ `GET ${ STAGING_API_ROUTES.switchTo }` ] ?? 0,
					{ timeout: 15000 }
				)
				.toBe( 1 );
		} );

		assertStagingRequests( tracker, [
			{ method: 'GET', route: STAGING_API_ROUTES.root, count: 1 },
			{ method: 'DELETE', route: STAGING_API_ROUTES.root, count: 1 },
			{ method: 'POST', route: STAGING_API_ROUTES.root, count: 1 },
			{
				method: 'GET',
				route: STAGING_API_ROUTES.switchTo,
				count: 1,
				params: { env: 'staging' },
			},
		] );

		assertActionRequestSequence( tracker, [
			`DELETE ${ STAGING_API_ROUTES.root }`,
			`POST ${ STAGING_API_ROUTES.root }`,
			`GET ${ STAGING_API_ROUTES.switchTo }`,
		] );
	} );
} );