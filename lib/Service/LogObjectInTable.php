<?php

/**
 * OpenRegister LogObjectInTable
 *
 * The object whose history is asked for, looked up in its register+schema table.
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
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\MultipleObjectsReturnedException;
use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;

/**
 * The in-table half of LogService's object lookup, extracted unchanged.
 *
 * The lookup is the one the object routes use (`MagicMapper::findInRegisterSchemaTable()`):
 * an id, uuid or slug of THIS register and schema, an exact id/uuid match winning over a
 * slug, a slug two objects hold refused as ambiguous. Like the history itself, it runs
 * without RBAC and multitenancy filters.
 *
 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
 */
final class LogObjectInTable {

	/**
	 * Constructor.
	 *
	 * @param MagicMapperInterface $objectMapper The object mapper.
	 */
	public function __construct(
		private readonly MagicMapperInterface $objectMapper,
	) {
	}//end __construct()

	/**
	 * The object, deleted or not, in the register+schema table.
	 *
	 * A slug freed by a delete goes to the next object that saves without one, so a
	 * deleted and a live object can share it. The single live holder is the one meant;
	 * only when the live rows alone are still ambiguous, or none is live, is the lookup
	 * refused.
	 *
	 * @param TableObjectRef $object The identifier (ID, UUID or slug) and its register and schema.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws DoesNotExistException            When no such object exists in the register and schema.
	 * @throws MultipleObjectsReturnedException When the slug is held by more than one live object,
	 *                                          or only by deleted ones.
	 *
	 * @spec openspec/specs/object-lifecycle/spec.md#requirement-a-slug-lookup-that-matches-more-than-one-object-in-a-register-and-schema-must-be-refused-as-ambiguous
	 */
	public function find(TableObjectRef $object): ObjectEntity {
		try {
			return $this->inTable(object: $object, includeDeleted: true);
		} catch (MultipleObjectsReturnedException $e) {
			return $this->singleLiveHolder(object: $object, ambiguous: $e);
		}
	}//end find()

	/**
	 * The one live object holding an identifier that several objects share, deleted ones included.
	 *
	 * @param TableObjectRef                   $object    The identifier and its register and schema.
	 * @param MultipleObjectsReturnedException $ambiguous The refusal when no single live holder exists.
	 *
	 * @return ObjectEntity The live object.
	 *
	 * @throws MultipleObjectsReturnedException When no object, or more than one, is live.
	 */
	private function singleLiveHolder(TableObjectRef $object, MultipleObjectsReturnedException $ambiguous): ObjectEntity {
		try {
			return $this->inTable(object: $object, includeDeleted: false);
		} catch (DoesNotExistException $none) {
			throw $ambiguous;
		}
	}//end singleLiveHolder()

	/**
	 * Look an object up in its register+schema table, without RBAC or multitenancy.
	 *
	 * @param TableObjectRef $object         The identifier and its register and schema.
	 * @param bool           $includeDeleted Whether soft-deleted objects count.
	 *
	 * @return ObjectEntity The object.
	 *
	 * @throws DoesNotExistException            When no object matches.
	 * @throws MultipleObjectsReturnedException When the identifier is ambiguous.
	 */
	private function inTable(TableObjectRef $object, bool $includeDeleted): ObjectEntity {
		return $this->objectMapper->findInRegisterSchemaTable(
			identifier: $object->id,
			register: $object->table->register,
			schema: $object->table->schema,
			_rbac: false,
			_multitenancy: false,
			includeDeleted: $includeDeleted
		);
	}//end inTable()
}//end class
