import { __ } from '@wordpress/i18n';

const getStagingSiteText = () => ( {
    title: __( 'Staging Site', 'wp-module-staging' ),
    noStagingSite: __(
        "You don't have a staging site yet.",
        'wp-module-staging'
    ),
    createStagingSite: __(
        'Create staging site',
        'wp-module-staging'
    ),
    deleteStagingSite: __( 'Delete Staging Site', 'wp-module-staging' ),
    deleteDescription: __(
        "This will permanently delete staging site. Are you sure you want to proceed? You can recreate another staging site at any time, but any specific changes you've made to this staging site will be lost.",
        'wp-module-staging'
    ),
    deleteNoticeCompleteText: __(
        'Deleted Staging',
        'wp-module-staging'
    ),
    deleteNoticeStartText: __(
        'Deleting the staging site, this should take about a minute.',
        'wp-module-staging'
    ),
} );

export default getStagingSiteText;
