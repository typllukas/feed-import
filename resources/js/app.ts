import { createApp } from 'vue';
import { z } from 'zod';
import FeedImportResult from './components/FeedImportResult.vue';
import { FeedImportSchema } from './api/feedImport.api';

const MountPropsSchema = z.object({
    initialFeedImport: FeedImportSchema,
    feedImportUrl: z.url(),
    rejectedRowsUrl: z.url(),
});

document.querySelectorAll<HTMLElement>('[data-feed-import-result]').forEach((element): void => {
    const mountProps = MountPropsSchema.parse(JSON.parse(element.dataset.props ?? '')) satisfies InstanceType<typeof FeedImportResult>['$props'];
    createApp(FeedImportResult, mountProps).mount(element);
});
