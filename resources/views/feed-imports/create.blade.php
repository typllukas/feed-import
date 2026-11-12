@use('App\Http\Requests\StoreFeedImportRequest')
<x-layout :title="'Upload feed · '.$partner->name">
    <p><a href="{{ route('partners.index') }}">← Partners</a></p>
    <form class="form-panel" method="post" action="{{ route('partners.feed-imports.store', $partner) }}" enctype="multipart/form-data">
        @csrf
        <h1>Upload feed · {{ $partner->name }}</h1>
        @if ($sampleFeed !== null)
            <p class="hint">Sample feed already chosen. Just click Upload.</p>
        @endif
        <label>Feed file (CSV, up to {{ intdiv(StoreFeedImportRequest::MAX_FEED_FILE_KILOBYTES, 1024) }} MB) <input type="file" name="file" accept=".csv,text/csv" required @if ($sampleFeed !== null) data-sample-feed="{{ $sampleFeed }}" @endif @error('file') aria-invalid="true" aria-describedby="file-error" @enderror></label>
        @error('file')
            <p class="error" id="file-error">{{ $message }}</p>
        @enderror
        <button type="submit">Upload</button>
    </form>
</x-layout>
