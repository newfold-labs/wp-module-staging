/**
 * Staging Module Test Helpers for Playwright
 */
import { expect } from '@playwright/test';
import { join, dirname } from 'path';
import { fileURLToPath, pathToFileURL } from 'url';
import { readFileSync } from 'fs';

// ES module equivalent of __dirname
const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

// Resolve plugin directory from PLUGIN_DIR env var (set by playwright.config.mjs) or process.cwd()
const pluginDir = process.env.PLUGIN_DIR || process.cwd();

// Build path to plugin helpers (.mjs extension for ES module compatibility)
const finalHelpersPath = join(pluginDir, 'tests/playwright/helpers/index.mjs');

// Import plugin helpers using file:// URL
const helpersUrl = pathToFileURL(finalHelpersPath).href;
const pluginHelpers = await import(helpersUrl);

// Destructure plugin helpers
export const { auth, wordpress, newfold, a11y, utils } = pluginHelpers;

// Get plugin ID from environment
export const pluginId = process.env.PLUGIN_ID || 'bluehost';

// Load fixtures
const fixturesPath = join(__dirname, '../fixtures');

export const loadFixture = (name) => {
  return JSON.parse(readFileSync(join(fixturesPath, `${name}.json`), 'utf8'));
};

// Pre-load all fixtures
export const FIXTURES = {
  stagingInit: loadFixture('stagingInit'),
  stagingClone: loadFixture('stagingClone'),
  stagingDelete: loadFixture('stagingDelete'),
  stagingCreate: loadFixture('stagingCreate'),
  stagingSwitch: loadFixture('stagingSwitch'),
  stagingStaging: loadFixture('stagingStaging'),
  stagingDeploy: loadFixture('stagingDeploy'),
};

// Common selectors
export const SELECTORS = {
  // Page containers
  stagingPage: '.newfold-staging-page',
  stagingProd: '.newfold-staging-prod',
  stagingStaging: '.newfold-staging-staging',
  
  // Toggle switches
  productionToggle: '#newfold-production-toggle',
  stagingToggle: '#newfold-staging-toggle',
  productionToggleLabel: 'label[for="newfold-production-toggle"]',
  stagingToggleLabel: 'label[for="newfold-staging-toggle"]',
  
  // Buttons
  cloneButton: '#staging-clone-button',
  deleteButton: '#staging-delete-button',
  deployButton: '#staging-deploy-button',
  createButton: '#staging-create-button',
  
  // Modal elements
  modal: '.nfd-modal',
  modalTitle: '.nfd-modal h1',
  modalPrimaryButton: '.nfd-modal .nfd-button--primary',
  modalErrorButton: '.nfd-modal .nfd-button--error',
  
  // Notification elements
  notifications: '.nfd-notifications',
  notificationError: '.nfd-notification--error',
  notificationButton: '.nfd-notification button',
};

// URL patterns for API interception
export const API_PATTERNS = {
  staging: /newfold-staging.*v1.*staging/,
  clone: /newfold-staging.*v1.*staging.*clone/,
  deploy: /newfold-staging.*v1.*staging.*deploy/,
  switchToStaging: /newfold-staging.*v1.*staging.*switch-to.*env.*staging/,
};

/**
 * Navigate to staging page
 * @param {import('@playwright/test').Page} page
 */
export async function navigateToStagingPage(page) {
  await page.goto(`/wp-admin/admin.php?page=${pluginId}#/settings/staging`);
}

/**
 * Setup route to mock staging API for initial data load
 * @param {import('@playwright/test').Page} page
 * @param {Object} fixture - The fixture data to return
 */
export async function mockStagingApi(page, fixture) {
  // Use API_PATTERNS.staging to match both /wp-json/ and index.php?rest_route= URLs
  await page.route(API_PATTERNS.staging, async (route) => {
    const method = route.request().method();
    const url = route.request().url();
    
    // Handle clone endpoint
    if (url.includes('clone') && method === 'POST') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingClone),
      });
      return;
    }
    
    // Handle deploy endpoint
    if (url.includes('deploy') && method === 'POST') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingDeploy),
      });
      return;
    }
    
    // Handle switch-to endpoint
    if (url.includes('switch-to')) {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingSwitch),
      });
      return;
    }
    
    // Default GET request - return provided fixture
    if (method === 'GET') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixture),
      });
      return;
    }
    
    // For other POST requests (like create)
    if (method === 'POST') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingCreate),
      });
      return;
    }
    
    // For DELETE requests (staging delete)
    if (method === 'DELETE') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingDelete),
      });
      return;
    }
    
    await route.continue();
  });
}

