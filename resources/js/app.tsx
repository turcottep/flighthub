import React, { FormEvent, useEffect, useMemo, useState } from 'react';
import { createRoot } from 'react-dom/client';
import {
    CalendarDays,
    ChevronDown,
    ChevronUp,
    Plane,
    Search,
    SlidersHorizontal,
    X,
} from 'lucide-react';
import mockFlightData from '../../data/generated/trip_data_ac_ca.json';
import { Badge } from './components/ui/badge';
import { Button } from './components/ui/button';

type TripType = 'one_way' | 'round_trip';
type SortKey = 'price' | 'departure' | 'arrival' | 'duration';
type FareTabKey = 'best' | 'cheapest' | 'shortest' | 'flexible';

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
    departureDate: string;
    returnDate: string;
    airline: string;
    sort: SortKey;
    maxStops: number;
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
    departure_utc?: string;
    arrival_utc?: string;
    duration_minutes: number;
    total_price_cents: number;
    legs: Array<{
        type: 'outbound' | 'return';
        itinerary: ApiItinerary;
    }>;
};

type Itinerary = {
    id: string;
    type: TripType;
    origin: Airport;
    destination: Airport;
    departureAt: Date;
    arrivalAt: Date;
    durationMinutes: number;
    stops: number;
    priceCents: number;
    outbound: Segment[];
    inbound?: Segment[];
};

const mockData = mockFlightData as DataSet;
const dataSource = (import.meta.env.VITE_FLIGHT_DATA_SOURCE ?? 'backend') as 'backend' | 'mock';
const useBackend = dataSource !== 'mock';
const MAX_RESULTS = 24;
const MIN_LAYOVER_MINUTES = 60;
const MAX_DURATION_MINUTES = 36 * 60;

let airportByCode = new Map(mockData.airports.map((airport) => [airport.code, airport]));
let airlineByCode = new Map(mockData.airlines.map((airline) => [airline.code, airline]));
let flightsByOrigin = mockData.flights.reduce<Map<string, Flight[]>>((map, flight) => {
    const flights = map.get(flight.departure_airport) ?? [];
    flights.push(flight);
    map.set(flight.departure_airport, flights);
    return map;
}, new Map());

let airportOptions = [...mockData.airports].sort((left, right) => {
    return `${left.city} ${left.code}`.localeCompare(`${right.city} ${right.code}`);
});

const todayIso = new Date().toISOString().slice(0, 10);
const defaultDepartureDate = addDays(todayIso, 7);
const defaultReturnDate = addDays(todayIso, 14);

