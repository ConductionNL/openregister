<?php

/**
 * RegulatorEscalateRegistryInterface: what callers outside the Service component use of RegulatorEscalateRegistry.
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
 * The registered regulator-escalation providers, resolved by id.
 */
interface RegulatorEscalateRegistryInterface {

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
	 * @spec openspec/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function resolve(?string $selectorId): RegulatorEscalateProvider;

	/**
	 * Return the ids of every registered provider.
	 *
	 * @return array<int, string>
	 *
	 * @spec openspec/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function listIds(): array;
}//end interface
