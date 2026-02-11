import { test, expect } from '@playwright/test';
import {
  a11y,
  FIXTURES,
  SELECTORS,
  API_PATTERNS,
  mockStagingApi,
  setupProductionEnvironmentMocks,
  setupAndNavigate,
  closeAllNotifications,
  expectNotification,
  confirmModalAction,
} from '../helpers/index.mjs';

test.describe('Staging Page - Production Environment', () => {
  test('Is Accessible', async ({ page }) => {
    await mockStagingApi(page, FIXTURES.stagingInit);
    await setupAndNavigate(page);
    
    await a11y.checkA11y(page, SELECTORS.stagingPage);
  });

  test('Displays in Production Environment Properly', async ({ page }) => {
    await mockStagingApi(page, FIXTURES.stagingInit);
    await setupAndNavigate(page);
    
    // Verify Production section
    const prodSection = page.locator(SELECTORS.stagingProd);
    await expect(prodSection).toBeVisible();
    await expect(page.locator(SELECTORS.productionToggle)).toBeChecked();
    await expect(prodSection.locator('h3')).toContainText('Production Site');
    await expect(prodSection.locator(SELECTORS.productionToggleLabel)).toContainText('Currently editing');
    
    // Verify Staging section
    const stagingSection = page.locator(SELECTORS.stagingStaging);
    await expect(stagingSection.locator('h3')).toContainText('Staging Site');
    await expect(stagingSection.locator(SELECTORS.stagingToggleLabel)).toContainText('Not currently editing');
    await expect(page.locator(SELECTORS.stagingToggle)).not.toBeChecked();
    await expect(stagingSection).toContainText('https://localhost:8882/staging/1234');
    await expect(stagingSection.locator('dd')).toContainText('May 30, 2023');
    
    // Verify button states
    await expect(page.locator(SELECTORS.cloneButton)).not.toBeDisabled();
    await expect(page.locator(SELECTORS.deleteButton)).not.toBeDisabled();
    await expect(page.locator(SELECTORS.deployButton)).toBeDisabled();
  });

  test('Errors as expected', async ({ page }) => {
    // Setup route WITHOUT clone mock to trigger error
    await page.route(API_PATTERNS.staging, async (route) => {
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
    
    await setupAndNavigate(page);
    
    // Click clone button and confirm
    await page.locator(SELECTORS.cloneButton).click();
    await confirmModalAction(page, 'Confirm Clone Action', 'Clone');
    
    // Wait for and verify error notification appears
    const errorNotification = page.locator(SELECTORS.notificationError);
    await expect(errorNotification).toBeVisible();
    await expect(errorNotification.locator('p')).not.toBeEmpty();
  });

  test('Clone Works', async ({ page }) => {
    await mockStagingApi(page, FIXTURES.stagingInit);
    await setupAndNavigate(page);
    
    // Click clone button and confirm
    await page.locator(SELECTORS.cloneButton).click();
    await confirmModalAction(page, 'Confirm Clone Action', 'Clone');
    
    // Verify success notification
    await expectNotification(page, 'Cloned to Staging');
  });

  test('Deleting, Creating and Switch to staging Environment', async ({ page }) => {
    test.slow(); // This test performs multiple operations, give it 3x timeout
    
    await setupProductionEnvironmentMocks(page);
    await setupAndNavigate(page);
    
    const stagingSection = page.locator(SELECTORS.stagingStaging);
    const modal = page.locator(SELECTORS.modal);
    
    await test.step('Delete staging site', async () => {
      await page.locator(SELECTORS.deleteButton).click();
      await confirmModalAction(page, 'Confirm Delete', 'Delete');
      await expectNotification(page, 'Deleted Staging');
      
      // Verify staging section shows no staging site
      await expect(stagingSection.locator('h3')).toContainText('Staging Site');
      await expect(stagingSection.locator(SELECTORS.stagingToggleLabel)).toHaveCount(0);
      await expect(stagingSection.getByText('https://localhost:8882/staging/1234')).toHaveCount(0);
      await expect(stagingSection).toContainText("You don't have a staging site yet");
      
      // Create button should be visible
      const createButton = page.locator(SELECTORS.createButton);
      await expect(createButton).toBeVisible();
      await expect(createButton).not.toBeDisabled();
    });
    
    await test.step('Create new staging site', async () => {
      await page.locator(SELECTORS.createButton).click();
      
      // Wait for thinking state
      await expect(page.locator(SELECTORS.stagingPage)).toHaveClass(/is-thinking/);
      
      // Wait for create to complete
      await expect(page.locator(SELECTORS.stagingPage)).not.toHaveClass(/is-thinking/, { timeout: 30000 });
      
      // Verify staging is created
      await expect(stagingSection.locator('h3')).toContainText('Staging Site');
      await expect(stagingSection.locator(SELECTORS.stagingToggleLabel)).toContainText('Not currently editing');
      await expect(stagingSection).toContainText('https://localhost:8882/staging/1234');
    });
    
    await test.step('Switch to staging environment', async () => {
      // Close all snackbar notifications first
      await closeAllNotifications(page);
      
      // Verify initial toggle states
      await expect(page.locator(SELECTORS.productionToggle)).toBeChecked();
      await expect(page.locator(SELECTORS.stagingToggle)).not.toBeChecked();
      
      // Click staging toggle and cancel
      await page.locator(SELECTORS.stagingToggle).click();
      await expect(modal.locator('h1')).toContainText('Switch to Staging');
      await confirmModalAction(page, 'Switch to Staging', 'Cancel', false);
      await expect(modal.locator('h1')).toHaveCount(0);
      
      // Verify toggles unchanged after cancel
      await expect(page.locator(SELECTORS.productionToggle)).toBeChecked();
      await expect(page.locator(SELECTORS.stagingToggle)).not.toBeChecked();
      
      // Click staging toggle again and proceed
      await page.locator(SELECTORS.stagingToggle).click();
      await confirmModalAction(page, 'Switch to Staging', 'Proceed');
      
      // Verify working notification appears
      await expectNotification(page, 'Working');
      
      // Wait for switch to complete
      await expectNotification(page, 'Switching');
    });
  });
});
