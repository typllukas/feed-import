import { render, type RenderResult, screen } from '@testing-library/vue';
import userEvent from '@testing-library/user-event';
import { delay, http, HttpResponse } from 'msw';
import { setupServer } from 'msw/node';
import { afterAll, afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import type { FeedImport, RejectedRow, RejectedRowPage } from '../api/feedImport.api';
import contractExample from '../api/feedImport.example.json';
import FeedImportResult from './FeedImportResult.vue';

const FEED_IMPORT_URL = 'http://localhost/feed-imports/12';
const REJECTED_ROWS_URL = 'http://localhost/feed-imports/12/rejected-rows';

function createFeedImport(overrides: Partial<FeedImport> = {}): FeedImport {
    return {
        status: 'completed',
        total_row_count: 120,
        imported_row_count: 117,
        rejected_row_count: 3,
        started_at: '2026-11-11T10:00:00.000000Z',
        finished_at: '2026-11-11T10:00:02.000000Z',
        failure_reason: null,
        ...overrides,
    };
}

function createRejectedRow(overrides: Partial<RejectedRow> = {}): RejectedRow {
    return {
        id: 1,
        line_number: 7,
        message: 'The price field must be at least 0.',
        feed_line: 'ACM-006;Tool 006;-12.90;CZK;42',
        ...overrides,
    };
}

function createRejectedRowPage(overrides: Partial<RejectedRowPage> = {}): RejectedRowPage {
    return { data: [createRejectedRow()], links: { prev: null, next: null }, ...overrides };
}

const server = setupServer();
const unhandledRequests: string[] = [];
server.events.on('request:unhandled', ({ request }) => {
    unhandledRequests.push(`${request.method} ${request.url}`);
});

beforeAll(() => server.listen({ onUnhandledFrame: 'error' }));
afterEach(() => {
    server.resetHandlers();
    vi.useRealTimers();
    expect(unhandledRequests.splice(0), 'requests no handler answered').toEqual([]);
});
afterAll(() => server.close());

function renderResult(initialFeedImport: FeedImport): RenderResult {
    return render(FeedImportResult, {
        props: { initialFeedImport, feedImportUrl: FEED_IMPORT_URL, rejectedRowsUrl: REJECTED_ROWS_URL },
    });
}

describe('FeedImportResult', () => {
    it('reads the responses in the contract example the backend test checks its JSON against', async () => {
        vi.useFakeTimers();
        server.use(
            http.get(FEED_IMPORT_URL, () => HttpResponse.json(contractExample.feedImport)),
            http.get(REJECTED_ROWS_URL, () => HttpResponse.json(contractExample.rejectedRowPage)),
        );

        renderResult(createFeedImport({ status: 'pending' }));
        await vi.advanceTimersByTimeAsync(1000);

        await vi.waitFor(() => {
            expect(screen.getByRole('status')).toHaveTextContent('completed');
            expect(screen.getByText(/Started 2026-11-11 10:00 UTC\s+· finished 2026-11-11 10:00 UTC\s+· 27 rows\s+· 1 imported\s+· 26 rejected/)).toBeVisible();
            expect(screen.getByRole('row', { name: '27 The price field must be at least 0. ACM-006;Tool 006;-12.90;CZK;42' })).toBeVisible();
            expect(screen.getByRole('button', { name: 'Previous' })).toHaveAttribute('aria-disabled', 'false');
            expect(screen.getByRole('button', { name: 'Next' })).toHaveAttribute('aria-disabled', 'true');
        });
    });

    it('polls a pending import until it finishes, then shows its rejected rows', async () => {
        vi.useFakeTimers();
        let importRequestCount = 0;
        server.use(
            http.get(FEED_IMPORT_URL, () => {
                importRequestCount++;
                return HttpResponse.json({ data: createFeedImport() });
            }),
            http.get(REJECTED_ROWS_URL, () => HttpResponse.json(createRejectedRowPage())),
        );

        renderResult(createFeedImport({ status: 'pending', imported_row_count: 0, rejected_row_count: 0 }));
        expect(screen.getByText('pending')).toBeVisible();

        await vi.advanceTimersByTimeAsync(1000);
        await vi.waitFor(() => {
            expect(screen.getByText('completed')).toBeVisible();
            expect(screen.getByText(/117 imported/)).toBeVisible();
            expect(screen.getByText(/3 rejected/)).toBeVisible();
            expect(screen.getByText('ACM-006;Tool 006;-12.90;CZK;42')).toBeVisible();
        });

        await vi.advanceTimersByTimeAsync(5000);
        expect(importRequestCount).toBe(1);
    });

    it('keeps polling while the import is still processing', async () => {
        vi.useFakeTimers();
        let importRequestCount = 0;
        server.use(
            http.get(FEED_IMPORT_URL, () => {
                importRequestCount++;
                return HttpResponse.json({ data: createFeedImport(importRequestCount === 1 ? { status: 'processing' } : {}) });
            }),
            http.get(REJECTED_ROWS_URL, () => HttpResponse.json(createRejectedRowPage())),
        );

        renderResult(createFeedImport({ status: 'pending' }));

        await vi.advanceTimersByTimeAsync(1000);
        await vi.waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('processing'));
        await vi.advanceTimersByTimeAsync(1000);
        await vi.waitFor(() => expect(screen.getByText('ACM-006;Tool 006;-12.90;CZK;42')).toBeVisible());
        expect(importRequestCount).toBe(2);
    });

    it('stops polling when unmounted while a poll is in flight', async () => {
        vi.useFakeTimers();
        let importRequestCount = 0;
        server.use(
            http.get(FEED_IMPORT_URL, async () => {
                importRequestCount++;
                await delay(500);
                return HttpResponse.json({ data: createFeedImport({ status: 'processing' }) });
            }),
        );

        const renderedResult = renderResult(createFeedImport({ status: 'pending' }));
        await vi.advanceTimersByTimeAsync(1000);
        renderedResult.unmount();

        await vi.advanceTimersByTimeAsync(5000);
        expect(importRequestCount).toBe(1);
    });

    it.each(['completed', 'failed', 'superseded'] as const)('loads the rejected rows of a %s import without polling', async (status) => {
        vi.useFakeTimers();
        let importRequestCount = 0;
        server.use(
            http.get(FEED_IMPORT_URL, () => {
                importRequestCount++;
                return HttpResponse.json({ data: createFeedImport({ status }) });
            }),
            http.get(REJECTED_ROWS_URL, () => HttpResponse.json(createRejectedRowPage())),
        );

        renderResult(createFeedImport({ status }));

        await vi.waitFor(() => expect(screen.getByText('The price field must be at least 0.')).toBeVisible());
        expect(screen.getByText(status)).toBeVisible();
        await vi.advanceTimersByTimeAsync(5000);
        expect(importRequestCount).toBe(0);
    });

    it('shows the start and finish in UTC like the Blade pages, whatever the browser zone', async () => {
        vi.stubEnv('TZ', 'Europe/Prague');
        server.use(http.get(REJECTED_ROWS_URL, () => HttpResponse.json(createRejectedRowPage())));

        renderResult(createFeedImport());

        expect(await screen.findByText(/Started 2026-11-11 10:00 UTC\s+· finished 2026-11-11 10:00 UTC/)).toBeVisible();
    });

    it('pages the rejected rows in place and keeps focus on the pager', async () => {
        server.use(
            http.get(REJECTED_ROWS_URL, ({ request }) => {
                if (new URL(request.url).searchParams.get('page') === '2') {
                    return HttpResponse.json(createRejectedRowPage({
                        data: [createRejectedRow({ id: 2, line_number: 30, message: 'The sku field is required.' })],
                        links: { prev: `${REJECTED_ROWS_URL}?page=1`, next: null },
                    }));
                }
                return HttpResponse.json(createRejectedRowPage({ links: { prev: null, next: `${REJECTED_ROWS_URL}?page=2` } }));
            }),
        );

        const user = userEvent.setup();
        renderResult(createFeedImport());
        await user.click(await screen.findByRole('button', { name: 'Next' }));

        expect(await screen.findByText('The sku field is required.')).toBeVisible();
        expect(screen.getByRole('button', { name: 'Previous' })).toBeVisible();
        expect(screen.getByRole('button', { name: 'Next' })).toHaveAttribute('aria-disabled', 'true');
        expect(screen.getByRole('button', { name: 'Next' })).toHaveFocus();
    });

    it('shows the page asked for last when an earlier page answers later', async () => {
        server.use(
            http.get(REJECTED_ROWS_URL, async ({ request }) => {
                const page = new URL(request.url).searchParams.get('page') ?? '1';
                if (page === '2') {
                    return HttpResponse.json(createRejectedRowPage({
                        data: [createRejectedRow({ id: 2, message: 'Row on page two.' })],
                        links: { prev: `${REJECTED_ROWS_URL}?page=1`, next: `${REJECTED_ROWS_URL}?page=3` },
                    }));
                }
                if (page === '3') {
                    await delay(200);
                    return HttpResponse.json(createRejectedRowPage({ data: [createRejectedRow({ id: 3, message: 'Row on page three.' })] }));
                }
                return HttpResponse.json(createRejectedRowPage({
                    data: [createRejectedRow({ message: 'Row on page one.' })],
                    links: { prev: null, next: `${REJECTED_ROWS_URL}?page=2` },
                }));
            }),
        );

        const user = userEvent.setup();
        renderResult(createFeedImport());
        await user.click(await screen.findByRole('button', { name: 'Next' }));
        await screen.findByText('Row on page two.');
        await user.click(screen.getByRole('button', { name: 'Next' }));
        await user.click(screen.getByRole('button', { name: 'Previous' }));

        expect(await screen.findByText('Row on page one.')).toBeVisible();
        await new Promise((resolve) => setTimeout(resolve, 300));
        expect(screen.getByText('Row on page one.')).toBeVisible();
        expect(screen.queryByText('Row on page three.')).not.toBeInTheDocument();
    });

    it('clears the error once a later page loads', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        let nextPageRequestCount = 0;
        server.use(
            http.get(REJECTED_ROWS_URL, ({ request }) => {
                if (new URL(request.url).searchParams.get('page') !== '2') {
                    return HttpResponse.json(createRejectedRowPage({ links: { prev: null, next: `${REJECTED_ROWS_URL}?page=2` } }));
                }
                nextPageRequestCount++;
                if (nextPageRequestCount === 1) {
                    return new HttpResponse(null, { status: 500 });
                }
                return HttpResponse.json(createRejectedRowPage({
                    data: [createRejectedRow({ id: 2, message: 'The sku field is required.' })],
                }));
            }),
        );

        const user = userEvent.setup();
        renderResult(createFeedImport());
        await user.click(await screen.findByRole('button', { name: 'Next' }));
        expect(await screen.findByRole('alert')).toHaveTextContent('Could not load the rejected rows. Reload the page.');

        await user.click(screen.getByRole('button', { name: 'Next' }));

        expect(await screen.findByText('The sku field is required.')).toBeVisible();
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    });

    it('says that no rows were rejected and shows why a failed import failed', async () => {
        server.use(http.get(REJECTED_ROWS_URL, () => HttpResponse.json(createRejectedRowPage({ data: [] }))));

        renderResult(createFeedImport({ status: 'failed', failure_reason: 'The feed file is missing.' }));

        expect(await screen.findByText('No rows were rejected.')).toBeVisible();
        expect(screen.getByText('The feed file is missing.')).toBeVisible();
    });

    it('keeps the last state and asks for a reload when a poll fails', async () => {
        vi.useFakeTimers();
        vi.spyOn(console, 'error').mockImplementation(() => {});
        let importRequestCount = 0;
        server.use(
            http.get(FEED_IMPORT_URL, () => {
                importRequestCount++;
                return HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 });
            }),
        );

        renderResult(createFeedImport({ status: 'pending' }));
        await vi.advanceTimersByTimeAsync(1000);

        await vi.waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Could not refresh this import. Reload the page.'));
        expect(screen.getByRole('status')).toHaveTextContent('pending');

        await vi.advanceTimersByTimeAsync(5000);
        expect(importRequestCount).toBe(1);
    });
});
