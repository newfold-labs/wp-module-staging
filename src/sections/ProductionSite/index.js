import {
	Button,
	Container, Radio,
} from '@newfold/ui-component-library';

import getProductionSiteText from './getProductionSiteText';

const {
	title,
	currentlyEditing,
	notCurrentlyEditing,
	clone,
	cloneConfirm,
	cloneDescription,
	cloneStagingSite
} = getProductionSiteText();

const ProductionSite = ({
		hasStaging,
		isProduction,
		productionUrl,
		switchToMe,
		cloneMe,
		setModal,
}) => {

	return (
		<Container.SettingsField
			title={title}
			className="newfold-staging-production-site-container"
		>
			<Radio
				checked={isProduction === true}
				label={isProduction ? currentlyEditing : notCurrentlyEditing }
				id="newfold-production-toggle"
				name="newfold-staging-selector"
				value="production"
				onChange={() => {
					switchToMe();
				}}
			/>
			<div className="nfd-flex nfd-justify-between nfd-items-center nfd-flex-wrap nfd-gap-3">
				<div>{productionUrl}</div>
				{hasStaging &&
					<Button
						variant="secondary"
						id="staging-clone-button"
						disabled={isProduction ? false : true}
						onClick={() => {
							setModal(
								cloneConfirm,
								cloneDescription,
								cloneMe,
								null,
								clone
							)
						}}>
						{cloneStagingSite}
					</Button>
				}
			</div>
		</Container.SettingsField>
	);
};

export default ProductionSite
