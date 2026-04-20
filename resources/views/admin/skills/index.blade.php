<x-layouts.app title="Skill Catalog">
    <div class="topbar">
        <div>
            <span class="eyebrow">Skill Catalog</span>
            <h2>Sync360 Skills</h2>
            <p>Scan repo-authored skills first, then import the eligible ones you want to register in the catalog.</p>
        </div>
        <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end;">
            @if ($skillCatalogFlags['scan_enabled'])
                <form method="POST" action="{{ route('admin.skills.scan') }}" class="panel" style="padding: 12px; display: flex; gap: 10px; align-items: end; flex-wrap: wrap;">
                    @csrf
                    <div>
                        <label for="scan-skill-key" class="hint" style="display: block; margin-bottom: 6px;">Skill key</label>
                        <input id="scan-skill-key" type="text" name="skill_key" value="" placeholder="blank = all repo skills">
                    </div>
                    <button type="submit" class="button button--secondary">Scan Repo Skills</button>
                </form>
            @endif
            <a href="{{ route('admin.index') }}" class="button button--secondary">Back to Admin</a>
        </div>
    </div>

    <div class="grid" style="gap: 20px;">
        <section class="panel">
            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: start; flex-wrap: wrap; margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Catalog</span>
                    <h3 style="margin: 10px 0 0;">Imported Skills</h3>
                </div>
                @if ($skillCatalogFlags['import_enabled'])
                    <form method="POST" action="{{ route('admin.skills.import') }}">
                        @csrf
                        <button type="submit" class="button button--primary">Import Repo Skills</button>
                    </form>
                @endif
            </div>

            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Skill</th>
                            <th>Published Version</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($skills as $skill)
                            @php
                                $publishedVersion = $skill->activePublishedVersion;
                                $isAssignable = ! $skill->is_orphaned && (bool) $skill->is_assignable && $publishedVersion !== null;
                            @endphp
                            <tr>
                                <td>
                                    <strong>{{ $skill->label }}</strong>
                                    <div class="hint">{{ $skill->description }}</div>
                                </td>
                                <td>{{ $publishedVersion?->version ?? 'Not published' }}</td>
                                <td>
                                    <span class="badge {{ $skill->is_orphaned ? 'failed' : ($isAssignable ? 'ready' : 'pending') }}">
                                        {{ $skill->is_orphaned ? 'orphaned' : ($isAssignable ? 'assignable' : 'unavailable') }}
                                    </span>
                                </td>
                                <td>
                                    <a href="{{ route('admin.skills.show', $skill) }}" class="button button--secondary">Open</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="hint">No skills have been imported into the DB catalog yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div style="display: flex; justify-content: space-between; gap: 12px; align-items: start; flex-wrap: wrap; margin-bottom: 16px;">
                <div>
                    <span class="eyebrow">Repo Scan</span>
                    <h3 style="margin: 10px 0 0;">Discovered Repo Skills</h3>
                    <p class="hint" style="margin: 8px 0 0;">Scan inspects <code>resources/skill-packs</code> without writing DB rows.</p>
                </div>
                @if ($skillCatalogFlags['scan_enabled'])
                    <form method="POST" action="{{ route('admin.skills.scan') }}">
                        @csrf
                        <button type="submit" class="button button--secondary">Scan Repo Skills</button>
                    </form>
                @endif
            </div>

            @php
                $scanRows = (array) ($skillScan['rows'] ?? []);
                $eligibleRows = array_values(array_filter($scanRows, fn (array $row): bool => (bool) ($row['importable'] ?? false)));
            @endphp

            @if ($scanRows !== [])
                <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 16px;">
                    @if ($skillCatalogFlags['import_enabled'])
                        <form method="POST" action="{{ route('admin.skills.import') }}">
                            @csrf
                            <button type="submit" class="button button--primary">Import All Eligible</button>
                        </form>
                    @endif
                    <span class="badge pending">{{ count($scanRows) }} scanned</span>
                    <span class="badge ready">{{ count($eligibleRows) }} eligible</span>
                </div>

                <form method="POST" action="{{ route('admin.skills.import') }}">
                    @csrf
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Select</th>
                                    <th>Skill</th>
                                    <th>Version</th>
                                    <th>Scan Status</th>
                                    <th>Catalog State</th>
                                    <th>Eligibility</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($scanRows as $row)
                                    <tr>
                                        <td>
                                            @if ($skillCatalogFlags['import_enabled'] && ($row['importable'] ?? false))
                                                <input type="checkbox" name="skill_keys[]" value="{{ $row['skill_key'] }}">
                                            @else
                                                <span class="hint">n/a</span>
                                            @endif
                                        </td>
                                        <td>
                                            <strong>{{ $row['label'] ?? $row['skill_key'] }}</strong>
                                            <div class="hint">{{ $row['skill_key'] }}</div>
                                            @if (! empty($row['error']))
                                                <div class="hint" style="color: var(--danger);">{{ $row['error'] }}</div>
                                            @endif
                                        </td>
                                        <td>{{ $row['version'] ?? '—' }}</td>
                                        <td>
                                            <span class="badge {{ ($row['manifest_valid'] ?? false) ? (($row['importable'] ?? false) ? 'ready' : 'pending') : 'failed' }}">
                                                {{ $row['status'] }}
                                            </span>
                                        </td>
                                        <td>{{ $row['catalog_state'] }}</td>
                                        <td>{{ $row['environment_label'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($skillCatalogFlags['import_enabled'])
                        <div style="margin-top: 16px; display: flex; gap: 10px; flex-wrap: wrap;">
                            <button type="submit" class="button button--primary">Import Selected</button>
                            <span class="hint">Import writes only the selected eligible skills into the DB catalog.</span>
                        </div>
                    @endif
                </form>
            @else
                <div class="hint">No repo scan results yet. Run <strong>Scan Repo Skills</strong> to review manifest validity, readiness, and import eligibility before writing anything to the DB.</div>
            @endif
        </section>
    </div>
</x-layouts.app>
