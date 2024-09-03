// <reference types="Cypress" />
const stagingInitFixture = require( '../fixtures/stagingInit.json' );
const stagingCloneFixture = require( '../fixtures/stagingClone.json' );
const stagingDeleteFixture = require( '../fixtures/stagingDelete.json' );
const stagingCreateFixture = require( '../fixtures/stagingCreate.json' );
const stagingSwitchFixture = require( '../fixtures/stagingSwitch.json' );
const customCommandTimeout = 60000;

describe( 'Staging Page - Production Environment', function () {
	const appClass = '.' + Cypress.env( 'appId' );

	before( () => {
		cy.intercept(
			{
				method: 'GET',
				url: /newfold-staging(\/|%2F)v1(\/|%2F)staging/,
			},
			stagingInitFixture
		).as('mock-staging-data');
		cy.visit(
			'/wp-admin/admin.php?page=' +
				Cypress.env( 'pluginId' ) +
				'#/staging'
		);
		cy.wait('@mock-staging-data', { timeout: customCommandTimeout } )
			.then( ( interception ) => {
				expect( interception.response.statusCode ).to.eq( 200 );
			});
	} );

	it( 'Is Accessible', () => {
		cy.injectAxe();
		cy.wait( 500 );
		cy.checkA11y( appClass + '-app-body' );
	} );

	it( 'Displays in Production Environment Properly', () => {
		cy.get( '.newfold-staging-prod' )
			.scrollIntoView()
			.should( 'be.visible' );

		cy.get( '#newfold-production-toggle' ).should( 'be.checked' );
		cy.get( '.newfold-staging-prod' )
			.contains( 'h3', 'Production Site' )
			.should( 'be.visible' );
		cy.get( '.newfold-staging-prod' )
			.contains(
				'label[for="newfold-production-toggle"]',
				'Currently editing'
			)
			.should( 'be.visible' );

		cy.get( '.newfold-staging-staging' )
			.contains( 'h3', 'Staging Site' )
			.should( 'be.visible' );
		cy.get( '.newfold-staging-staging' )
			.contains(
				'label[for="newfold-staging-toggle"]',
				'Not currently editing'
			)
			.should( 'be.visible' );
		cy.get( '#newfold-staging-toggle' ).should( 'not.be.checked' );
		cy.get( '.newfold-staging-staging' )
			.contains( 'div', 'https://localhost:8882/staging/1234' )
			.should( 'be.visible' );
		cy.get( '.newfold-staging-staging' )
			.contains( 'div', 'May 30, 2023' )
			.should( 'be.visible' );

		cy.get( '#staging-clone-button' ).should( 'not.be.disabled' );
		cy.get( '#staging-delete-button' ).should( 'not.be.disabled' );
		cy.get( '#staging-deploy-button' ).should( 'be.disabled' );
	} );

	it( 'Errors as expected', () => {
		cy.get( '#staging-clone-button' ).click();
		cy.get( '.nfd-modal' )
			.contains( 'h1', 'Confirm Clone Action' )
			.should( 'be.visible' );
		cy.get( '.nfd-modal .nfd-button--primary' )
			.contains( 'Clone' )
			.should( 'be.visible' )
			.click();
		cy.wait( 100 );

		cy.get( '.nfd-notifications' )
			.contains( 'p', 'Error' )
			.should( 'be.visible' );
	} );

	it( 'Clone Works', () => {
		cy.intercept(
			{
				method: 'POST',
				url: /newfold-staging(\/|%2F)v1(\/|%2F)staging(\/|%2F)clone/,
			},
			stagingCloneFixture
		).as( 'stagingClone' );

		cy.get( '#staging-clone-button' ).click();
		cy.get( '.nfd-modal' )
			.contains( 'h1', 'Confirm Clone Action' )
			.should( 'be.visible' );
		cy.get( '.nfd-modal .nfd-button--primary' )
			.contains( 'Clone' )
			.should( 'be.visible' )
			.click();

		cy.wait( '@stagingClone' );

		cy.get( '.nfd-notifications' )
			.contains( 'p', 'Cloned to Staging' )
			.should( 'be.visible' );
	} );

	it( 'Delete Works', () => {
		cy.intercept(
			{
				url: /newfold-staging(\/|%2F)v1(\/|%2F)staging/,
			},
			stagingDeleteFixture
		).as( 'stagingDelete' );

		cy.get( '#staging-delete-button' ).click();
		cy.get( '.nfd-modal' )
			.contains( 'h1', 'Confirm Delete' )
			.should( 'be.visible' );
		cy.get( '.nfd-modal .nfd-button--primary' )
			.contains( 'Delete' )
			.should( 'be.visible' )
			.click();

		cy.wait( '@stagingDelete' );

		cy.get( '.nfd-notifications' )
			.contains( 'p', 'Deleted Staging' )
			.should( 'be.visible' );

		cy.get( '.newfold-staging-staging' )
			.contains( 'h3', 'Staging Site' )
			.should( 'be.visible' );
		cy.get( '.newfold-staging-staging' )
			.contains(
				'label[for="newfold-staging-toggle"]',
				'Not currently editing'
			)
			.should( 'not.exist' );
		cy.get( '.newfold-staging-staging' )
			.contains( 'div', 'https://localhost:8882/staging/1234' )
			.should( 'not.exist' );
		cy.get( '.newfold-staging-staging' )
			.contains( 'div', "You don't have a staging site yet" )
			.should( 'be.visible' );
		cy.get( '#staging-create-button' )
			.should( 'be.visible' )
			.should( 'not.be.disabled' );
	} );

	it( 'Create Works', () => {
		cy.intercept(
			{
				method: 'POST',
				url: /newfold-staging(\/|%2F)v1(\/|%2F)staging/,
			},
			{
				body: stagingCreateFixture,
				delay: 1000,
			}
		).as( 'stagingCreate' );

		cy.get( '.newfold-staging-staging' )
			.contains( 'div', "You don't have a staging site yet" )
			.should( 'be.visible' );

		cy.get( '#staging-create-button' ).should( 'be.visible' ).click();
		cy.get( '.newfold-staging-page' ).should( 'have.class', 'is-thinking' );
		cy.wait( '@stagingCreate' );

		cy.get( '.newfold-staging-page' ).should(
			'not.have.class',
			'is-thinking'
		);

		cy.get( '.newfold-staging-staging' )
			.contains( 'h3', 'Staging Site' )
			.should( 'be.visible' );
		cy.get( '.newfold-staging-staging' )
			.contains(
				'label[for="newfold-staging-toggle"]',
				'Not currently editing'
			)
			.should( 'be.visible' );
		cy.get( '.newfold-staging-staging' )
			.contains( 'div', 'https://localhost:8882/staging/1234' )
			.should( 'be.visible' );
	} );

	it( 'Switch Works', () => {
		cy.intercept(
			{
				method: 'GET',
				url: /newfold-staging(\/|%2F)v1(\/|%2F)staging(\/|%2F)switch-to(\&|%26)env(\=|%3D)staging/,
			},
			{
				body: stagingSwitchFixture,
				delay: 1000,
			}
		).as( 'stagingSwitch' );

		// close all snackbar notices
		cy.get( '.nfd-notification button' ).each( ( $btn ) => {
			cy.wrap( $btn ).click();
		});

		cy.get( '#newfold-production-toggle' ).should( 'be.checked' );
		cy.get( '#newfold-staging-toggle' ).should( 'not.be.checked' );
		cy.get( '#newfold-staging-toggle' ).click();
		cy.get( '.nfd-modal' )
			.contains( 'h1', 'Switch to Staging' )
			.should( 'be.visible' );
		cy.get( '.nfd-modal .nfd-button--error' )
			.contains( 'Cancel' )
			.should( 'be.visible' )
			.click();
		cy.get( '.nfd-modal h1' ).should( 'not.exist' );
		cy.get( '#newfold-production-toggle' ).should( 'be.checked' );
		cy.get( '#newfold-staging-toggle' ).should( 'not.be.checked' );

		cy.get( '#newfold-staging-toggle' ).click();
		cy.get( '.nfd-modal .nfd-button--primary' )
			.contains( 'Switch' )
			.should( 'be.visible' )
			.click();
		cy.wait( 100 );

		cy.get( '.nfd-notifications' )
			.contains( 'p', 'Working' )
			.should( 'be.visible' );

		cy.wait( '@stagingSwitch' );

		cy.get( '.nfd-notifications' )
			.contains( 'p', 'Switching' )
			.should( 'be.visible' );

		// actual reload cancelled by fixture containing a load_page value of `#`
	} );
} );