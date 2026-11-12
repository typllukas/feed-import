<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, shallowRef } from 'vue';
import {
    fetchFeedImport,
    fetchRejectedRowPage,
    type FeedImport,
    type RejectedRowPage,
} from '../api/feedImport.api';

const POLL_INTERVAL_MILLISECONDS = 1000;
const IS_FINISHED_BY_STATUS: Record<FeedImport['status'], boolean> = {
    pending: false,
    processing: false,
    completed: true,
    failed: true,
    superseded: true,
};

const props = defineProps<{
    initialFeedImport: FeedImport;
    feedImportUrl: string;
    rejectedRowsUrl: string;
}>();

const feedImport = shallowRef(props.initialFeedImport);
const rejectedRowPage = shallowRef<RejectedRowPage | null>(null);
const requestFailureMessage = ref<string | null>(null);
let pollTimer: ReturnType<typeof setTimeout> | undefined;
let isUnmounted = false;
let rejectedRowRequestCount = 0;

const isFeedImportFinished = computed<boolean>(() => IS_FINISHED_BY_STATUS[feedImport.value.status]);

const dateTimeFormat = new Intl.DateTimeFormat('sv-SE', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'UTC',
    timeZoneName: 'short',
});

function formatDateTime(dateTime: string | null): string {
    return dateTime === null ? '—' : dateTimeFormat.format(new Date(dateTime));
}

async function loadRejectedRowPage(url: string): Promise<void> {
    rejectedRowRequestCount++;
    const requestNumber = rejectedRowRequestCount;
    try {
        const loadedPage = await fetchRejectedRowPage(url);
        if (requestNumber === rejectedRowRequestCount) {
            rejectedRowPage.value = loadedPage;
            requestFailureMessage.value = null;
        }
    } catch (exception) {
        console.error(exception);
        if (requestNumber === rejectedRowRequestCount) {
            requestFailureMessage.value = 'Could not load the rejected rows. Reload the page.';
        }
    }
}

function openRejectedRowPage(url: string | null): void {
    if (url !== null) {
        void loadRejectedRowPage(url);
    }
}

function pollUntilFinished(): void {
    if (isFeedImportFinished.value) {
        void loadRejectedRowPage(props.rejectedRowsUrl);
        return;
    }
    pollTimer = setTimeout(() => void refreshFeedImport(), POLL_INTERVAL_MILLISECONDS);
}

async function refreshFeedImport(): Promise<void> {
    try {
        feedImport.value = await fetchFeedImport(props.feedImportUrl);
    } catch (exception) {
        console.error(exception);
        requestFailureMessage.value = 'Could not refresh this import. Reload the page.';
        return;
    }
    if (!isUnmounted) {
        pollUntilFinished();
    }
}

onMounted(pollUntilFinished);

onUnmounted((): void => {
    isUnmounted = true;
    clearTimeout(pollTimer);
});
</script>

<template>
    <p role="status">
        <span
            class="status"
            :class="`status-${feedImport.status}`"
        >{{ feedImport.status }}</span>
    </p>
    <p class="muted">
        Started {{ formatDateTime(feedImport.started_at) }}
        · finished {{ formatDateTime(feedImport.finished_at) }}
        · {{ feedImport.total_row_count ?? '—' }} rows
        · {{ feedImport.imported_row_count }} imported
        · {{ feedImport.rejected_row_count }} rejected
    </p>
    <p
        v-if="feedImport.failure_reason !== null"
        class="error"
    >
        {{ feedImport.failure_reason }}
    </p>
    <p
        v-if="requestFailureMessage !== null"
        class="error"
        role="alert"
    >
        {{ requestFailureMessage }}
    </p>

    <h2>Rejected rows</h2>
    <template v-if="rejectedRowPage !== null">
        <p
            v-if="rejectedRowPage.data.length === 0"
            class="muted"
        >
            No rows were rejected.
        </p>
        <template v-else>
            <table>
                <thead>
                    <tr>
                        <th>Line</th>
                        <th>Reason</th>
                        <th>Row</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="rejectedRow in rejectedRowPage.data"
                        :key="rejectedRow.id"
                    >
                        <td>{{ rejectedRow.line_number }}</td>
                        <td>{{ rejectedRow.message }}</td>
                        <td><code>{{ rejectedRow.feed_line }}</code></td>
                    </tr>
                </tbody>
            </table>
            <nav
                v-if="rejectedRowPage.links.prev !== null || rejectedRowPage.links.next !== null"
                class="pagination"
            >
                <button
                    type="button"
                    :aria-disabled="rejectedRowPage.links.prev === null"
                    @click="openRejectedRowPage(rejectedRowPage.links.prev)"
                >
                    Previous
                </button>
                <button
                    type="button"
                    :aria-disabled="rejectedRowPage.links.next === null"
                    @click="openRejectedRowPage(rejectedRowPage.links.next)"
                >
                    Next
                </button>
            </nav>
        </template>
    </template>
    <p
        v-else-if="requestFailureMessage === null"
        class="muted"
    >
        Loading rejected rows…
    </p>
</template>
