<?php

/**
 * ProcessRevealAuditInterface: ProcessRevealAudit as callers outside the Service component reach it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Runs an internal process and records, once, that it revealed audited values.
 */
interface ProcessRevealAuditInterface {
	/**
	 * Run the operation as the named process, and record its reveals as one entry.
	 *
	 * @param RevealingProcess $run       The process identity and its run.
	 * @param callable         $operation The work.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function run(RevealingProcess $run, callable $operation): mixed;
}//end interface
