<?php

/**
 * Records the reveals of an internal run (D-3 of sensitive-field-reveal-audit).
 *
 * An export, an aggregation job or a flow run reads protected values as part
 * of its work. The run is wrapped here: every audited value it reveals is
 * counted, and when it ends one entry names the process, the run and the
 * count. The entry is written at the end of the run, because a background job
 * has no request end where the reveal audit middleware would write it.
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
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

/**
 * Wraps an internal run in a reveal count.
 *
 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
 */
class ProcessRevealAudit implements ProcessRevealAuditInterface {
	/**
	 * Constructor.
	 *
	 * @param RevealCollector        $collector The request's collector, shared with the read path.
	 * @param RevealFlusherInterface $flusher   Writes what was collected.
	 */
	public function __construct(
		private readonly RevealCollector $collector,
		private readonly RevealFlusherInterface $flusher,
	) {
	}//end __construct()

	/**
	 * Run the operation as the named process, and record its reveals as one entry.
	 *
	 * The entry is written even when the operation throws: what was read before
	 * the failure was still read.
	 *
	 * @param RevealingProcess $run       The process identity and its run.
	 * @param callable         $operation The work.
	 *
	 * @return mixed What the operation returns.
	 *
	 * @spec openspec/changes/sensitive-field-reveal-audit/specs/row-field-level-security/spec.md
	 */
	public function run(RevealingProcess $run, callable $operation): mixed {
		$this->collector->beginProcess(run: $run);
		try {
			return $operation();
		} finally {
			$this->collector->endProcess();
			$this->flusher->flush();
		}
	}//end run()
}//end class
