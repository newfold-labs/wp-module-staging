import { test, expect } from '@playwright/test';
import {
  auth,
  pluginId,
  FIXTURES,
  SELECTORS,
  mockStagingApi,
  navigateToStagingPage,
  waitForStagingPage,
  clickModalButton,
} from '../helpers/index.mjs';

test.describe('Staging Page - Staging Environment', () => {
  test('Displays staging environment properly', async ({ page }) => {
    // Setup route interception with staging environment fixture
    await mockStagingApi(page, FIXTURES.stagingStaging);
    
    await auth.loginToWordPress(page);
    await navigateToStagingPage(page);
    await waitForStagingPage(page);
    
    // Verify Production section (not currently editing)
    const productionToggle = page.locator(SELECTORS.productionToggle);
    await expect(productionToggle).not.toBeChecked();
    
    const prodSection = page.locator(SELECTORS.stagingProd);
    await expect(prodSection.locator('h3')).toContainText('Production Site');
    await expect(prodSection.locator('label[for="newfold-production-toggle"]')).toContainText('Not currently editing');
    
    // Verify Staging section (currently editing)
    const stagingToggle = page.locator(SELECTORS.stagingToggle);
    await expect(stagingToggle).toBeChecked();
    
    const stagingSection = page.locator(SELECTORS.stagingStaging);
    await expect(stagingSection.locator('h3')).toContainText('Staging Site');
    await expect(stagingSection.locator('label[for="newfold-staging-toggle"]')).toContainText('Currently editing');
    
    // Verify button states in staging environment
    await expect(page.locator(SELECTORS.cloneButton)).toBeDisabled();
    await expect(page.locator(SELECTORS.deleteButton)).toBeDisabled();
    await expect(page.locator(SELECTORS.deployButton)).not.toBeDisabled();
  });

  test('Deploy Works', async ({ page }) => {
    // Setup route interception with staging environment fixture
    await mockStagingApi(page, FIXTURES.stagingStaging);
    
    await auth.loginToWordPress(page);
    await navigateToStagingPage(page);
    await waitForStagingPage(page);
    
    // Click deploy button
    await page.locator(SELECTORS.deployButton).click();
    
    // Verify deploy modal
    const modal = page.locator(SELECTORS.modal);
    await expect(modal.locator('h1')).toContainText('Confirm Deployment');
    
    // Verify buttons
    const cancelButton = modal.locator(SELECTORS.modalErrorButton.replace('.nfd-modal ', ''));
    await expect(cancelButton).toContainText('Cancel');
    
    const deployButton = modal.locator(SELECTORS.modalPrimaryButton.replace('.nfd-modal ', ''));
    await expect(deployButton).toContainText('Deploy');
    
    // Click Deploy
    await clickModalButton(page, 'Deploy', true);
    await page.waitForTimeout(100);
    
    // Verify working notification - filter to notification container with content
    await expect(page.locator(SELECTORS.notifications).filter({ hasText: 'Working' })).toContainText('Working');
    
    // Wait for response
    await page.waitForLoadState('networkidle');
    
    // Verify deployed success notification - filter to notification container with content
    await expect(page.locator(SELECTORS.notifications).filter({ hasText: 'Deployed' })).toContainText('Deployed');
  });
});
