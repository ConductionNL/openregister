<?php

/**
 * Database migration adding the owner of a webhook.
 *
 * @category Migration
 * @package  OCA\OpenRegister\Migration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds a nullable `owner` (user id) to openregister_webhooks.
 *
 * Null is an administrator's webhook, which is every webhook that exists
 * today, so nothing changes for them. An owned webhook carries the user who
 * made it (webhooks-for-owners design D-1).
 *
 * @package OCA\OpenRegister\Migration
 *
 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
 */
class Version1Date20261113120200 extends SimpleMigrationStep {
	/**
	 * Add the owner column and its index.
	 *
	 * @param IOutput $output        Migration output
	 * @param Closure $schemaClosure Schema closure
	 * @param array   $options       Migration options
	 *
	 * @return ISchemaWrapper|null The updated schema
	 *
	 * @spec openspec/changes/webhooks-for-owners/specs/webhook-payload-mapping/spec.md#requirement-a-person-with-the-right-may-own-webhook-subscriptions
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		// @var ISchemaWrapper $schema
		$schema = $schemaClosure();

		if ($schema->hasTable('openregister_webhooks') === false) {
			return $schema;
		}

		$table = $schema->getTable('openregister_webhooks');
		if ($table->hasColumn('owner') === true) {
			return $schema;
		}

		$table->addColumn('owner', Types::STRING, ['notnull' => false, 'length' => 64, 'default' => null]);
		$table->addIndex(['owner'], 'idx_or_webhooks_owner');

		return $schema;
	}//end changeSchema()
}//end class
