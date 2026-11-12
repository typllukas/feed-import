@use('App\Http\Resources\FeedImportResource')
<x-layout :title="'Feed import #' . $feedImport->id">
    <p><a href="{{ route('partners.index') }}">← Partners</a></p>
    <h1>Feed import #{{ $feedImport->id }} · {{ $feedImport->partner->name }}</h1>
    <div data-feed-import-result data-props="{{ json_encode([
        'initialFeedImport' => FeedImportResource::make($feedImport),
        'feedImportUrl' => route('feed-imports.show', $feedImport),
        'rejectedRowsUrl' => route('feed-imports.rejected-rows.index', $feedImport),
    ], JSON_THROW_ON_ERROR) }}"></div>
</x-layout>
