<?php

/**
 * Image regions — `page` and `box` on entity relations.
 *
 * A relation that stands for a region of an image carries the page it sits on
 * and its box `{x, y, w, h}` normalised to 0..1, so an image finding goes
 * through the same decision and review as a text finding. Both are nullable:
 * a text-only finding has neither.
 *
 * Idempotent: each column is added only when absent.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
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
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add nullable `page` and `box` to openregister_entity_relations.
 *
 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
 */
class Version1Date20261113150000 extends SimpleMigrationStep {

	/**
	 * Change the database schema.
	 *
	 * @param IOutput                 $output        Output for the migration process.
	 * @param Closure                 $schemaClosure The schema closure.
	 * @param array<array-key, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema, changed or not.
	 *
	 * @spec openspec/changes/anonymisation-image-seam/specs/image-anonymisation/spec.md#requirement-images-and-image-content-are-detected-through-one-seam-req-ais-001
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */
		$schema = $schemaClosure();
		if ($schema->hasTable('openregister_entity_relations') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_entity_relations');
		if ($table->hasColumn('page') === false) {
			$table->addColumn('page', Types::INTEGER, ['notnull' => false, 'unsigned' => true]);
			$output->info('Added page to openregister_entity_relations');
		}

		if ($table->hasColumn('box') === false) {
			$table->addColumn('box', Types::JSON, ['notnull' => false]);
			$output->info('Added box to openregister_entity_relations');
		}

		return $schema;
	}//end changeSchema()
}//end class
