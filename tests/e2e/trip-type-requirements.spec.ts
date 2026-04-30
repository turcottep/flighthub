import { expect, test, type Page } from '@playwright/test';

function airportInput(page: Page, index: number) {
    return page.locator('.airport-field').nth(index).locator('input');
}

async function selectAirport(page: Page, inputIndex: number, query: string, code: string) {
    const input = airportInput(page, inputIndex);

    await input.click();
    await input.fill(query);
    await page.locator('.airport-dropdown button').filter({ hasText: code }).first().click();
}

async function waitForOneWaySearch(page: Page, origin: string, destination: string) {
    return page.waitForResponse((response) => {
        const url = new URL(response.url());

        return response.request().method() === 'GET'
            && url.pathname === '/api/trips/search/one-way'
            && url.searchParams.get('origin') === origin
            && url.searchParams.get('destination') === destination
            && response.status() === 200;
    });
}

async function openAdvancedTripBuilder(page: Page, legCount = 2) {
    await page.getByRole('button', { name: 'Advanced' }).click();

    while (await page.locator('.multi-city-leg').count() < legCount) {
        await page.getByRole('button', { name: /add flight/i }).click();
    }
}

test.beforeEach(async ({ page }) => {
    const airportsResponse = page.waitForResponse((response) => response.url().endsWith('/api/airports') && response.status() === 200);
    const airlinesResponse = page.waitForResponse((response) => response.url().endsWith('/api/airlines') && response.status() === 200);

    await page.goto('/');
    await Promise.all([airportsResponse, airlinesResponse]);
    await expect(page.getByRole('button', { name: /^Search$/ })).toBeVisible();
});

test('supports open-jaw trip searches from the frontend', async ({ page }) => {
    await openAdvancedTripBuilder(page, 2);

    await expect(airportInput(page, 0)).toBeVisible();
    await expect(airportInput(page, 1)).toBeVisible();
    await expect(airportInput(page, 2)).toBeVisible();
    await expect(airportInput(page, 3)).toBeVisible();
    await selectAirport(page, 2, 'LAX', 'LAX');
    await selectAirport(page, 3, 'YUL', 'YUL');

    const outboundResponse = waitForOneWaySearch(page, 'YUL', 'YVR');
    const returnResponse = waitForOneWaySearch(page, 'LAX', 'YUL');

    await page.getByRole('button', { name: /^Search$/ }).click();
    await Promise.all([outboundResponse, returnResponse]);

    await expect(page.getByRole('heading', { name: /Choose flight 1/ })).toBeVisible();
    await expect(page.locator('.leg-option-section').nth(0)).toContainText('Flight 1');
    await page.locator('.itinerary-card').first().getByRole('button', { name: /Select flight/ }).click();
    await page.getByRole('button', { name: /Next flight/ }).click();
    await expect(page.getByRole('heading', { name: /Choose flight 2/ })).toBeVisible();
    await expect(page.locator('.leg-option-section').first()).toContainText('Flight 2');
});

test('supports multi-city trip searches from the frontend', async ({ page }) => {
    await openAdvancedTripBuilder(page, 2);

    await expect(page.getByRole('button', { name: /add flight/i })).toBeVisible();
    await expect(airportInput(page, 0)).toBeVisible();
    await expect(airportInput(page, 1)).toBeVisible();
    await expect(airportInput(page, 2)).toBeVisible();
    await expect(airportInput(page, 3)).toBeVisible();
    await selectAirport(page, 3, 'YYZ', 'YYZ');

    const firstLegResponse = waitForOneWaySearch(page, 'YUL', 'YVR');
    const secondLegResponse = waitForOneWaySearch(page, 'YVR', 'YYZ');

    await page.getByRole('button', { name: /^Search$/ }).click();
    await Promise.all([firstLegResponse, secondLegResponse]);

    await expect(page.getByRole('heading', { name: /Choose flight 1/ })).toBeVisible();
    await expect(page.locator('.leg-option-section').nth(0)).toContainText('Flight 1');
    await page.locator('.itinerary-card').first().getByRole('button', { name: /Select flight/ }).click();
    await page.getByRole('button', { name: /Next flight/ }).click();
    await expect(page.getByRole('heading', { name: /Choose flight 2/ })).toBeVisible();
    await expect(page.locator('.leg-option-section').first()).toContainText('Flight 2');
});
