import { expect, test, type Page } from '@playwright/test';

function airportInput(page: Page, index: number) {
    return page.locator('.airport-field').nth(index).locator('input');
}

async function selectAirport(page: Page, inputIndex: number, query: string, code: string) {
    const input = airportInput(page, inputIndex);

    await input.click();
    await input.fill(query);
    await page.getByRole('button', { name: new RegExp(`${code}.*${query}`, 'i') }).click();
}

async function waitForSearch(page: Page, path: string) {
    return page.waitForResponse((response) => {
        const url = new URL(response.url());

        return response.request().method() === 'GET'
            && url.pathname === path
            && response.status() === 200;
    });
}

test.beforeEach(async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('heading', { name: /build one-way and round-trip flight itineraries/i })).toBeVisible();
    await expect(airportInput(page, 0)).toHaveValue(/Montreal.*YUL/i);
    await expect(airportInput(page, 1)).toHaveValue(/Vancouver.*YVR/i);
});

test('searches one-way trips through the UI', async ({ page }) => {
    await page.getByRole('button', { name: 'One way' }).click();
    await expect(page.getByRole('button', { name: /choose return date/i })).toBeDisabled();

    const searchResponse = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /^Search$/ }).click();
    const response = await searchResponse;
    const url = new URL(response.url());

    expect(url.searchParams.get('origin')).toBe('YUL');
    expect(url.searchParams.get('destination')).toBe('YVR');
    expect(url.searchParams.get('max_stops')).toBe('0');
    expect(url.searchParams.has('return_date')).toBe(false);

    await expect.poll(async () => page.locator('.itinerary-card').count()).toBeGreaterThanOrEqual(2);
    await expect(page.locator('.itinerary-card').first()).toContainText('YUL');
    await expect(page.locator('.itinerary-card').first()).toContainText('YVR');
    await expect(page.locator('.itinerary-card').first()).toContainText('$199.99');
    await expect(page.locator('.itinerary-card').first()).toContainText('Nonstop');
});

test('searches round trips and renders outbound and return legs', async ({ page }) => {
    const searchResponse = waitForSearch(page, '/api/trips/search/round-trip');
    await page.getByRole('button', { name: /^Search$/ }).click();
    const response = await searchResponse;
    const url = new URL(response.url());

    expect(url.searchParams.get('origin')).toBe('YUL');
    expect(url.searchParams.get('destination')).toBe('YVR');
    expect(url.searchParams.has('return_date')).toBe(true);

    const firstCard = page.locator('.itinerary-card').first();
    await expect(firstCard).toBeVisible();
    await expect(firstCard).toContainText('Outbound');
    await expect(firstCard).toContainText('Return');
    await expect(firstCard).toContainText('YUL');
    await expect(firstCard).toContainText('YVR');
});

test('expands flight details with airport-local times', async ({ page }) => {
    await page.getByRole('button', { name: 'One way' }).click();

    const searchResponse = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /^Search$/ }).click();
    await searchResponse;

    const firstCard = page.locator('.itinerary-card').first();
    await firstCard.getByRole('button', { name: /show flight details/i }).click();

    await expect(firstCard).toContainText('Flight WS701');
    await expect(firstCard).toContainText('Montreal (YUL)');
    await expect(firstCard).toContainText('Vancouver (YVR)');
    await expect(firstCard).toContainText(/9:15\s*(a\.m\.|AM)/i);
    await expect(firstCard).toContainText(/12:30\s*(p\.m\.|PM)/i);

    await firstCard.getByRole('button', { name: /hide flight details/i }).click();
    await expect(firstCard).not.toContainText('Flight WS701');
});

test('filters and selects airports', async ({ page }) => {
    await selectAirport(page, 1, 'Los Angeles', 'LAX');

    await expect(airportInput(page, 1)).toHaveValue(/Los Angeles.*LAX/i);
});

test('shows an empty state for valid routes with no matching trips', async ({ page }) => {
    await page.getByRole('button', { name: 'One way' }).click();
    await selectAirport(page, 1, 'Los Angeles', 'LAX');

    const searchResponse = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /^Search$/ }).click();
    await searchResponse;

    await expect(page.getByText('No matching trips')).toBeVisible();
    await expect(page.locator('.itinerary-card')).toHaveCount(0);
});

test('sort tabs submit new searches with the selected sort', async ({ page }) => {
    await page.getByRole('button', { name: 'One way' }).click();

    const initialSearch = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /^Search$/ }).click();
    await initialSearch;

    const durationSearch = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /shortest/i }).click();
    const durationResponse = await durationSearch;
    expect(new URL(durationResponse.url()).searchParams.get('sort')).toBe('duration');
    await expect(page.getByText('Sorted by shortest duration')).toBeVisible();

    const departureSearch = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /flexible/i }).click();
    const departureResponse = await departureSearch;
    expect(new URL(departureResponse.url()).searchParams.get('sort')).toBe('departure');
    await expect(page.getByText('Sorted by earliest departure')).toBeVisible();
});
