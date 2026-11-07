<x-layout :title="'Feed import #' . $feedImport->id">
    <p><a href="{{ route('partners.index') }}">← Partners</a></p>
    <h1>Feed import #{{ $feedImport->id }} · {{ $feedImport->partner->name }} <x-feed-import-status :status="$feedImport->status" /></h1>
    <p class="muted">
        Started {{ $feedImport->started_at?->format('Y-m-d H:i T') ?? '—' }}
        · finished {{ $feedImport->finished_at?->format('Y-m-d H:i T') ?? '—' }}
        · {{ $feedImport->total_row_count ?? '—' }} rows
        · {{ $feedImport->imported_row_count }} imported
        · {{ $feedImport->rejected_row_count }} rejected
    </p>
    @if ($feedImport->failure_reason !== null)
        <p class="error">{{ $feedImport->failure_reason }}</p>
    @endif

    <h2>Rejected rows</h2>
    @if ($rejectedRows->isEmpty())
        <p class="muted">No rows were rejected.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Line</th>
                <th>Reason</th>
                <th>Row</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($rejectedRows as $rejectedRow)
                <tr>
                    <td>{{ $rejectedRow->line_number }}</td>
                    <td>{{ $rejectedRow->message }}</td>
                    <td><code>{{ $rejectedRow->feed_line }}</code></td>
                </tr>
            @endforeach
            </tbody>
        </table>
        {{ $rejectedRows->links('pagination::simple-default') }}
    @endif
</x-layout>
