<?php

namespace Tests\Unit;

use App\Services\OpenWrtRuntimeConfigRenderer;
use App\Services\UciSerializer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class UciSerializerTest extends TestCase
{
    public function test_quotes_backslashes_and_unicode_are_serialized_as_literal_values(): void
    {
        $this->assertSame("'O'\\''Reilly\\router বাংলা'", UciSerializer::quote("O'Reilly\\router বাংলা"));
        $this->assertSame("    option username 'O'\\''Reilly'", UciSerializer::entry('option', 'username', "O'Reilly"));
    }

    public function test_control_characters_and_syntax_identifiers_are_rejected(): void
    {
        foreach (["line\nbreak", "null\0byte", "tab\tvalue", "carriage\rreturn"] as $value) {
            try {
                UciSerializer::quote($value);
                $this->fail('Control character was accepted.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('control', $exception->getMessage());
            }
        }
        $this->expectException(InvalidArgumentException::class);
        UciSerializer::entry('option', 'key injected', 'value');
    }

    public function test_renderer_escapes_credentials_emits_search_domains_and_multiple_addresses(): void
    {
        $rendered = (new OpenWrtRuntimeConfigRenderer)->render([
            'management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'],
            'addresses' => [['interface' => 'eth0', 'cidr' => '192.0.2.3/24'], ['interface' => 'eth1', 'cidr' => '198.51.100.1/24'], ['interface' => 'eth1', 'cidr' => '203.0.113.1/24']],
            'services' => ['dns' => ['search_domains' => ['one.example', 'two.example']], 'pppoe_clients' => [['name' => 'wan', 'username' => "O'Reilly"]]],
        ], ['services' => ['pppoe_clients' => ['wan' => ['password' => "it's-private"]]]], false);
        $this->assertStringContainsString("list ipaddr '192.0.2.2/24'", $rendered['network']);
        $this->assertStringContainsString("list ipaddr '192.0.2.3/24'", $rendered['network']);
        $this->assertStringContainsString("list ipaddr '198.51.100.1/24'", $rendered['network']);
        $this->assertStringContainsString("list ipaddr '203.0.113.1/24'", $rendered['network']);
        $this->assertStringContainsString("option password 'it'\\''s-private'", $rendered['network']);
        $this->assertStringContainsString("list dhcp_option '119,one.example,two.example'", $rendered['dhcp']);
    }

    public function test_filter_chain_semantics_are_explicit_and_invalid_chains_are_rejected(): void
    {
        $renderer = new OpenWrtRuntimeConfigRenderer;
        foreach (['input' => [true, false], 'output' => [false, true], 'forward' => [true, true]] as $chain => [$src, $dest]) {
            $firewall = $renderer->render(['firewall' => ['rules' => [['chain' => $chain, 'action' => 'drop']]]])['firewall'];
            $rule = substr($firewall, strpos($firewall, 'config rule'));
            $this->assertSame($src, str_contains($rule, "option src '*'"));
            $this->assertSame($dest, str_contains($rule, "option dest '*'"));
        }
        $this->expectException(InvalidArgumentException::class);
        $renderer->render(['firewall' => ['rules' => [['chain' => 'custom-chain', 'action' => 'drop']]]]);
    }

    public function test_management_device_routes_use_the_management_logical_interface(): void
    {
        $network = (new OpenWrtRuntimeConfigRenderer)->render(['management' => ['interface' => 'eth0', 'cidr' => '192.0.2.2/24'], 'routes' => [['destination' => '203.0.114.0/24', 'gateway' => '192.0.2.254', 'device' => 'eth0']]])['network'];
        $this->assertStringContainsString("option interface 'management'", $network);
    }

    public function test_unrepresentable_kernel_forwarding_toggle_is_rejected_before_overlay_generation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported');
        (new OpenWrtRuntimeConfigRenderer)->render(['forwarding' => ['ipv4' => false]]);
    }
}
