<?php

/**
 * OpenRegister HarvestedRecord
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Sync;

use OCA\OpenRegister\Db\SyncRecord;

/**
 * One fetched record on its way through the import stage: the tracking row,
 * its mapped content, that content's hash and the earlier row that tracks the
 * same external id (null when this is the first time it is seen).
 *
 * @spec openspec/specs/data-sync-harvesting/spec.md
 */
final readonly class HarvestedRecord {

	/**
	 * Describe the record.
	 *
	 * @param SyncRecord      $record  The tracking row being imported.
	 * @param array           $mapped  The mapped object data.
	 * @param string          $hash    The content hash of the mapped data.
	 * @param SyncRecord|null $tracked The earlier tracking row for the same external id.
	 */
	public function __construct(
		public SyncRecord $record,
		public array $mapped,
		public string $hash,
		public ?SyncRecord $tracked,
	) {
	}//end __construct()

	/**
	 * The local object uuid the earlier tracking row carries, or null.
	 *
	 * @return string|null The tracked object uuid.
	 *
	 * @spec openspec/specs/data-sync-harvesting/spec.md
	 */
	public function trackedUuid(): ?string {
		return $this->tracked?->getObjectUuid();
	}//end trackedUuid()
}//end class
