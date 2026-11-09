<?php

/**
 * One update of a record: the record with its new data, its schema, and its data before.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * One update of a record, as retention follows it (ADR-011 Rule 2).
 *
 * @spec openspec/specs/retention-management/spec.md
 */
final class RecordUpdate {
	/**
	 * Build the update.
	 *
	 * @param ObjectEntity         $object       The record, with its updated data.
	 * @param Schema               $schema       Its schema.
	 * @param array<string, mixed> $previousData The record's data before this update.
	 *
	 * @spec openspec/specs/retention-management/spec.md
	 */
	public function __construct(
		public readonly ObjectEntity $object,
		public readonly Schema $schema,
		public readonly array $previousData,
	) {
	}//end __construct()
}//end class
