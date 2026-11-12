<?php

/**
 * Expiry of one row scanned by the archival retention sweep.
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

use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Service\Archival\RetentionEvaluatorFactory;
use OCA\OpenRegister\Service\Archival\RetentionEvaluatorInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads a row's `_created` and evaluates the schema's retention rules against it.
 *
 * A row whose expiry cannot be computed is logged and treated as not due.
 *
 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
 */
final class ArchivalRowExpiry {

	/**
	 * Retention evaluator.
	 *
	 * @var RetentionEvaluatorInterface
	 */
	private readonly RetentionEvaluatorInterface $retentionEvaluator;

	/**
	 * Constructor
	 *
	 * @param LoggerInterface $logger Logger for rows that are skipped
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
		$this->retentionEvaluator = (new RetentionEvaluatorFactory())->create(logger: $logger);
	}//end __construct()

	/**
	 * The row's expiry, or null when it cannot be computed.
	 *
	 * @param ArchivalSweep        $sweep The schema being swept.
	 * @param array<string, mixed> $row   Raw table row.
	 *
	 * @return DateTimeImmutable|null
	 *
	 * @spec openspec/changes/add-archival-annotation-support/tasks.md#task-5
	 */
	public function expiryOf(ArchivalSweep $sweep, array $row): ?DateTimeImmutable {
		$createdAt = $this->createdAtOf(sweep: $sweep, row: $row);
		if ($createdAt === null) {
			return null;
		}

		try {
			$row    = $this->stripMetadataColumns(row: $row);
			$result = $this->retentionEvaluator->evaluate(annotation: $sweep->annotation, row: $row, createdAt: $createdAt);
		} catch (Exception $error) {
			$this->warn(message: sprintf('Skipping row on "%s": %s', $sweep->schemaSlug, $error->getMessage()));
			return null;
		}

		return new DateTimeImmutable($result['expiresAt']);
	}//end expiryOf()

	/**
	 * The row's `_created` time, or null when it is absent or unparseable.
	 *
	 * A ROW WITH NO `_created` IS NEVER SWEPT: without a creation timestamp
	 * there is no expiry to compute.
	 *
	 * @param ArchivalSweep        $sweep The schema being swept.
	 * @param array<string, mixed> $row   Raw table row.
	 *
	 * @return DateTimeImmutable|null
	 */
	private function createdAtOf(ArchivalSweep $sweep, array $row): ?DateTimeImmutable {
		$createdRaw = ($row['_created'] ?? null);
		if ($createdRaw === null) {
			return null;
		}

		try {
			return new DateTimeImmutable((string)$createdRaw);
		} catch (Exception $error) {
			$this->warn(
				message: sprintf('Row with unparseable _created skipped on "%s": %s', $sweep->schemaSlug, $error->getMessage())
			);
			return null;
		}
	}//end createdAtOf()

	/**
	 * Drop the magic-table metadata columns (`_uuid`, `_created`, ...) from a
	 * raw row so the row passed to the condition evaluator carries only the
	 * schema-declared fields.
	 *
	 * @param array<string, mixed> $row Raw row as returned by the QueryBuilder cursor.
	 *
	 * @return array<string, mixed> Filtered row.
	 */
	private function stripMetadataColumns(array $row): array {
		$filtered = [];
		foreach ($row as $key => $value) {
			if (is_string($key) === true && str_starts_with($key, '_') === true) {
				continue;
			}

			$filtered[$key] = $value;
		}

		return $filtered;
	}//end stripMetadataColumns()

	/**
	 * Log a warning under the sweep's prefix.
	 *
	 * @param string $message Message after the prefix.
	 *
	 * @return void
	 */
	private function warn(string $message): void {
		$this->logger->warning('[ArchivalRetentionTask] '.$message, ['app' => 'openregister']);
	}//end warn()
}//end class
