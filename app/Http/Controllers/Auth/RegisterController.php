<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegisterController extends Controller
{
    public function create()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.signup');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'business_name' => ['required', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'industry' => ['required', 'string', 'max:100'],
            'skill_pack' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:25'],
        ]);

        $user = null;
        $tenant = null;
        $provisioningJob = null;

        DB::transaction(function () use ($validated, &$user, &$tenant, &$provisioningJob): void {
            $user = User::query()->create([
                'name' => $validated['contact_name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => $validated['password'],
            ]);

            $tenant = Tenant::query()->create([
                'tenant_id' => (string) Str::ulid(),
                'slug' => $this->generateSlug($validated['business_name']),
                'business_name' => $validated['business_name'],
                'industry' => $validated['industry'],
                'skill_pack' => $validated['skill_pack'],
                'user_id' => $user->id,
                'trial_status' => TrialStatus::Active,
                'provisioning_status' => TenantProvisioningStatus::Pending,
            ]);

            $provisioningJob = ProvisioningJob::query()->create([
                'tenant_id' => $tenant->id,
                'job_type' => 'provision_tenant',
                'status' => ProvisioningJobStatus::Queued,
                'payload_json' => [
                    'contact_name' => $validated['contact_name'],
                    'email' => $validated['email'],
                    'industry' => $validated['industry'],
                    'skill_pack' => $validated['skill_pack'],
                ],
            ]);
        });

        Auth::login($user);
        $request->session()->regenerate();

        ProcessTenantProvisioning::dispatch($tenant->id, $provisioningJob->id)->afterCommit();

        return redirect()->route('tenant.setup');
    }

    private function generateSlug(string $businessName): string
    {
        $baseSlug = Str::slug($businessName);
        $seed = $baseSlug !== '' ? $baseSlug : 'tenant';
        $slug = $seed;
        $counter = 1;

        while (Tenant::query()->where('slug', $slug)->exists()) {
            $counter++;
            $slug = sprintf('%s-%d', $seed, $counter);
        }

        return $slug;
    }
}
