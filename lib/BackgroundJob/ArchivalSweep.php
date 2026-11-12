<?php

/**
 * One `(register, schema)` pass of the archival retention sweep.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use DateTimeInterface;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;

/**
 * The schema being swept, its retention rules and the per-schema counters.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */
final class ArchivalSweep {

	/**
	 * Schema slug, or its id when it has no slug, for log lines.
	 *
	 * @var string
	 */
	public readonly string $schemaSlug;

	/**
	 * Magic table the schema's rows live in.
	 *
	 * @var string
	 */
	public readonly string $tableName;

	/**
	 * Rows read from the table.
	 *
	 * @var integer
	 */
	public int $scanned = 0;

	/**
	 * Rows past their retention.
	 *
	 * @var integer
	 */
	public int $expired = 0;

	/**
	 * Expired rows deleted.
	 *
	 * @var integer
	 */
	public int $deleted = 0;

	/**
	 * Expired rows kept by an active legal hold.
	 *
	 * @var integer
	 */
	public int $held = 0;

	/**
	 * Expired rows kept because their object could not be loaded.
	 *
	 * @var integer
	 */
	public int $unresolved = 0;

	/**
	 * Constructor
	 *
	 * @param Register             $register   Register the schema belongs to
	 * @param Schema               $schema     Schema being swept
	 * @param array<string, mixed> $annotation `x-openregister-archival` annotation
	 * @param DateTimeInterface    $now        Sweep wall-clock anchor
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
	 */
	public function __construct(
		public readonly Register $register,
		public readonly Schema $schema,
		public readonly array $annotation,
		public readonly DateTimeInterface $now,
	) {
		$this->schemaSlug = $schema->getSlug() ?? (string)$schema->getId();
		$this->tableName  = MagicMapperInterface::TABLE_PREFIX.((string)$register->getId()).'_'.((string)$schema->getId());
	}//end __construct()

	/**
	 * The counters, in log order.
	 *
	 * @return array{scanned: int, expired: int, deleted: int, held: int, unresolved: int}
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
	 */
	public function counts(): array {
		return [
			'scanned' => $this->scanned,
			'expired' => $this->expired,
			'deleted' => $this->deleted,
			'held' => $this->held,
			'unresolved' => $this->unresolved,
		];
	}//end counts()
}//end class
