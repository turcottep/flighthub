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

async function waitForSearchStatus(page: Page, path: string, status: number) {
    return page.waitForResponse((response) => {
        const url = new URL(response.url());

        return response.request().method() === 'GET'
            && url.pathname === path
            && response.status() === status;
    });
}

test.beforeEach(async ({ page }) => {
    await page.goto('/');
    await expect(page.getByRole('button', { name: /^Search$/ })).toBeVisible();
    await expect(airportInput(page, 0)).toHaveValue(/Montreal.*YUL/i);
    await expect(airportInput(page, 1)).toHaveValue(/Vancouver.*YVR/i);
});

test('searches one-way trips through the UI', async ({ page }) => {
    await page.getByRole('button', { name: 'One way' }).click();
    await expect(page.getByRole('button', { name: /choose return date/i })).toHaveCount(0);

    const searchResponse = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /^Search$/ }).click();
    const response = await searchResponse;
    const url = new URL(response.url());

    expect(url.searchParams.get('origin')).toBe('YUL');
    expect(url.searchParams.get('destination')).toBe('YVR');
    expect(url.searchParams.has('max_stops')).toBe(false);
    expect(url.searchParams.has('return_date')).toBe(false);

    await expect.poll(async () => page.locator('.itinerary-card').count()).toBeGreaterThanOrEqual(2);
    await expect(page.locator('.itinerary-card').first()).toContainText('YUL');
    await expect(page.locator('.itinerary-card').first()).toContainText('YVR');
});

test('paginates one-way trip results with a stable search session', async ({ page }) => {
    await page.getByRole('button', { name: 'One way' }).click();

    const firstPageResponse = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /^Search$/ }).click();
    const firstPage = await firstPageResponse;
    const firstPayload = await firstPage.json();
    const pagination = firstPayload.meta.pagination;
    const searchId = pagination.search_id;
    const firstPageCount = Math.min(pagination.per_page, pagination.total);
    const secondPageCount = Math.min(pagination.per_page, pagination.total - pagination.per_page);

    expect(pagination.total_pages).toBeGreaterThan(1);
    await expect(page.locator('.itinerary-card')).toHaveCount(firstPageCount);
    await expect(page.getByText(`Page 1 of ${pagination.total_pages}`)).toBeVisible();

    const secondPageResponse = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: 'Next' }).click();
    const secondPage = await secondPageResponse;
    const secondUrl = new URL(secondPage.url());

    expect(secondUrl.searchParams.get('search_id')).toBe(searchId);
    expect(secondUrl.searchParams.get('page')).toBe('2');
    expect(secondUrl.searchParams.has('origin')).toBe(false);

    await expect(page.locator('.itinerary-card')).toHaveCount(secondPageCount);
    await expect(page.getByText(`Page 2 of ${pagination.total_pages}`)).toBeVisible();
});

test('shows an expired-results message when a search session expires', async ({ page }) => {
    await page.getByRole('button', { name: 'One way' }).click();

    const firstPageResponse = waitForSearch(page, '/api/trips/search/one-way');
    await page.getByRole('button', { name: /^Search$/ }).click();
    const firstPage = await firstPageResponse;
    const firstPayload = await firstPage.json();
    const searchId = firstPayload.meta.pagination.search_id;

    await page.request.get(`/api/trips/search/one-way?search_id=${searchId}&page=2&per_page=10`);
    await page.evaluate(async (id) => {
        await fetch(`/__e2e/expire-search-session/${id}`, { method: 'POST' });
    }, searchId);

    const expiredResponse = waitForSearchStatus(page, '/api/trips/search/one-way', 410);
    await page.getByRole('button', { name: 'Next' }).click();
    await expiredResponse;

    await expect(page.getByRole('heading', { name: 'Results expired' })).toBeVisible();
    await expect(page.getByText('These search results expired. Run the search again to see current trips.')).toBeVisible();
});

test('searches round trips and renders outbound and return legs', async ({ page }) => {
    const outboundResponse = waitForOneWaySearch(page, 'YUL', 'YVR');
    const returnResponse = waitForOneWaySearch(page, 'YVR', 'YUL');
    await page.getByRole('button', { name: /^Search$/ }).click();
    const [outbound, returnTrip] = await Promise.all([outboundResponse, returnResponse]);
    const outboundUrl = new URL(outbound.url());
    const returnUrl = new URL(returnTrip.url());

    expect(outboundUrl.searchParams.get('departure_date')).toBeTruthy();
    expect(returnUrl.searchParams.get('departure_date')).toBeTruthy();

    await expect(page.getByRole('heading', { name: /Choose flight 1: Montreal to Vancouver/ })).toBeVisible();
    await expect(page.locator('.leg-option-section').nth(0)).toContainText('Outbound');
    await page.locator('.itinerary-card').first().getByRole('button', { name: /Select flight/ }).click();
    await page.getByRole('button', { name: /Next flight/ }).click();
    await expect(page.getByRole('heading', { name: /Choose flight 2: Vancouver to Montreal/ })).toBeVisible();
    await expect(page.locator('.leg-option-section').first()).toContainText('Return');
    await expect(page.locator('.itinerary-card').first()).toContainText('YUL');
    await expect(page.locator('.itinerary-card').first()).toContainText('YVR');
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

test('does not render round-trip time between outbound and return as a layover', async ({ page }) => {
    const outboundResponse = waitForOneWaySearch(page, 'YUL', 'YVR');
    const returnResponse = waitForOneWaySearch(page, 'YVR', 'YUL');
    await page.getByRole('button', { name: /^Search$/ }).click();
    await Promise.all([outboundResponse, returnResponse]);

    const firstCard = page.locator('.itinerary-card').first();
    await firstCard.getByRole('button', { name: /show flight details/i }).click();

    await expect(firstCard.locator('.flight-details')).toBeVisible();
    await expect(firstCard.locator('.layover-row')).toHaveCount(0);
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
