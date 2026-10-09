<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Drops `openregister_favourites` once its rows have become follows.
 *
 * A step of its own because the copy in Version1Date20261009130000 runs in
 * that step's postSchemaChange, which Nextcloud runs AFTER the same step's
 * changeSchema: dropping the table there would drop it before the copy.
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
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-favourites-became-follows-with-notifications-off
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Drops the favourites table.
 *
 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-favourites-became-follows-with-notifications-off
 */
class Version1Date20261009130100 extends SimpleMigrationStep {

	/**
	 * Drop `openregister_favourites` when it is there.
	 *
	 * @param IOutput $output Migration output.
	 * @param Closure $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options Migration options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null when there was nothing to drop.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 *
	 * @spec openspec/changes/merge-follow-and-favourites/specs/object-interactions/spec.md#requirement-favourites-became-follows-with-notifications-off
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		$schema = $schemaClosure();
		if ($schema->hasTable(Version1Date20261009130000::FAVOURITES_TABLE) === false) {
			return null;
		}

		$schema->dropTable(Version1Date20261009130000::FAVOURITES_TABLE);

		return $schema;

	}//end changeSchema()
}//end class
