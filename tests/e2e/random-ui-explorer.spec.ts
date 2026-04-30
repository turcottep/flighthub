import { expect, test, type APIRequestContext, type Locator, type Page, type Response } from '@playwright/test';

type Airport = {
    code: string;
    city: string;
    name: string;
    country_code: string;
    latitude: number;
    longitude: number;
};

type Airline = {
    code: string;
    name: string;
};

type ReferenceData = {
    airports: Airport[];
    airlines: Airline[];
};

type ExploratoryIssue = {
    kind: string;
    message: string;
};

type Random = () => number;

const defaultSeed = 'flightjob-random-ui-explorer';
const randomSeed = process.env.RANDOM_SEED ?? defaultSeed;
const randomRuns = Number(process.env.RANDOM_RUNS ?? 15);
const searchTimeout = Number(process.env.RANDOM_SEARCH_TIMEOUT_MS ?? 60_000);
const flows = ['one-way', 'round-trip', 'open-jaw', 'nearby', 'multi-city'] as const;

test.describe.configure({ mode: 'serial' });

test('explores randomized trip search modes without crashing @tool', async ({ page, request }, testInfo) => {
    test.setTimeout(Math.max(180_000, randomRuns * 45_000));
    testInfo.annotations.push({ type: 'seed', description: randomSeed });
    testInfo.annotations.push({ type: 'runs', description: String(randomRuns) });

    const random = createRandom(randomSeed);
    const referenceData = await loadReferenceData(request);
    const issues: ExploratoryIssue[] = [];
    attachCrashGuards(page, issues);

    for (let run = 0; run < randomRuns; run++) {
        const flow = flows[run % flows.length];
        const viewport = random() < 0.25
            ? { width: 390, height: 900 }
            : { width: 1440, height: 1100 };

        await test.step(`run ${run + 1}: ${flow}`, async () => {
            await page.setViewportSize(viewport);
            const airportsResponse = page.waitForResponse((response) => response.url().endsWith('/api/airports') && response.status() === 200);
            const airlinesResponse = page.waitForResponse((response) => response.url().endsWith('/api/airlines') && response.status() === 200);

            await page.goto('/');
            await Promise.all([airportsResponse, airlinesResponse]);
            await expect(page.getByRole('button', { name: /^Search$/ })).toBeVisible();

            if (flow === 'one-way') {
                await configureOneWay(page, referenceData, random);
                await maybePickRandomDate(page, /choose departure date/i, random);
                await maybeSelectAirline(page, referenceData.airlines, random);
                await submitSearch(page, '/api/trips/search/one-way');
            }

            if (flow === 'round-trip') {
                await configureRoundTrip(page, referenceData, random);
                await maybePickRandomDate(page, /choose departure date/i, random);
                await maybePickRandomDate(page, /choose return date/i, random);
                await maybeSelectAirline(page, referenceData.airlines, random);
                await submitSearch(page, '/api/trips/search/one-way');
            }

            if (flow === 'open-jaw') {
                await configureOpenJaw(page, referenceData, random);
                await maybePickRandomDate(page, /choose departure date/i, random);
                await maybePickRandomDate(page, /choose return date/i, random);
                await maybeSelectAirline(page, referenceData.airlines, random);
                await submitSearch(page, '/api/trips/search/one-way');
            }

            if (flow === 'nearby') {
                await configureNearby(page, referenceData, random);
                await maybePickRandomDate(page, /choose departure date/i, random);
                await submitSearch(page, '/api/trips/search/one-way-nearby');
            }

            if (flow === 'multi-city') {
                await configureMultiCity(page, referenceData, random);
                await maybeSelectAirline(page, referenceData.airlines, random);
                await submitSearch(page, '/api/trips/search/one-way');
            }

            await pokeResults(page, random);
            await assertUsable(page);
            expect(issues).toEqual([]);
        });
    }
});

