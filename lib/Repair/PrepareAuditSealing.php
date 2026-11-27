<?php

/**
 * Give an instance the partial index and cutover marker the audit seal backlog needs.
 *
 * Both come from the post-schema half of two migrations: the partial index
 * `idx_audit_unsealed` (Version1Date20261113210000) and the cutover marker
 * `audit_seal_cutover_id` (Version1Date20261124193002). Nextcloud runs the
 * migrations of a FRESH install schema-only, which skips every post-schema
 * step, so a fresh instance got neither: the backlog cursor falls back to a
 * primary-key scan, and `verifyChain()` never reports an unsealed row written
 * after the cutover, because there is no cutover. This step runs those two
 * post-schema steps themselves, so their logic stays in one place. Both are
 * idempotent: the index is created only when missing, and a recorded marker is
 * never moved.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
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

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Migration\Version1Date20261113210000;
use OCA\OpenRegister\Migration\Version1Date20261124193002;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Runs the post-schema steps of the audit seal migrations that a fresh install skips.
 *
 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
 */
class PrepareAuditSealing implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param Version1Date20261113210000 $unsealedIndex The step creating `idx_audit_unsealed`.
	 * @param Version1Date20261124193002 $cutover       The step recording `audit_seal_cutover_id`.
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
	 */
	public function __construct(
		private readonly Version1Date20261113210000 $unsealedIndex,
		private readonly Version1Date20261124193002 $cutover,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
	 */
	public function getName(): string {
		return 'Add the unsealed audit row index and record the audit seal cutover when they are missing';
	}//end getName()

	/**
	 * Run both post-schema steps; a failure of one never stops the other or the install.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md
	 */
	public function run(IOutput $output): void {
		$noSchema = static fn () => null;
		foreach (['the unsealed audit row index' => $this->unsealedIndex, 'the audit seal cutover' => $this->cutover] as $what => $step) {
			try {
				$step->postSchemaChange($output, $noSchema, []);
			} catch (Throwable $e) {
				$output->warning(sprintf('Could not prepare %s: %s', $what, $e->getMessage()));
			}
		}
	}//end run()
}//end class
