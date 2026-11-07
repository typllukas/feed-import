<x-layout title="Partners">
    <h1>Partners</h1>
    @if ($partners->isEmpty())
        <p class="muted">No partners yet. Run <code>make demo</code>.</p>
    @else
        <table>
            <thead>
            <tr>
                <th>Partner</th>
                <th class="number">Products</th>
                <th>Recent imports</th>
            </tr>
            </thead>
            <tbody>
            @foreach ($partners as $partner)
                <tr>
                    <td><span class="partner-name">{{ $partner->name }}</span><br><span class="muted">{{ $partner->code }}</span></td>
                    <td class="number">{{ $partner->products_count }}</td>
                    <td>
                        <div class="recent-imports">
                            @forelse ($partner->latestFeedImports as $feedImport)
                                <div>
                                    <a href="{{ route('feed-imports.show', $feedImport) }}">#{{ $feedImport->id }}</a>
                                    <span class="muted">{{ $feedImport->created_at?->format('Y-m-d H:i T') }}</span>
                                    <x-feed-import-status :status="$feedImport->status" />
                                    @if ($feedImport->failure_reason !== null)
                                        <span class="error">{{ $feedImport->failure_reason }}</span>
                                    @elseif ($feedImport->finished_at !== null)
                                        <span class="muted">{{ $feedImport->imported_row_count }} imported · {{ $feedImport->rejected_row_count }} rejected</span>
                                    @else
                                        <span class="muted">—</span>
                                    @endif
                                </div>
                            @empty
                                <span class="muted">No imports yet.</span>
                            @endforelse
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endif
</x-layout>
