<?php

/**
 * ExplicitRevealTrailMaskerInterface: what callers outside the component use of ExplicitRevealTrailMasker.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * The explicit-reveal masking the audit trail endpoints apply.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface ExplicitRevealTrailMaskerInterface {

	/**
	 * The entries as arrays, their explicit-reveal values masked.
	 *
	 * @param array<int, mixed> $entries Audit entries (AuditTrail entities or arrays).
	 *
	 * @return array<int, mixed> The entries.
	 *
	 * @spec openspec/changes/property-explicit-reveal/specs/row-field-level-security/spec.md
	 */
	public function mask(array $entries): array;
}//end interface
