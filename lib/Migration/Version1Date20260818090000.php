<?php

/**
 * A home on the row for which OpenBuild virtual app a flow belongs to.
 *
 * `app` scopes a flow to the owning Nextcloud app (`hermiq`, `openconnector`,
 * …), but OpenBuild's "virtual apps" are multiple independent products living
 * inside one host app. `app=hermiq` alone cannot distinguish between them —
 * there is nothing narrower than the host app to filter on. `applicationSlug`
 * adds that narrower key, mirroring the shape OpenBuild's own automations
 * channel already uses for the same problem
 * (`AppRepoSerializer::collectAutomations()`).
 *
 * Additive and optional, following `Version1Date20260812100000`'s `comment`
 * column exactly: nullable, no default, no backfill. Existing flows keep
 * working unchanged; nothing populates the column as part of adding it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-application-slug/specs/flow-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds `openregister_flows.applicationSlug` and its index.
 *
 * @spec openspec/changes/flow-application-slug/specs/flow-engine/spec.md
 */
class Version1Date20260818090000 extends SimpleMigrationStep {
	/**
	 * Add the column and its index.
	 *
	 * @param IOutput $output        Migration output.
	 * @param Closure $schemaClosure Schema closure returning an ISchemaWrapper.
	 * @param array   $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The modified schema, or null when unchanged.
	 *
	 * @spec openspec/changes/flow-application-slug/specs/flow-engine/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_flows') === false) {
			return null;
		}

		$table   = $schema->getTable('openregister_flows');
		$changed = false;

		if ($table->hasColumn('applicationSlug') === false) {
			$table->addColumn(
				'applicationSlug',
				Types::STRING,
				[
					'notnull' => false,
					'length' => 255,
					'default' => null,
				]
			);
			$changed = true;
		}

		if ($table->hasIndex('or_flow_app_slug_idx') === false) {
			$table->addIndex(['applicationSlug', 'id'], 'or_flow_app_slug_idx');
			$changed = true;
		}

		if ($changed === false) {
			return null;
		}

		return $schema;

	}//end changeSchema()
}//end class
