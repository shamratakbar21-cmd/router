<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ApplianceTransportException extends Exception
{
    public const KIND_TIMEOUT = 'ssh_timeout';

    public const KIND_AUTH_FAILURE = 'ssh_auth_failure';

    public const KIND_HOST_KEY_MISMATCH = 'ssh_host_key_mismatch';

    public const KIND_UNREACHABLE = 'appliance_unreachable';

    public const KIND_MALFORMED_RESPONSE = 'malformed_agent_response';

    public const KIND_APPLY_FAILED = 'apply_failed';

    public const KIND_PPPOE_ADMISSION = 'pppoe_admission_denied';

    public const KIND_LICENSE_MUTATION_BLOCKED = 'license_mutation_blocked';

    public const KIND_NOT_ENROLLED = 'host_key_not_enrolled';

    public const KIND_COMMAND_REJECTED = 'command_rejected';

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'kind' => $this->kind,
            ...$this->context,
        ];
    }

    public function userMessage(): string
    {
        return match ($this->kind) {
            self::KIND_TIMEOUT => 'The appliance SSH connection timed out. Check reachability and retry. Staged configuration was preserved.',
            self::KIND_AUTH_FAILURE => 'Appliance SSH authentication failed. Verify the management key is enrolled and not revoked. Staged configuration was preserved.',
            self::KIND_HOST_KEY_MISMATCH => 'Appliance host key does not match the enrolled pin. Re-enroll only after verifying the device identity out-of-band. Staged configuration was preserved.',
            self::KIND_UNREACHABLE => 'The appliance is unreachable over the management network. Staged configuration was preserved.',
            self::KIND_MALFORMED_RESPONSE => 'The appliance returned a malformed response. No success was recorded. Staged configuration was preserved.',
            self::KIND_APPLY_FAILED => $this->applyFailedUserMessage(),
            self::KIND_PPPOE_ADMISSION => $this->detailOr(
                'PPPoE admission limit rejected this apply. Reduce PPPoE client profiles and retry. Established sessions were preserved; staged configuration remains.'
            ),
            self::KIND_LICENSE_MUTATION_BLOCKED => $this->detailOr(
                'The appliance license policy blocked entitlement-gated changes. Existing traffic and local recovery remain available. Staged configuration was preserved.'
            ),
            self::KIND_NOT_ENROLLED => 'This router has no enrolled host-key pin. Capture the console host-key fingerprint, then register trust with nur:register-appliance-ssh --known-host-file before connecting. Staged configuration was preserved.',
            self::KIND_COMMAND_REJECTED => 'The requested appliance operation was rejected by the constrained management identity.',
            default => $this->getMessage(),
        };
    }

    private function detailOr(string $fallback): string
    {
        $detail = trim($this->getMessage());

        if ($detail === '' || strcasecmp($detail, $fallback) === 0) {
            return $fallback;
        }

        return $detail.(str_ends_with($detail, '.') ? '' : '.').' Staged configuration was preserved.';
    }

    private function applyFailedUserMessage(): string
    {
        $detail = trim($this->getMessage());

        if ($detail !== '' && ! str_contains(strtolower($detail), 'appliance apply failed')) {
            return $detail.(str_ends_with($detail, '.') ? '' : '.').' Staged configuration was preserved.';
        }

        return 'The appliance rejected or failed the apply operation. Staged configuration was preserved; review the appliance logs.';
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*') || $request->routeIs('routers.state.show', 'routers.desired-state.show')) {
            return response()->json([
                'ok' => false,
                'error' => [
                    'kind' => $this->kind,
                    'message' => $this->userMessage(),
                ],
            ], 502);
        }

        $router = $request->route('router');

        return redirect()
            ->to($router ? route('routers.show', $router) : url()->previous())
            ->with('error', $this->userMessage())
            ->with('error_kind', $this->kind);
    }

    public static function fromProcessFailure(string $stderr, int $exitCode, string $operation): self
    {
        $normalized = self::redact(strtolower($stderr));

        if (
            str_contains($normalized, 'connection timed out')
            || str_contains($normalized, 'operation timed out')
            || str_contains($normalized, 'connecttimeout')
        ) {
            return new self(self::KIND_TIMEOUT, self::redact($stderr), [
                'operation' => $operation,
                'exit_code' => $exitCode,
            ]);
        }

        if (
            str_contains($normalized, 'permission denied')
            || str_contains($normalized, 'authentication failed')
            || str_contains($normalized, 'no more authentication methods')
            || str_contains($normalized, 'invalid format')
        ) {
            return new self(self::KIND_AUTH_FAILURE, self::redact($stderr), [
                'operation' => $operation,
                'exit_code' => $exitCode,
            ]);
        }

        if (
            str_contains($normalized, 'host key verification failed')
            || str_contains($normalized, 'remote host identification has changed')
            || str_contains($normalized, 'host key does not match')
            || (str_contains($normalized, 'mismatch') && str_contains($normalized, 'host key'))
        ) {
            return new self(self::KIND_HOST_KEY_MISMATCH, self::redact($stderr), [
                'operation' => $operation,
                'exit_code' => $exitCode,
            ]);
        }

        if ($exitCode === 20 || str_contains($normalized, 'pppoe admission limit')) {
            return new self(self::KIND_PPPOE_ADMISSION, self::redact($stderr), [
                'operation' => $operation,
                'exit_code' => $exitCode,
            ]);
        }

        if ($exitCode === 21 || str_contains($normalized, 'allow_entitlement_mutations=false')) {
            return new self(self::KIND_LICENSE_MUTATION_BLOCKED, self::redact($stderr), [
                'operation' => $operation,
                'exit_code' => $exitCode,
            ]);
        }

        if (
            str_contains($normalized, 'no route to host')
            || str_contains($normalized, 'network is unreachable')
            || str_contains($normalized, 'connection refused')
            || str_contains($normalized, 'connection reset')
            || str_contains($normalized, 'name or service not known')
            || str_contains($normalized, 'could not resolve hostname')
        ) {
            return new self(self::KIND_UNREACHABLE, self::redact($stderr), [
                'operation' => $operation,
                'exit_code' => $exitCode,
            ]);
        }

        if ($operation === 'apply') {
            return new self(
                self::KIND_APPLY_FAILED,
                self::redact($stderr !== '' ? $stderr : 'Appliance apply failed.'),
                ['operation' => $operation, 'exit_code' => $exitCode],
            );
        }

        return new self(
            self::KIND_UNREACHABLE,
            self::redact($stderr !== '' ? $stderr : 'SSH appliance command failed.'),
            ['operation' => $operation, 'exit_code' => $exitCode],
        );
    }

    public static function fromThrowable(Throwable $throwable, string $operation = 'unknown'): self
    {
        if ($throwable instanceof self) {
            return $throwable;
        }

        if ($throwable instanceof \JsonException) {
            return new self(
                self::KIND_MALFORMED_RESPONSE,
                'Malformed appliance JSON: '.$throwable->getMessage(),
                ['operation' => $operation],
                $throwable,
            );
        }

        if ($throwable instanceof RuntimeException) {
            return self::fromProcessFailure($throwable->getMessage(), 1, $operation);
        }

        return new self(
            self::KIND_UNREACHABLE,
            self::redact($throwable->getMessage()),
            ['operation' => $operation],
            $throwable,
        );
    }

    public static function redact(string $value): string
    {
        $redacted = preg_replace('/-----BEGIN [A-Z0-9 ]+-----.*?-----END [A-Z0-9 ]+-----/s', '[REDACTED_KEY]', $value) ?? $value;
        $redacted = preg_replace('/\b(?:password|secret|token)=([^\s]+)/i', '$0=[REDACTED]', $redacted) ?? $redacted;

        return trim($redacted);
    }
}
