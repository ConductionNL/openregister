<?php

/**
 * Give federated shares an expiry: openregister_federated_shares.expires_at.
 *
 * A share token is served until its expiry. The column is nullable so existing rows
 * survive the migration; the GiveFederationSharesALifetime repair step then gives
 * each of them its lifetime from the upgrade.
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
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add the expiry column to the federated shares table.
 *
 * @spec openspec/specs/federation-scope-enforcement/spec.md
 */
class Version1Date20261111150000 extends SimpleMigrationStep {
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
	 * @spec openspec/specs/federation-scope-enforcement/spec.md
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/*
		 * @var ISchemaWrapper $schema
		 */

		$schema = $schemaClosure();
		if ($schema->hasTable(self::TABLE) === false || $schema->getTable(self::TABLE)->hasColumn('expires_at') === true) {
			return null;
		}

		$schema->getTable(self::TABLE)->addColumn('expires_at', Types::DATETIME, ['notnull' => false, 'default' => null]);

		return $schema;
	}//end changeSchema()
}//end class
