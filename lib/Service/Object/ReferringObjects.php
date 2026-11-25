<?php
/**
 * ReferringObjects: the objects of one schema that refer to given uuids.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\MagicMapper\MagicRelationReaderInterface;
use OCA\OpenRegister\Kernel\Contracts\ReferringObjectsInterface;

/**
 * Reads the referring objects through the magic tables' relations index.
 *
 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-organisations-using-an-application-hear-about-a-new-version
 */
class ReferringObjects implements ReferringObjectsInterface {

	/**
	 * Constructor.
	 *
	 * @param MagicRelationReaderInterface $relations Reads the referring objects.
	 */
	public function __construct(
		private readonly MagicRelationReaderInterface $relations,
	) {
	}//end __construct()

	/**
	 * The objects in the schema that refer to any of the uuids.
	 *
	 * @param array<int, string> $uuids      The referred uuids.
	 * @param int                $schemaId   The schema.
	 * @param int                $registerId The register.
	 * @param string             $fieldName  The referring property.
	 *
	 * @return list<\OCA\OpenRegister\Db\ObjectEntity>
	 *
	 * @spec openspec/changes/notifications-new-notes-and-referrers/specs/notificatie-engine/spec.md#scenario-organisations-using-an-application-hear-about-a-new-version
	 */
	public function referringTo(array $uuids, int $schemaId, int $registerId, string $fieldName): array {
		return $this->relations->findByRelationBatchInSchema(
			uuids: $uuids,
			schemaId: $schemaId,
			registerId: $registerId,
			fieldName: $fieldName
		);
	}//end referringTo()
}//end class
