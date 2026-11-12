import { z } from 'zod';

export const FeedImportSchema = z.object({
    status: z.enum(['pending', 'processing', 'completed', 'failed', 'superseded']),
    total_row_count: z.number().nullable(),
    imported_row_count: z.number(),
    rejected_row_count: z.number(),
    started_at: z.iso.datetime({ offset: true }).nullable(),
    finished_at: z.iso.datetime({ offset: true }).nullable(),
    failure_reason: z.string().nullable(),
});

export type FeedImport = z.infer<typeof FeedImportSchema>;

const RejectedRowSchema = z.object({
    id: z.number(),
    line_number: z.number(),
    message: z.string(),
    feed_line: z.string(),
});

export type RejectedRow = z.infer<typeof RejectedRowSchema>;

const RejectedRowPageSchema = z.object({
    data: z.array(RejectedRowSchema),
    links: z.object({ prev: z.url().nullable(), next: z.url().nullable() }),
});

export type RejectedRowPage = z.infer<typeof RejectedRowPageSchema>;

async function fetchJson(url: string): Promise<unknown> {
    const response = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!response.ok) {
        throw new Error(`Request to ${url} failed with status ${response.status}`);
    }
    return response.json();
}

export async function fetchFeedImport(url: string): Promise<FeedImport> {
    return z.object({ data: FeedImportSchema }).parse(await fetchJson(url)).data;
}

export async function fetchRejectedRowPage(url: string): Promise<RejectedRowPage> {
    return RejectedRowPageSchema.parse(await fetchJson(url));
}
