<?php

namespace Tests\Feature;

use App\Enums\ProvisioningJobStatus;
use App\Enums\TenantProvisioningStatus;
use App\Enums\TrialStatus;
use App\Jobs\ProcessTenantProvisioning;
use App\Models\ProvisioningJob;
use App\Models\Server;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SignupFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders_the_trial_cta(): void
    {
        $response = $this->get('/');

        $response
            ->assertOk()
            ->assertSee('Start Free Trial')
            ->assertSee(route('signup'), escape: false);
    }

    public function test_signup_creates_user_tenant_and_provisioning_job_records(): void
    {
        Queue::fake();

        $response = $this->post('/signup', [
            'business_name' => 'Acme Plumbing',
            'contact_name' => 'Alice Admin',
            'email' => 'alice@example.com',
            'password' => 'super-secret',
            'password_confirmation' => 'super-secret',
            'industry' => 'Trades',
            'skill_pack' => 'Operations Core',
            'phone' => '+64 21 555 0101',
        ]);

        $response->assertRedirect(route('tenant.setup'));
        $this->assertAuthenticated();

        $this->assertDatabaseHas('users', [
            'email' => 'alice@example.com',
            'phone' => '+64 21 555 0101',
        ]);

        $tenant = Tenant::query()->first();
        $job = ProvisioningJob::query()->first();

        $this->assertNotNull($tenant);
        $this->assertNotNull($job);
        $this->assertNotNull($tenant->server_id);
        $this->assertSame(TrialStatus::Active, $tenant->trial_status);
        $this->assertSame(TenantProvisioningStatus::Pending, $tenant->provisioning_status);
        $this->assertSame(ProvisioningJobStatus::Queued, $job->status);
        $this->assertSame(1, Server::query()->firstOrFail()->current_clients);

        Queue::assertPushed(ProcessTenantProvisioning::class, function (ProcessTenantProvisioning $queuedJob) use ($tenant, $job): bool {
            return $queuedJob->tenantId === $tenant->id
                && $queuedJob->provisioningJobId === $job->id;
        });
    }

    public function test_signup_rejects_duplicate_email_and_creates_no_tenant_records(): void
    {
        User::query()->create([
            'name' => 'Existing User',
            'email' => 'taken@example.com',
            'password' => 'super-secret',
        ]);

        $response = $this->from('/signup')->post('/signup', [
            'business_name' => 'Taken Co',
            'contact_name' => 'Taylor Taken',
            'email' => 'taken@example.com',
            'password' => 'super-secret',
            'password_confirmation' => 'super-secret',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
        ]);

        $response
            ->assertRedirect('/signup')
            ->assertSessionHasErrors('email');

        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('provisioning_jobs', 0);
    }

    public function test_signup_fails_cleanly_when_no_client_vps_is_available(): void
    {
        Server::query()->delete();

        $response = $this->from('/signup')->post('/signup', [
            'business_name' => 'No Server Co',
            'contact_name' => 'Nora Ops',
            'email' => 'nora@example.com',
            'password' => 'super-secret',
            'password_confirmation' => 'super-secret',
            'industry' => 'Retail',
            'skill_pack' => 'Client Support',
        ]);

        $response
            ->assertRedirect('/signup')
            ->assertSessionHasErrors('signup');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('tenants', 0);
        $this->assertDatabaseCount('provisioning_jobs', 0);
    }
}
