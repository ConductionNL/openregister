<?php

/**
 * OpenRegister AppHost — Observability Manifest
 *
 * Parses and validates the `observability` block of a host app's
 * `src/manifest.json` into descriptor value objects, applying ADR-040
 * defaults when the block (or a sub-section) is absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category ValueObject
 * @package  OCA\OpenRegister\AppHost\Observability
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\AppHost\Observability;

/**
 * Validated observability configuration for a single host app.
 */
final class ObservabilityManifest
{
    /**
     * Default status-code policy (ADR-006: 503 on critical failure).
     */
    public const POLICY_ADR006 = 'adr006';

    /**
     * Always-200 policy (decidesk REQ-API-004 reverse-proxy contract).
     */
    public const POLICY_ALWAYS200 = 'always200';

    /**
     * Closed set of supported status-code policies.
     *
     * @var string[]
     */
    public const POLICIES = [self::POLICY_ADR006, self::POLICY_ALWAYS200];

    /**
     * Constructor.
     *
     * @param string                   $appId            Calling app id.
     * @param HealthCheckDescriptor[]   $checks          Parsed health checks.
     * @param MetricDescriptor[]        $metrics         Parsed metrics.
     * @param string                   $statusCodePolicy adr006|always200.
     * @param bool                     $cors             Emit CORS headers when true.
     * @param string[]                 $diagnostics     Validation diagnostics (block fell back to defaults).
     */
    public function __construct(
        public readonly string $appId,
        public readonly array $checks,
        public readonly array $metrics,
        public readonly string $statusCodePolicy=self::POLICY_ADR006,
        public readonly bool $cors=false,
        public readonly array $diagnostics=[]
    ) {
    }//end __construct()

    /**
     * Build an observability config from a decoded manifest.
     *
     * Invalid descriptors do not throw out of this method: they are collected
     * into `$diagnostics` and the config falls back to safe defaults so the
     * endpoint never 500s on a bad manifest (Descriptor Validation requirement).
     *
     * @param string               $appId    Calling app id.
     * @param array<string, mixed> $manifest Decoded manifest.json.
     *
     * @return self
     */
    public static function fromManifest(string $appId, array $manifest): self
    {
        $block = $manifest['observability'] ?? null;
        if (is_array($block) === false) {
            return self::defaults(appId: $appId, manifest: $manifest);
        }

        $diagnostics = [];

        // Health section.
        $statusCodePolicy = self::POLICY_ADR006;
        $cors             = false;
        $checks           = [];
        $healthValid      = true;

        $health = $block['health'] ?? null;
        if (is_array($health) === true) {
            $policy = $health['statusCodePolicy'] ?? self::POLICY_ADR006;
            if (is_string($policy) === true && in_array($policy, self::POLICIES, true) === true) {
                $statusCodePolicy = $policy;
            } else {
                $diagnostics[] = sprintf('Invalid statusCodePolicy "%s"; defaulting to adr006.', is_scalar($policy) === true ? (string) $policy : gettype($policy));
            }

            $cors = ($health['cors'] ?? false) === true;

            $rawChecks = $health['checks'] ?? [];
            if (is_array($rawChecks) === true) {
                foreach ($rawChecks as $index => $rawCheck) {
                    if (is_array($rawCheck) === false) {
                        $diagnostics[] = sprintf('Health check #%d is not an object.', $index);
                        $healthValid   = false;
                        continue;
                    }

                    try {
                        $checks[] = HealthCheckDescriptor::fromArray(raw: $rawCheck, index: (int) $index);
                    } catch (ObservabilityValidationException $e) {
                        $diagnostics[] = $e->getMessage();
                        $healthValid   = false;
                    }
                }
            } else {
                $diagnostics[] = 'observability.health.checks must be an array.';
                $healthValid   = false;
            }
        }

        // If the health block was malformed enough to lose checks, fall back to default health.
        if ($healthValid === false || $checks === []) {
            $checks = self::defaultChecks(manifest: $manifest);
        }

        // Metrics section.
        $metrics    = [];
        $rawMetrics = $block['metrics'] ?? [];
        if (is_array($rawMetrics) === true) {
            foreach ($rawMetrics as $index => $rawMetric) {
                if (is_array($rawMetric) === false) {
                    $diagnostics[] = sprintf('Metric #%d is not an object.', $index);
                    continue;
                }

                try {
                    $metrics[] = MetricDescriptor::fromArray(raw: $rawMetric);
                } catch (ObservabilityValidationException $e) {
                    // Skip the single bad metric; implicit info/up always still serve.
                    $diagnostics[] = $e->getMessage();
                }
            }
        } else {
            $diagnostics[] = 'observability.metrics must be an array.';
        }

        return new self(
            appId: $appId,
            checks: $checks,
            metrics: $metrics,
            statusCodePolicy: $statusCodePolicy,
            cors: $cors,
            diagnostics: $diagnostics
        );
    }//end fromManifest()

    /**
     * Build the default config for an app with no (or an unusable)
     * observability block: a `database` check, plus `orAvailable` when the
     * manifest declares OpenRegister registers; metrics = implicit only.
     *
     * @param string               $appId    Calling app id.
     * @param array<string, mixed> $manifest Decoded manifest.json.
     *
     * @return self
     */
    public static function defaults(string $appId, array $manifest): self
    {
        return new self(
            appId: $appId,
            checks: self::defaultChecks(manifest: $manifest),
            metrics: [],
            statusCodePolicy: self::POLICY_ADR006,
            cors: false,
            diagnostics: []
        );
    }//end defaults()

    /**
     * Build the default health check list from a manifest.
     *
     * @param array<string, mixed> $manifest Decoded manifest.json.
     *
     * @return HealthCheckDescriptor[]
     */
    private static function defaultChecks(array $manifest): array
    {
        $checks = [
            new HealthCheckDescriptor(id: 'database', type: 'database', severity: 'critical'),
        ];

        if (self::declaresOpenRegisterRegisters(manifest: $manifest) === true) {
            $checks[] = new HealthCheckDescriptor(id: 'or', type: 'orAvailable', severity: 'critical');
        }

        return $checks;
    }//end defaultChecks()

    /**
     * Heuristic: does the manifest declare OpenRegister registers? Looks at the
     * common manifest shapes (`registers`, `openregister.registers`,
     * `config.registers`) so the default `orAvailable` check is added for
     * OR-backed apps.
     *
     * @param array<string, mixed> $manifest Decoded manifest.json.
     *
     * @return bool
     */
    private static function declaresOpenRegisterRegisters(array $manifest): bool
    {
        if (isset($manifest['registers']) === true && empty($manifest['registers']) === false) {
            return true;
        }

        $openregister = $manifest['openregister'] ?? null;
        if (is_array($openregister) === true && empty($openregister['registers']) === false) {
            return true;
        }

        $config = $manifest['config'] ?? null;
        if (is_array($config) === true && empty($config['registers']) === false) {
            return true;
        }

        return false;
    }//end declaresOpenRegisterRegisters()
}//end class
