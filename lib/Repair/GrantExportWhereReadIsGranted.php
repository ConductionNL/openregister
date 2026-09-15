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
 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Repair;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
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
 */
class GrantExportWhereReadIsGranted implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param SchemaMapper    $schemaMapper Schema lookups and writes.
	 * @param LoggerInterface $logger       Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SchemaMapper $schemaMapper,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Human-readable step name surfaced in occ and the admin UI.
	 *
	 * @return string The step name.
	 *
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
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
	 * @spec openspec/changes/export-as-its-own-right/specs/authorization-rbac/spec.md
	 */
	public function run(IOutput $output): void {
		try {
			$schemas = $this->schemaMapper->findAll(_rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			$this->logger->info(
				'[GrantExportWhereReadIsGranted] Could not list schemas, skipping: ' . $e->getMessage()
			);
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

		$output->info(
			sprintf(
				'Wrote the export grant onto %d schema(s), %d could not be written. '
				. 'Every schema that already named the export right was left alone.',
				$granted,
				$failed
			)
		);
	}//end run()

	/**
	 * Write the export grant onto one schema, when it is missing.
	 *
	 * @param Schema $schema The schema to walk.
	 * @param int    $failed Running count of schemas that could not be written, by reference.
	 *
	 * @return int 1 when a grant was written, 0 otherwise.
	 */
	private function grantOn(Schema $schema, int &$failed): int {
		$authorization = $schema->getAuthorization();
		if (is_array($authorization) === false || $authorization === []) {
			// No block at all. Nothing is narrowed here, so nothing has to be
			// widened: the fallback to `read` already answers for this schema.
			return 0;
		}

		if (isset($authorization[ExportRightService::ACTION]) === true) {
			return 0;
		}

		$read = ($authorization[ExportRightService::FALLBACK_ACTION] ?? null);
		if (is_array($read) === false) {
			return 0;
		}

		$authorization[ExportRightService::ACTION] = array_values($read);

		try {
			$schema->setAuthorization($authorization);
			$this->schemaMapper->update($schema);
		} catch (Throwable $e) {
			$failed++;
			$this->logger->warning(
				'[GrantExportWhereReadIsGranted] Could not write the export grant on schema '
				. (string)$schema->getId() . ': ' . $e->getMessage()
			);
			return 0;
		}

		return 1;
	}//end grantOn()
}//end class
