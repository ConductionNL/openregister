<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An organisation's quota limits its members and its live objects: three
 * nullable columns on `openregister_organisations`, `user_quota`,
 * `object_quota` and `object_quota_schema` (the slug of the schema the object
 * quota counts). NULL is no limit, as for the other quotas.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the user and object quota columns to the organisations table.
 *
 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
 */
class Version1Date20261023090000 extends SimpleMigrationStep {

	/**
	 * The organisations table.
	 */
	public const TABLE = 'openregister_organisations';

	/**
	 * Add the columns when they are absent.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/specs/organisation-quota/spec.md#requirement-an-organisation-quota-limits-its-users-and-its-objects
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false) {
			return $schema;
		}

		$table = $schema->getTable(self::TABLE);
		foreach (['user_quota', 'object_quota'] as $column) {
			if ($table->hasColumn($column) === false) {
				$table->addColumn($column, Types::BIGINT, ['notnull' => false, 'default' => null]);
			}
		}

		if ($table->hasColumn('object_quota_schema') === false) {
			$table->addColumn('object_quota_schema', Types::STRING, ['notnull' => false, 'length' => 255, 'default' => null]);
		}

		return $schema;
	}//end changeSchema()
}//end class
