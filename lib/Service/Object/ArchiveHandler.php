<?php

/**
 * Archive Handler
 *
 * Puts an object into the archive, takes it back out, freezes it and unfreezes
 * it. Four verbs, two states, one place that decides who may use them.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail\AuditedChange;
use OCA\OpenRegister\Db\FreezeTerms;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ArchiveNotOfferedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;

/**
 * ArchiveHandler
 *
 * RESPONSIBILITIES:
 * - Archive an object and restore it from the archive
 * - Freeze an object and unfreeze it
 * - Refuse all four on a schema that does not declare archiving
 * - Refuse all four to a caller without `update` on the object
 * - Write one audit entry per transition
 *
 * WHY `update` AND NOT `delete` (openregister ADR-010). Archiving is not a
 * step towards deletion; the archived object is whole and stays whole. Gating
 * it on `delete` would mean the people who finish a case are exactly the
 * people who cannot file it, and would hand anybody who CAN file it a verb
 * next to the destructive one.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 */
class ArchiveHandler implements ArchiveHandlerInterface {

	/**
	 * Constructor.
	 *
	 * @param PermissionEnforcementInterface $permissionHandler The `update` gate (an access guard, so a direct parameter).
	 * @param IUserSession                   $userSession       The acting user.
	 * @param ObjectChangeRecording          $recording         Object lookup and persistence, and the audit
	 *                                                          trail and the log each transition is recorded in.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly PermissionEnforcementInterface $permissionHandler,
		private readonly IUserSession $userSession,
		private readonly ObjectChangeRecording $recording,
	) {
	}//end __construct()

	/**
	 * Archive an object.
	 *
	 * Kept for the apps that call it by class name; archiveWith takes the request.
	 *
	 * @param string      $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason     Why it is being archived.
	 * @param string|null $register   The register the url names.
	 * @param string|null $schema     The schema the url names.
	 *
	 * @return array{uuid: string|null, archived: array|null} The stored marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function archive(
		string $identifier,
		?string $reason = null,
		?string $register = null,
		?string $schema = null,
	): array {
		return $this->archiveWith(request: new ObjectStateRequest(identifier: $identifier, reason: $reason, register: $register, schema: $schema));
	}//end archive()

	/**
	 * Archive the object a request names.
	 *
	 * @param ObjectStateRequest $request The object, its address and the reason.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, archived: array|null} The uuid and the archive marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function archiveWith(ObjectStateRequest $request): array {
		$context = $this->resolveAndAuthorize(request: $request);
		$object = $context['object'];

		// Already archived is not an error. The caller wanted the object in the
		// archive and it is in the archive; re-archiving it would rewrite the
		// original archiver and the original date out of the record, which is
		// the one fact the marker exists to keep.
		if ($object->isArchived() === true) {
			return [
				'uuid' => $object->getUuid(),
				'archived' => $object->getArchived(),
			];
		}

		$before = clone $object;
		$object->archive(userSession: $this->userSession, reason: $request->reason);

		$saved = $this->persist(context: $context, transition: new ObjectStateTransition(action: 'archive', before: $before));

		return [
			'uuid' => $saved->getUuid(),
			'archived' => $saved->getArchived(),
		];
	}//end archiveWith()

	/**
	 * Restore an object from the archive.
	 *
	 * Kept for the apps that call it by class name; unarchiveWith takes the request.
	 *
	 * @param string      $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason     Why it is being restored.
	 * @param string|null $register   The register the url names.
	 * @param string|null $schema     The schema the url names.
	 *
	 * @return array{uuid: string|null, archived: null} The cleared marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unarchive(
		string $identifier,
		?string $reason = null,
		?string $register = null,
		?string $schema = null,
	): array {
		return $this->unarchiveWith(request: new ObjectStateRequest(identifier: $identifier, reason: $reason, register: $register, schema: $schema));
	}//end unarchive()

	/**
	 * Take the object a request names out of the archive.
	 *
	 * @param ObjectStateRequest $request The object, its address and the reason.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, archived: null} The uuid; the marker is gone.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unarchiveWith(ObjectStateRequest $request): array {
		// A read by identifier is deliberately unaffected by the archive: the
		// exclusion is a property of the LIST, not of the record. That is what
		// keeps a `$ref` into an archived contact resolving, and it is also
		// what lets this endpoint find the object it exists to restore. Were
		// the lookup filtered too, restore would answer 404 for every object it
		// can act on — the state hiding the one verb that undoes it.
		$context = $this->resolveAndAuthorize(request: $request);
		$object = $context['object'];

		if ($object->isArchived() === false) {
			return [
				'uuid' => $object->getUuid(),
				'archived' => null,
			];
		}

		$before = clone $object;
		$object->unarchive();

		$saved = $this->persist(
			context: $context,
			transition: new ObjectStateTransition(action: 'unarchive', before: $before, reason: $request->reason)
		);

		return [
			'uuid' => $saved->getUuid(),
			'archived' => null,
		];
	}//end unarchiveWith()

	/**
	 * Freeze the object a request names.
	 *
	 * @param ObjectStateRequest $request The object (id, uuid, slug or uri), the register and schema
	 *                                    the url names (so an object in another one is not reachable
	 *                                    from this address) and why it is being frozen.
	 * @param string|null        $state   The lifecycle state declaring the freeze, when one does.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: array|null} The uuid and the freeze marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function freeze(ObjectStateRequest $request, ?string $state = null): array {
		$context = $this->resolveAndAuthorize(request: $request);
		$object = $context['object'];

		if ($object->isFrozen() === true) {
			return [
				'uuid' => $object->getUuid(),
				'frozen' => $object->getFrozen(),
			];
		}

		$before = clone $object;
		$object->freezeWith(userSession: $this->userSession, terms: new FreezeTerms(reason: $request->reason, state: $state));

		$saved = $this->persist(context: $context, transition: new ObjectStateTransition(action: 'freeze', before: $before));

		return [
			'uuid' => $saved->getUuid(),
			'frozen' => $saved->getFrozen(),
		];
	}//end freeze()

	/**
	 * Unfreeze an object.
	 *
	 * Kept for the apps that call it by class name; unfreezeWith takes the request.
	 *
	 * @param string      $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason     Why it is being unfrozen.
	 * @param string|null $register   The register the url names.
	 * @param string|null $schema     The schema the url names.
	 *
	 * @return array{uuid: string|null, frozen: null} The cleared marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unfreeze(
		string $identifier,
		?string $reason = null,
		?string $register = null,
		?string $schema = null,
	): array {
		return $this->unfreezeWith(request: new ObjectStateRequest(identifier: $identifier, reason: $reason, register: $register, schema: $schema));
	}//end unfreeze()

	/**
	 * Unfreeze the object a request names.
	 *
	 * @param ObjectStateRequest $request The object, its address and the reason.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: null} The uuid; the marker is gone.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unfreezeWith(ObjectStateRequest $request): array {
		// A frozen object never left the working views, so the ordinary lookup
		// still finds it and no archive lens is needed here.
		$context = $this->resolveAndAuthorize(request: $request);
		$object = $context['object'];

		if ($object->isFrozen() === false) {
			return [
				'uuid' => $object->getUuid(),
				'frozen' => null,
			];
		}

		$before = clone $object;
		$object->unfreeze();

		$saved = $this->persist(
			context: $context,
			transition: new ObjectStateTransition(action: 'unfreeze', before: $before, reason: $request->reason)
		);

		return [
			'uuid' => $saved->getUuid(),
			'frozen' => null,
		];
	}//end unfreezeWith()

	/**
	 * Find the object, refuse a schema that does not offer archiving, and
	 * refuse a caller without `update`.
	 *
	 * All four verbs go through here so the three refusals are decided once.
	 * Four copies of the same gate is how one of them ends up checking `read`.
	 *
	 * @param ObjectStateRequest $request The object, and the register and schema the url names, so an
	 *                                    object in another one is not reachable from this address.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists or it is out of scope.
	 *
	 * @return array{object: ObjectEntity, register: Register, schema: Schema} The resolved context.
	 */
	private function resolveAndAuthorize(ObjectStateRequest $request): array {
		$result = $this->recording->objects->findAcrossAllSources(
			identifier: $request->identifier,
			includeDeleted: false,
			_rbac: true,
			_multitenancy: true
		);

		$object = $result['object'];
		$registerEntity = $result['register'];
		$schemaEntity = $result['schema'];

		$this->assertResolved(register: $registerEntity, schema: $schemaEntity);

		// The url names a register and a schema, so the object had better be in
		// them. `findAcrossAllSources()` resolves a uuid wherever it lives, which
		// is right for a lookup and wrong for an authorization boundary: without
		// this, an identifier belonging to a register the caller is merely
		// pointing at could be archived through another register's url, and the
		// `update` check below would be asked about the WRONG schema. Answered
		// as not-found rather than as a refusal, because from the caller's
		// position that object is not at that address.
		$this->assertInScope(entity: $registerEntity, request: $request);
		$this->assertInScope(entity: $schemaEntity, request: $request);

		// The schema decides whether the action exists at all (ADR-031). A
		// schema with no finished state does not grow an action nobody uses,
		// and a leaf app rendering an Archive button reads this rather than
		// deciding for itself.
		$this->assertArchivingOffered(schema: $schemaEntity);

		$this->permissionHandler->checkPermission(
			schema: $schemaEntity,
			action: 'update',
			options: new PermissionCheckOptions(
				userId: $this->userSession->getUser()?->getUID(),
				objectOwner: $object->getOwner(),
				object: $object
			),
			_rbac: true
		);

		return [
			'object' => $object,
			'register' => $registerEntity,
			'schema' => $schemaEntity,
		];
	}//end resolveAndAuthorize()