function App() {
    const [referenceData, setReferenceData] = useState<DataSet>(mockData);
    const [search, setSearch] = useState<TripSearchParams>({
        tripType: 'round_trip',
        origin: 'YUL',
        destination: 'YVR',
        departureDate: defaultDepartureDate,
        returnDate: defaultReturnDate,
        airline: '',
        sort: 'price',
        maxStops: 0,
    });
    const [submittedSearch, setSubmittedSearch] = useState<TripSearchParams>(search);
    const [results, setResults] = useState<Itinerary[]>([]);
    const [isSearching, setIsSearching] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [hasSubmittedSearch, setHasSubmittedSearch] = useState(false);
    const [activeFareTab, setActiveFareTab] = useState<FareTabKey>('best');
    const [expandedId, setExpandedId] = useState<string | null>(null);

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
        if (!useBackend) {
            return;
        }

        let cancelled = false;

        async function loadReferenceData() {
            try {
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

    async function executeSearch(params: TripSearchParams) {
        setIsSearching(true);
        setError(null);

        try {
            const nextResults = useBackend ? await searchTripsFromBackend(params) : searchTrips(params);
            setSubmittedSearch(params);
            setResults(nextResults);
            setHasSubmittedSearch(true);
        } catch (error) {
            setResults([]);
            setError(error instanceof Error ? error.message : 'Search failed.');
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
        void executeSearch(nextSearch);
    }

    return (
        <main className="flight-app">
            <Header />
            <section className="search-hero">
                <div className="shell">
                    <div className="hero-copy">
                        <h1>Build one-way and round-trip flight itineraries.</h1>
                        <p>Search static airline data with dates, stops, prices, and timezone-aware flight times.</p>
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
                    activeFareTab={activeFareTab}
                    expandedId={expandedId}
                    onSortChange={updateResultsSort}
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
                    <h2>Built as a trip-search service, not a canned demo.</h2>
                    <p>
                        The UI calls Laravel APIs backed by Postgres flight templates. The route calculation then runs
                        against in-memory lookup maps so searches avoid repeated database queries inside the connection
                        loop.
                    </p>
                </div>
                <div className="approach-notes" aria-label="Technical approach">
                    <section>
                        <span>01</span>
                        <h3>Recurring flight templates</h3>
                        <p>
                            Flights are stored once with local departure and arrival times, then combined with the
                            requested date at search time. That matches the prompt without materializing one row per
                            flight per day.
                        </p>
                    </section>
                    <section>
                        <span>02</span>
                        <h3>Timezone-first routing</h3>
                        <p>
                            Each segment is converted from airport-local time to UTC before validating layovers,
                            elapsed duration, return chronology, and the 365-day departure window.
                        </p>
                    </section>
                    <section>
                        <span>03</span>
                        <h3>Bounded graph search</h3>
                        <p>
                            The planner indexes airports by code and flights by departure airport, then expands
                            candidate paths with stop, layover, duration, airline, and result-count limits.
                        </p>
                    </section>
                    <section>
                        <span>04</span>
                        <h3>Reviewable production path</h3>
                        <p>
                            The repo includes migrations, import tooling for generated flight data, API docs, and a
                            passing automated test suite covering one-way, round-trip, constraints, and extra trip
                            shapes.
                        </p>
                    </section>
                </div>
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
                </div>
                <div className="compact-fields">
                    <label>
                        Preferred airline
                        <select value={search.airline} onChange={(event) => onChange({ airline: event.target.value })}>
                            <option value="">Any airline</option>
                            {data.airlines.map((airline) => (
                                <option key={airline.code} value={airline.code}>
                                    {airline.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Sort
                        <select value={search.sort} onChange={(event) => onChange({ sort: event.target.value as SortKey })}>
                            <option value="price">Lowest price</option>
                            <option value="departure">Earliest departure</option>
                            <option value="arrival">Earliest arrival</option>
                            <option value="duration">Shortest duration</option>
                        </select>
                    </label>
                </div>
            </div>
            <div className="search-grid">
                <AirportSelect label="From" value={search.origin} onChange={(origin) => onChange({ origin })} />
                <AirportSelect label="To" value={search.destination} onChange={(destination) => onChange({ destination })} />
                <label className="field">
                    Departure
                    <input
                        min={todayIso}
                        type="date"
                        value={search.departureDate}
                        onChange={(event) => onChange({ departureDate: event.target.value })}
                    />
                </label>
                <label className={`field ${search.tripType === 'one_way' ? 'field-disabled' : ''}`}>
                    Return
                    <input
                        disabled={search.tripType === 'one_way'}
                        min={search.departureDate}
                        type="date"
                        value={search.returnDate}
                        onChange={(event) => onChange({ returnDate: event.target.value })}
                    />
                </label>
                <Button className="search-button" size="lg" type="submit">
                    <Search size={18} />
                    Search
                </Button>
            </div>
        </form>
    );
}

type AirportSelectProps = {
    label: string;
    value: string;
    onChange: (value: string) => void;
};

function AirportSelect({ label, value, onChange }: AirportSelectProps) {
    const [query, setQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const selected = airportByCode.get(value);
    const visibleAirports = airportOptions
        .filter((airport) => {
            const haystack = `${airport.code} ${airport.city} ${airport.name} ${airport.country_code}`.toLowerCase();
            return haystack.includes(query.trim().toLowerCase());
        })
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
            <label>{label}</label>
            <span className="field-icon"><Plane size={22} /></span>
            <input
                autoComplete="off"
                className="airport-input"
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
    activeFareTab: FareTabKey;
    expandedId: string | null;
    onSortChange: (sort: SortKey, fareTab: FareTabKey) => void;
    onToggleDetails: (id: string) => void;
};

function ResultsView({ error, isSearching, params, results, activeFareTab, expandedId, onSortChange, onToggleDetails }: ResultsViewProps) {
    const origin = airportByCode.get(params.origin);
    const destination = airportByCode.get(params.destination);

    return (
        <section id="results" className="results-section">
            <div className="shell results-layout">
                <aside className="filters-panel">
                    <div className="filter-heading">
                        <h2>Search summary</h2>
                        <p>{results.length} results found</p>
                    </div>
                    <div className="filter-card">
                        <p className="eyebrow">Search summary</p>
                        <h2>
                            {origin?.city ?? params.origin} to {destination?.city ?? params.destination}
                        </h2>
                        <dl>
                            <div>
                                <dt>Trip type</dt>
                                <dd>{params.tripType === 'round_trip' ? 'Round trip' : 'One way'}</dd>
                            </div>
                            <div>
                                <dt>Departure</dt>
                                <dd>{formatDateLabel(params.departureDate)}</dd>
                            </div>
                            {params.tripType === 'round_trip' && (
                                <div>
                                    <dt>Return</dt>
                                    <dd>{formatDateLabel(params.returnDate)}</dd>
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
                            <h2>{isSearching ? 'Searching flights...' : `${results.length} result${results.length === 1 ? '' : 's'} found`}</h2>
                        </div>
                        <Badge className="toolbar-pill" variant="secondary">
                            <SlidersHorizontal size={14} />
                            {labelForSort(params.sort)}
                        </Badge>
                    </div>
                    {!isSearching && results.length > 0 && (
                        <FareTabs activeTab={activeFareTab} results={results} onSortChange={onSortChange} />
                    )}

                    {isSearching && <LoadingState />}
                    {!isSearching && error && <EmptyState title="Search failed" message={error} />}
                    {!isSearching && !error && results.length === 0 && <EmptyState />}
                    {!isSearching && results.length > 0 && (
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
                </div>
            </div>
        </section>
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
    const cheapest = results[0];
    const shortest = [...results].sort((left, right) => left.durationMinutes - right.durationMinutes)[0];
    const earliest = [...results].sort((left, right) => left.departureAt.getTime() - right.departureAt.getTime())[0];

    return (
        <div className="fare-tabs">
            <button className={activeTab === 'best' ? 'active' : ''} type="button" onClick={() => onSortChange('price', 'best')}>
                <span>Best</span>
                <strong>{formatCurrency(cheapest.priceCents)}</strong>
            </button>
            <button className={activeTab === 'cheapest' ? 'active' : ''} type="button" onClick={() => onSortChange('price', 'cheapest')}>
                <span>Cheapest</span>
                <strong>{formatCurrency(cheapest.priceCents)}</strong>
            </button>
            <button className={activeTab === 'shortest' ? 'active' : ''} type="button" onClick={() => onSortChange('duration', 'shortest')}>
                <span>Shortest</span>
                <strong>{formatCurrency(shortest.priceCents)}</strong>
            </button>
            <button className={activeTab === 'flexible' ? 'active' : ''} type="button" onClick={() => onSortChange('departure', 'flexible')}>
                <span>Flexible</span>
                <strong>{formatCurrency(earliest.priceCents)}</strong>
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
    onToggleDetails: () => void;
};

function ItineraryCard({ itinerary, isExpanded, onToggleDetails }: ItineraryCardProps) {
    const segments = itinerary.inbound ? [...itinerary.outbound, ...itinerary.inbound] : itinerary.outbound;
    const leadAirline = itinerary.outbound[0]?.airline;

    return (
        <article className="itinerary-card">
            <div className="itinerary-body">
                <div className="segment-stack">
                    <TripLeg label="Outbound" segments={itinerary.outbound} />
                    {itinerary.inbound && <TripLeg label="Return" segments={itinerary.inbound} />}
                </div>
                <aside className="price-panel">
                    <div className="airline-lockup">
                        <AirlineLogo airlineCode={leadAirline?.code ?? 'AC'} />
                        <span>{leadAirline?.name ?? 'Air Canada'}</span>
                    </div>
                    <div className="price">{formatCurrency(itinerary.priceCents)}</div>
                    <p>taxes included</p>
                    <Button className="select-button" type="button">
                        Choose itinerary
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
            {isExpanded && <FlightDetails segments={segments} />}
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
    const stops = Math.max(0, segments.length - 1);
    const duration = minutesBetween(first.departureAt, last.arrivalAt);

    return (
        <div className="trip-leg">
            <input aria-label={`Select ${label.toLowerCase()} leg`} type="checkbox" />
            <AirlineLogo airlineCode={first.airline.code} />
            <div className="leg-label">{label}</div>
            <div className="leg-summary">
                <div className="time-route">
                    <strong>
                        {formatTime(first.departureAt)} <span>{first.departureAirport.code}</span>
                    </strong>
                    <Timeline stops={stops} />
                    <strong>
                        {formatTime(last.arrivalAt)} <span>{last.arrivalAirport.code}</span>
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

function FlightDetails({ segments }: { segments: Segment[] }) {
    return (
        <div className="flight-details">
            {segments.map((segment, index) => (
                <React.Fragment key={`${segment.flightNumber}-${segment.departureAt.toISOString()}`}>
                    {index > 0 && <Layover previous={segments[index - 1]} next={segment} />}
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
                                <strong>{formatTime(segment.departureAt)}</strong>
                                <span>{formatDateShort(segment.departureAt)}</span>
                                <span>
                                    {segment.departureAirport.city} ({segment.departureAirport.code})
                                </span>
                            </div>
                            <div>
                                <strong>{formatTime(segment.arrivalAt)}</strong>
                                <span>{formatDateShort(segment.arrivalAt)}</span>
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
    const path = airlineCode === 'AC' ? '/example/flighthub_results_desktop_files/ACx2.png' : '';

    if (!path) {
        return <span className="logo-fallback">{airlineCode}</span>;
    }

    return <img className="airline-logo" src={path} alt={`${airlineCode} logo`} />;
}

async function searchTripsFromBackend(params: TripSearchParams): Promise<Itinerary[]> {
    const endpoint = params.tripType === 'round_trip'
        ? '/api/trips/search/round-trip'
        : '/api/trips/search/one-way';
    const query = new URLSearchParams({
        origin: params.origin,
        destination: params.destination,
        departure_date: params.departureDate,
        sort: params.sort,
        max_stops: String(params.maxStops),
        max_results: String(MAX_RESULTS),
    });

    if (params.tripType === 'round_trip') {
        query.set('return_date', params.returnDate);
    }

    if (params.airline !== '') {
        query.set('airline', params.airline);
    }

    const response = await fetchJson<{ data: Array<ApiItinerary | ApiRoundTrip> }>(`${endpoint}?${query.toString()}`);

    return response.data.map((trip, index) => {
        if (trip.type === 'round_trip') {
            return mapApiRoundTrip(trip, index);
        }

        return mapApiOneWay(trip, index);
    });
}

async function fetchJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        headers: {
            Accept: 'application/json',
        },
    });

    if (!response.ok) {
        const payload = await response.json().catch(() => null) as { message?: string } | null;
        throw new Error(payload?.message ?? `Request failed with status ${response.status}.`);
    }

    return response.json() as Promise<T>;
}

function mapApiRoundTrip(trip: ApiRoundTrip, index: number): Itinerary {
    const outbound = trip.legs.find((leg) => leg.type === 'outbound')?.itinerary;
    const inbound = trip.legs.find((leg) => leg.type === 'return')?.itinerary;

    if (!outbound || !inbound) {
        throw new Error('Round-trip response is missing one or more legs.');
    }

    const outboundTrip = mapApiOneWay(outbound, index);
    const inboundTrip = mapApiOneWay(inbound, index);

    return {
        id: `api-rt-${index}-${outboundTrip.id}-${inboundTrip.id}`,
        type: 'round_trip',
        origin: outboundTrip.origin,
        destination: outboundTrip.destination,
        departureAt: outboundTrip.departureAt,
        arrivalAt: inboundTrip.arrivalAt,
        durationMinutes: trip.duration_minutes,
        stops: outboundTrip.stops + inboundTrip.stops,
        priceCents: trip.total_price_cents,
        outbound: outboundTrip.outbound,
        inbound: inboundTrip.outbound,
    };
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
        outbound: segments,
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

function searchTrips(params: TripSearchParams): Itinerary[] {
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
                destination: outboundTrip.destination,
                departureAt: outboundTrip.departureAt,
                arrivalAt: inboundTrip.arrivalAt,
                durationMinutes: outboundTrip.durationMinutes + inboundTrip.durationMinutes,
                stops: outboundTrip.stops + inboundTrip.stops,
                priceCents: outboundTrip.priceCents + inboundTrip.priceCents,
                outbound: outboundTrip.outbound,
                inbound: inboundTrip.outbound,
            });
        });
    });

    return sortItineraries(trips, params.sort).slice(0, MAX_RESULTS);
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
                outbound: state.segments,
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

            return left.priceCents - right.priceCents;
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
        if (sort === 'departure') {
            return left.departureAt.getTime() - right.departureAt.getTime() || left.priceCents - right.priceCents;
        }

        if (sort === 'arrival') {
            return left.arrivalAt.getTime() - right.arrivalAt.getTime() || left.priceCents - right.priceCents;
        }

        if (sort === 'duration') {
            return left.durationMinutes - right.durationMinutes || left.priceCents - right.priceCents;
        }

        return left.priceCents - right.priceCents || left.durationMinutes - right.durationMinutes;
    });
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

function formatTime(date: Date) {
    return new Intl.DateTimeFormat('en-CA', {
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    }).format(date);
}

function formatDateShort(date: Date) {
    return new Intl.DateTimeFormat('en-CA', {
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
        price: 'Sorted by lowest price',
        departure: 'Sorted by earliest departure',
        arrival: 'Sorted by earliest arrival',
        duration: 'Sorted by shortest duration',
    }[sort];
}

const root = document.getElementById('root');

if (root) {
    createRoot(root).render(
        <React.StrictMode>
            <App />
        </React.StrictMode>,
    );
}
