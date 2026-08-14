<?php

/**
 * OpenRegister Gdpr RegulatorEscalateProvider
 *
 * Public contract a leaf app implements to escalate / dossier a DSAR case to a
 * supervisory authority (e.g. pipelinq NL → the Autoriteit Persoonsgegevens in
 * Phase-3). Providers are registered into {@see RegulatorEscalateRegistry} at
 * app bootstrap (ADR-019), so OpenRegister core never hard-codes a
 * jurisdiction-specific escalation target: a provider that is not registered
 * cannot escalate, and the case resolves the OR-shipped fail-closed default
 * (which refuses) instead.
 *
 * A PHP interface + registry + runtime resolution is the legitimate
 * ADR-019 / ADR-031 registry-seam exception. The escalate operation returns an
 * outcome carrying a regulator reference and a status (see
 * {@see RegulatorEscalateResult}); a missing or unavailable provider MUST NOT be
 * recorded as a silent success (ADR-005 / CWE-863).
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
 * A registerable regulator-escalation provider for a DSAR case.
 */
interface RegulatorEscalateProvider {
	/**
	 * Stable provider id addressed by a `dsarPolicyPack.regulatorEscalateProvider`
	 * selector. MUST be unique across the registry; collisions are rejected
	 * first-wins at registration time.
	 *
	 * @return string The provider id (e.g. `or.default.regulator-escalate.null`).
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function getProviderId(): string;

	/**
	 * Escalate / dossier a DSAR case to a supervisory authority.
	 *
	 * The provider is handed the case's serialised payload (subject identifier,
	 * jurisdiction, denial ground, dossier, …) so it can construct and submit
	 * the escalation. It MUST return a {@see RegulatorEscalateResult} carrying a
	 * regulator reference and a status — `escalated` only when the authority
	 * actually accepted the escalation, otherwise `refused`. It MUST NOT report
	 * success when no escalation was performed (fail-closed).
	 *
	 * @param string $caseUuid The case object uuid.
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return RegulatorEscalateResult The escalation outcome (reference + status).
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function escalate(string $caseUuid, array $case): RegulatorEscalateResult;
}//end interface
