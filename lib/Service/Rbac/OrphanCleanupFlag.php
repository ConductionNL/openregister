<?php

/**
 * OrphanCleanupFlag
 *
 * This file is part of the OpenRegister app for Nextcloud.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\Schema;

/**
 * Whether an incoming schema document changes `orphanCleanup`, which is for administrators.
 *
 * @spec openspec/specs/referential-integrity/spec.md
 */
final class OrphanCleanupFlag implements OrphanCleanupFlagInterface {
	/**
	 * Whether the document sets the flag to another value than the schema holds.
	 *
	 * A document that leaves the key out drops the flag or keeps it, which gives
	 * nobody more.
	 *
	 * @param Schema               $schema   The schema as stored, or a new one.
	 * @param array<string, mixed> $document The incoming schema.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/referential-integrity/spec.md
	 */
	public function changedBy(Schema $schema, array $document): bool {
		$configuration = ($document['configuration'] ?? null);
		if (is_string($configuration) === true) {
			$configuration = json_decode($configuration, associative: true);
		}

		return is_array($configuration) === true
			&& array_key_exists('orphanCleanup', $configuration) === true
			&& $configuration['orphanCleanup'] !== (($schema->getConfiguration() ?? [])['orphanCleanup'] ?? false);
	}//end changedBy()
}//end class
