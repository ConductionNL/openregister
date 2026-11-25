<?php

/**
 * Audit seal backlog — record the hash-chain cutover marker.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/audit-seal-backlog-repair/tasks.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;

/**
 * Persist the first audit id written after this migration as the cutover marker.
 *
 * The chain verification used to skip every row without a hash as "pre-migration", so a
 * row left unsealed minutes ago passed verification exactly like a row from
 * before the hash chain existed. The marker tells the two apart: the rows that
 * exist when this step runs are the legacy population and keep being skipped;
 * a row written after it with no hash fails verification until a seal pass
 * chains it. Recording the marker as an id rather than a time keeps it exact:
 * ids are assigned in write order, and timestamps carry the server's clock.
 *
 * Written once: a marker that is already there is left alone, so re-running the
 * step never reclassifies rows.
 *
 * @spec openspec/changes/audit-seal-backlog-repair/tasks.md
 */
class Version1Date20261124193002 extends TargetedMigrationStep {

	/**
	 * The app-config key verifyChain() reads (AuditHashService::SEAL_CUTOVER_KEY).
	 */
	public const CUTOVER_KEY = 'audit_seal_cutover_id';

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $connection The database connection.
	 * @param IAppConfig    $appConfig  The app config the marker is stored in.
	 */
	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Record the cutover marker after the schema step, unless one is recorded.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/audit-seal-backlog-repair/specs/audit-hash-chain/spec.md#requirement-verification-shall-distinguish-legacy-unsealed-rows-from-unsealed-rows-written-after-the-cutover
	 */
	protected function afterTargetedSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$recorded = $this->appConfig->getValueInt('openregister', self::CUTOVER_KEY, 0);
		if ($recorded > 0) {
			$output->info('Audit seal cutover already recorded at id ' . $recorded . '.');
			return;
		}

		$cutover = ($this->maxAuditId() + 1);
		$this->appConfig->setValueInt('openregister', self::CUTOVER_KEY, $cutover);
		$output->info('Recorded the audit seal cutover: rows from id ' . $cutover . ' on must be sealed.');
	}//end afterTargetedSchemaChange()

	/**
	 * The highest audit row id, or 0 when the trail is empty.
	 *
	 * @return int
	 */
	private function maxAuditId(): int {
		$qb = $this->connection->getQueryBuilder();
		$qb->select($qb->func()->max('id'))->from('openregister_audit_trails');
		$result = $qb->executeQuery();
		$max = $result->fetchOne();
		$result->closeCursor();

		return (int)$max;
	}//end maxAuditId()
}//end class
