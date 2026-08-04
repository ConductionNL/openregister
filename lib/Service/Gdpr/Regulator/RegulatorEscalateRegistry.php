<?php

/**
 * OpenRegister Gdpr RegulatorEscalateRegistry
 *
 * Discovers and resolves {@see RegulatorEscalateProvider} implementations
 * registered by leaf apps at bootstrap. Mirrors ObjectSourceRegistry /
 * IntegrationRegistry / EvidenceSourceRegistry: each app registers its provider
 * from its own boot hook, the registry is a single per-request shared service,
 * and duplicate provider ids follow a first-wins policy with a logged warning
 * (ADR-019).
 *
 * The read path resolves the provider named by the active
 * `dsarPolicyPack.regulatorEscalateProvider` selector. Resolution is
 * FAIL-CLOSED: an unset or unregistered selector resolves to the OR-shipped
 * fail-closed default provider (never null, never a silent success) so an
 * unbound seam can never be mistaken for "escalated" (ADR-005 / CWE-863).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Regulator
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Gdpr\Regulator;

use Psr\Log\LoggerInterface;

/**
 * Registry of all RegulatorEscalateProvider implementations on this instance.
 */
class RegulatorEscalateRegistry
{

    /**
     * Registered providers, keyed by provider id.
     *
     * @var array<string, RegulatorEscalateProvider>
     */
    private array $providers = [];

    /**
     * Constructor.
     *
     * @param LoggerInterface               $logger  Logger for collision + fail-closed warnings.
     * @param NullRegulatorEscalateProvider $default Fail-closed default returned when resolution finds no bound provider.
     *
     * @return void
     */
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly NullRegulatorEscalateProvider $default
    ) {
        // The fail-closed default is itself registered so a pack that names it
        // explicitly (as the default pack does) resolves through the same path.
        $this->providers[$this->default->getProviderId()] = $this->default;
    }//end __construct()

    /**
     * Register a provider with the registry.
     *
     * Duplicate id: first registration wins, the second logs a warning.
     *
     * @param RegulatorEscalateProvider $provider The provider to register.
     *
     * @return bool True when accepted, false when rejected (duplicate id).
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
     */
    public function addProvider(RegulatorEscalateProvider $provider): bool
    {
        $id = $provider->getProviderId();
        if (isset($this->providers[$id]) === true) {
            $this->logger->warning(
                sprintf(
                    '[RegulatorEscalateRegistry] duplicate provider id "%s" — keeping first registration',
                    $id
                )
            );
            return false;
        }

        $this->providers[$id] = $provider;
        return true;
    }//end addProvider()

    /**
     * Look up a provider by id (no fail-closed fallback).
     *
     * Prefer {@see resolve()} on the case path; `get()` is the raw lookup used
     * by tests and diagnostics.
     *
     * @param string $id The provider id.
     *
     * @return RegulatorEscalateProvider|null Provider, or null when unregistered.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
     */
    public function get(string $id): ?RegulatorEscalateProvider
    {
        return $this->providers[$id] ?? null;
    }//end get()

    /**
     * Resolve the regulator provider for a pack selector, FAIL-CLOSED.
     *
     * Returns the provider registered under $selectorId when present; otherwise
     * (selector unset, empty, or naming an unregistered provider) returns the
     * OR-shipped fail-closed default and logs a warning. NEVER returns null and
     * NEVER treats "provider unavailable" as "escalated" (ADR-005 / CWE-863).
     *
     * @param string|null $selectorId The active pack's regulatorEscalateProvider selector.
     *
     * @return RegulatorEscalateProvider The bound provider, or the fail-closed default.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
     */
    public function resolve(?string $selectorId): RegulatorEscalateProvider
    {
        if ($selectorId !== null && $selectorId !== '' && isset($this->providers[$selectorId]) === true) {
            return $this->providers[$selectorId];
        }

        $this->logger->warning(
            sprintf(
                '[RegulatorEscalateRegistry] regulatorEscalateProvider "%s" is unset or unregistered — '
                .'resolving fail-closed default "%s" (escalation NOT performed)',
                ($selectorId ?? '(null)'),
                $this->default->getProviderId()
            )
        );
        return $this->default;
    }//end resolve()

    /**
     * List every registered provider, irrespective of enablement.
     *
     * @return array<int, RegulatorEscalateProvider>
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
     */
    public function list(): array
    {
        return array_values($this->providers);
    }//end list()

    /**
     * Return the ids of every registered provider.
     *
     * @return array<int, string>
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
     */
    public function listIds(): array
    {
        return array_keys($this->providers);
    }//end listIds()
}//end class
