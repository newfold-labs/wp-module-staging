import { test, expect } from '@playwright/test';
import {
  auth,
  a11y,
  pluginId,
  FIXTURES,
  SELECTORS,
  mockStagingApi,
  setupProductionEnvironmentMocks,
  navigateToStagingPage,
  waitForStagingPage,
  closeAllNotifications,
  clickModalButton,
} from '../helpers/index.mjs';

test.describe('Staging Page - Production Environment', () => {
  test('Is Accessible', async ({ page }) => {
    // Setup route interception before any navigation
    await mockStagingApi(page, FIXTURES.stagingInit);
    
    await auth.loginToWordPress(page);
    await navigateToStagingPage(page);
    await waitForStagingPage(page);
    
    // Accessibility check
    await a11y.checkA11y(page, SELECTORS.stagingPage);
  });

  test('Displays in Production Environment Properly', async ({ page }) => {
    // Setup route interception before any navigation
    await mockStagingApi(page, FIXTURES.stagingInit);
    
    await auth.loginToWordPress(page);
    await navigateToStagingPage(page);
    await waitForStagingPage(page);
    
    // Verify Production section
    const prodSection = page.locator(SELECTORS.stagingProd);
    await expect(prodSection).toBeVisible();
    
    const productionToggle = page.locator(SELECTORS.productionToggle);
    await expect(productionToggle).toBeChecked();
    
    await expect(prodSection.locator('h3')).toContainText('Production Site');
    await expect(prodSection.locator('label[for="newfold-production-toggle"]')).toContainText('Currently editing');
    
    // Verify Staging section
    const stagingSection = page.locator(SELECTORS.stagingStaging);
    await expect(stagingSection.locator('h3')).toContainText('Staging Site');
    await expect(stagingSection.locator('label[for="newfold-staging-toggle"]')).toContainText('Not currently editing');
    
    const stagingToggle = page.locator(SELECTORS.stagingToggle);
    await expect(stagingToggle).not.toBeChecked();
    
    await expect(stagingSection).toContainText('https://localhost:8882/staging/1234');
    await expect(stagingSection.locator('dd')).toContainText('May 30, 2023');
    
    // Verify button states
    await expect(page.locator(SELECTORS.cloneButton)).not.toBeDisabled();
    await expect(page.locator(SELECTORS.deleteButton)).not.toBeDisabled();
    await expect(page.locator(SELECTORS.deployButton)).toBeDisabled();
  });

  test('Errors as expected', async ({ page }) => {
    // Setup route WITHOUT clone mock to trigger error
    await page.route(/newfold-staging.*v1.*staging/, async (route) => {
      const method = route.request().method();
      const url = route.request().url();
      
      // Let clone request fail (don't intercept it)
      if (url.includes('clone') && method === 'POST') {
        await route.continue();
        return;
      }
      
      // Return initial data for GET
      if (method === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(FIXTURES.stagingInit),
        });
        return;
      }
      
      await route.continue();
    });
    
    await auth.loginToWordPress(page);
    await navigateToStagingPage(page);
    await waitForStagingPage(page);
    
    // Click clone button
    await page.locator(SELECTORS.cloneButton).click();
    
    // Verify modal appears
    const modal = page.locator(SELECTORS.modal);
    await expect(modal.locator('h1')).toContainText('Confirm Clone Action');
    
    // Click Clone in modal
    await clickModalButton(page, 'Clone', true);
    await page.waitForTimeout(500);
    
    // Verify error notification appears
    const errorNotification = page.locator(SELECTORS.notificationError);
    await expect(errorNotification).toBeVisible();
    await expect(errorNotification.locator('p')).not.toBeEmpty();
  });

  test('Clone Works', async ({ page }) => {
    // Setup route interception before any navigation
    await mockStagingApi(page, FIXTURES.stagingInit);
    
    await auth.loginToWordPress(page);
    await navigateToStagingPage(page);
    await waitForStagingPage(page);
    
    // Click clone button
    await page.locator(SELECTORS.cloneButton).click();
    
    // Verify modal appears
    const modal = page.locator(SELECTORS.modal);
    await expect(modal.locator('h1')).toContainText('Confirm Clone Action');
    
    // Click Clone in modal
    await clickModalButton(page, 'Clone', true);
    
    // Verify success notification - filter to notification container with content
    const notifications = page.locator(SELECTORS.notifications).filter({ hasText: 'Cloned' });
    await expect(notifications).toContainText('Cloned to Staging');
  });

  test('Deleting, Creating and Switch to staging Environment', async ({ page }) => {
    // Setup full production environment mocks
    await setupProductionEnvironmentMocks(page);
    
    await auth.loginToWordPress(page);
    await navigateToStagingPage(page);
    await waitForStagingPage(page);
    
    // --- Delete Staging ---
    await page.locator(SELECTORS.deleteButton).click();
    
    // Verify delete modal
    const modal = page.locator(SELECTORS.modal);
    await expect(modal.locator('h1')).toContainText('Confirm Delete');
    
    // Click Delete
    await clickModalButton(page, 'Delete', true);
    
    // Verify delete success notification - filter to notification container with content
    await expect(page.locator(SELECTORS.notifications).filter({ hasText: 'Deleted' })).toContainText('Deleted Staging');
    
    // Verify staging section shows no staging site
    const stagingSection = page.locator(SELECTORS.stagingStaging);
    await expect(stagingSection.locator('h3')).toContainText('Staging Site');
    await expect(stagingSection.locator('label[for="newfold-staging-toggle"]')).toHaveCount(0);
    await expect(stagingSection.getByText('https://localhost:8882/staging/1234')).toHaveCount(0);
    await expect(stagingSection).toContainText("You don't have a staging site yet");
    
    // Create button should be visible
    const createButton = page.locator(SELECTORS.createButton);
    await expect(createButton).toBeVisible();
    await expect(createButton).not.toBeDisabled();
    
    // --- Create Staging ---
    await createButton.click();
    
    // Wait for thinking state
    await expect(page.locator(SELECTORS.stagingPage)).toHaveClass(/is-thinking/);
    
    // Wait for response
    await page.waitForLoadState('networkidle');
    
    // Verify staging is created
    await expect(page.locator(SELECTORS.stagingPage)).not.toHaveClass(/is-thinking/);
    await expect(stagingSection.locator('h3')).toContainText('Staging Site');
    await expect(stagingSection.locator('label[for="newfold-staging-toggle"]')).toContainText('Not currently editing');
    await expect(stagingSection).toContainText('https://localhost:8882/staging/1234');
    
    // --- Switch to Staging ---
    // Close all snackbar notifications first
    await closeAllNotifications(page);
    
    // Verify toggle states
    await expect(page.locator(SELECTORS.productionToggle)).toBeChecked();
    await expect(page.locator(SELECTORS.stagingToggle)).not.toBeChecked();
    
    // Click staging toggle
    await page.locator(SELECTORS.stagingToggle).click();
    
    // Verify switch modal appears
    await expect(modal.locator('h1')).toContainText('Switch to Staging');
    
    // Cancel first
    await clickModalButton(page, 'Cancel', false);
    await expect(modal.locator('h1')).toHaveCount(0);
    
    // Verify toggles unchanged
    await expect(page.locator(SELECTORS.productionToggle)).toBeChecked();
    await expect(page.locator(SELECTORS.stagingToggle)).not.toBeChecked();
    
    // Click staging toggle again and proceed
    await page.locator(SELECTORS.stagingToggle).click();
    await clickModalButton(page, 'Proceed', true);
    await page.waitForTimeout(100);
    
    // Verify working notification - filter to notification container with content
    await expect(page.locator(SELECTORS.notifications).filter({ hasText: 'Working' })).toContainText('Working');
    
    // Wait for switch response
    await page.waitForLoadState('networkidle');
    
    // Verify switching notification - filter to notification container with content
    await expect(page.locator(SELECTORS.notifications).filter({ hasText: 'Switching' })).toContainText('Switching');
  });
});
