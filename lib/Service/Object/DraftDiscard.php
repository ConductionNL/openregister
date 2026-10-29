<?php

/**
 * Discards an owner's draft (decisions 180 and 195).
 *
 * A draft is the destination object in status `draft`. Its owner may throw
 * it away: "start again", or a form whose answers it no longer wants kept.
 * Only a draft passes, and only the asserted owner's (DraftFinder::own), so
 * a sent object or someone else's draft is never removed through here; both
 * read as "not found". The delete is permanent: a discarded draft holds a
 * resident's unsent answers and nothing else refers to it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-discarded-by-its-owner
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaMapperInterface;
use OCA\OpenRegister\Service\ObjectService;
use Throwable;

/**
 * Removes one owner's draft from a destination.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-discarded-by-its-owner
 */
class DraftDiscard {

	/**
	 * Constructor.
	 *
	 * @param ObjectService  $objects   Reads and deletes objects.
	 * @param RegisterMapperInterface<Register, Schema> $registers Resolves the destination register.
	 * @param SchemaMapperInterface<Schema>   $schemas   Resolves the destination schema.
	 */
	public function __construct(
		private readonly ObjectService $objects,
		private readonly RegisterMapperInterface $registers,
		private readonly SchemaMapperInterface $schemas,
	) {

	}//end __construct()

	/**
	 * Discard the owner's draft with this id.
	 *
	 * @param array{register?: mixed, schema?: mixed} $destination The destination pair (id, uuid, slug or entity).
	 * @param string                                  $draftId     The draft's uuid.
	 * @param DraftOwner                              $owner       The asserted owner.
	 *
	 * @return bool True when the draft was removed; false when it is unknown, not a draft,
	 *              someone else's, its destination does not exist, or the delete failed.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-discarded-by-its-owner
	 */
	public function discard(array $destination, string $draftId, DraftOwner $owner): bool {
		if ($draftId === '') {
			return false;
		}

		try {
			$register = $this->register(reference: $destination['register'] ?? null);
			$schema   = $this->schema(reference: $destination['schema'] ?? null);
		} catch (Throwable) {
			return false;
		}

		$draft = (new DraftFinder(objects: $this->objects))->own(register: $register, schema: $schema, draftId: $draftId, owner: $owner);
		if ($draft === null) {
			return false;
		}

		try {
			// RBAC off: a scope-value owner has no Nextcloud user to judge;
			// DraftFinder::own() above was the owner check.
			return $this->objects->deleteObject(
				uuid: (string)$draft->getUuid(),
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false,
				permanent: true
			);
		} catch (Throwable) {
			return false;
		}
	}//end discard()

	/**
	 * A destination's register.
	 *
	 * @param mixed $reference Id, uuid, slug or entity.
	 *
	 * @return Register The register.
	 */
	private function register(mixed $reference): Register {
		if ($reference instanceof Register) {
			return $reference;
		}

		return $this->registers->find((string)$reference, _rbac: false, _multitenancy: false);
	}//end register()

	/**
	 * A destination's schema.
	 *
	 * @param mixed $reference Id, uuid, slug or entity.
	 *
	 * @return Schema The schema.
	 */
	private function schema(mixed $reference): Schema {
		if ($reference instanceof Schema) {
			return $reference;
		}

		return $this->schemas->find((string)$reference, _multitenancy: false);
	}//end schema()
}//end class
