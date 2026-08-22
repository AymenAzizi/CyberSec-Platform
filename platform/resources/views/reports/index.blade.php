@extends('layouts.app')

@section('title', 'Reports')

@section('breadcrumb')
    <span class="text-gray-600">/</span>
    <span class="text-white">Reports</span>
@endsection

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="font-display text-2xl font-semibold text-white">Reports</h1>
        <p class="text-sm text-gray-400">{{ $reports->total() }} report{{ $reports->total() === 1 ? '' : 's' }} generated</p>
    </div>

    <div class="card overflow-hidden">
        <div class="overflow-x-auto" data-searchable>
            <table class="table">
                <thead>
                    <tr>
                        <th>Title</th>
                        <th>Project</th>
                        <th>Scan type</th>
                        <th>Generated</th>
                        <th>Format</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($reports as $report)
                        <tr data-search-item="{{ $report->title }} {{ $report->project?->name }}">
                            <td class="text-sm text-white">{{ $report->title }}</td>
                            <td class="text-xs">
                                @if ($report->project) <a href="{{ route('projects.show', $report->project) }}" class="hover:text-white">{{ $report->project->name }}</a> @else — @endif
                            </td>
                            <td class="font-mono text-xs">{{ $report->scan?->type ?? '—' }}</td>
                            <td class="text-xs">{{ $report->generated_at?->diffForHumans() ?? '—' }}</td>
                            <td><span class="badge-neutral uppercase">{{ $report->format }}</span></td>
                            <td>
                                <div class="flex items-center gap-1">
                                    <a href="{{ route('reports.show', $report) }}" class="btn-ghost !p-1.5" title="View"><span class="material-symbols-rounded text-[18px]">visibility</span></a>
                                    <a href="{{ route('reports.export', [$report, 'pdf']) }}" class="btn-ghost !p-1.5" title="Export PDF"><span class="material-symbols-rounded text-[18px]">picture_as_pdf</span></a>
                                    <a href="{{ route('reports.export', [$report, 'html']) }}" class="btn-ghost !p-1.5" title="Export HTML"><span class="material-symbols-rounded text-[18px]">code</span></a>
                                    <a href="{{ route('reports.export', [$report, 'json']) }}" class="btn-ghost !p-1.5" title="Export JSON"><span class="material-symbols-rounded text-[18px]">data_object</span></a>
                                    @if ($report->file_path)
                                        <a href="{{ route('reports.download', $report) }}" class="btn-ghost !p-1.5" title="Download file"><span class="material-symbols-rounded text-[18px]">download</span></a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-gray-500 py-6">No reports generated yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-4 py-3 border-t border-white/5">{{ $reports->withQueryString()->links() }}</div>
    </div>
</div>
@endsection
