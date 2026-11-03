<?php

namespace Tests\Feature;

use App\Exceptions\ApplianceTransportException;
use App\Models\RouterNode;
use App\Models\User;
use App\Services\ApplianceSshOperation;
use App\Services\OpenWrtApplianceClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class RouterDiagnosticTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_receives_real_client_result_without_creating_configuration_revision(): void
    {
        $router = RouterNode::factory()->create();
        $this->mock(OpenWrtApplianceClient::class, function ($mock) {
            $mock->shouldReceive('diagnose')->once()->with(Mockery::type(RouterNode::class), 'ping', '1.1.1.1')->andReturn(['exit_code' => 0, 'output' => "4 packets transmitted, 4 received\n"]);
        });
        $this->actingAs(User::factory()->admin()->create())->postJson(route('routers.diagnostics', $router), ['tool' => 'ping', 'target' => '1.1.1.1'])->assertOk()->assertJsonPath('result.exit_code', 0)->assertJsonPath('result.output', "4 packets transmitted, 4 received\n");
        $this->assertNull($router->fresh()->desired_revision_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'router.diagnostic.executed', 'status' => 'success']);
    }

    public function test_command_injection_invalid_targets_and_non_admin_requests_are_rejected(): void
    {
        $router = RouterNode::factory()->create();
        $this->mock(OpenWrtApplianceClient::class, fn ($mock) => $mock->shouldNotReceive('diagnose'));
        $this->actingAs(User::factory()->admin()->create());
        foreach (['1.1.1.1;reboot', '-f', "example.com\nreboot", 'example..com', '999.999.999.999'] as $target) {
            $this->postJson(route('routers.diagnostics', $router), ['tool' => 'ping', 'target' => $target])->assertUnprocessable();
        }
        $this->postJson(route('routers.diagnostics', $router), ['tool' => 'shell', 'target' => 'example.com'])->assertUnprocessable();
        $this->actingAs(User::factory()->create(['is_admin' => false]))->postJson(route('routers.diagnostics', $router), ['tool' => 'ping', 'target' => '1.1.1.1'])->assertForbidden();
    }

    public function test_transport_failure_is_reported_and_logged(): void
    {
        $router = RouterNode::factory()->create();
        $this->mock(OpenWrtApplianceClient::class, fn ($mock) => $mock->shouldReceive('diagnose')->once()->andThrow(new ApplianceTransportException(ApplianceTransportException::KIND_UNREACHABLE, 'Connection refused')));
        $this->actingAs(User::factory()->admin()->create())->postJson(route('routers.diagnostics', $router), ['tool' => 'nslookup', 'target' => 'example.com'])->assertStatus(502)->assertJsonPath('error.kind', ApplianceTransportException::KIND_UNREACHABLE);
        $this->assertDatabaseHas('audit_logs', ['action' => 'router.diagnostic.failed', 'status' => 'failed']);
    }

    public function test_operation_validates_even_when_constructed_directly(): void
    {
        $this->assertSame('/usr/sbin/nur-runtimectl diagnose ping ::1', ApplianceSshOperation::diagnose('ping', '::1')->remoteCommand());
        $this->expectException(ApplianceTransportException::class);
        (new ApplianceSshOperation(ApplianceSshOperation::DIAGNOSE, ['tool' => 'ping', 'target' => 'example.com;id']))->remoteCommand();
    }
}
