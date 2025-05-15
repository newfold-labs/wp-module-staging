import { __ } from '@wordpress/i18n';

const getAppText = () => ( {
	cancelSite: __( 'Cancel', 'wp-module-staging' ),
	cloneNoticeCompleteText: __(
		'Cloned to Staging',
		'wp-module-staging'
	),
	cloneNoticeStartText: __(
		'Cloning production to staging, this should take about a minute.',
		'wp-module-staging'
	),
	createNoticeCompleteText: __(
		'Staging Created',
		'wp-module-staging'
	),
	createNoticeStartText: __(
		'Creating a staging site, this should take about a minute.',
		'wp-module-staging'
	),
	created: __( 'Created', 'wp-module-staging' ),
	currentlyEditing: __( 'Currently editing', 'wp-module-staging' ),
	deleteSite: __( 'Delete', 'wp-module-staging' ),
	deleteConfirm: __( 'Confirm Delete', 'wp-module-staging' ),
	deleteNoticeCompleteText: __(
		'Deleted Staging',
		'wp-module-staging'
	),
	deleteNoticeStartText: __(
		'Deleting the staging site, this should take about a minute.',
		'wp-module-staging'
	),
	deploy: __( 'Deploy', 'wp-module-staging' ),
	deployAll: __( 'Deploy all changes', 'wp-module-staging' ),
	deployConfirm: __( 'Confirm Deployment', 'wp-module-staging' ),
	deployDatabase: __( 'Deploy database only', 'wp-module-staging' ),
	deployDescription: __(
		'This will deploy staging to production and overwrite current production site. Are you sure you want to proceed?',
		'wp-module-staging'
	),
	deployFiles: __( 'Deploy files only', 'wp-module-staging' ),
	deployNoticeCompleteText: __( 'Deployed', 'wp-module-staging' ),
	deployNoticeStartText: __(
		'Deploying from staging to production, this should take about a minute.',
		'wp-module-staging'
	),
	deploySite: __( 'Deploy Site', 'wp-module-staging' ),
	error: __( 'Error', 'wp-module-staging' ),
	notCurrentlyEditing: __(
		'Not currently editing',
		'wp-module-staging'
	),
	pageDescription: __(
		'A staging site is a duplicate of your live site, offering a secure environment to experiment, test updates, and deploy when ready.',
		'wp-module-staging'
	),
	pageTitle: __( 'Staging', 'wp-module-performance' ),
	proceed: __( 'Proceed', 'wp-module-staging' ),
	switch: __( 'Switch', 'wp-module-staging' ),
	switchToProductionTitle: __(
		'Switch to Production',
		'wp-module-staging'
	),
	switchToProductionDescription: __(
		'This will navigate you to the production environment',
		'wp-module-staging'
	),
	switchToProductionNoticeCompleteText: __(
		'Loading the production environment now.',
		'wp-module-staging'
	),
	switchToProductionNoticeStartText: __(
		'Switching to the production environment, this should take about a minute.',
		'wp-module-staging'
	),
	switchToStagingTitle: __( 'Switch to Staging', 'wp-module-staging' ),
	switchToStagingDescription: __(
		'This will navigate you to the staging environment',
		'wp-module-staging'
	),
	switchToStagingNoticeCompleteText: __(
		'Loading the staging environment now.',
		'wp-module-staging'
	),
	switchToStagingNoticeStartText: __(
		'Switching to the staging environment, this should take about a minute.',
		'wp-module-staging'
	),
	switching: __( 'Switching', 'wp-module-staging' ),
	unknownErrorMessage: __(
		'An unknown error has occurred.',
		'wp-module-staging'
	),
	working: __( 'Working…', 'wp-module-staging' ),
} );

export default getAppText;