	/**
	 * Refuse an object whose register or schema could not be resolved.
	 *
	 * @param mixed $register The resolved register.
	 * @param mixed $schema   The resolved schema.
	 *
	 * @return void
	 *
	 * @throws ArchiveNotOfferedException When either is missing.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	private function assertResolved(mixed $register, mixed $schema): void {
		if ($schema instanceof Schema === false || $register instanceof Register === false) {
			throw new ArchiveNotOfferedException(
				message: 'Cannot archive this object: its register and schema could not be resolved.'
			);
		}
	}//end assertResolved()

	/**
	 * Refuse a schema that does not declare the archive annotation.
	 *
	 * @param Schema $schema The schema.
	 *
	 * @return void
	 *
	 * @throws ArchiveNotOfferedException When the schema offers no archive.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	private function assertArchivingOffered(Schema $schema): void {
		if ($schema->isArchivingEnabled() === false) {
			throw new ArchiveNotOfferedException(
				message: 'Schema "' . (string)$schema->getTitle()
					. '" does not declare x-openregister-archive, so its objects cannot be archived.'
			);
		}
	}//end assertArchivingOffered()

	/**
	 * Refuse an object that is not in the register or schema the url names.
	 *
	 * Accepts either spelling of the route value, because both are legal in
	 * this API: `/api/objects/1/2/...` and `/api/objects/zaken/zaak/...` reach
	 * the same place, so a check that understood only ids would refuse every
	 * slug-shaped url and one that understood only slugs would refuse every
	 * numeric one.
	 *
	 * A null `$named` means the caller did not scope the call, which is the
	 * case for an internal caller holding a uuid and no url. Nothing to check.
	 *
	 * @param Register|Schema    $entity  The entity the object actually belongs to.
	 * @param ObjectStateRequest $request The object identifier (for the message) and the register
	 *                                    and schema named in the url.
	 *
	 * @throws DoesNotExistException When the object is not in the named scope.
	 *
	 * @return void
	 */
	private function assertInScope(Register|Schema $entity, ObjectStateRequest $request): void {
		$kind = 'schema';
		$named = $request->schema;
		if ($entity instanceof Register) {
			$kind = 'register';
			$named = $request->register;
		}

		if ((string)$named === '') {
			return;
		}

		$id = (string)$entity->getId();
		$slug = (string)$entity->getSlug();

		if ($named === $id || $named === $slug) {
			return;
		}

		throw new DoesNotExistException(
			'Object "' . $request->identifier . '" is not in ' . $kind . ' "' . $named . '".'
		);
	}//end assertInScope()

	/**
	 * Write the changed marker and its audit entry.
	 *
	 * @param array{object: ObjectEntity, register: Register, schema: Schema} $context The resolved context; its object carries the new marker.
	 * @param ObjectStateTransition $transition The audit action, the object as it was (for the audit
	 *                                          changeset) and the reason for the log line on a clearing verb.
	 *
	 * @return ObjectEntity The saved object.
	 */
	private function persist(array $context, ObjectStateTransition $transition): ObjectEntity {
		$before = $transition->before;
		$object = $context['object'];
		$saved = $this->recording->objects->updateObjectEntity(
			entity: $object,
			register: $context['register'],
			schema: $context['schema'],
			oldEntity: $before
		);

		// Each transition is an audit fact on the hash chain, not a silent flag
		// flip (openregister ADR-003). Both directions are recorded: an object
		// that shows no archive marker today is a different thing from one that
		// was never archived, and only the trail can tell them apart.
		$this->recording->auditTrail->recordChange(change: new AuditedChange(old: $before, new: $saved, action: $transition->action));

		$this->recording->logger->info(
			message: '[ArchiveHandler] ' . $transition->action,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $saved->getUuid(),
				'reason' => $transition->reason,
			]
		);

		return $saved;
	}//end persist()
}//end class
