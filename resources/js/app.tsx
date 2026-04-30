import React, { FormEvent, useEffect, useMemo, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { format } from 'date-fns';
import {
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronUp,
    ChevronsLeft,
    ChevronsRight,
    CirclePlus,
    Minus,
    Plane,
    Search,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';
import { Calendar } from './components/ui/calendar';
import { Popover, PopoverContent, PopoverTrigger } from './components/ui/popover';

type TripType = 'one_way' | 'round_trip' | 'multi_city';
type TripResultType = 'one_way' | 'round_trip' | 'open_jaw' | 'multi_city';
type SortKey = 'best' | 'price' | 'departure' | 'arrival' | 'duration';
type FareTabKey = 'best' | 'price' | 'time';
type LegSortKey = 'best' | 'price' | 'time';

type Airline = {
    code: string;
    name: string;
};

type Airport = {
    code: string;
    city_code: string;
    name: string;
    city: string;
    country_code: string;
    region_code: string;
    latitude: number;
    longitude: number;
    timezone: string;
};

type Flight = {
    airline: string;
    number: string;
    departure_airport: string;
    departure_time: string;
    arrival_airport: string;
    arrival_time: string;
    price: string;
};

type DataSet = {
    airlines: Airline[];
    airports: Airport[];
    flights: Flight[];
};

type TripSearchParams = {
    tripType: TripType;
    origin: string;
    destination: string;
    returnOrigin: string;
    finalDestination: string;
    departureDate: string;
    returnDate: string;
    airline: string;
    sort: SortKey;
    maxStops: number;
    includeNearbyAirports: boolean;
    nearbyRadiusKm: number;
    multiCityLegs: MultiCityLeg[];
};

type MultiCityLeg = {
    id: string;
    origin: string;
    destination: string;
    departureDate: string;
};

type Segment = {
    airline: Airline;
    flightNumber: string;
    departureAirport: Airport;
    arrivalAirport: Airport;
    departureAt: Date;
    arrivalAt: Date;
    durationMinutes: number;
    priceCents: number;
};

type ApiSegment = {
    airline: string;
    number: string;
    flight_number: string;
    departure_airport: string;
    arrival_airport: string;
    departure_at: string;
    arrival_at: string;
    departure_utc: string;
    arrival_utc: string;
    duration_minutes: number;
    price_cents: number;
};

type ApiItinerary = {
    type: 'one_way';
    origin: string;
    destination: string;
    departure_utc: string;
    arrival_utc: string;
    duration_minutes: number;
    stops: number;
    total_price_cents: number;
    segments: ApiSegment[];
};

type ApiRoundTrip = {
    type: 'round_trip';
    origin: string;
    destination: string;
    departure_date: string;
    return_date: string;
    legs: Array<{
        type: 'outbound' | 'return';
        origin: string;
        destination: string;
        departure_date: string;
        options: ApiItinerary[];
        option_count: number;
    }>;
};

type ApiOpenJaw = {
    type: 'open_jaw';
    origin: string;
    outbound_destination: string;
    return_origin: string;
    final_destination: string;
    departure_date: string;
    return_date: string;
    legs: Array<{
        type: 'outbound' | 'return';
        origin: string;
        destination: string;
        departure_date: string;
        options: ApiItinerary[];
        option_count: number;
    }>;
};

type ApiMultiCity = {
    type: 'multi_city';
    origin: string;
    destination: string;
    legs: Array<{
        type: string;
        origin: string;
        destination: string;
        departure_date: string;
        options: ApiItinerary[];
        option_count: number;
    }>;
};

type ApiTripOptionsLeg = {
    id: string;
    type: string;
    label?: string;
    origin: string;
    destination: string;
    departure_date: string;
    option_count?: number;
    options: ApiItinerary[];
};

type ApiTripOptions = {
    type: Exclude<TripResultType, 'one_way'>;
    legs: ApiTripOptionsLeg[];
};

type ItineraryLeg = {
    label: string;
    segments: Segment[];
};

type Itinerary = {
    id: string;
    type: TripResultType;
    origin: Airport;
    destination: Airport;
    departureAt: Date;
    arrivalAt: Date;
    durationMinutes: number;
    stops: number;
    priceCents: number;
    legs: ItineraryLeg[];
};

type TripOptionLeg = {
    id: string;
    type: string;
    label: string;
    origin: Airport;
    destination: Airport;
    departureDate: string;
    options: Itinerary[];
    pagination: SearchPagination | null;
};

type TripOptionSet = {
    type: Exclude<TripResultType, 'one_way'>;
    legs: TripOptionLeg[];
};

type SearchPagination = {
    search_id: string;
    page: number;
    per_page: number;
    total: number;
    total_pages: number;
    has_previous: boolean;
    has_next: boolean;
    expires_at: string;
};

type ApiSearchResponse = {
    data: Array<ApiItinerary | ApiRoundTrip | ApiOpenJaw | ApiMultiCity> | ApiTripOptions;
    meta?: {
        pagination?: SearchPagination;
    };
};

type SearchResultSet = {
    results: Itinerary[];
    pagination: SearchPagination | null;
    tripOptions: TripOptionSet | null;
};

type TripOptionLegRequest = {
    id: string;
    type: string;
    label: string;
    origin: string;
    destination: string;
    departureDate: string;
};

const emptyData: DataSet = {
    airlines: [],
    airports: [],
    flights: [],
};
const dataSource = (import.meta.env.VITE_FLIGHT_DATA_SOURCE ?? 'backend') as 'backend' | 'mock';
const useBackend = dataSource !== 'mock';
const MAX_RESULTS = 100;
const DEFAULT_MAX_STOPS = 5;
const PAGE_SIZE = 5;
const MIN_LAYOVER_MINUTES = 60;
const MAX_DURATION_MINUTES = 36 * 60;
const MOCK_SEARCH_TTL_MINUTES = 5;
const AIRPORT_SEARCH_PRIORITY = [
    'YUL',
    'YVR',
    'YYZ',
    'LAS',
    'LAX',
    'JFK',
    'LGA',
    'EWR',
    'SFO',
    'ORD',
    'MIA',
    'YQB',
    'YOW',
    'YYC',
    'YEG',
    'YWG',
    'YHZ',
].reduce<Map<string, number>>((map, code, index) => map.set(code, index), new Map());

let airportByCode = new Map(emptyData.airports.map((airport) => [airport.code, airport]));
let airlineByCode = new Map(emptyData.airlines.map((airline) => [airline.code, airline]));
let flightsByOrigin = emptyData.flights.reduce<Map<string, Flight[]>>((map, flight) => {
    const flights = map.get(flight.departure_airport) ?? [];
    flights.push(flight);
    map.set(flight.departure_airport, flights);
    return map;
}, new Map());

let airportOptions = [...emptyData.airports].sort((left, right) => {
    return `${left.city} ${left.code}`.localeCompare(`${right.city} ${right.code}`);
});

const todayIso = new Date().toISOString().slice(0, 10);
const defaultDepartureDate = addDays(todayIso, 7);
const defaultReturnDate = addDays(todayIso, 14);

function App() {
    const [referenceData, setReferenceData] = useState<DataSet>(emptyData);
    const [search, setSearch] = useState<TripSearchParams>({
        tripType: 'round_trip',
        origin: 'YUL',
        destination: 'YVR',
        returnOrigin: 'YVR',
        finalDestination: 'YUL',
        departureDate: defaultDepartureDate,
        returnDate: defaultReturnDate,
        airline: '',
        sort: 'best',
        maxStops: DEFAULT_MAX_STOPS,
        includeNearbyAirports: false,
        nearbyRadiusKm: 50,
        multiCityLegs: [
            {
                id: 'leg-1',
                origin: 'YUL',
                destination: 'YVR',
                departureDate: defaultDepartureDate,
            },
            {
                id: 'leg-2',
                origin: 'YVR',
                destination: 'YYZ',
                departureDate: addDays(defaultDepartureDate, 3),
            },
        ],
    });
    const [submittedSearch, setSubmittedSearch] = useState<TripSearchParams>(search);
    const [results, setResults] = useState<Itinerary[]>([]);
    const [isSearching, setIsSearching] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [hasSubmittedSearch, setHasSubmittedSearch] = useState(false);
    const [activeFareTab, setActiveFareTab] = useState<FareTabKey>('best');
    const [expandedId, setExpandedId] = useState<string | null>(null);
    const [pagination, setPagination] = useState<SearchPagination | null>(null);
    const [tripOptions, setTripOptions] = useState<TripOptionSet | null>(null);
    const [selectedOptionIds, setSelectedOptionIds] = useState<Record<string, string>>({});

    useMemo(() => {
        airportByCode = new Map(referenceData.airports.map((airport) => [airport.code, airport]));
        airlineByCode = new Map(referenceData.airlines.map((airline) => [airline.code, airline]));
        flightsByOrigin = referenceData.flights.reduce<Map<string, Flight[]>>((map, flight) => {
            const flights = map.get(flight.departure_airport) ?? [];
            flights.push(flight);
            map.set(flight.departure_airport, flights);
            return map;
        }, new Map());
        airportOptions = [...referenceData.airports].sort((left, right) => {
            return `${left.city} ${left.code}`.localeCompare(`${right.city} ${right.code}`);
        });
    }, [referenceData]);

    useEffect(() => {
        let cancelled = false;

        async function loadReferenceData() {
            try {
                if (!useBackend) {
                    const module = await import('../../data/generated/trip_data_full.json');

                    if (!cancelled) {
                        setReferenceData(module.default as DataSet);
                    }

                    return;
                }

                const [airports, airlines] = await Promise.all([
                    fetchJson<{ data: Airport[] }>('/api/airports'),
                    fetchJson<{ data: Airline[] }>('/api/airlines'),
                ]);

                if (!cancelled) {
                    setReferenceData({
                        airports: airports.data,
                        airlines: airlines.data,
                        flights: [],
                    });
                }
            } catch (error) {
                if (!cancelled) {
                    setError(error instanceof Error ? error.message : 'Could not load airport data.');
                }
            }
        }

        void loadReferenceData();

        return () => {
            cancelled = true;
        };
    }, []);

    function updateSearch(next: Partial<TripSearchParams>) {
        setSearch((current) => ({ ...current, ...next }));
    }

    async function submitSearch(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setExpandedId(null);
        setActiveFareTab('best');
        await executeSearch(search);
    }

    async function executeSearch(params: TripSearchParams, page = 1, searchId?: string) {
        setIsSearching(true);
        setError(null);

        try {
            const resultSet = useBackend
                ? await searchTripsFromBackend(params, page, searchId)
                : searchTrips(params, page);
            setSubmittedSearch(params);
            setResults(resultSet.results);
            setPagination(resultSet.pagination);
            setTripOptions(resultSet.tripOptions);
            setSelectedOptionIds({});
            setHasSubmittedSearch(true);
        } catch (error) {
            setResults([]);
            setPagination(null);
            setTripOptions(null);
            setSelectedOptionIds({});
            setError(error instanceof SearchExpiredError
                ? 'These search results expired. Run the search again to see current trips.'
                : error instanceof Error ? error.message : 'Search failed.');
            setHasSubmittedSearch(true);
        } finally {
            setIsSearching(false);
        }
    }

    function updateResultsSort(sort: SortKey, fareTab: FareTabKey) {
        const nextSearch = { ...submittedSearch, sort };
        setSubmittedSearch(nextSearch);
        setSearch((current) => ({ ...current, sort }));
        setActiveFareTab(fareTab);
        setExpandedId(null);
        setResults((current) => sortItineraries(current, sort));
    }

    function changeResultsPage(page: number) {
        if (!pagination) {
            return;
        }

        setExpandedId(null);
        void executeSearch(submittedSearch, page, pagination.search_id).then(scrollResultsIntoView);
    }

    function scrollResultsIntoView() {
        window.requestAnimationFrame(() => {
            document.getElementById('results')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    async function changeLegOptionPage(legId: string, page: number) {
        const leg = tripOptions?.legs.find((candidate) => candidate.id === legId);

        if (!leg?.pagination) {
            return;
        }

        setIsSearching(true);
        setError(null);
        setExpandedId(null);

        try {
            const nextLeg = await searchTripOptionLegFromBackend(
                {
                    id: leg.id,
                    type: leg.type,
                    label: leg.label,
                    origin: leg.origin.code,
                    destination: leg.destination.code,
                    departureDate: leg.departureDate,
                },
                submittedSearch,
                page,
                leg.pagination.search_id,
            );

            setTripOptions((current) => current
                ? {
                    ...current,
                    legs: current.legs.map((candidate) => (candidate.id === legId ? nextLeg : candidate)),
                }
                : current);
            setSelectedOptionIds((current) => ({
                ...current,
                [legId]: nextLeg.options.some((option) => option.id === current[legId]) ? current[legId] : '',
            }));
        } catch (error) {
            setError(error instanceof SearchExpiredError
                ? 'These search results expired. Run the search again to see current trips.'
                : error instanceof Error ? error.message : 'Search failed.');
        } finally {
            setIsSearching(false);
        }
    }

    return (
        <main className="flight-app">
            <Header />
            <section className="search-hero">
                <div className="shell">
                    <div className="hero-copy">
                        <h1>Build flight itineraries across simple and complex trips.</h1>
                        <p>Search one-way, round-trip, nearby airport, and advanced multi-leg routes.</p>
                    </div>
                    <SearchForm data={referenceData} search={search} onChange={updateSearch} onSubmit={submitSearch} />
                </div>
            </section>
            {(hasSubmittedSearch || isSearching) && (
                <ResultsView
                    error={error}
                    isSearching={isSearching}
                    params={submittedSearch}
                    results={results}
                    tripOptions={tripOptions}
                    selectedOptionIds={selectedOptionIds}
                    pagination={pagination}
                    activeFareTab={activeFareTab}
                    expandedId={expandedId}
                    onSortChange={updateResultsSort}
                    onPageChange={changeResultsPage}
                    onLegPageChange={changeLegOptionPage}
                    onSelectLegOption={(legId, optionId) => setSelectedOptionIds((current) => ({ ...current, [legId]: optionId }))}
                    onToggleDetails={(id) => setExpandedId((current) => (current === id ? null : id))}
                />
            )}
            <ApproachFooter />
        </main>
    );
}

function Header() {
    return (
        <header className="site-header">
            <div className="shell header-inner">
                <a className="brand" href="/">
                    <span className="brand-mark" aria-hidden="true">
                        <span />
                        <span />
                        <span />
                    </span>
                    <span><strong>Flight</strong>Job</span>
                </a>
            </div>
        </header>
    );
}

function ApproachFooter() {
    return (
        <footer className="approach-footer">
            <div className="shell approach-layout">
                <div className="approach-intro">
                    <p className="eyebrow">Implementation notes</p>
                    <h2>From the assignment prompt to a route planner.</h2>
                    <p>
                        The prompt starts with one-way and round-trip search on a small JSON shape. This implementation
                        keeps that format, then builds the production path around Laravel APIs, Postgres flight
                        templates, a React SPA, and a planner that can handle a much larger route network.
                    </p>
                </div>
                <div className="approach-notes" aria-label="Technical approach">
                    <section>
                        <span>01</span>
                        <h3>Required workflow</h3>
                        <p>
                            The app provides PHP web services, a no-refresh React interface, one-way search, round-trip
                            search, future-date validation, timezone-aware flight times, and local setup instructions.
                        </p>
                    </section>
                    <section>
                        <span>02</span>
                        <h3>Extra coverage</h3>
                        <p>
                            The submission also includes Postgres storage, API documentation, automated tests, sorting,
                            pagination, nearby airports, airline restriction, and advanced multi-leg trips.
                        </p>
                    </section>
                    <section>
                        <span>03</span>
                        <h3>Realistic dataset</h3>
                        <p>
                            The sample JSON is still supported, but it is too small to prove a search strategy. I used
                            OpenFlights airlines, airports, and routes, enriched airport metadata from OurAirports, and
                            generated deterministic schedules and fares for the assignment format.
                        </p>
                    </section>
                    <section>
                        <span>04</span>
                        <h3>Recurring templates</h3>
                        <p>
                            Flights are stored once with airport-local times because the prompt says every flight is
                            available every day. Search combines the template, requested date, and airport timezone.
                        </p>
                    </section>
                </div>
                <section className="flight-network-map" aria-labelledby="flight-network-map-title">
                    <div>
                        <p className="eyebrow">Dataset</p>
                        <h3 id="flight-network-map-title">Why the data was expanded.</h3>
                        <p>
                            The real network shape comes from OpenFlights: airline codes, airport codes, coordinates,
                            timezones, and directional route pairs. Country and region gaps are filled with OurAirports
                            metadata. Since those sources do not include daily timetables or fares, the generator creates
                            deterministic assignment data for each route: flight numbers, departure times, arrival
                            times, and prices. The routes are realistic; the schedules and fares are synthetic but
                            repeatable.
                        </p>
                    </div>
                    <img
                        alt="Full generated flight dataset route map"
                        height="620"
                        src="/flight-network-map.svg"
                        width="1200"
                    />
                </section>
                <section className="algorithm-evolution" aria-labelledby="algorithm-evolution-title">
                    <div>
                        <p className="eyebrow">Planner evolution</p>
                        <h3 id="algorithm-evolution-title">Why the final algorithm is route-first.</h3>
                        <p>
                            The first instinct is to search every dated flight option. That works on the sample file and
                            fails on realistic data. The final planner separates the problem into two stages: find good
                            airport paths first, then schedule actual flights along those paths.
                        </p>
                        <p>
                            The benchmark route is AAT to AAX on the full dataset. It is a remote-to-remote trip that
                            needs five flight segments. That route is useful because it punishes broad searches quickly.
                        </p>
                    </div>
                    <div className="algorithm-timeline">
                        <article>
                            <strong>Attempt 1</strong>
                            <h4>Search actual flights</h4>
                            <em>Out of memory</em>
                            <p>
                                It expanded real flight times immediately. Correct for easy routes, but it kept too many
                                partial itineraries alive on long connection chains.
                            </p>
                        </article>
                        <article>
                            <strong>Attempt 2</strong>
                            <h4>Add a destination score</h4>
                            <em>3.66s</em>
                            <p>
                                It ranked branches by how promising they looked for the destination. That helped, but it
                                was still doing schedule work before knowing whether the route pattern was good.
                            </p>
                        </article>
                        <article>
                            <strong>Attempt 3</strong>
                            <h4>Try timetable labels</h4>
                            <em>6.45s</em>
                            <p>
                                It added Pareto-style pruning over timed connections. That reduced some waste, but the
                                scan was still too broad for sparse remote routes.
                            </p>
                        </article>
                        <article>
                            <strong>Production</strong>
                            <h4>Search route patterns first</h4>
                            <em>0.87s</em>
                            <p>
                                It collapses recurring flights into weighted airport-to-airport edges, finds candidate
                                paths, then validates local times, UTC layovers, duration, and price only for those paths.
                            </p>
                        </article>
                        <article>
                            <strong>Production memory</strong>
                            <h4>Keep rows lazy</h4>
                            <em>0.75s, 96.5 MB</em>
                            <p>
                                The planner keeps the route graph compact and fetches full flight rows only after a route
                                pattern survives. That makes the hard route fit inside a normal 128 MB PHP request.
                            </p>
                        </article>
                    </div>
                </section>
                <section className="testing-summary" aria-labelledby="production-planner-title">
                    <div>
                        <p className="eyebrow">Production planner</p>
                        <h3 id="production-planner-title">What runs on each search.</h3>
                        <p>
                            Postgres stores durable data. The planner builds compact lookup structures for the request,
                            searches route patterns in memory, then materializes real dated flights only for the route
                            edges that survive.
                        </p>
                    </div>
                    <div className="testing-layers" aria-label="Production planner details">
                        <article>
                            <strong>Route graph</strong>
                            <h4>Airport edges first</h4>
                            <p>
                                Multiple flight templates on the same airport pair collapse into one weighted edge for
                                route search. This keeps the search focused on paths before schedules are expanded.
                            </p>
                        </article>
                        <article>
                            <strong>Lazy flight rows</strong>
                            <h4>Fetch after the path survives</h4>
                            <p>
                                Full flight rows are loaded only for candidate route edges. That avoids pulling the
                                whole flight table into a normal PHP request.
                            </p>
                        </article>
                        <article>
                            <strong>UTC validation</strong>
                            <h4>Correct elapsed time</h4>
                            <p>
                                User-facing times stay local to each airport. Layovers, overnight flights, DST, and total
                                duration are checked with UTC instants.
                            </p>
                        </article>
                    </div>
                </section>
                <section className="testing-summary" aria-labelledby="planner-details-title">
                    <div>
                        <p className="eyebrow">Routing behavior</p>
                        <h3 id="planner-details-title">The trip model.</h3>
                    </div>
                    <div className="testing-layers" aria-label="Planner behavior details">
                        <article>
                            <strong>Round trips</strong>
                            <h4>Two independent searches</h4>
                            <p>
                                A round trip is searched as outbound options plus return options. The user chooses each
                                leg separately, and each leg has its own pagination.
                            </p>
                        </article>
                        <article>
                            <strong>Airline restriction</strong>
                            <h4>Matching segments only</h4>
                            <p>
                                When an airline is selected, every returned segment must use that airline. If a mixed
                                carrier connection would be required, that route is not returned for the restricted search.
                            </p>
                        </article>
                        <article>
                            <strong>Pagination</strong>
                            <h4>Stored result order</h4>
                            <p>
                                The planner runs once, stores the ranked results briefly, and serves later pages from
                                that same ordered list. Multi-leg trips keep separate result lists per leg, so paging the
                                return flight does not change the outbound options.
                            </p>
                        </article>
                        <article>
                            <strong>Timezones</strong>
                            <h4>Visible local times</h4>
                            <p>
                                Results display local airport times for each segment so the itinerary reads like an
                                actual flight schedule.
                            </p>
                        </article>
                        <article>
                            <strong>Known limitation</strong>
                            <h4>Candidate-set ranking</h4>
                            <p>
                                Best, price, and time ranking happen after the planner has narrowed
                                the search to a bounded candidate set. That keeps searches fast, but it means the app
                                can miss a globally cheapest or fastest itinerary if that route pattern was pruned
                                earlier.
                            </p>
                        </article>
                    </div>
                </section>
                <section className="testing-summary" aria-labelledby="ui-design-title">
                    <div>
                        <p className="eyebrow">UI design</p>
                        <h3 id="ui-design-title">How the interface maps to the planner.</h3>
                        <p>
                            The frontend uses Vite, React, Tailwind CSS, and shadcn/ui. Vite keeps the local Laravel
                            workflow fast, React handles the stateful trip-builder interactions, Tailwind keeps the
                            FlightHub-inspired styling close to the markup, and shadcn/ui provides accessible primitives
                            for buttons, popovers, badges, and the date picker.
                        </p>
                    </div>
                    <div className="testing-layers" aria-label="UI design choices">
                        <article>
                            <strong>Vite + React</strong>
                            <h4>Fast SPA workflow</h4>
                            <p>
                                The app needs autocomplete, calendars, result sorting, pagination, expandable details,
                                and staged leg selection without full page reloads. React owns that UI state, while Vite
                                gives fast local hot reload during development.
                            </p>
                        </article>
                        <article>
                            <strong>Tailwind CSS</strong>
                            <h4>Custom travel styling</h4>
                            <p>
                                Tailwind keeps the layout and responsive rules in the app instead of adding a separate
                                design system layer. That made it easier to match the FlightHub reference while still
                                tuning the result cards for this planner.
                            </p>
                        </article>
                        <article>
                            <strong>shadcn/ui</strong>
                            <h4>Accessible primitives</h4>
                            <p>
                                The app uses shadcn-style components where primitives matter: buttons, badges, popovers,
                                and the calendar date picker. The visual layer is customized so those controls still fit
                                the FlightHub-inspired interface.
                            </p>
                        </article>
                        <article>
                            <strong>Trip workflow</strong>
                            <h4>Pick flights in stages</h4>
                            <p>
                                The UI is based on the FlightHub search pattern, but the result flow is adapted for
                                one-way, round-trip, and advanced multi-leg searches. Each leg can be sorted, paged,
                                and selected independently before the trip is reviewed.
                            </p>
                        </article>
                    </div>
                </section>
                <section className="testing-summary" aria-labelledby="testing-summary-title">
                    <div>
                        <p className="eyebrow">Automated testing</p>
                        <h3 id="testing-summary-title">How the behavior was verified.</h3>
                        <p>
                            The suite targets the failure modes that matter for this app: planner rules, API contracts,
                            browser workflows, pagination sessions, and full-data routes.
                        </p>
                    </div>
                    <div className="testing-layers" aria-label="Automated test coverage">
                        <article>
                            <strong>71 PHPUnit tests</strong>
                            <h4>Planner edge cases</h4>
                            <p>
                                These tests catch the mistakes that usually break flight search: invalid connections,
                                impossible dates, wrong return chronology, overnight arrivals, DST duration errors,
                                bad sorting, and trip modes returning the wrong shape.
                            </p>
                        </article>
                        <article>
                            <strong>API feature tests</strong>
                            <h4>Real request path</h4>
                            <p>
                                Exercises Laravel endpoints against database-backed flight templates, including bad
                                input, unknown airports, return-date rejection, pagination sessions, expiry, and lazy
                                loading by route.
                            </p>
                        </article>
                        <article>
                            <strong>Browser workflows</strong>
                            <h4>Actual UI flows</h4>
                            <p>
                                Clicks through the rendered app: trip modes, airport autocomplete, calendars, nearby
                                search, paging, selectable legs, and flight-detail expansion.
                            </p>
                        </article>
                        <article>
                            <strong>Seeded exploratory UI</strong>
                            <h4>Messy interactions</h4>
                            <p>
                                Randomized browser tests pick airports and airlines from the API, try invalid values,
                                recover, and fail on JavaScript errors, failed requests, or server errors.
                            </p>
                        </article>
                        <article>
                            <strong>Full-data checks</strong>
                            <h4>Scale regression guard</h4>
                            <p>
                                These checks run against the generated network, including hard remote-to-remote routes,
                                so changes that reintroduce broad scans, slow queries, or memory-heavy loading show up
                                before review.
                            </p>
                        </article>
                        <article>
                            <strong>Search sessions</strong>
                            <h4>Stable pagination</h4>
                            <p>
                                These tests make sure page navigation keeps the same ranked list, does not recompute the
                                search on every click, and shows a clear recovery message when stored results expire.
                            </p>
                        </article>
                    </div>
                </section>
            </div>
        </footer>
    );
}

type SearchFormProps = {
    data: DataSet;
    search: TripSearchParams;
    onChange: (next: Partial<TripSearchParams>) => void;
    onSubmit: (event: FormEvent<HTMLFormElement>) => void;
};

function SearchForm({ data, search, onChange, onSubmit }: SearchFormProps) {
    const [showAdvanced, setShowAdvanced] = useState(search.airline !== '');

    function updateMultiCityLeg(index: number, next: Partial<MultiCityLeg>) {
        onChange({
            multiCityLegs: search.multiCityLegs.map((leg, legIndex) => (
                legIndex === index ? { ...leg, ...next } : leg
            )),
        });
    }

    function addMultiCityLeg() {
        const lastLeg = search.multiCityLegs[search.multiCityLegs.length - 1];
        if (!lastLeg || search.multiCityLegs.length >= 5) {
            return;
        }

        onChange({
            multiCityLegs: [
                ...search.multiCityLegs,
                {
                    id: `leg-${Date.now()}`,
                    origin: lastLeg.destination,
                    destination: search.finalDestination,
                    departureDate: addDays(lastLeg.departureDate, 2),
                },
            ],
        });
    }

    function removeMultiCityLeg(index: number) {
        if (search.multiCityLegs.length <= 1) {
            return;
        }

        onChange({
            multiCityLegs: search.multiCityLegs.filter((_, legIndex) => legIndex !== index),
        });
    }

    return (
        <form id="search" className="search-panel" onSubmit={onSubmit}>
            <div className="form-topline">
                <div className="trip-toggle" aria-label="Trip type">
                    <button
                        className={search.tripType === 'round_trip' ? 'active' : ''}
                        type="button"
                        onClick={() => onChange({ tripType: 'round_trip' })}
                    >
                        Round trip
                    </button>
                    <button
                        className={search.tripType === 'one_way' ? 'active' : ''}
                        type="button"
                        onClick={() => onChange({ tripType: 'one_way' })}
                    >
                        One way
                    </button>
                    <button
                        className={search.tripType === 'multi_city' ? 'active' : ''}
                        type="button"
                        onClick={() => onChange({ tripType: 'multi_city' })}
                    >
                        Advanced
                    </button>
                </div>
            </div>
            {search.tripType !== 'multi_city' ? (
                <>
                    <div className="search-grid">
                        <AirportSelect label="From" value={search.origin} onChange={(origin) => onChange({ origin, finalDestination: origin })} />
                        <AirportSelect label="To" value={search.destination} onChange={(destination) => onChange({ destination, returnOrigin: destination })} />
                        <DatePickerField
                            label="Departure"
                            min={todayIso}
                            value={search.departureDate}
                            onChange={(departureDate) => onChange({ departureDate })}
                        />
                        {search.tripType === 'round_trip' && (
                            <DatePickerField
                                label="Return"
                                min={search.departureDate}
                                value={search.returnDate}
                                onChange={(returnDate) => onChange({ returnDate })}
                            />
                        )}
                        <Button className="search-button" size="lg" type="submit">
                            <Search size={18} />
                            Search
                        </Button>
                    </div>
                    <div className="search-options">
                        <button
                            aria-expanded={showAdvanced}
                            className="advanced-toggle"
                            type="button"
                            onClick={() => setShowAdvanced((current) => !current)}
                        >
                            More options
                            <ChevronDown className={showAdvanced ? 'open' : ''} size={16} />
                        </button>
                    </div>
                    {showAdvanced && (
                        <AdvancedSearchOptions airlines={data.airlines} search={search} onChange={onChange} />
                    )}
                </>
            ) : (
                <div className="multi-city-fields">
                    {search.multiCityLegs.map((leg, index) => (
                        <div className="multi-city-leg" key={leg.id}>
                            <div className="leg-index">Flight {index + 1}</div>
                            <AirportSelect label="From" value={leg.origin} onChange={(origin) => updateMultiCityLeg(index, { origin })} />
                            <AirportSelect label="To" value={leg.destination} onChange={(destination) => updateMultiCityLeg(index, { destination })} />
                            <DatePickerField
                                label="Departure"
                                min={index === 0 ? todayIso : search.multiCityLegs[index - 1]?.departureDate ?? todayIso}
                                value={leg.departureDate}
                                onChange={(departureDate) => updateMultiCityLeg(index, { departureDate })}
                            />
                            <button
                                aria-label={`Remove flight ${index + 1}`}
                                className="remove-leg-button"
                                disabled={search.multiCityLegs.length <= 1}
                                type="button"
                                onClick={() => removeMultiCityLeg(index)}
                            >
                                <Minus size={18} />
                            </button>
                        </div>
                    ))}
                    <div className="search-options">
                        <button
                            aria-expanded={showAdvanced}
                            className="advanced-toggle"
                            type="button"
                            onClick={() => setShowAdvanced((current) => !current)}
                        >
                            More options
                            <ChevronDown className={showAdvanced ? 'open' : ''} size={16} />
                        </button>
                    </div>
                    {showAdvanced && (
                        <AdvancedSearchOptions airlines={data.airlines} search={search} onChange={onChange} />
                    )}
                    <div className="multi-city-actions">
                        <Button disabled={search.multiCityLegs.length >= 5} type="button" variant="outline" onClick={addMultiCityLeg}>
                            <CirclePlus size={18} />
                            Add flight
                        </Button>
                        <Button className="search-button" size="lg" type="submit">
                            <Search size={18} />
                            Search
                        </Button>
                    </div>
                </div>
            )}
        </form>
    );
}

type AdvancedSearchOptionsProps = {
    airlines: Airline[];
    search: TripSearchParams;
    onChange: (next: Partial<TripSearchParams>) => void;
};

function AdvancedSearchOptions({ airlines, search, onChange }: AdvancedSearchOptionsProps) {
    return (
        <div className="advanced-options">
            <AirlineSelect airlines={airlines} value={search.airline} onChange={(airline) => onChange({ airline })} />
            <div className="advanced-nearby-options">
                <label className="check-option">
                    <input
                        checked={search.includeNearbyAirports}
                        type="checkbox"
                        onChange={(event) => onChange({ includeNearbyAirports: event.target.checked })}
                    />
                    <span>Include nearby airports</span>
                </label>
                {search.includeNearbyAirports && (
                    <label className="radius-field">
                        Radius
                        <input
                            min={5}
                            max={1000}
                            step={5}
                            type="number"
                            value={search.nearbyRadiusKm}
                            onChange={(event) => onChange({ nearbyRadiusKm: Number(event.target.value) })}
                        />
                        <span>km</span>
                    </label>
                )}
            </div>
        </div>
    );
}

type AirlineSelectProps = {
    airlines: Airline[];
    value: string;
    onChange: (value: string) => void;
};

function AirlineSelect({ airlines, value, onChange }: AirlineSelectProps) {
    const inputId = React.useId();
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const selectedAirline = airlines.find((airline) => airline.code === value);
    const normalizedQuery = normalizeSearchText(query);
    const visibleAirlines = airlines
        .filter((airline) => {
            if (normalizedQuery === '') {
                return airline.code === value || airline.code === 'AC' || airline.name.toLowerCase().startsWith('air ');
            }

            return normalizeSearchText(`${airline.code} ${airline.name}`).includes(normalizedQuery);
        })
        .slice(0, 8);

    function selectAirline(code: string) {
        onChange(code);
        setQuery('');
        setIsOpen(false);
    }

    return (
        <div
            className="advanced-select airline-select"
            onBlur={() => {
                window.setTimeout(() => {
                    setIsOpen(false);
                    setQuery('');
                }, 120);
            }}
        >
            <label htmlFor={inputId}>Restrict to airline</label>
            <div className="airline-control">
                <input
                    autoComplete="off"
                    id={inputId}
                    placeholder={formatAirlineField(selectedAirline)}
                    type="text"
                    value={isOpen ? query : formatAirlineField(selectedAirline)}
                    onChange={(event) => {
                        setQuery(event.target.value);
                        setIsOpen(true);
                    }}
                    onFocus={() => {
                        setQuery('');
                        setIsOpen(true);
                    }}
                />
                <ChevronDown className={isOpen ? 'open' : ''} size={18} />
            </div>
            {isOpen && (
                <div className="airline-dropdown">
                    <button type="button" onMouseDown={(event) => event.preventDefault()} onClick={() => selectAirline('')}>
                        Any airline
                    </button>
                    {visibleAirlines.map((airline) => (
                        <button
                            key={airline.code}
                            type="button"
                            onMouseDown={(event) => event.preventDefault()}
                            onClick={() => selectAirline(airline.code)}
                        >
                            <strong>{airline.code}</strong>
                            <span>{airline.name}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

type DatePickerFieldProps = {
    disabled?: boolean;
    label: string;
    min: string;
    value: string;
    onChange: (value: string) => void;
};

function DatePickerField({ disabled = false, label, min, value, onChange }: DatePickerFieldProps) {
    const selectedDate = parseIsoDate(value);
    const minDate = parseIsoDate(min);

    return (
        <div className={`field date-picker-field ${disabled ? 'field-disabled' : ''}`}>
            <label>{label}</label>
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        aria-label={`Choose ${label.toLowerCase()} date`}
                        className="date-trigger"
                        disabled={disabled}
                        type="button"
                        variant="outline"
                    >
                        <CalendarDays size={18} />
                        <span>{format(selectedDate, 'EEE, MMM d')}</span>
                    </Button>
                </PopoverTrigger>
                <PopoverContent>
                    <Calendar
                        disabled={(date) => startOfLocalDay(date).getTime() < minDate.getTime()}
                        mode="single"
                        selected={selectedDate}
                        onSelect={(date) => {
                            if (date) {
                                onChange(toIsoDate(date));
                            }
                        }}
                    />
                </PopoverContent>
            </Popover>
        </div>
    );
}

type AirportSelectProps = {
    label: string;
    value: string;
    onChange: (value: string) => void;
};

function AirportSelect({ label, value, onChange }: AirportSelectProps) {
    const inputId = React.useId();
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const selected = airportByCode.get(value);
    const normalizedQuery = normalizeSearchText(query);
    const visibleAirports = airportOptions
        .map((airport) => ({
            airport,
            rank: rankAirportMatch(airport, normalizedQuery),
        }))
        .filter((match) => match.rank < Number.POSITIVE_INFINITY)
        .sort((left, right) => {
            if (left.rank !== right.rank) {
                return left.rank - right.rank;
            }

            const priorityDifference = airportSearchPriority(left.airport) - airportSearchPriority(right.airport);
            if (priorityDifference !== 0) {
                return priorityDifference;
            }

            const regionDifference = airportRegionPriority(left.airport) - airportRegionPriority(right.airport);
            if (regionDifference !== 0) {
                return regionDifference;
            }

            return `${left.airport.city} ${left.airport.code}`.localeCompare(`${right.airport.city} ${right.airport.code}`);
        })
        .map((match) => match.airport)
        .slice(0, 8);

    function selectAirport(code: string) {
        onChange(code);
        setQuery('');
        setIsOpen(false);
    }

    return (
        <div
            className="field airport-field"
            onBlur={() => {
                window.setTimeout(() => {
                    setIsOpen(false);
                    setQuery('');
                }, 120);
            }}
        >
            <label htmlFor={inputId}>{label}</label>
            <div className="airport-control">
                <Plane className="airport-control-icon" size={22} />
                <div className="airport-input-stack">
                    <input
                        autoComplete="off"
                        aria-label={airportInputLabel(label)}
                        className="airport-input"
                        id={inputId}
                        type="text"
                        value={isOpen ? query : formatAirportField(selected)}
                        onChange={(event) => {
                            setQuery(event.target.value);
                            setIsOpen(true);
                        }}
                        onFocus={() => {
                            setQuery('');
                            setIsOpen(true);
                        }}
                    />
                </div>
                {selected && (
                    <button
                        aria-label={`Clear ${label.toLowerCase()} airport`}
                        className="field-clear"
                        type="button"
                        onMouseDown={(event) => event.preventDefault()}
                        onClick={() => {
                            setQuery('');
                            setIsOpen(true);
                        }}
                    >
                        <X size={18} />
                    </button>
                )}
            </div>
            {isOpen && (
                <div className="airport-dropdown">
                    {visibleAirports.length > 0 ? (
                        visibleAirports.map((airport) => (
                            <button
                                key={airport.code}
                                type="button"
                                onMouseDown={(event) => event.preventDefault()}
                                onClick={() => selectAirport(airport.code)}
                            >
                                <Plane size={20} />
                                <span>
                                    <strong>{airport.code}</strong>
                                    {' - '}
                                    {airport.name}, {airport.city}, {airport.country_code}
                                </span>
                            </button>
                        ))
                    ) : (
                        <p>No matching airports</p>
                    )}
                </div>
            )}
        </div>
    );
}

type ResultsViewProps = {
    error: string | null;
    isSearching: boolean;
    params: TripSearchParams;
    results: Itinerary[];
    tripOptions: TripOptionSet | null;
    selectedOptionIds: Record<string, string>;
    pagination: SearchPagination | null;
    activeFareTab: FareTabKey;
    expandedId: string | null;
    onSortChange: (sort: SortKey, fareTab: FareTabKey) => void;
    onPageChange: (page: number) => void;
    onLegPageChange: (legId: string, page: number) => void;
    onSelectLegOption: (legId: string, optionId: string) => void;
    onToggleDetails: (id: string) => void;
};

function ResultsView({
    error,
    isSearching,
    params,
    results,
    tripOptions,
    selectedOptionIds,
    pagination,
    activeFareTab,
    expandedId,
    onSortChange,
    onPageChange,
    onLegPageChange,
    onSelectLegOption,
    onToggleDetails,
}: ResultsViewProps) {
    const origin = params.tripType === 'multi_city'
        ? airportByCode.get(params.multiCityLegs[0]?.origin ?? params.origin)
        : airportByCode.get(params.origin);
    const destination = params.tripType === 'multi_city'
        ? airportByCode.get(params.multiCityLegs[params.multiCityLegs.length - 1]?.destination ?? params.destination)
        : airportByCode.get(params.destination);
    const optionCount = tripOptions?.legs.reduce((sum, leg) => sum + leg.options.length, 0) ?? 0;
    const resultCount = tripOptions ? optionCount : pagination?.total ?? results.length;

    return (
        <section id="results" className="results-section">
            <div className="shell results-layout">
                <aside className="filters-panel">
                    <div className="filter-heading">
                        <h2>Search summary</h2>
                        <p>{resultCount} results found</p>
                    </div>
                    <div className="filter-card">
                        <p className="eyebrow">Search summary</p>
                        <h2>
                            {origin?.city ?? params.origin} to {destination?.city ?? params.destination}
                        </h2>
                        <dl>
                            <div>
                                <dt>Trip type</dt>
                                <dd>{tripTypeLabel(params)}</dd>
                            </div>
                            <div>
                                <dt>Departure</dt>
                                <dd>{formatDateLabel(params.tripType === 'multi_city' ? params.multiCityLegs[0]?.departureDate ?? params.departureDate : params.departureDate)}</dd>
                            </div>
                            {params.tripType === 'round_trip' && (
                                <div>
                                    <dt>Return</dt>
                                    <dd>{formatDateLabel(params.returnDate)}</dd>
                                </div>
                            )}
                            {params.tripType === 'one_way' && params.includeNearbyAirports && (
                                <div>
                                    <dt>Nearby</dt>
                                    <dd>{params.nearbyRadiusKm} km radius</dd>
                                </div>
                            )}
                            {params.tripType === 'multi_city' && (
                                <div>
                                    <dt>Flights</dt>
                                    <dd>{params.multiCityLegs.length}</dd>
                                </div>
                            )}
                            <div>
                                <dt>Stops</dt>
                                <dd>{params.maxStops === 0 ? 'Nonstop only' : `Up to ${params.maxStops} stop${params.maxStops > 1 ? 's' : ''}`}</dd>
                            </div>
                        </dl>
                    </div>
                </aside>
                <div className="results-main">
                    <div className="results-toolbar">
                        <div>
                            <p className="eyebrow">Available trips</p>
                            <h2>{isSearching ? 'Searching flights...' : `${resultCount} result${resultCount === 1 ? '' : 's'} found`}</h2>
                        </div>
                        {!tripOptions && (
                            <Badge className="toolbar-pill" variant="secondary">
                                <SlidersHorizontal size={14} />
                                {labelForSort(params.sort)}
                            </Badge>
                        )}
                    </div>
                    {!isSearching && !tripOptions && results.length > 0 && (
                        <FareTabs activeTab={activeFareTab} results={results} onSortChange={onSortChange} />
                    )}

                    {isSearching && <LoadingState />}
                    {!isSearching && error && <EmptyState title={error.includes('expired') ? 'Results expired' : 'Search failed'} message={error} />}
                    {!isSearching && !error && !tripOptions && results.length === 0 && <EmptyState />}
                    {!isSearching && !error && tripOptions && (
                        <TripOptionBuilder
                            expandedId={expandedId}
                            selectedOptionIds={selectedOptionIds}
                            tripOptions={tripOptions}
                            onLegPageChange={onLegPageChange}
                            onSelectLegOption={onSelectLegOption}
                            onToggleDetails={onToggleDetails}
                        />
                    )}
                    {!isSearching && !tripOptions && results.length > 0 && (
                        <div className="itinerary-list">
                            {results.map((result) => (
                                <ItineraryCard
                                    key={result.id}
                                    itinerary={result}
                                    isExpanded={expandedId === result.id}
                                    onToggleDetails={() => onToggleDetails(result.id)}
                                />
                            ))}
                        </div>
                    )}
                    {!isSearching && !error && !tripOptions && pagination && (
                        <PaginationControls pagination={pagination} onPageChange={onPageChange} />
                    )}
                </div>
            </div>
        </section>
    );
}

function TripOptionBuilder({
    tripOptions,
    selectedOptionIds,
    expandedId,
    onLegPageChange,
    onSelectLegOption,
    onToggleDetails,
}: {
    tripOptions: TripOptionSet;
    selectedOptionIds: Record<string, string>;
    expandedId: string | null;
    onLegPageChange: (legId: string, page: number) => void;
    onSelectLegOption: (legId: string, optionId: string) => void;
    onToggleDetails: (id: string) => void;
}) {
    const selectedOptions = tripOptions.legs
        .map((leg) => leg.options.find((option) => option.id === selectedOptionIds[leg.id]))
        .filter((option): option is Itinerary => Boolean(option));
    const isComplete = selectedOptions.length === tripOptions.legs.length;
    const totalPrice = selectedOptions.reduce((sum, option) => sum + option.priceCents, 0);
    const totalDuration = selectedOptions.reduce((sum, option) => sum + option.durationMinutes, 0);
    const totalStops = selectedOptions.reduce((sum, option) => sum + option.stops, 0);
    const [activeLegIndex, setActiveLegIndex] = useState(0);
    const [activeLegSort, setActiveLegSort] = useState<LegSortKey>('best');
    const [isReviewingTrip, setIsReviewingTrip] = useState(false);
    const builderRef = useRef<HTMLDivElement | null>(null);
    const activeLeg = tripOptions.legs[activeLegIndex] ?? tripOptions.legs[0];
    const activeLegSelectedOptionId = activeLeg ? selectedOptionIds[activeLeg.id] : '';
    const canGoBack = activeLegIndex > 0;
    const canGoNext = Boolean(activeLegSelectedOptionId) && activeLegIndex < tripOptions.legs.length - 1;
    const sortedActiveOptions = activeLeg
        ? sortLegOptions(activeLeg.options, activeLegSort)
        : [];
    const activeLegPagination = activeLeg
        ? activeLeg.pagination ?? singlePagePagination(activeLeg.options.length)
        : null;

    useEffect(() => {
        setActiveLegIndex(0);
        setIsReviewingTrip(false);
    }, [tripOptions]);

    if (!activeLeg) {
        return <EmptyState />;
    }

    function scrollBuilderIntoView() {
        window.requestAnimationFrame(() => {
            builderRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    }

    function goToLeg(index: number) {
        setActiveLegIndex(Math.max(0, Math.min(tripOptions.legs.length - 1, index)));
        setIsReviewingTrip(false);
        scrollBuilderIntoView();
    }

    function changeLegPage(legId: string, page: number) {
        onLegPageChange(legId, page);
        scrollBuilderIntoView();
    }

    function reviewTrip() {
        setIsReviewingTrip(true);
        scrollBuilderIntoView();
    }

    return (
        <div className="trip-builder-results" ref={builderRef}>
            <div className="trip-builder-summary">
                <div className="trip-summary-primary">
                    <p className="eyebrow">Trip summary</p>
                    <h3>{isComplete ? formatCurrency(totalPrice) : `${selectedOptions.length} of ${tripOptions.legs.length} selected`}</h3>
                    {!isComplete && <p className="trip-summary-subtitle">Choose one flight per leg</p>}
                </div>
                <div className="trip-stepper" aria-label="Trip leg progress">
                    {tripOptions.legs.map((leg, index) => (
                        <TripStepButton
                            index={index}
                            isActive={index === activeLegIndex}
                            key={leg.id}
                            leg={leg}
                            selectedOption={leg.options.find((option) => option.id === selectedOptionIds[leg.id])}
                            onClick={() => goToLeg(index)}
                        />
                    ))}
                </div>
                <dl>
                    <div>
                        <dt>Selected</dt>
                        <dd>{selectedOptions.length} of {tripOptions.legs.length}</dd>
                    </div>
                    <div>
                        <dt>Travel time</dt>
                        <dd>{isComplete ? formatDuration(totalDuration) : '--'}</dd>
                    </div>
                    <div>
                        <dt>Stops</dt>
                        <dd>{isComplete ? totalStops : '--'}</dd>
                    </div>
                </dl>
            </div>

            {isReviewingTrip ? (
                <TripReview
                    legs={tripOptions.legs}
                    selectedOptionIds={selectedOptionIds}
                    totalDuration={totalDuration}
                    totalPrice={totalPrice}
                    totalStops={totalStops}
                    onEdit={() => {
                        setIsReviewingTrip(false);
                        scrollBuilderIntoView();
                    }}
                />
            ) : (
            <section className="leg-option-section" aria-labelledby={`leg-options-${activeLeg.id}`}>
                <div className="leg-option-heading">
                    <div>
                        <p className="eyebrow">{activeLeg.label}</p>
                        <h3 id={`leg-options-${activeLeg.id}`}>
                            Choose flight {activeLegIndex + 1}: {activeLeg.origin.city} to {activeLeg.destination.city}
                        </h3>
                        <span>{formatDateLabel(activeLeg.departureDate)}</span>
                    </div>
                    <div className="leg-sort-tabs" aria-label="Sort flight options">
                        {([
                            ['best', 'Best'],
                            ['price', 'Price'],
                            ['time', 'Time'],
                        ] as Array<[LegSortKey, string]>).map(([sort, label]) => (
                            <button
                                className={activeLegSort === sort ? 'active' : ''}
                                key={sort}
                                type="button"
                                onClick={() => setActiveLegSort(sort)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                    <Badge variant="secondary">{activeLeg.options.length} option{activeLeg.options.length === 1 ? '' : 's'}</Badge>
                </div>
                {activeLeg.options.length === 0 ? (
                    <EmptyState
                        title={`No options for ${activeLeg.label.toLowerCase()}`}
                        message="Try another date, route, or stop setting for this leg."
                    />
                ) : (
                    <div className="itinerary-list">
                        {sortedActiveOptions.map((option) => (
                            <ItineraryCard
                                key={option.id}
                                itinerary={option}
                                isExpanded={expandedId === option.id}
                                isSelected={activeLegSelectedOptionId === option.id}
                                selectLabel="Select flight"
                                selectedLabel="Selected"
                                onSelect={() => onSelectLegOption(activeLeg.id, option.id)}
                                onToggleDetails={() => onToggleDetails(option.id)}
                            />
                        ))}
                    </div>
                )}
                {activeLeg.options.length > 0 && activeLegPagination && (
                    <PaginationControls
                        pagination={activeLegPagination}
                        onPageChange={(page) => changeLegPage(activeLeg.id, page)}
                    />
                )}
                <div className="leg-step-actions">
                    <Button disabled={!canGoBack} type="button" variant="outline" onClick={() => goToLeg(activeLegIndex - 1)}>
                        Back
                    </Button>
                    {activeLegIndex < tripOptions.legs.length - 1 ? (
                        <Button className="select-button" disabled={!canGoNext} type="button" onClick={() => goToLeg(activeLegIndex + 1)}>
                            Next flight
                        </Button>
                    ) : (
                        <Button className="select-button" disabled={!isComplete} type="button" onClick={reviewTrip}>
                            Review trip
                        </Button>
                    )}
                </div>
            </section>
            )}
        </div>
    );
}

function TripReview({
    legs,
    selectedOptionIds,
    totalPrice,
    totalDuration,
    totalStops,
    onEdit,
}: {
    legs: TripOptionLeg[];
    selectedOptionIds: Record<string, string>;
    totalPrice: number;
    totalDuration: number;
    totalStops: number;
    onEdit: () => void;
}) {
    return (
        <section className="trip-review" aria-labelledby="trip-review-title">
            <div className="trip-review-heading">
                <div>
                    <p className="eyebrow">Review trip</p>
                    <h3 id="trip-review-title">{formatCurrency(totalPrice)}</h3>
                    <span>{formatDuration(totalDuration)} total travel time - {totalStops} stop{totalStops === 1 ? '' : 's'}</span>
                </div>
                <Button type="button" variant="outline" onClick={onEdit}>
                    Edit flights
                </Button>
            </div>
            <div className="trip-review-legs">
                {legs.map((leg, index) => {
                    const selectedOption = leg.options.find((option) => option.id === selectedOptionIds[leg.id]);

                    if (!selectedOption) {
                        return null;
                    }

                    return (
                        <article key={leg.id}>
                            <p className="eyebrow">{leg.label}</p>
                            <h4>
                                {leg.origin.city} to {leg.destination.city}
                            </h4>
                            <TripLeg label={`Flight ${index + 1}`} segments={selectedOption.legs[0]?.segments ?? []} />
                            <div className="trip-review-price">{formatCurrency(selectedOption.priceCents)}</div>
                        </article>
                    );
                })}
            </div>
        </section>
    );
}

function TripStepButton({
    index,
    isActive,
    leg,
    selectedOption,
    onClick,
}: {
    index: number;
    isActive: boolean;
    leg: TripOptionLeg;
    selectedOption?: Itinerary;
    onClick: () => void;
}) {
    return (
        <button className={isActive ? 'active' : ''} type="button" onClick={onClick}>
            <span>{index + 1}</span>
            <strong>{leg.label}</strong>
            {selectedOption ? (
                <small>
                    {formatTime(selectedOption.departureAt, selectedOption.origin.timezone)}
                    {' - '}
                    {formatTime(selectedOption.arrivalAt, selectedOption.destination.timezone)}
                </small>
            ) : (
                <small>Not selected</small>
            )}
        </button>
    );
}

function singlePagePagination(total: number): SearchPagination {
    return {
        search_id: '',
        page: 1,
        per_page: total,
        total,
        total_pages: 1,
        has_previous: false,
        has_next: false,
        expires_at: '',
    };
}

function PaginationControls({
    pagination,
    onPageChange,
}: {
    pagination: SearchPagination;
    onPageChange: (page: number) => void;
}) {
    return (
        <nav className="pagination-controls" aria-label="Trip result pages">
            <Button
                aria-label="First page"
                className="pagination-icon-button"
                disabled={!pagination.has_previous}
                type="button"
                variant="outline"
                onClick={() => onPageChange(1)}
            >
                <ChevronsLeft size={16} />
            </Button>
            <Button
                disabled={!pagination.has_previous}
                type="button"
                variant="outline"
                onClick={() => onPageChange(pagination.page - 1)}
            >
                <ChevronLeft size={16} />
                Previous
            </Button>
            <span>
                Page {pagination.page} of {pagination.total_pages}
            </span>
            <Button
                disabled={!pagination.has_next}
                type="button"
                variant="outline"
                onClick={() => onPageChange(pagination.page + 1)}
            >
                Next
                <ChevronRight size={16} />
            </Button>
            <Button
                aria-label="Last page"
                className="pagination-icon-button"
                disabled={!pagination.has_next}
                type="button"
                variant="outline"
                onClick={() => onPageChange(pagination.total_pages)}
            >
                <ChevronsRight size={16} />
            </Button>
        </nav>
    );
}

function FareTabs({
    activeTab,
    results,
    onSortChange,
}: {
    activeTab: FareTabKey;
    results: Itinerary[];
    onSortChange: (sort: SortKey, fareTab: FareTabKey) => void;
}) {
    const best = sortItineraries(results, 'best')[0];
    const cheapest = sortItineraries(results, 'price')[0];
    const fastest = sortItineraries(results, 'duration')[0];

    return (
        <div className="fare-tabs">
            <button className={activeTab === 'best' ? 'active' : ''} type="button" onClick={() => onSortChange('best', 'best')}>
                <span>Best</span>
                <strong>{formatCurrency(best.priceCents)}</strong>
            </button>
            <button className={activeTab === 'price' ? 'active' : ''} type="button" onClick={() => onSortChange('price', 'price')}>
                <span>Price</span>
                <strong>{formatCurrency(cheapest.priceCents)}</strong>
            </button>
            <button className={activeTab === 'time' ? 'active' : ''} type="button" onClick={() => onSortChange('duration', 'time')}>
                <span>Time</span>
                <strong>{formatCurrency(fastest.priceCents)}</strong>
            </button>
        </div>
    );
}

function LoadingState() {
    return (
        <div className="state-card">
            <div className="loader" />
            <p>Checking daily flight schedules and connection windows.</p>
        </div>
    );
}

function EmptyState({
    title = 'No matching trips',
    message = 'Try allowing one more stop, changing the route, or searching another date.',
}: {
    title?: string;
    message?: string;
}) {
    return (
        <div className="state-card">
            <h3>{title}</h3>
            <p>{message}</p>
        </div>
    );
}

type ItineraryCardProps = {
    itinerary: Itinerary;
    isExpanded: boolean;
    isSelected?: boolean;
    selectLabel?: string;
    selectedLabel?: string;
    onSelect?: () => void;
    onToggleDetails: () => void;
};

function ItineraryCard({
    itinerary,
    isExpanded,
    isSelected = false,
    selectLabel = 'Choose itinerary',
    selectedLabel = 'Selected',
    onSelect,
    onToggleDetails,
}: ItineraryCardProps) {
    const segments = itinerary.legs.flatMap((leg) => leg.segments);
    const leadAirline = itinerary.legs[0]?.segments[0]?.airline;

    return (
        <article className={`itinerary-card ${isSelected ? 'selected' : ''}`}>
            <div className="itinerary-body">
                <div className="segment-stack">
                    {itinerary.legs.map((leg) => (
                        <TripLeg key={leg.label} label={leg.label} segments={leg.segments} />
                    ))}
                </div>
                <aside className="price-panel">
                    <div className="airline-lockup">
                        <AirlineLogo airlineCode={leadAirline?.code ?? 'AC'} />
                        <span>{leadAirline?.name ?? 'Air Canada'}</span>
                    </div>
                    <div className="price">{formatCurrency(itinerary.priceCents)}</div>
                    <p>taxes included</p>
                    <Button className="select-button" type="button" variant={isSelected ? 'outline' : 'default'} onClick={onSelect}>
                        {isSelected ? selectedLabel : selectLabel}
                    </Button>
                </aside>
            </div>
            <div className="card-footer">
                <button className="details-button" type="button" onClick={onToggleDetails}>
                    {isExpanded ? 'Hide flight details' : 'Show flight details'}
                    {isExpanded ? <ChevronUp size={16} /> : <ChevronDown size={16} />}
                </button>
                <span>
                    <CalendarDays size={14} />
                    {segments.length} flight{segments.length === 1 ? '' : 's'} total
                </span>
            </div>
            {isExpanded && <FlightDetails legs={itinerary.legs} />}
        </article>
    );
}

type TripLegProps = {
    label: string;
    segments: Segment[];
};

function TripLeg({ label, segments }: TripLegProps) {
    const first = segments[0];
    const last = segments[segments.length - 1];

    if (!first || !last) {
        return null;
    }

    const stops = Math.max(0, segments.length - 1);
    const duration = minutesBetween(first.departureAt, last.arrivalAt);

    return (
        <div className="trip-leg">
            <AirlineLogo airlineCode={first.airline.code} />
            <div className="leg-label">{label}</div>
            <div className="leg-summary">
                <div className="time-route">
                    <strong>
                        {formatTime(first.departureAt, first.departureAirport.timezone)} <span>{first.departureAirport.code}</span>
                    </strong>
                    <Timeline stops={stops} />
                    <strong>
                        {formatTime(last.arrivalAt, last.arrivalAirport.timezone)} <span>{last.arrivalAirport.code}</span>
                    </strong>
                </div>
                <div className="leg-meta">
                    <span>{formatDuration(duration)}</span>
                    <span>{stops === 0 ? 'Nonstop' : `${stops} stop${stops > 1 ? 's' : ''}`}</span>
                    <span>{first.airline.name}</span>
                </div>
            </div>
        </div>
    );
}

function Timeline({ stops }: { stops: number }) {
    return (
        <div className="timeline" aria-label={stops === 0 ? 'Nonstop' : `${stops} stops`}>
            <span />
            {Array.from({ length: stops }).map((_, index) => (
                <i key={index} />
            ))}
            <span />
        </div>
    );
}

function FlightDetails({ legs }: { legs: ItineraryLeg[] }) {
    return (
        <div className="flight-details">
            {legs.map((leg) => (
                <div className="flight-detail-leg" key={leg.label}>
                    {leg.segments.map((segment, index) => (
                        <React.Fragment key={`${leg.label}-${segment.flightNumber}-${segment.departureAt.toISOString()}`}>
                            {index > 0 && <Layover previous={leg.segments[index - 1]} next={segment} />}
                            <div className="detail-row">
                                <div className="detail-airline">
                                    <AirlineLogo airlineCode={segment.airline.code} />
                                    <div>
                                        <strong>{segment.airline.name}</strong>
                                        <span>Flight {segment.flightNumber}</span>
                                    </div>
                                </div>
                                <div className="detail-times">
                                    <div>
                                        <strong>{formatTime(segment.departureAt, segment.departureAirport.timezone)}</strong>
                                        <span>{formatDateShort(segment.departureAt, segment.departureAirport.timezone)}</span>
                                        <span>
                                            {segment.departureAirport.city} ({segment.departureAirport.code})
                                        </span>
                                    </div>
                                    <div>
                                        <strong>{formatTime(segment.arrivalAt, segment.arrivalAirport.timezone)}</strong>
                                        <span>{formatDateShort(segment.arrivalAt, segment.arrivalAirport.timezone)}</span>
                                        <span>
                                            {segment.arrivalAirport.city} ({segment.arrivalAirport.code})
                                        </span>
                                    </div>
                                </div>
                                <div className="detail-duration">{formatDuration(segment.durationMinutes)}</div>
                            </div>
                        </React.Fragment>
                    ))}
                </div>
            ))}
        </div>
    );
}

function Layover({ previous, next }: { previous: Segment; next: Segment }) {
    const layoverMinutes = minutesBetween(previous.arrivalAt, next.departureAt);

    return (
        <div className="layover-row">
            <span>{formatDuration(layoverMinutes)} in {previous.arrivalAirport.city}</span>
        </div>
    );
}

function AirlineLogo({ airlineCode }: { airlineCode: string }) {
    return <span className="logo-fallback">{airlineCode}</span>;
}

async function searchTripsFromBackend(params: TripSearchParams, page = 1, searchId?: string): Promise<SearchResultSet> {
    if (isTripOptionSearch(params) && !searchId) {
        const legRequests = tripOptionLegRequests(params);
        const legs = await Promise.all(
            legRequests.map((leg) => searchTripOptionLegFromBackend(leg, params)),
        );

        return {
            results: [],
            pagination: null,
            tripOptions: {
                type: params.tripType,
                legs,
            },
        };
    }

    const request = buildBackendSearchRequest(params);
    let query = request.query;

    if (searchId) {
        query = new URLSearchParams();
        query.set('search_id', searchId);
    }

    query.set('page', String(page));
    query.set('per_page', String(PAGE_SIZE));

    const response = await fetchJson<ApiSearchResponse>(`${request.endpoint}?${query.toString()}`);

    if (!Array.isArray(response.data)) {
        return {
            results: [],
            pagination: null,
            tripOptions: mapApiTripOptions(response.data),
        };
    }

    const results = response.data.flatMap(mapApiTrip);
    const pagination = response.meta?.pagination ?? null;

    return {
        results,
        pagination: pagination && response.data.length === 1 && results.length > 1
            ? {
                ...pagination,
                total: results.length,
                total_pages: 1,
                has_next: false,
                has_previous: false,
            }
            : pagination,
        tripOptions: null,
    };
}

function isTripOptionSearch(params: TripSearchParams) {
    return params.tripType === 'round_trip' || params.tripType === 'multi_city';
}

function tripOptionLegRequests(params: TripSearchParams): TripOptionLegRequest[] {
    if (params.tripType === 'multi_city') {
        return params.multiCityLegs.map((leg, index) => ({
            id: leg.id,
            type: `leg_${index + 1}`,
            label: `Flight ${index + 1}`,
            origin: leg.origin,
            destination: leg.destination,
            departureDate: leg.departureDate,
        }));
    }

    return [
        {
            id: 'outbound',
            type: 'outbound',
            label: 'Outbound',
            origin: params.origin,
            destination: params.destination,
            departureDate: params.departureDate,
        },
        {
            id: 'return',
            type: 'return',
            label: 'Return',
            origin: params.destination,
            destination: params.origin,
            departureDate: params.returnDate,
        },
    ];
}

async function searchTripOptionLegFromBackend(
    leg: TripOptionLegRequest,
    params: TripSearchParams,
    page = 1,
    searchId?: string,
): Promise<TripOptionLeg> {
    const origin = airportByCode.get(leg.origin);
    const destination = airportByCode.get(leg.destination);

    if (!origin || !destination) {
        throw new Error('Trip option search contains an unknown airport.');
    }

    const request = searchId
        ? {
            endpoint: '/api/trips/search/one-way',
            query: new URLSearchParams({ search_id: searchId }),
        }
        : oneWaySearchRequest(params, leg.origin, leg.destination, leg.departureDate);
    const query = request.query;

    query.set('page', String(page));
    query.set('per_page', String(PAGE_SIZE));

    const response = await fetchJson<ApiSearchResponse>(`${request.endpoint}?${query.toString()}`);
    const data = Array.isArray(response.data) ? response.data : [];

    return {
        id: leg.id,
        type: leg.type,
        label: leg.label,
        origin,
        destination,
        departureDate: leg.departureDate,
        options: data
            .filter((trip): trip is ApiItinerary => trip.type === 'one_way')
            .map((option, optionIndex) => mapApiOneWay(option, optionIndex)),
        pagination: response.meta?.pagination ?? null,
    };
}

function mapApiTrip(trip: ApiItinerary | ApiRoundTrip | ApiOpenJaw | ApiMultiCity, index: number): Itinerary[] {
    if (trip.type === 'round_trip') {
        return mapApiOptionGroups(trip, index, 'api-rt');
    }

    if (trip.type === 'open_jaw') {
        return mapApiOptionGroups(trip, index, 'api-oj');
    }

    if (trip.type === 'multi_city') {
        return mapApiOptionGroups(trip, index, 'api-mc');
    }

    return [mapApiOneWay(trip, index)];
}

function buildBackendSearchRequest(params: TripSearchParams) {
    return oneWaySearchRequest(params, params.origin, params.destination, params.departureDate);
}

function oneWaySearchRequest(params: TripSearchParams, originCode: string, destinationCode: string, departureDate: string) {
    const query = baseSearchQuery(params);

    if (params.includeNearbyAirports) {
        const origin = airportByCode.get(originCode);
        const destination = airportByCode.get(destinationCode);

        if (!origin || !destination) {
            throw new Error('Nearby search needs valid origin and destination airports.');
        }

        query.set('origin_latitude', String(origin.latitude));
        query.set('origin_longitude', String(origin.longitude));
        query.set('destination_latitude', String(destination.latitude));
        query.set('destination_longitude', String(destination.longitude));
        query.set('departure_date', departureDate);
        query.set('radius_km', String(params.nearbyRadiusKm));

        return {
            endpoint: '/api/trips/search/one-way-nearby',
            query,
        };
    }

    query.set('origin', originCode);
    query.set('destination', destinationCode);
    query.set('departure_date', departureDate);

    return {
        endpoint: '/api/trips/search/one-way',
        query,
    };
}

function baseSearchQuery(params: TripSearchParams) {
    const query = new URLSearchParams({
        sort: params.sort,
        max_results: String(MAX_RESULTS),
        max_flights_per_route: '50',
    });

    if (params.airline !== '') {
        query.set('airline', params.airline);
    }

    return query;
}

async function fetchJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => null) as { message?: string } | null;
        if (response.status === 410) {
            throw new SearchExpiredError(payload?.message ?? 'These search results expired. Run the search again to see current trips.');
        }

        throw new Error(payload?.message ?? `Request failed with status ${response.status}.`);
    }

    return response.json() as Promise<T>;
}

class SearchExpiredError extends Error {}

function mapApiTripOptions(response: ApiTripOptions): TripOptionSet {
    return {
        type: response.type,
        legs: response.legs.map((leg, legIndex) => {
            const origin = airportByCode.get(leg.origin);
            const destination = airportByCode.get(leg.destination);

            if (!origin || !destination) {
                throw new Error('Trip option response contains an unknown airport.');
            }

            return {
                id: leg.id,
                type: leg.type,
                label: leg.label ?? labelForApiLeg(leg.type, legIndex),
                origin,
                destination,
                departureDate: leg.departure_date,
                options: leg.options.map((option, optionIndex) => {
                    const itinerary = mapApiOneWay(option, optionIndex);

                    return {
                        ...itinerary,
                        id: `${leg.id}-${itinerary.id}`,
                    };
                }),
                pagination: null,
            };
        }),
    };
}

function mapApiOptionGroups(trip: ApiRoundTrip | ApiOpenJaw | ApiMultiCity, index: number, prefix: string): Itinerary[] {
    return trip.legs.flatMap((leg, legIndex) => (
        leg.options.map((option, optionIndex) => {
            const itinerary = mapApiOneWay(option, optionIndex);
            const label = `${labelForApiLeg(leg.type, legIndex)} option ${optionIndex + 1}`;

            return {
                ...itinerary,
                id: `${prefix}-${index}-${leg.type}-${optionIndex}-${itinerary.id}`,
                type: trip.type,
                legs: [
                    {
                        label,
                        segments: itinerary.legs[0]?.segments ?? [],
                    },
                ],
            };
        })
    ));
}

function mapApiOneWay(trip: ApiItinerary, index: number): Itinerary {
    const segments = trip.segments.map(mapApiSegment);
    const first = segments[0];
    const last = segments[segments.length - 1];

    if (!first || !last) {
        throw new Error('Search response included an itinerary without segments.');
    }

    return {
        id: `api-ow-${index}-${segments.map((segment) => segment.flightNumber).join('-')}`,
        type: 'one_way',
        origin: first.departureAirport,
        destination: last.arrivalAirport,
        departureAt: new Date(trip.departure_utc),
        arrivalAt: new Date(trip.arrival_utc),
        durationMinutes: trip.duration_minutes,
        stops: trip.stops,
        priceCents: trip.total_price_cents,
        legs: [
            {
                label: 'Flight',
                segments,
            },
        ],
    };
}

function mapApiSegment(segment: ApiSegment): Segment {
    const departureAirport = airportByCode.get(segment.departure_airport);
    const arrivalAirport = airportByCode.get(segment.arrival_airport);
    const airline = airlineByCode.get(segment.airline);

    if (!departureAirport || !arrivalAirport || !airline) {
        throw new Error(`Search response referenced unknown flight data for ${segment.flight_number}.`);
    }

    return {
        airline,
        flightNumber: segment.flight_number,
        departureAirport,
        arrivalAirport,
        departureAt: new Date(segment.departure_utc),
        arrivalAt: new Date(segment.arrival_utc),
        durationMinutes: segment.duration_minutes,
        priceCents: segment.price_cents,
    };
}

function searchTrips(params: TripSearchParams, page = 1): SearchResultSet {
    return paginateMockResults(searchTripsUnpaginated(params), page);
}

function searchTripsUnpaginated(params: TripSearchParams): Itinerary[] {
    if (params.tripType === 'multi_city') {
        return searchMultiCityMock(params);
    }

    if (params.origin === params.destination) {
        return [];
    }

    const outbound = searchOneWay(params.origin, params.destination, params.departureDate, params);

    if (params.tripType === 'one_way') {
        return outbound.slice(0, MAX_RESULTS);
    }

    const inbound = searchOneWay(params.destination, params.origin, params.returnDate, params);
    const trips: Itinerary[] = [];

    outbound.slice(0, 12).forEach((outboundTrip) => {
        inbound.slice(0, 12).forEach((inboundTrip) => {
            if (inboundTrip.departureAt <= outboundTrip.arrivalAt) {
                return;
            }

            trips.push({
                id: `rt-${outboundTrip.id}-${inboundTrip.id}`,
                type: 'round_trip',
                origin: outboundTrip.origin,
                destination: inboundTrip.destination,
                departureAt: outboundTrip.departureAt,
                arrivalAt: inboundTrip.arrivalAt,
                durationMinutes: outboundTrip.durationMinutes + inboundTrip.durationMinutes,
                stops: outboundTrip.stops + inboundTrip.stops,
                priceCents: outboundTrip.priceCents + inboundTrip.priceCents,
                legs: [
                    {
                        label: 'Outbound',
                        segments: outboundTrip.legs[0]?.segments ?? [],
                    },
                    {
                        label: 'Return',
                        segments: inboundTrip.legs[0]?.segments ?? [],
                    },
                ],
            });
        });
    });

    return sortItineraries(trips, params.sort).slice(0, MAX_RESULTS);
}

function paginateMockResults(results: Itinerary[], page: number): SearchResultSet {
    const totalPages = Math.max(1, Math.ceil(results.length / PAGE_SIZE));
    const normalizedPage = Math.max(1, Math.min(page, totalPages));

    return {
        results: results.slice((normalizedPage - 1) * PAGE_SIZE, normalizedPage * PAGE_SIZE),
        pagination: {
            search_id: 'mock',
            page: normalizedPage,
            per_page: PAGE_SIZE,
            total: results.length,
            total_pages: totalPages,
            has_previous: normalizedPage > 1,
            has_next: normalizedPage < totalPages,
            expires_at: new Date(Date.now() + MOCK_SEARCH_TTL_MINUTES * 60_000).toISOString(),
        },
        tripOptions: null,
    };
}

function searchMultiCityMock(params: TripSearchParams): Itinerary[] {
    const legResults = params.multiCityLegs.map((leg) => searchOneWay(leg.origin, leg.destination, leg.departureDate, params)[0]);

    if (legResults.some((leg) => !leg)) {
        return [];
    }

    const firstLeg = legResults[0];
    const lastLeg = legResults[legResults.length - 1];

    if (!firstLeg || !lastLeg) {
        return [];
    }

    return [
        {
            id: `mc-${legResults.map((leg) => leg.id).join('-')}`,
            type: 'multi_city',
            origin: firstLeg.origin,
            destination: lastLeg.destination,
            departureAt: firstLeg.departureAt,
            arrivalAt: lastLeg.arrivalAt,
            durationMinutes: legResults.reduce((sum, leg) => sum + leg.durationMinutes, 0),
            stops: legResults.reduce((sum, leg) => sum + leg.stops, 0),
            priceCents: legResults.reduce((sum, leg) => sum + leg.priceCents, 0),
            legs: legResults.map((leg, index) => ({
                label: `Flight ${index + 1}`,
                segments: leg.legs[0]?.segments ?? [],
            })),
        },
    ];
}

function searchOneWay(origin: string, destination: string, date: string, params: TripSearchParams): Itinerary[] {
    const originAirport = airportByCode.get(origin);
    const destinationAirport = airportByCode.get(destination);

    if (!originAirport || !destinationAirport) {
        return [];
    }

    type State = {
        airport: string;
        availableAfter: Date;
        firstDeparture: Date | null;
        segments: Segment[];
        visited: Set<string>;
        priceCents: number;
    };

    const initialAvailable = zonedTimeToUtc(`${date}T00:00`, originAirport.timezone);
    const states: State[] = [
        {
            airport: origin,
            availableAfter: initialAvailable,
            firstDeparture: null,
            segments: [],
            visited: new Set([origin]),
            priceCents: 0,
        },
    ];
    const results: Itinerary[] = [];
    const maxSegments = params.maxStops + 1;

    while (states.length > 0 && results.length < MAX_RESULTS * 2) {
        const state = states.shift();
        if (!state) {
            continue;
        }

        if (state.airport === destination && state.segments.length > 0) {
            const first = state.segments[0];
            const last = state.segments[state.segments.length - 1];
            results.push({
                id: state.segments.map((segment) => `${segment.flightNumber}-${segment.departureAt.getTime()}`).join('_'),
                type: 'one_way',
                origin: first.departureAirport,
                destination: last.arrivalAirport,
                departureAt: first.departureAt,
                arrivalAt: last.arrivalAt,
                durationMinutes: minutesBetween(first.departureAt, last.arrivalAt),
                stops: Math.max(0, state.segments.length - 1),
                priceCents: state.priceCents,
                legs: [
                    {
                        label: 'Flight',
                        segments: state.segments,
                    },
                ],
            });
            continue;
        }

        if (state.segments.length >= maxSegments) {
            continue;
        }

        const availableAfter = state.segments.length === 0
            ? state.availableAfter
            : new Date(state.availableAfter.getTime() + MIN_LAYOVER_MINUTES * 60_000);

        for (const flight of flightsByOrigin.get(state.airport) ?? []) {
            if (params.airline !== '' && flight.airline !== params.airline) {
                continue;
            }

            if (state.visited.has(flight.arrival_airport) && flight.arrival_airport !== destination) {
                continue;
            }

            const occurrenceDate = state.segments.length === 0 ? date : getZonedDateString(availableAfter, airportByCode.get(flight.departure_airport)?.timezone ?? 'UTC');
            let segment = buildSegment(flight, occurrenceDate);

            if (!segment || segment.departureAt < availableAfter) {
                segment = buildSegment(flight, addDays(occurrenceDate, 1));
            }

            if (!segment || segment.departureAt < availableAfter) {
                continue;
            }

            if (state.segments.length === 0 && getZonedDateString(segment.departureAt, segment.departureAirport.timezone) !== date) {
                continue;
            }

            const firstDeparture = state.firstDeparture ?? segment.departureAt;
            const fullDuration = minutesBetween(firstDeparture, segment.arrivalAt);
            if (fullDuration > MAX_DURATION_MINUTES) {
                continue;
            }

            const nextVisited = new Set(state.visited);
            nextVisited.add(segment.arrivalAirport.code);
            states.push({
                airport: segment.arrivalAirport.code,
                availableAfter: segment.arrivalAt,
                firstDeparture,
                segments: [...state.segments, segment],
                visited: nextVisited,
                priceCents: state.priceCents + segment.priceCents,
            });
        }

        states.sort((left, right) => {
            if (params.sort === 'duration') {
                return currentDuration(left) - currentDuration(right);
            }

            if (params.sort === 'departure') {
                return (left.firstDeparture?.getTime() ?? 0) - (right.firstDeparture?.getTime() ?? 0);
            }

            return legBestScore(left) - legBestScore(right);
        });
    }

    return sortItineraries(results, params.sort);
}

function currentDuration(state: { firstDeparture: Date | null; availableAfter: Date }) {
    return state.firstDeparture ? minutesBetween(state.firstDeparture, state.availableAfter) : 0;
}

function buildSegment(flight: Flight, localDepartureDate: string): Segment | null {
    const departureAirport = airportByCode.get(flight.departure_airport);
    const arrivalAirport = airportByCode.get(flight.arrival_airport);
    const airline = airlineByCode.get(flight.airline);

    if (!departureAirport || !arrivalAirport || !airline) {
        return null;
    }

    const departureAt = zonedTimeToUtc(`${localDepartureDate}T${flight.departure_time}`, departureAirport.timezone);
    let arrivalAt = zonedTimeToUtc(`${localDepartureDate}T${flight.arrival_time}`, arrivalAirport.timezone);

    if (arrivalAt <= departureAt) {
        arrivalAt = zonedTimeToUtc(`${addDays(localDepartureDate, 1)}T${flight.arrival_time}`, arrivalAirport.timezone);
    }

    return {
        airline,
        flightNumber: `${flight.airline}${flight.number}`,
        departureAirport,
        arrivalAirport,
        departureAt,
        arrivalAt,
        durationMinutes: minutesBetween(departureAt, arrivalAt),
        priceCents: Math.round(Number(flight.price) * 100),
    };
}

function sortItineraries(results: Itinerary[], sort: SortKey) {
    return [...results].sort((left, right) => {
        if (sort === 'best') {
            return legBestScore(left) - legBestScore(right)
                || left.priceCents - right.priceCents
                || left.durationMinutes - right.durationMinutes;
        }

        if (sort === 'departure') {
            return left.departureAt.getTime() - right.departureAt.getTime()
                || left.priceCents - right.priceCents;
        }

        if (sort === 'arrival') {
            return left.arrivalAt.getTime() - right.arrivalAt.getTime()
                || left.priceCents - right.priceCents;
        }

        if (sort === 'duration') {
            return left.durationMinutes - right.durationMinutes
                || left.priceCents - right.priceCents;
        }

        return left.priceCents - right.priceCents
            || left.durationMinutes - right.durationMinutes;
    });
}

function sortLegOptions(options: Itinerary[], sort: LegSortKey) {
    return [...options].sort((left, right) => {
        if (sort === 'price') {
            return left.priceCents - right.priceCents
                || left.durationMinutes - right.durationMinutes;
        }

        if (sort === 'time') {
            return left.durationMinutes - right.durationMinutes
                || left.priceCents - right.priceCents;
        }

        return legBestScore(left) - legBestScore(right)
            || left.priceCents - right.priceCents
            || left.durationMinutes - right.durationMinutes;
    });
}

function legBestScore(itinerary: Itinerary) {
    return itinerary.priceCents
        + itinerary.durationMinutes * 35
        + itinerary.stops * 7_500;
}

function zonedTimeToUtc(localDateTime: string, timeZone: string): Date {
    const [datePart, timePart] = localDateTime.split('T');
    const [year, month, day] = datePart.split('-').map(Number);
    const [hour, minute] = timePart.split(':').map(Number);
    const target = Date.UTC(year, month - 1, day, hour, minute);
    let guess = target;

    for (let index = 0; index < 3; index++) {
        const zoned = getZonedParts(new Date(guess), timeZone);
        const zonedAsUtc = Date.UTC(zoned.year, zoned.month - 1, zoned.day, zoned.hour, zoned.minute);
        guess -= zonedAsUtc - target;
    }

    return new Date(guess);
}

function getZonedParts(date: Date, timeZone: string) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const value = (type: string) => Number(parts.find((part) => part.type === type)?.value ?? 0);

    return {
        year: value('year'),
        month: value('month'),
        day: value('day'),
        hour: value('hour'),
        minute: value('minute'),
    };
}

function getZonedDateString(date: Date, timeZone: string) {
    const parts = getZonedParts(date, timeZone);
    return [
        parts.year,
        String(parts.month).padStart(2, '0'),
        String(parts.day).padStart(2, '0'),
    ].join('-');
}

function formatAirportField(airport?: Airport) {
    if (!airport) {
        return '';
    }

    return `${airport.city}, ${airport.code} - ${airport.name}`;
}

function formatAirlineField(airline?: Airline) {
    if (!airline) {
        return 'Any airline';
    }

    return `${airline.code} - ${airline.name}`;
}

function airportInputLabel(label: string) {
    return {
        From: 'Leaving from',
        To: 'Going to',
        'Returning from': 'Returning from',
        'Returning to': 'Returning to',
    }[label] ?? label;
}

function rankAirportMatch(airport: Airport, query: string) {
    if (query === '') {
        return 20;
    }

    const code = normalizeSearchText(airport.code);
    const city = normalizeSearchText(airport.city);
    const name = normalizeSearchText(airport.name);
    const country = normalizeSearchText(airport.country_code);

    if (code === query) {
        return 0;
    }

    if (code.startsWith(query)) {
        return 1;
    }

    if (city === query) {
        return 2;
    }

    if (startsWithSearchWord(city, query)) {
        return 3;
    }

    if (name === query) {
        return 4;
    }

    if (startsWithSearchWord(name, query)) {
        return 5;
    }

    if (query.length >= 2 && code.includes(query)) {
        return 6;
    }

    if (query.length >= 4 && city.includes(query)) {
        return 7;
    }

    if (query.length >= 4 && name.includes(query)) {
        return 8;
    }

    if (query.length >= 2 && country === query) {
        return 9;
    }

    return Number.POSITIVE_INFINITY;
}

function airportSearchPriority(airport: Airport) {
    return AIRPORT_SEARCH_PRIORITY.get(airport.code) ?? Number.MAX_SAFE_INTEGER;
}

function airportRegionPriority(airport: Airport) {
    if (airport.country_code === 'CA') {
        return 0;
    }

    if (airport.country_code === 'US') {
        return 1;
    }

    return 2;
}

function normalizeSearchText(value: string) {
    return value
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .toLowerCase()
        .trim();
}

function startsWithSearchWord(value: string, query: string) {
    return value.split(/[^a-z0-9]+/).some((word) => word.startsWith(query));
}

function parseIsoDate(date: string) {
    const [year, month, day] = date.split('-').map(Number);
    return new Date(year, month - 1, day);
}

function startOfLocalDay(date: Date) {
    return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function toIsoDate(date: Date) {
    return [
        date.getFullYear(),
        String(date.getMonth() + 1).padStart(2, '0'),
        String(date.getDate()).padStart(2, '0'),
    ].join('-');
}

function addDays(date: string, days: number) {
    const [year, month, day] = date.split('-').map(Number);
    const next = new Date(Date.UTC(year, month - 1, day + days));
    return next.toISOString().slice(0, 10);
}

function minutesBetween(start: Date, end: Date) {
    return Math.round((end.getTime() - start.getTime()) / 60_000);
}

function formatCurrency(cents: number) {
    return new Intl.NumberFormat('en-CA', {
        style: 'currency',
        currency: 'CAD',
    }).format(cents / 100);
}

function formatTime(date: Date, timeZone?: string) {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(date);
}

function formatDateShort(date: Date, timeZone?: string) {
    return new Intl.DateTimeFormat('en-CA', {
        timeZone,
        weekday: 'short',
        month: 'short',
        day: 'numeric',
    }).format(date);
}

function formatDateLabel(date: string) {
    const [year, month, day] = date.split('-').map(Number);
    return new Intl.DateTimeFormat('en-CA', {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    }).format(new Date(Date.UTC(year, month - 1, day)));
}

function formatDuration(minutes: number) {
    const hours = Math.floor(minutes / 60);
    const remainder = minutes % 60;
    return `${hours}h ${String(remainder).padStart(2, '0')}m`;
}

function labelForSort(sort: SortKey) {
    return {
        best: 'Sorted by best match',
        price: 'Sorted by lowest price',
        departure: 'Sorted by earliest departure',
        arrival: 'Sorted by earliest arrival',
        duration: 'Sorted by shortest duration',
    }[sort];
}

function tripTypeLabel(params: TripSearchParams) {
    if (params.tripType === 'multi_city') {
        return 'Advanced';
    }

    if (params.tripType === 'round_trip') {
        return 'Round trip';
    }

    return params.includeNearbyAirports ? 'One way with nearby airports' : 'One way';
}

function labelForApiLeg(type: string, index: number) {
    if (type === 'outbound') {
        return 'Outbound';
    }

    if (type === 'return') {
        return 'Return';
    }

    return `Flight ${index + 1}`;
}

type ReactRootElement = HTMLElement & {
    reactRoot?: ReturnType<typeof createRoot>;
};

const root = document.getElementById('root') as ReactRootElement | null;

if (root) {
    const appRoot = root.reactRoot ?? createRoot(root);
    root.reactRoot = appRoot;

    appRoot.render(
        <React.StrictMode>
            <App />
        </React.StrictMode>,
    );
}