async function loadReferenceData(request: APIRequestContext): Promise<ReferenceData> {
    const [airportsResponse, airlinesResponse] = await Promise.all([
        request.get('/api/airports'),
        request.get('/api/airlines'),
    ]);

    expect(airportsResponse.ok()).toBe(true);
    expect(airlinesResponse.ok()).toBe(true);

    const airportsPayload = await airportsResponse.json() as { data: Airport[] };
    const airlinesPayload = await airlinesResponse.json() as { data: Airline[] };
    const airports = airportsPayload.data.filter((airport) => (
        airport.code
        && airport.city
        && airport.name
        && Number.isFinite(Number(airport.latitude))
        && Number.isFinite(Number(airport.longitude))
    ));
    const airlines = airlinesPayload.data.filter((airline) => airline.code && airline.name);

    expect(airports.length).toBeGreaterThan(1);
    expect(airlines.length).toBeGreaterThan(0);

    return { airports, airlines };
}

function attachCrashGuards(page: Page, issues: ExploratoryIssue[]) {
    page.on('pageerror', (error) => {
        issues.push({ kind: 'pageerror', message: error.message });
    });
    page.on('console', (message) => {
        if (message.type() === 'error') {
            issues.push({ kind: 'console', message: message.text() });
        }
    });
    page.on('requestfailed', (request) => {
        issues.push({
            kind: 'requestfailed',
            message: `${request.method()} ${request.url()} ${request.failure()?.errorText ?? ''}`.trim(),
        });
    });
    page.on('response', (response) => {
        if (response.status() >= 500) {
            issues.push({
                kind: 'server',
                message: `${response.status()} ${response.request().method()} ${response.url()}`,
            });
        }
    });
}

async function configureOneWay(page: Page, data: ReferenceData, random: Random) {
    await page.getByRole('button', { name: 'One way' }).click();
    await expect(page.getByRole('button', { name: /choose return date/i })).toHaveCount(0);
    const [origin, destination] = pickDistinctAirports(data.airports, random, 2);

    await selectAirport(page, 0, origin, random);
    await selectAirport(page, 1, destination, random);
}

async function configureRoundTrip(page: Page, data: ReferenceData, random: Random) {
    await page.getByRole('button', { name: 'Round trip' }).click();
    await expect(page.getByRole('button', { name: /choose return date/i })).toBeVisible();
    const [origin, destination] = pickDistinctAirports(data.airports, random, 2);

    await selectAirport(page, 0, origin, random);
    await selectAirport(page, 1, destination, random);
}

async function configureOpenJaw(page: Page, data: ReferenceData, random: Random) {
    await page.getByRole('button', { name: 'Advanced' }).click();
    await ensureMultiLegCount(page, 2);
    const [origin, outboundDestination, returnOrigin, finalDestination] = pickDistinctAirports(data.airports, random, 4);

    await selectAirport(page, 0, origin, random);
    await selectAirport(page, 1, outboundDestination, random);
    await selectAirport(page, 2, returnOrigin, random);
    await selectAirport(page, 3, finalDestination, random);
}

async function configureNearby(page: Page, data: ReferenceData, random: Random) {
    await page.getByRole('button', { name: 'One way' }).click();
    await expect(page.getByRole('button', { name: /choose return date/i })).toHaveCount(0);
    const [origin, destination] = pickDistinctAirports(data.airports, random, 2);

    await selectAirport(page, 0, origin, random);
    await selectAirport(page, 1, destination, random);
    await page.getByLabel('Include nearby airports').check();
    const radiusInput = page.locator('.radius-field input');
    await exerciseInvalidRadius(page, radiusInput, random);
    await radiusInput.fill(String(randomInt(random, 5, 150) * 5));
}

async function configureMultiCity(page: Page, data: ReferenceData, random: Random) {
    await page.getByRole('button', { name: 'Advanced' }).click();

    const targetLegs = randomInt(random, 2, 5);
    await ensureMultiLegCount(page, targetLegs);

    if (targetLegs > 2 && random() < 0.35) {
        await page.getByRole('button', { name: /remove flight/i }).last().click();
        await page.getByRole('button', { name: /add flight/i }).click();
    }

    const airports = pickAirportSequence(data.airports, random, targetLegs + 1);

    for (let leg = 0; leg < targetLegs; leg++) {
        await selectAirport(page, leg * 2, airports[leg], random);
        await selectAirport(page, leg * 2 + 1, airports[leg + 1], random);
    }

    const dateButtons = await page.getByRole('button', { name: /choose departure date/i }).count();
    for (let index = 0; index < dateButtons; index++) {
        await maybePickRandomDate(page, /choose departure date/i, random, index);
    }
}

