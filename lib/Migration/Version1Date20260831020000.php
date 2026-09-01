<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Give an organisation a way to say it is NOT a tenant of this installation.
 *
 * `Organisation` already carried a `type` discriminator, and that one is
 * deliberately NOT an authorization input (ADR-002 Rule 1: the UUID is the only
 * tenant key). So there was no field anything could safely consult to tell a
 * tenant from a counterparty — an organisation that is a tenant somewhere ELSE
 * and interacts with us across the federation.
 *
 * 🔴 That gap is destructive, not cosmetic. The three tenant background jobs
 * select organisations by `status` alone, and TenantPurgeJob PERMANENTLY
 * DELETES what it selects. An archived ketenpartner in the same table is
 * indistinguishable from an archived tenant and is deleted with it.
 *
 * `is_local_tenant` defaults to TRUE so every existing row keeps exactly the
 * meaning it has today; only rows explicitly marked otherwise change behaviour.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-database-migration-must-add-lifecycle-fields-to-organisation-entity
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the counterparty discriminator and its federation link.
 *
 * @spec openspec/specs/tenant-lifecycle/spec.md#requirement-database-migration-must-add-lifecycle-fields-to-organisation-entity
 */
class Version1Date20260831020000 extends SimpleMigrationStep {

	/**
	 * The table this migration changes.
	 */
	private const TABLE = 'openregister_organisations';

	/**
	 * The columns this migration adds.
	 *
	 * @return array<int, array{name: string, type: string, options: array<string, mixed>}> The specifications.
	 */
	private function columnSpecifications(): array {
		return [
			[
				'name' => 'is_local_tenant',
				'type' => Types::BOOLEAN,
				'options' => [
					'notnull' => false,
					'default' => true,
					'comment' => 'True when this organisation is a tenant of THIS installation; '
						. 'false for a federated counterparty. Unlike `type`, this IS '
						. 'consulted by tenancy and by the purge job.',
				],
			],
			[
				'name' => 'remote_instance_url',
				'type' => Types::STRING,
				'options' => [
					'notnull' => false,
					'length' => 512,
					'comment' => 'Base URL of the OpenRegister instance a counterparty is a '
						. 'tenant of; matches FederatedShare.remote_instance_url. '
						. 'Empty for a local tenant.',
				],
			],
		];

	}//end columnSpecifications()

	/**
	 * Add the columns.
	 *
	 * @param IOutput  $output        Migration output.
	 * @param Closure  $schemaClosure The schema closure.
	 * @param array    $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 *
	 * @spec openspec/changes/organisation-as-federated-counterparty/specs/organisation-tenancy-scope/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/* @var ISchemaWrapper $schema The schema wrapper. */
		$schema = $schemaClosure();

		// This class sorts AFTER the one that creates the table, so the guard is
		// the ordinary "already ran" check and not a permanent skip — the trap
		// Version1Date20250102000000 fell into and documents.
		if ($schema->hasTable(self::TABLE) === false) {
			$output->warning(message: 'openregister_organisations is absent; skipping the counterparty columns');

			return null;
		}

		$table = $schema->getTable(self::TABLE);
		$added = [];

		foreach ($this->columnSpecifications() as $column) {
			if ($table->hasColumn($column['name']) === true) {
				continue;
			}

			$table->addColumn($column['name'], $column['type'], $column['options']);
			$added[] = $column['name'];
		}

		if ($added === []) {
			return null;
		}

		$output->info(message: 'openregister_organisations: added ' . implode(', ', $added));

		return $schema;

	}//end changeSchema()

}//end class
