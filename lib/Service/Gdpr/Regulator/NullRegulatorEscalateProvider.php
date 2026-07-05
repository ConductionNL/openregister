<?php

/**
 * OpenRegister Gdpr NullRegulatorEscalateProvider
 *
 * The OR-shipped fail-closed default {@see RegulatorEscalateProvider}.
 * Registered at bootstrap under the id the default policy pack selects
 * (`or.default.regulator-escalate.null`), it is the provider a case resolves to
 * whenever the active pack's `regulatorEscalateProvider` selector is unset or
 * names an unregistered provider.
 *
 * It NEVER silently succeeds: `escalate()` always returns a `refused` result
 * (no reference, "no regulator provider bound"). This encodes the ADR-005 /
 * CWE-863 security invariant in code — an unbound regulator seam MUST fail
 * closed, so a case can never be recorded as escalated when no leaf regulator
 * provider is available.
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

/**
 * Fail-closed default regulator-escalate provider: never escalates.
 */
final class NullRegulatorEscalateProvider implements RegulatorEscalateProvider
{

    /**
     * The stable id the default policy pack binds for this fail-closed provider.
     *
     * @var string
     */
    public const PROVIDER_ID = 'or.default.regulator-escalate.null';

    /**
     * {@inheritDoc}
     *
     * @return string The fail-closed default provider id.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
     */
    public function getProviderId(): string
    {
        return self::PROVIDER_ID;
    }//end getProviderId()

    /**
     * Always refuses — no escalation is performed, no reference is minted.
     *
     * No leaf regulator provider is bound, so there is no supervisory authority
     * to escalate to; the only safe answer is an explicit refusal (never a
     * silent success).
     *
     * @param string               $caseUuid The case object uuid.
     * @param array<string, mixed> $case     The case's serialised payload (unused).
     *
     * @return RegulatorEscalateResult Always a `refused` result.
     *
     * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
     *
     * @SuppressWarnings(PHPMD.StaticAccess) RegulatorEscalateResult::refused is a named constructor — no DI alternative.
     */
    public function escalate(string $caseUuid, array $case): RegulatorEscalateResult
    {
        return RegulatorEscalateResult::refused(
            providerId: self::PROVIDER_ID,
            message: 'No regulator-escalate provider is bound; escalation not performed (fail-closed default).'
        );
    }//end escalate()
}//end class