async function ensureMultiLegCount(page: Page, legCount: number) {
    while (await page.locator('.multi-city-leg').count() < legCount) {
        await page.getByRole('button', { name: /add flight/i }).click();
    }
}

async function maybeSelectAirline(page: Page, airlines: Airline[], random: Random) {
    if (random() < 0.45) {
        return;
    }

    const advancedToggle = page.getByRole('button', { name: /more options/i }).first();
    if (await advancedToggle.isVisible()) {
        await advancedToggle.click();
    }

    const airline = pick(airlines, random);
    const input = page.locator('.airline-select input').first();
    await input.waitFor({ state: 'visible' });
    await input.click();
    await input.fill(airline.code);
    const option = page.locator('.airline-dropdown button').filter({ hasText: airline.code }).first();
    await option.waitFor({ state: 'visible' });
    await option.click();
}

async function exerciseInvalidRadius(page: Page, radiusInput: Locator, random: Random) {
    const invalidRadius = pick(['0', '3', '698', '1001', '-5'], random);

    await radiusInput.fill(invalidRadius);
    await page.getByRole('button', { name: /^Search$/ }).click();
    await expect.poll(async () => radiusInput.evaluate((input: HTMLInputElement) => input.validity.valid)).toBe(false);
    await expect.poll(async () => radiusInput.evaluate((input: HTMLInputElement) => input.validationMessage.length)).toBeGreaterThan(0);
    await expect(page.locator('main.flight-app')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Search$/ })).toBeVisible();
}

async function selectAirport(page: Page, inputIndex: number, airport: Airport, random: Random) {
    const input = airportInput(page, inputIndex);
    const query = randomAirportQuery(airport, random);

    await input.waitFor({ state: 'visible' });
    await input.click();
    await input.fill(query);

    let option = page.locator('.airport-dropdown button').filter({ hasText: airport.code }).first();
    try {
        await option.waitFor({ state: 'visible', timeout: 4_000 });
    } catch {
        await input.fill(airport.code);
        option = page.locator('.airport-dropdown button').filter({ hasText: airport.code }).first();
        await option.waitFor({ state: 'visible' });
    }

    await option.click();
    await expect(input).toHaveValue(new RegExp(escapeRegExp(airport.code)));
}

async function maybePickRandomDate(page: Page, label: RegExp, random: Random, triggerIndex = 0) {
    const trigger = page.getByRole('button', { name: label }).nth(triggerIndex);
    if (!await trigger.isVisible().catch(() => false)) {
        return;
    }

    await trigger.click();
    const dayButtons = page.locator('.rdp-day_button:not([disabled])');
    const count = await dayButtons.count();

    if (count > 0) {
        await dayButtons.nth(randomInt(random, 0, Math.min(count - 1, 20))).click();
    }

    await page.keyboard.press('Escape');
}

async function submitSearch(page: Page, expectedPath: string): Promise<Response> {
    const responsePromise = page.waitForResponse((response) => {
        const url = new URL(response.url());

        return response.request().method() === 'GET'
            && url.pathname === expectedPath;
    }, { timeout: searchTimeout });

    await page.getByRole('button', { name: /^Search$/ }).click();
    const response = await responsePromise;
    expect(response.status()).toBeLessThan(400);
    await waitForSearchToSettle(page);
    await expect(page.locator('#results')).toBeVisible();

    return response;
}

