import { __ } from '@wordpress/i18n';

const getAppText = () => ( {
	cancelSite: __( 'Cancel', 'wp-plugin-bluehost' ),
	cloneNoticeCompleteText: __(
		'Cloned to Staging',
		'wp-plugin-bluehost'
	),
	cloneNoticeStartText: __(
		'Cloning production to staging, this should take about a minute.',
		'wp-plugin-bluehost'
	),
	createNoticeCompleteText: __(
		'Staging Created',
		'wp-plugin-bluehost'
	),
	createNoticeStartText: __(
		'Creating a staging site, this should take about a minute.',
		'wp-plugin-bluehost'
	),
	created: __( 'Created', 'wp-plugin-bluehost' ),
	currentlyEditing: __( 'Currently editing', 'wp-plugin-bluehost' ),
	deleteSite: __( 'Delete', 'wp-plugin-bluehost' ),
	deleteConfirm: __( 'Confirm Delete', 'wp-plugin-bluehost' ),
	deleteNoticeCompleteText: __(
		'Deleted Staging',
		'wp-plugin-bluehost'
	),
	deleteNoticeStartText: __(
		'Deleting the staging site, this should take about a minute.',
		'wp-plugin-bluehost'
	),
	deploy: __( 'Deploy', 'wp-plugin-bluehost' ),
	deployAll: __( 'Deploy all changes', 'wp-plugin-bluehost' ),
	deployConfirm: __( 'Confirm Deployment', 'wp-plugin-bluehost' ),
	deployDatabase: __( 'Deploy database only', 'wp-plugin-bluehost' ),
	deployDescription: __(
		'This will deploy staging to production and overwrite current production site. Are you sure you want to proceed?',
		'wp-plugin-bluehost'
	),
	deployFiles: __( 'Deploy files only', 'wp-plugin-bluehost' ),
	deployNoticeCompleteText: __( 'Deployed', 'wp-plugin-bluehost' ),
	deployNoticeStartText: __(
		'Deploying from staging to production, this should take about a minute.',
		'wp-plugin-bluehost'
	),
	deploySite: __( 'Deploy Site', 'wp-plugin-bluehost' ),
	error: __( 'Error', 'wp-plugin-bluehost' ),
	notCurrentlyEditing: __(
		'Not currently editing',
		'wp-plugin-bluehost'
	),
	pageDescription: __(
		'A staging site is a duplicate of your live site, offering a secure environment to experiment, test updates, and deploy when ready.',
		'wp-module-staging'
	),
	pageTitle: __( 'Staging', 'wp-module-performance' ),
	proceed: __( 'Proceed', 'wp-plugin-bluehost' ),
	switch: __( 'Switch', 'wp-plugin-bluehost' ),
	switchToProduction: __(
		'Switch to Production',
		'wp-plugin-bluehost'
	),
	switchToProductionDescription: __(
		'This will navigate you to the production environment',
		'wp-plugin-bluehost'
	),
	switchToProductionNoticeCompleteText: __(
		'Loading the production environment now.',
		'wp-plugin-bluehost'
	),
	switchToProductionNoticeStartText: __(
		'Switching to the production environment, this should take about a minute.',
		'wp-plugin-bluehost'
	),
	switchToStaging: __( 'Switch to Staging', 'wp-plugin-bluehost' ),
	switchToStagingDescription: __(
		'This will navigate you to the staging environment',
		'wp-plugin-bluehost'
	),
	switchToStagingNoticeCompleteText: __(
		'Loading the staging environment now.',
		'wp-plugin-bluehost'
	),
	switchToStagingNoticeStartText: __(
		'Switching to the staging environment, this should take about a minute.',
		'wp-plugin-bluehost'
	),
	switching: __( 'Switching', 'wp-plugin-bluehost' ),
	unknownErrorMessage: __(
		'An unknown error has occurred.',
		'wp-plugin-bluehost'
	),
	working: __( 'Working…', 'wp-plugin-bluehost' ),
} );

export default getAppText;