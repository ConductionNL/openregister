<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Give an organisation the four chain-partner fields it did not already have.
 *
 * Version1Date20260831020000 made an organisation able to say it is a tenant
 * somewhere ELSE (`is_local_tenant`, `remote_instance_url`). That settled WHERE
 * a counterparty lives. It did not settle WHO it is.
 *
 * dossiq carried that separately, in its own `partnerOrganization` schema, and
 * folding partners into Organisation would have dropped whatever this table
 * could not hold. Measured against the live table rather than assumed: of that
 * schema's nine properties, `name`, `slug`, `isActive` and `groupId` already
 * map onto columns here, and `oin` ALREADY EXISTS (alongside `tooi`) — the
 * Organisatie-identificatienummer, how a Dutch government body is named across
 * installations, was added before this. Four properties had nowhere to go, and
 * those four are what this migration adds.
 *
 * All four are nullable with NO default. An organisation that is this
 * installation's own tenant has no chain-partner contact address and has never
 * been scored, and writing a zero onto it would read as "scored badly" rather
 * than "not scored".
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the chain-partner identity columns to `openregister_organisations`.
 */
final class Version1Date20260901090000 extends SimpleMigrationStep {

	/**
	 * The table this migration widens.
	 *
	 * @var string
	 */
	private const TABLE = 'openregister_organisations';

	/**
	 * Add the columns when they are absent.
	 *
	 * @param IOutput                   $output        Migration output.
	 * @param Closure(): ISchemaWrapper $schemaClosure The schema closure.
	 * @param array<string, mixed>      $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when nothing changed.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/* @var ISchemaWrapper $schema The schema wrapper. */
		$schema = $schemaClosure();

		if ($schema->hasTable(self::TABLE) === false) {
			$output->warning(message: 'openregister_organisations is absent; skipping the chain-partner columns');

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

	/**
	 * The columns this migration adds.
	 *
	 * @return array<int, array<string, mixed>> The specifications.
	 */
	private function columnSpecifications(): array {
		return [
			[
				'name' => 'contact_email',
				'type' => Types::STRING,
				'options' => [
					'notnull' => false,
					'length' => 255,
					'comment' => 'Where to reach this organisation about the work, as opposed '
						. 'to any individual user account inside it.',
				],
			],
			[
				'name' => 'default_permission_level',
				'type' => Types::STRING,
				'options' => [
					'notnull' => false,
					'length' => 64,
					'comment' => 'The access level a share with this organisation starts at. '
						. 'A DEFAULT, never an authorization decision: ADR-002 Rule 1 keeps '
						. 'the organisation UUID as the only tenant key, and nothing may '
						. 'read this column to decide whether an actor may act.',
				],
			],
			[
				'name' => 'quality_score',
				'type' => Types::INTEGER,
				'options' => [
					'notnull' => false,
					'comment' => 'Chain-partner quality score, as scored by the installation '
						. 'that works with them. Nullable with no default: a zero would '
						. 'read as "scored badly" rather than "not scored".',
				],
			],
			[
				'name' => 'quality_status',
				'type' => Types::STRING,
				'options' => [
					'notnull' => false,
					'length' => 64,
					'comment' => 'The qualitative reading of quality_score.',
				],
			],
		];

	}//end columnSpecifications()

}//end class
