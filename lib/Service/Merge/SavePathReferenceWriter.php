<?php

/**
 * SavePathReferenceWriter: writes one reference move through the save path, with RBAC.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Merge
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Merge;

use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\WriteCause;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads the referencing object and saves it with the moved field, both with
 * RBAC and multitenancy on, so the merging user's rights, the schema's
 * validation and the audit trail all apply. A refusal of any kind leaves the
 * object as it was and answers false; nothing is forced (design D-1).
 *
 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
 */
final class SavePathReferenceWriter implements ReferenceWriterInterface {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objects The object read and save path.
	 * @param LoggerInterface           $logger  Receives a write that failed for another reason than rights.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Apply one move as the actor; false when it could not be written.
	 *
	 * @param ReferenceMove $move  The field to rewrite, with its new value.
	 * @param string        $actor The merging user, who is the session's user.
	 *
	 * @return bool True when the move was written.
	 *
	 * @spec openspec/changes/mdm-merge-relinks-every-reference/specs/mdm-merge/spec.md#requirement-a-merge-relinks-every-reference-to-the-losing-record
	 */
	public function write(ReferenceMove $move, string $actor): bool {
		try {
			$entity = WriteCause::asLookup(fn () => $this->objects->find(id: $move->objectUuid, _rbac: true, _multitenancy: true));
			if ($entity === null) {
				return false;
			}

			$data = MergeService::normaliseRoundTripDates(data: ($entity->getObject() ?? []));
			$data[$move->path] = $move->after;
			$entity->setObject($data);
			$this->objects->saveObject(
				object: $entity,
				options: new ObjectSaveOptions(
					target: new ObjectFindTarget(register: $entity->getRegister(), schema: $entity->getSchema()),
					uuid: $entity->getUuid()
				),
				_rbac: true,
				_multitenancy: true
			);
		} catch (NotAuthorizedException $e) {
			return false;
		} catch (Throwable $e) {
			$this->logger->warning(
				'[SavePathReferenceWriter] "' . $actor . '" could not relink "' . $move->path . '" on "' . $move->objectUuid . '": ' . $e->getMessage()
			);
			return false;
		}//end try

		return true;
	}//end write()
}//end class