async function pokeResults(page: Page, random: Random) {
    if (await page.locator('.fare-tabs').isVisible().catch(() => false)) {
        for (const label of shuffle(['Price', 'Time'], random)) {
            const tab = page.getByRole('button', { name: new RegExp(label, 'i') }).first();
            if (await tab.isVisible() && !await tab.evaluate((element) => element.classList.contains('active'))) {
                await tab.click();
                await waitForSearchToSettle(page);
            }
        }
    }

    const next = page.getByRole('button', { name: 'Next' });
    if (await next.isVisible().catch(() => false) && await next.isEnabled()) {
        const responsePromise = waitForAnySearch(page);
        await next.click();
        const response = await responsePromise;
        expect(response.status()).toBeLessThan(500);
        await waitForSearchToSettle(page);

        const previous = page.getByRole('button', { name: 'Previous' });
        if (await previous.isVisible().catch(() => false) && await previous.isEnabled()) {
            const previousResponse = waitForAnySearch(page);
            await previous.click();
            expect((await previousResponse).status()).toBeLessThan(500);
            await waitForSearchToSettle(page);
        }
    }

    const detailsButtons = page.getByRole('button', { name: /show flight details/i });
    const detailsCount = await detailsButtons.count();
    if (detailsCount > 0) {
        const button = detailsButtons.nth(randomInt(random, 0, Math.min(detailsCount - 1, 2)));
        await button.click();
        await expect(page.locator('.flight-details').first()).toBeVisible();
        await page.getByRole('button', { name: /hide flight details/i }).first().click();
    }

    const selectButtons = page.locator('button.select-button:not([disabled])');
    const selectCount = await selectButtons.count();
    if (selectCount > 0) {
        await selectButtons.nth(randomInt(random, 0, Math.min(selectCount - 1, 2))).click();
    }
}

function waitForAnySearch(page: Page) {
    return page.waitForResponse((response) => {
        const url = new URL(response.url());

        return response.request().method() === 'GET'
            && url.pathname.startsWith('/api/trips/search/');
    }, { timeout: searchTimeout });
}

async function waitForSearchToSettle(page: Page) {
    await expect(page.locator('.loader')).toHaveCount(0, { timeout: searchTimeout });
    await expect(page.getByRole('button', { name: /^Search$/ })).toBeVisible();
}

async function assertUsable(page: Page) {
    await expect(page.locator('main.flight-app')).toBeVisible();
    await expect(page.getByRole('button', { name: /^Search$/ })).toBeVisible();
    await expect(page.locator('#results')).toBeVisible();
}

function airportInput(page: Page, index: number) {
    return page.locator('.airport-field').nth(index).locator('input');
}

function pickDistinctAirports(airports: Airport[], random: Random, count: number) {
    const picked: Airport[] = [];
    const seen = new Set<string>();

    while (picked.length < count) {
        const airport = pick(airports, random);
        if (!seen.has(airport.code)) {
            picked.push(airport);
            seen.add(airport.code);
        }
    }

    return picked;
}

function pickAirportSequence(airports: Airport[], random: Random, count: number) {
    const picked: Airport[] = [];

    while (picked.length < count) {
        const airport = pick(airports, random);
        const previous = picked[picked.length - 1];

        if (!previous || previous.code !== airport.code) {
            picked.push(airport);
        }
    }

    return picked;
}

function randomAirportQuery(airport: Airport, random: Random) {
    const roll = random();

    if (roll < 0.45) {
        return airport.city;
    }

    if (roll < 0.7) {
        return airport.name.split(/[ ,/-]/).filter(Boolean)[0] ?? airport.code;
    }

    return airport.code;
}

function pick<T>(items: T[], random: Random) {
    return items[randomInt(random, 0, items.length - 1)];
}

function shuffle<T>(items: T[], random: Random) {
    const next = [...items];

    for (let index = next.length - 1; index > 0; index--) {
        const swapIndex = randomInt(random, 0, index);
        [next[index], next[swapIndex]] = [next[swapIndex], next[index]];
    }

    return next;
}

function randomInt(random: Random, min: number, max: number) {
    return Math.floor(random() * (max - min + 1)) + min;
}

function createRandom(seed: string): Random {
    let state = 2166136261;

    for (let index = 0; index < seed.length; index++) {
        state ^= seed.charCodeAt(index);
        state = Math.imul(state, 16777619);
    }

    return () => {
        state += 0x6D2B79F5;
        let value = state;
        value = Math.imul(value ^ value >>> 15, value | 1);
        value ^= value + Math.imul(value ^ value >>> 7, value | 61);

        return ((value ^ value >>> 14) >>> 0) / 4294967296;
    };
}

function escapeRegExp(value: string) {
    return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
