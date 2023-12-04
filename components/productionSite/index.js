import { Button, Container, Radio } from '@newfold/ui-component-library';

/**
 * Staging Module - Production Site
 * For use in brand plugin apps to display staging page
 * 
 * @param {*} props 
 * @returns 
 */
const ProductionSite = ({ 
    methods,
    constants,
    hasStaging, 
    isProduction, 
    productionUrl,
    switchToMe,
    cloneMe,
    setModal,
}) => {
    return (
        <Container.SettingsField
            title={constants.text.productionSiteTitle}
            description={
                <Radio
                    checked={isProduction === true}
                    label={isProduction ? constants.text.currentlyEditing : constants.text.notCurrentlyEditing }
                    id="newfold-production-toggle"
                    name="newfold-staging-selector"
                    value="production"
                    onChange={() => {
                        switchToMe();
                    }}
                />
            }
        >
            <div className="nfd-flex nfd-justify-between nfd-items-center nfd-flex-wrap nfd-gap-3">
                <div>{productionUrl}</div>
                {hasStaging &&
                    <Button
                        variant="secondary"
                        id="staging-clone-button"
                        disabled={isProduction ? false : true}
                        onClick={() => { 
                            setModal(
                                constants.text.cloneConfirm,
                                constants.text.cloneDescription,
                                cloneMe,
                                null,
                                constants.text.clone
                            )
                        }}>
                        {constants.text.cloneStagingSite}
                    </Button>
                }
            </div>
        </Container.SettingsField>
    );
};

export default ProductionSite;