<?php

/**
 * Give federated shares a declaration of what crosses: openregister_federated_shares.crosses.
 *
 * A share declares which parts of an object are sent: its data, its files,
 * its public timeline entries, or a subset. The column is nullable so existing
 * rows survive the migration; a share without a declaration serves the
 * narrowest, the object's data alone.
 *
 * Idempotent: the column is added only while it is missing.
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
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the crosses column to the federated shares table.
 *
 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
 */
class Version1Date20261114130000 extends SimpleMigrationStep {
	private const TABLE = 'openregister_federated_shares';

	/**
	 * Add the column.
	 *
	 * @param IOutput              $output        Migration output.
	 * @param Closure              $schemaClosure Returns the schema wrapper.
	 * @param array<string, mixed> $options       Migration options.
	 *
	 * @return ISchemaWrapper|null The schema.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is Nextcloud's.
	 * @spec openspec/changes/platform-cloud-federation-provider/specs/federation/spec.md#requirement-a-federated-share-declares-what-crosses-req-pcf-002
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false || $schema->getTable(self::TABLE)->hasColumn('crosses') === true) {
			return $schema;
		}

		$schema->getTable(self::TABLE)->addColumn('crosses', Types::TEXT, ['notnull' => false, 'default' => null]);

		return $schema;
	}//end changeSchema()
}//end class
