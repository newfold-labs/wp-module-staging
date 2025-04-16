import {
    Button,
    Container, Radio, Select,
} from '@newfold/ui-component-library';

import getStagingSiteText from './getStagingSiteText';
import {ArrowPathIcon, TrashIcon} from "@heroicons/react/24/outline";

import { useState } from '@wordpress/element';

const {
    title,
    noStagingSite,
    createStagingSite,
    deleteStagingSite,
    deleteDescription
} = getStagingSiteText();

const StagingSite = ( {
    getAppText,
    isProduction,
    hasStaging,
    createMe,
    deleteMe,
    deployMe,
    switchToMe,
    stagingUrl,
    creationDate,
    setModal,
   } ) => {

    const {
        created,
        currentlyEditing,
        deleteConfirm,
        deleteSite,
        deploy,
        deployAll,
        deployConfirm,
        deployDatabase,
        deployDescription,
        deployFiles,
        deploySite,
        notCurrentlyEditing,
    } = getAppText();

    const [deployOption, setDeployOption] = useState( 'all' );


    return (
        <Container.SettingsField
            title={title}
            description={!hasStaging ? noStagingSite :
                <Radio
                    checked={isProduction !== true}
                    label={isProduction ? notCurrentlyEditing : currentlyEditing }
                    id="newfold-staging-toggle"
                    name="newfold-staging-selector"
                    value="staging"
                    onChange={() => {
                        switchToMe();
                    }}
                />
            }
        >
            <div className="nfd-flex nfd-justify-between nfd-items-center nfd-flex-wrap nfd-gap-3">
                {!hasStaging &&
                    <div className="nfd-flex nfd-justify-end nfd-w-full">
                        <Button
                            variant="secondary"
                            id="staging-create-button"
                            onClick={() => {
                                createMe()
                            }}>
                            {createStagingSite}
                        </Button>
                    </div>
                }
                {hasStaging &&
                    <>
                        <div>
                            {stagingUrl}
                            <dl className="nfd-flex nfd-justify-between nfd-items-center nfd-flex-wrap nfd-gap-3">
                                <dt>{created}:</dt>
                                <dd>{creationDate}</dd>
                            </dl>
                        </div>
                        <div className="nfd-flex nfd-gap-1.5 nfd-relative">
                            <Select
                                disabled={ isProduction ? true : false }
                                id="newfold-staging-select"
                                name="newfold-staging"
                                className="nfd-w-48"
                                value={deployOption}
                                onChange={(value) => { setDeployOption(value) }}
                                options={[
                                    {
                                        label: deployAll,
                                        value: 'all'
                                    },
                                    {
                                        label: deployFiles,
                                        value: 'files'
                                    },
                                    {
                                        label: deployDatabase,
                                        value: 'db'
                                    }
                                ]}
                            />
                            <Button
                                disabled={isProduction ? true : false }
                                id="staging-deploy-button"
                                title={deploySite}
                                onClick={() => {
                                    // console.log('Open confirm modal: Deploy stagin option to production');
                                    setModal(
                                        deployConfirm,
                                        deployDescription,
                                        deployMe,
                                        deployOption,
                                        deploy
                                    )
                                }}
                            >
                                <ArrowPathIcon />
                            </Button>

                            <Button
                                disabled={isProduction ? false : true }
                                variant="error"
                                id="staging-delete-button"
                                title={deleteStagingSite}
                                onClick={() => {
                                    // console.log('Open confirm modal: Delete stagin option to production');
                                    setModal(
                                        deleteConfirm,
                                        deleteDescription,
                                        deleteMe,
                                        null,
                                        deleteSite
                                    )
                                }}
                            >
                                <TrashIcon />
                            </Button>
                        </div>
                    </>
                }

            </div>
        </Container.SettingsField>
    );
};

export default StagingSite;