/**
 * Setup route for production environment tests with full mock chain
 * @param {import('@playwright/test').Page} page
 */
export async function setupProductionEnvironmentMocks(page) {
  let currentFixture = FIXTURES.stagingInit;
  
  // Use API_PATTERNS.staging to match both /wp-json/ and index.php?rest_route= URLs
  await page.route(API_PATTERNS.staging, async (route) => {
    const method = route.request().method();
    const url = route.request().url();
    
    // Handle clone endpoint
    if (url.includes('clone') && method === 'POST') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingClone),
      });
      return;
    }
    
    // Handle deploy endpoint
    if (url.includes('deploy') && method === 'POST') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingDeploy),
      });
      return;
    }
    
    // Handle switch-to endpoint - add delay to allow Working state to be visible
    if (url.includes('switch-to')) {
      await new Promise(resolve => setTimeout(resolve, 250));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingSwitch),
      });
      return;
    }
    
    // Handle DELETE (delete staging)
    if (method === 'DELETE') {
      // Update fixture to simulate staging deleted
      currentFixture = { ...FIXTURES.stagingDelete, stagingExists: false };
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingDelete),
      });
      return;
    }
    
    // Handle POST (create staging) - add delay to allow is-thinking state to be visible
    if (method === 'POST' && !url.includes('clone') && !url.includes('deploy')) {
      currentFixture = FIXTURES.stagingCreate;
      // Add delay to allow is-thinking class to be observed (250ms is sufficient)
      await new Promise(resolve => setTimeout(resolve, 250));
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(FIXTURES.stagingCreate),
      });
      return;
    }
    
    // Default GET request
    if (method === 'GET') {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(currentFixture),
      });
      return;
    }
    
    await route.continue();
  });
}

/**
 * Wait for staging page to be ready
 * @param {import('@playwright/test').Page} page
 */
export async function waitForStagingPage(page) {
  await page.waitForSelector(SELECTORS.stagingPage, { timeout: 10000 });
}

/**
 * Close all open snackbar notifications
 * @param {import('@playwright/test').Page} page
 */
export async function closeAllNotifications(page) {
  const notificationButtons = page.locator(SELECTORS.notificationButton);
  const count = await notificationButtons.count();
  for (let i = 0; i < count; i++) {
    const button = notificationButtons.nth(0); // Always get first as others shift
    if (await button.isVisible()) {
      await button.click();
      await page.waitForTimeout(50); // Reduced from 100ms - just enough for UI to settle
    }
  }
}

/**
 * Click a modal action button
 * @param {import('@playwright/test').Page} page
 * @param {string} buttonText - Text of the button to click
 * @param {boolean} isPrimary - Whether it's the primary or error button
 */
export async function clickModalButton(page, buttonText, isPrimary = true) {
  const selector = isPrimary ? SELECTORS.modalPrimaryButton : SELECTORS.modalErrorButton;
  const button = page.locator(selector).filter({ hasText: buttonText });
  await expect(button).toBeVisible();
  await button.click();
}

/**
 * Combined setup: login, navigate to staging page, and wait for it to load
 * @param {import('@playwright/test').Page} page
 */
export async function setupAndNavigate(page) {
  await auth.loginToWordPress(page);
  await navigateToStagingPage(page);
  await waitForStagingPage(page);
}

/**
 * Assert that a notification with specific text is visible
 * Uses filter to handle multiple notification containers on the page
 * @param {import('@playwright/test').Page} page
 * @param {string} text - The text to expect in the notification
 */
export async function expectNotification(page, text) {
  await expect(
    page.locator(SELECTORS.notifications).filter({ hasText: text })
  ).toContainText(text);
}

/**
 * Verify modal title and click action button
 * @param {import('@playwright/test').Page} page
 * @param {string} expectedTitle - Expected modal title text
 * @param {string} buttonText - Button text to click
 * @param {boolean} isPrimary - Whether it's the primary (true) or cancel/error (false) button
 */
export async function confirmModalAction(page, expectedTitle, buttonText, isPrimary = true) {
  const modal = page.locator(SELECTORS.modal);
  await expect(modal.locator('h1')).toContainText(expectedTitle);
  await clickModalButton(page, buttonText, isPrimary);
}
