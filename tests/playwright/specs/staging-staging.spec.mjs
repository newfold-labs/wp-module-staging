import { test, expect } from '@playwright/test';
import {
  FIXTURES,
  SELECTORS,
  mockStagingApi,
  setupAndNavigate,
  confirmModalAction,
  expectNotification,
} from '../helpers/index.mjs';

test.describe('Staging Page - Staging Environment', () => {
  test('Displays staging environment properly', async ({ page }) => {
    await mockStagingApi(page, FIXTURES.stagingStaging);
    await setupAndNavigate(page);
    
    // Verify Production section (not currently editing)
    await expect(page.locator(SELECTORS.productionToggle)).not.toBeChecked();
    const prodSection = page.locator(SELECTORS.stagingProd);
    await expect(prodSection.locator('h3')).toContainText('Production Site');
    await expect(prodSection.locator(SELECTORS.productionToggleLabel)).toContainText('Not currently editing');
    
    // Verify Staging section (currently editing)
    await expect(page.locator(SELECTORS.stagingToggle)).toBeChecked();
    const stagingSection = page.locator(SELECTORS.stagingStaging);
    await expect(stagingSection.locator('h3')).toContainText('Staging Site');
    await expect(stagingSection.locator(SELECTORS.stagingToggleLabel)).toContainText('Currently editing');
    
    // Verify button states in staging environment
    await expect(page.locator(SELECTORS.cloneButton)).toBeDisabled();
    await expect(page.locator(SELECTORS.deleteButton)).toBeDisabled();
    await expect(page.locator(SELECTORS.deployButton)).not.toBeDisabled();
  });

  test('Deploy Works', async ({ page }) => {
    await mockStagingApi(page, FIXTURES.stagingStaging);
    await setupAndNavigate(page);
    
    // Click deploy button
    await page.locator(SELECTORS.deployButton).click();
    
    // Verify deploy modal has correct buttons
    const modal = page.locator(SELECTORS.modal);
    await expect(modal.locator('h1')).toContainText('Confirm Deployment');
    await expect(modal.locator('.nfd-button--error')).toContainText('Cancel');
    await expect(modal.locator('.nfd-button--primary')).toContainText('Deploy');
    
    // Click Deploy and verify notifications
    await confirmModalAction(page, 'Confirm Deployment', 'Deploy');
    await expectNotification(page, 'Working');
    
    // Wait for response
    await page.waitForLoadState('networkidle');
    
    // Verify deployed success notification
    await expectNotification(page, 'Deployed');
  });
});
