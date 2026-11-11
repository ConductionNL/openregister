<?php

/**
 * AuditSinkStatusInterface: what callers outside the component use of AuditSinkStatus.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
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

namespace OCA\OpenRegister\Service\Audit;

/**
 * The audit sink status the audit sink endpoint reads and acknowledges.
 *
 * Declares only what callers outside the component use; the rest of the
 * class stays its own.
 */
interface AuditSinkStatusInterface {

	/**
	 * An operator says they have dealt with the gap.
	 *
	 * @return array<string, mixed> The cleared status.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function acknowledge(): array;

	/**
	 * Read the status back.
	 *
	 * @return array<string, mixed> The status, with every key always present.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function read(): array;
}//end interface
