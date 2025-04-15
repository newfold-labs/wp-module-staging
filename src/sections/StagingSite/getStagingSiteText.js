import { __ } from '@wordpress/i18n';

const getStagingSiteText = () => ( {
    title: __( 'Staging Site', 'wp-module-staging' ),
    noStagingSite: __(
        "You don't have a staging site yet.",
        'wp-plugin-bluehost'
    ),
    createStagingSite: __(
        'Create staging site',
        'wp-plugin-bluehost'
    ),
    deleteStagingSite: __( 'Delete Staging Site', 'wp-plugin-bluehost' ),
    deleteDescription: __(
        "This will permanently delete staging site. Are you sure you want to proceed? You can recreate another staging site at any time, but any specific changes you've made to this staging site will be lost.",
        'wp-plugin-bluehost'
    ),
    deleteNoticeCompleteText: __(
        'Deleted Staging',
        'wp-plugin-bluehost'
    ),
    deleteNoticeStartText: __(
        'Deleting the staging site, this should take about a minute.',
        'wp-plugin-bluehost'
    ),
} );

export default getStagingSiteText;
