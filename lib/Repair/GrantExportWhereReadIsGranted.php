<?php

/**
 * GrantExportWhereReadIsGranted — the upgrade that keeps every working export
 * working, and makes the new verb visible enough to narrow.
 *
 * @category Repair
 * @package  OCA\OpenRegister\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Export\ExportRightService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Repair step: copy the `read` grant into an `export` grant on every schema
 * that declares one and does not yet declare the other.
 *
 * WHY A GRANT IS WRITTEN RATHER THAN INFERRED. `ExportRightService` already
 * falls back to `read` while no `export` key exists, so an instance would keep
 * working without this step. What it would not have is a grant an administrator
 * can see. A verb nobody can find in the block is a verb nobody narrows, and
 * narrowing it is the entire point of publishing it (design D-2).
 *
 * Idempotent: a schema that already declares `export` is left exactly as it is,
 * including one an administrator has already narrowed. Never throws, because a
 * single unreadable schema must not abort an app upgrade.
 *
 * @spec openspec/specs/authorization-rbac/spec.md
 */
class GrantExportWhereReadIsGranted implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapperInterface    $schemaMapper Schema lookups and writes.
	 * @param LoggerInterface $logger       Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function getName(): string {
		return 'Grant the export right wherever the read right is granted';
	}//end getName()

	/**
	 * Walk every schema and write the export grant where it is missing.
	 *
	 * @param IOutput $output Migration output handle.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	public function run(IOutput $output): void {
		$schemas = (new SchemaListing(schemaMapper: $this->schemaMapper, logger: $this->logger))->allOrSkip(step: 'GrantExportWhereReadIsGranted');
		if ($schemas === null) {
			return;
		}

		$granted = 0;
		$failed = 0;
		foreach ($schemas as $schema) {
			if ($schema instanceof Schema === false) {
				continue;
			}

			$granted += $this->grantOn(schema: $schema, failed: $failed);
		}

		$output->info(self::summary(granted: $granted, failed: $failed));
	}//end run()

	/**
	 * The closing line: grants written and schemas that could not be written.
	 *
	 * @param int $granted Schemas given the export grant.
	 * @param int $failed  Schemas whose write was refused.
	 *
	 * @return string The line.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private static function summary(int $granted, int $failed): string {
		return sprintf(
			'Wrote the export grant onto %d schema(s), %d could not be written. '
			. 'Every schema that already named the export right was left alone.',
			$granted,
			$failed
		);
	}//end summary()

	/**
	 * Write the export grant onto one schema, when it is missing.
	 *
	 * @param Schema $schema The schema to walk.
	 * @param int    $failed Running count of schemas that could not be written, by reference.
	 *
	 * @return int 1 when a grant was written, 0 otherwise.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function grantOn(Schema $schema, int &$failed): int {
		$authorization = self::withExportGrant(schema: $schema);
		if ($authorization === null) {
			return 0;
		}

		try {
			$schema->setAuthorization($authorization);
			$this->schemaMapper->update($schema);
		} catch (Throwable $e) {
			$failed++;
			$this->logRefusedWrite(schema: $schema, error: $e);
			return 0;
		}

		return 1;
	}//end grantOn()

	/**
	 * Log a schema whose export grant could not be written.
	 *
	 * @param Schema    $schema The schema.
	 * @param Throwable $error  Why the write was refused.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private function logRefusedWrite(Schema $schema, Throwable $error): void {
		$this->logger->warning(
			'[GrantExportWhereReadIsGranted] Could not write the export grant on schema '
			. (string)$schema->getId() . ': ' . $error->getMessage()
		);
	}//end logRefusedWrite()

	/**
	 * The schema's authorization block with an export grant equal to its read grant,
	 * or null when it needs none.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return array|null The block to write, or null to leave the schema alone.
	 *
	 * @spec openspec/specs/authorization-rbac/spec.md
	 */
	private static function withExportGrant(Schema $schema): ?array {
		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false || $authorization === []) {
			// No block at all. Nothing is narrowed here, so nothing has to be
			// widened: the fallback to `read` already answers for this schema.
			return null;
		}

		if (isset($authorization[ExportRightService::ACTION]) === true) {
			return null;
		}

		$read = ($authorization[ExportRightService::FALLBACK_ACTION] ?? null);
		if (is_array($read) === false) {
			return null;
		}

		$authorization[ExportRightService::ACTION] = array_values($read);

		return $authorization;
	}//end withExportGrant()
}//end class
