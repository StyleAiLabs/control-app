<x-layouts.app title="Admin Users">
    <div class="topbar">
        <div>
            <span class="eyebrow">Admin Users</span>
            <h2>User Accounts</h2>
            <p>Review all user signups, admin access, and linked tenant details.</p>
        </div>
    </div>

    <section class="panel table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Tenant</th>
                    <th>Workspace URL</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>{{ $user->name }}</td>
                        <td>{{ $user->email }}</td>
                        <td>{{ $user->phone ?? '—' }}</td>
                        <td>{{ $user->is_admin ? 'Super Admin' : 'User' }}</td>
                        <td>{{ $user->tenant?->business_name ?? '—' }}</td>
                        <td>{{ $user->tenant?->workspace_url ?? '—' }}</td>
                        <td>{{ $user->created_at?->toDateTimeString() ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7">No users found yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>
</x-layouts.app>
