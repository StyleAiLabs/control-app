<x-layouts.app title="Skill Detail">
    <div class="topbar">
        <div>
            <span class="eyebrow">Skill Detail</span>
            <h2>{{ $skill->label }}</h2>
            <p>{{ $skill->description }}</p>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="{{ route('admin.skills.index') }}" class="button button--secondary">Back to Skill Catalog</a>
        </div>
    </div>

    @if ($skill->orphaned_warning)
        <div class="note note--danger">{{ $skill->orphaned_warning }}</div>
    @endif

    <section class="panel">
        <span class="eyebrow">Versions</span>
        <h3 style="margin-top: 8px;">Catalog Versions</h3>

        <table class="table">
            <thead>
                <tr>
                    <th>Version</th>
                    <th>Status</th>
                    <th>Assigned Tenants</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($skill->versions as $version)
                    <tr>
                        <td>{{ $version->version }}</td>
                        <td>
                            @if ($version->is_active_published)
                                <span class="badge ready">published</span>
                            @elseif ($version->is_archived)
                                <span class="badge failed">archived</span>
                            @else
                                <span class="badge pending">registered</span>
                            @endif
                        </td>
                        <td>{{ $tenantAssignmentCounts[$version->id] ?? 0 }}</td>
                        <td style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <form method="POST" action="{{ route('admin.skills.versions.publish', ['skill' => $skill, 'version' => $version]) }}">
                                @csrf
                                <button type="submit" class="button button--primary">Publish</button>
                            </form>
                            <form method="POST" action="{{ route('admin.skills.versions.archive', ['skill' => $skill, 'version' => $version]) }}">
                                @csrf
                                <button type="submit" class="button button--secondary">Archive</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</x-layouts.app>
