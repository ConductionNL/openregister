<?php

/**
 * The running tally of one batch of a schema run.
 *
 * A migration or revalidation batch walks its objects in id order; this
 * value keeps the report counters and the highest object id reached, and
 * writes both back onto the run when the batch is closed. Nothing reaches
 * the run before {@see close()}, so a batch that throws leaves the run's
 * report and cursor as they were.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schema
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Schema;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaRun;

/**
 * Report counters and cursor of one schema-run batch.
 */
final class SchemaRunBatch {

	/**
	 * The report counters.
	 *
	 * @var array<string, mixed>
	 */
	private array $report;

	/**
	 * The highest object id reached.
	 *
	 * @var int
	 */
	private int $cursor;

	/**
	 * Constructor.
	 *
	 * @param SchemaRun            $run         The run the batch belongs to.
	 * @param Schema               $schema      The schema the objects are processed against.
	 * @param array<string, mixed> $emptyReport The report of a run that has none yet.
	 */
	public function __construct(
		public readonly SchemaRun $run,
		public readonly Schema $schema,
		array $emptyReport
	) {
		$this->report = ($run->getReport() ?? $emptyReport);
		$this->cursor = $run->getCursor();

	}//end __construct()

	/**
	 * Move the cursor up to an object, never down.
	 *
	 * @param ObjectEntity $object The object reached.
	 *
	 * @return void
	 */
	public function reach(ObjectEntity $object): void {
		$this->cursor = max($this->cursor, (int)$object->getId());

	}//end reach()

	/**
	 * Count one more object under a report key.
	 *
	 * @param string $key The report key.
	 *
	 * @return void
	 */
	public function tally(string $key): void {
		$this->report[$key] = (($this->report[$key] ?? 0) + 1);

	}//end tally()

	/**
	 * Count one more processed object on the run.
	 *
	 * @return void
	 */
	public function markProcessed(): void {
		$this->run->setProcessed(($this->run->getProcessed() + 1));

	}//end markProcessed()

	/**
	 * Write the cursor and the report onto the run.
	 *
	 * @return void
	 */
	public function close(): void {
		$this->run->setCursor($this->cursor);
		$this->run->setReport($this->report);

	}//end close()
}//end class
