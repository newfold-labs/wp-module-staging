// <reference types="Cypress" />
const stagingStagingFixture = require( '../fixtures/stagingStaging.json' );
const stagingDeployFixture = require( '../fixtures/stagingDeploy.json' );

describe( 'Staging Page - Staging Environmant', function () {
	const appClass = '.' + Cypress.env( 'appId' );

	before( () => {
		cy.intercept(
			{
				method: 'GET',
				url: /newfold-staging(\/|%2F)v1(\/|%2F)staging/,
			},
			stagingStagingFixture
		);
		cy.visit(
			'/wp-admin/admin.php?page=' +
				Cypress.env( 'pluginId' ) +
				'#/staging'
		);
	} );

	it( 'Displays staging environemnt properly', () => {
		cy.get( '#newfold-production-toggle' ).should( 'not.be.checked' );
		cy.get( '.newfold-staging-prod' )
			.contains( 'h3', 'Production Site' )
			.should( 'be.visible' );
		cy.get( '.newfold-staging-prod' )
			.contains(
				'label[for="newfold-production-toggle"]',
				'Not currently editing'
			)
			.should( 'be.visible' );

		cy.get( '#newfold-staging-toggle' ).should( 'be.checked' );
		cy.get( '.newfold-staging-staging' )
			.contains( 'h3', 'Staging Site' )
			.should( 'be.visible' );
		cy.get( '.newfold-staging-staging' )
			.contains(
				'label[for="newfold-staging-toggle"]',
				'Currently editing'
			)
			.should( 'be.visible' );

		cy.get( '#staging-clone-button' ).should( 'be.disabled' );
		cy.get( '#staging-delete-button' ).should( 'be.disabled' );
		cy.get( '#staging-deploy-button' ).should( 'not.be.disabled' );
	} );

	it( 'Deploy Works', () => {
		cy.intercept(
			{
				method: 'POST',
				url: /newfold-staging(\/|%2F)v1(\/|%2F)staging(\/|%2F)deploy/,
			},
			{
				body: stagingDeployFixture,
				delay: 500,
			}
		).as( 'stagingDeploy' );

		cy.get( '#staging-deploy-button' ).click();
		cy.get( '.nfd-modal' )
			.contains( 'h1', 'Confirm Deployment' )
			.should( 'be.visible' );
		cy.get( '.nfd-modal .nfd-button--error' )
			.contains( 'Cancel' )
			.should( 'be.visible' );
		cy.get( '.nfd-modal .nfd-button--primary' )
			.contains( 'Deploy' )
			.should( 'be.visible' )
			.click();

		cy.get( '.nfd-notifications' )
			.contains( 'p', 'Working...' )
			.should( 'be.visible' );

		cy.wait( '@stagingDeploy' );

		cy.get( '.nfd-notifications' )
			.contains( 'p', 'Deployed' )
			.should( 'be.visible' );
	} );
} );
