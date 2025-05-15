import { __ } from '@wordpress/i18n';

const getProductionSiteText = () => ( {
	title: __('Production Site', 'wp-module-staging'),
	currentlyEditing: __('Currently editing', 'wp-module-staging'),
	notCurrentlyEditing: __('Not currently editing', 'wp-module-staging'),
	clone: __('Clone', 'wp-module-staging'),
	cloneConfirm:  __('Confirm Clone Action', 'wp-module-staging'),
	cloneDescription:  __('This will overwrite anything in staging and update it to an exact clone of the current production site. Are you sure you want to proceed?', 'wp-module-staging'),
	cloneStagingSite: __('Clone to staging', 'wp-module-staging'),
} );

export default getProductionSiteText;
