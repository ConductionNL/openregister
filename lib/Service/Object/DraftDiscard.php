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
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
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
	 * @param ObjectOperationsInterface $objects Reads and deletes objects.
	 * @param SchemaCatalogInterface    $catalog Resolves the destination register and schema.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly SchemaCatalogInterface $catalog,
	) {

	}//end __construct()

	/**
	 * Discard the owner's draft with this id.
	 *
	 * @param DiscardDraftRequest $request The destination pair (id, uuid, slug or entity), the draft's uuid and the asserted owner.
	 *
	 * @return bool True when the draft was removed; false when it is unknown, not a draft,
	 *              someone else's, its destination does not exist, or the delete failed.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-draft-must-be-able-to-be-discarded-by-its-owner
	 */
	public function discard(DiscardDraftRequest $request): bool {
		$destination = $request->destination;
		$draftId     = $request->draftId;
		if ($draftId === '') {
			return false;
		}

		try {
			$register = $this->register(reference: $destination['register'] ?? null);
			$schema   = $this->schema(reference: $destination['schema'] ?? null);
		} catch (Throwable) {
			return false;
		}

		$draft = (new DraftFinder(objects: $this->objects))->own(
			address: new DraftAddress(register: $register, schema: $schema, draftId: $draftId),
			owner: $request->owner
		);
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

		return $this->catalog->registers()->find((string)$reference, _rbac: false, _multitenancy: false);
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

		return $this->catalog->schemas()->find((string)$reference, _multitenancy: false);
	}//end schema()
}//end class
