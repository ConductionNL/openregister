<?php

/**
 * DsarPolicyPackResolverInterface: what callers outside the Service component use of DsarPolicyPackResolver.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Policy
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

namespace OCA\OpenRegister\Service\Gdpr\Policy;

/**
 * Resolves the active DSAR policy pack of a case and the provider ids it names.
 */
interface DsarPolicyPackResolverInterface {

	/**
	 * The full policy-pack payload active for a case (jurisdiction match,
	 * `default` fallback), or null when no pack resolves.
	 *
	 * Public read surface for consumers that need MORE than the two seam
	 * selectors: the DPIA pattern-detection job reads the pack's
	 * `dpiaDetection` block and the privacy-officer notification recipient
	 * resolver reads `privacyOfficerGroup`. Both inherit the resolver's
	 * fail-safe semantics — no pack ⇒ null ⇒ the consumer no-ops.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 * @param bool $systemContext True for background-job (user-less) reads: the
	 *                            pack query bypasses RBAC/tenancy scoping, because
	 *                            a system sweep has no session to scope by.
	 *
	 * @return array<string, mixed>|null The active pack payload, or null.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 *   (Requirement: Detection configuration lives in the DSAR policy pack)
	 */
	public function activePackForCase(array $case, bool $systemContext = false): ?array;

	/**
	 * The active pack's `identityVerifyProvider` selector for a case.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return string|null The selector id, or null when unset/no pack resolves.
	 *
	 * @spec openspec/specs/dsar-identity-verify-seam/spec.md
	 */
	public function identityVerifyProviderId(array $case): ?string;

	/**
	 * The active pack's `regulatorEscalateProvider` selector for a case.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return string|null The selector id, or null when unset/no pack resolves.
	 *
	 * @spec openspec/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function regulatorEscalateProviderId(array $case): ?string;
}//end interface
