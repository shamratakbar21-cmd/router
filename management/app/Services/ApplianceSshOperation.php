<?php

namespace App\Services;

use App\Exceptions\ApplianceTransportException;
use InvalidArgumentException;

/**
 * Typed SSH operations allowed against the appliance management identity.
 * Arbitrary shell strings are rejected to prevent command injection.
 */
final class ApplianceSshOperation
{
    public const DIAGNOSE = 'diagnose';

    public const OBSERVE = 'observe';

    public const APPLY = 'apply';

    public const CONFIRM = 'confirm';

    public const CLEANUP_OVERLAY = 'cleanup_overlay';

    /**
     * @param  array{overlay?: string, operation?: string, safe_mode_seconds?: int}  $arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments = [],
    ) {
        if (! in_array($name, [self::OBSERVE, self::APPLY, self::CONFIRM, self::CLEANUP_OVERLAY, self::DIAGNOSE], true)) {
            throw new InvalidArgumentException("Unsupported appliance SSH operation [{$name}].");
        }
    }

    public static function diagnose(string $tool, string $target): self
    {
        self::assertDiagnostic($tool, $target);

        return new self(self::DIAGNOSE, ['tool' => $tool, 'target' => $target]);
    }

    public static function assertDiagnostic(string $tool, string $target): void
    {
        $valid = in_array($tool, ['ping', 'traceroute', 'nslookup'], true) && strlen($target) <= 253 && $target !== '';
        if ($valid && ! filter_var($target, FILTER_VALIDATE_IP)) {
            $valid = ! preg_match('/^[0-9.]+$/D', $target);
            foreach (explode('.', $target) as $label) {
                $valid = $valid && strlen($label) <= 63 && preg_match('/^[a-zA-Z0-9](?:[a-zA-Z0-9-]*[a-zA-Z0-9])?$/D', $label);
            }
        }
        if (! $valid) {
            throw new ApplianceTransportException(ApplianceTransportException::KIND_COMMAND_REJECTED, 'Enter a supported tool and a valid IP address or ASCII hostname.');
        }
    }

    private function diagnosticCommand(): string
    {
        $tool = (string) ($this->arguments['tool'] ?? '');
        $target = (string) ($this->arguments['target'] ?? '');
        self::assertDiagnostic($tool, $target);

        return '/usr/sbin/nur-runtimectl diagnose '.$tool.' '.$target;
    }

    public static function observe(): self
    {
        return new self(self::OBSERVE);
    }

    public static function apply(string $overlayDirectory, string $operationUuid, int $safeModeSeconds): self
    {
        self::assertSafePath($overlayDirectory, 'overlay');
        self::assertOperationUuid($operationUuid);

        return new self(self::APPLY, [
            'overlay' => $overlayDirectory,
            'operation' => $operationUuid,
            'safe_mode_seconds' => max(0, $safeModeSeconds),
        ]);
    }

    public static function confirm(string $operationUuid): self
    {
        self::assertOperationUuid($operationUuid);

        return new self(self::CONFIRM, [
            'operation' => $operationUuid,
        ]);
    }

    public static function cleanupOverlay(string $overlayDirectory): self
    {
        self::assertSafePath($overlayDirectory, 'overlay');

        return new self(self::CLEANUP_OVERLAY, [
            'overlay' => $overlayDirectory,
        ]);
    }

    public function remoteCommand(): string
    {
        return match ($this->name) {
            self::DIAGNOSE => $this->diagnosticCommand(),
            self::OBSERVE => '/usr/sbin/nur-runtimectl observe',
            // Paths/UUIDs are pre-validated by assertSafePath/assertOperationUuid.
            // Do not wrap in shell quotes: nur-mgmt-ssh allowlists unquoted overlay paths.
            self::APPLY => sprintf(
                '/usr/sbin/nur-runtimectl apply --overlay %s --operation %s --safe-mode-seconds %d',
                (string) $this->arguments['overlay'],
                (string) $this->arguments['operation'],
                (int) $this->arguments['safe_mode_seconds'],
            ),
            self::CONFIRM => sprintf(
                '/usr/sbin/nur-runtimectl confirm --operation %s',
                (string) $this->arguments['operation'],
            ),
            self::CLEANUP_OVERLAY => sprintf(
                '/bin/rm -rf -- %s',
                (string) $this->arguments['overlay'],
            ),
            default => throw new ApplianceTransportException(
                ApplianceTransportException::KIND_COMMAND_REJECTED,
                "Rejected appliance operation [{$this->name}].",
            ),
        };
    }

    private static function assertOperationUuid(string $operationUuid): void
    {
        if (! preg_match('/^[A-Za-z0-9_-]{8,80}$/D', $operationUuid)) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_COMMAND_REJECTED,
                'Rejected unsafe operation identifier.',
            );
        }
    }

    private static function assertSafePath(string $path, string $label): void
    {
        if (
            ! preg_match('#^/tmp/nurrouter-overlay-[A-Za-z0-9_-]{8,80}$#D', $path)
        ) {
            throw new ApplianceTransportException(
                ApplianceTransportException::KIND_COMMAND_REJECTED,
                "Rejected unsafe {$label} path.",
            );
        }
    }
}
