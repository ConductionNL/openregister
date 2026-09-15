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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\ArchiveNotOfferedException;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

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
class ArchiveHandler {

	/**
	 * Constructor.
	 *
	 * @param MagicMapper $magicMapper Object lookup and persistence.
	 * @param AuditTrailMapper $auditTrailMapper Audit entries for each transition.
	 * @param PermissionHandler $permissionHandler The `update` gate.
	 * @param IUserSession $userSession The acting user.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function __construct(
		private readonly MagicMapper $magicMapper,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly PermissionHandler $permissionHandler,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Archive an object.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason Why it is being archived.
	 * @param string|null $register The register the url names, so an object in
	 *                              another one is not reachable from this address.
	 * @param string|null $schema The schema the url names, checked the same way.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
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
		$context = $this->resolveAndAuthorize(identifier: $identifier, register: $register, schema: $schema);
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
		$object->archive(userSession: $this->userSession, reason: $reason);

		$saved = $this->persist(object: $object, context: $context, action: 'archive', before: $before);

		return [
			'uuid' => $saved->getUuid(),
			'archived' => $saved->getArchived(),
		];
	}//end archive()

	/**
	 * Restore an object from the archive.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason Why it is being restored.
	 * @param string|null $register The register the url names, so an object in
	 *                              another one is not reachable from this address.
	 * @param string|null $schema The schema the url names, checked the same way.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
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
		// A read by identifier is deliberately unaffected by the archive: the
		// exclusion is a property of the LIST, not of the record. That is what
		// keeps a `$ref` into an archived contact resolving, and it is also
		// what lets this endpoint find the object it exists to restore. Were
		// the lookup filtered too, restore would answer 404 for every object it
		// can act on — the state hiding the one verb that undoes it.
		$context = $this->resolveAndAuthorize(identifier: $identifier, register: $register, schema: $schema);
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
			object: $object,
			context: $context,
			action: 'unarchive',
			before: $before,
			reason: $reason
		);

		return [
			'uuid' => $saved->getUuid(),
			'archived' => null,
		];
	}//end unarchive()

	/**
	 * Freeze an object.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason Why it is being frozen.
	 * @param string|null $state The lifecycle state declaring the freeze, when one does.
	 * @param string|null $register The register the url names, so an object in
	 *                              another one is not reachable from this address.
	 * @param string|null $schema The schema the url names, checked the same way.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: array|null} The stored marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function freeze(
		string $identifier,
		?string $reason = null,
		?string $state = null,
		?string $register = null,
		?string $schema = null,
	): array {
		$context = $this->resolveAndAuthorize(identifier: $identifier, register: $register, schema: $schema);
		$object = $context['object'];

		if ($object->isFrozen() === true) {
			return [
				'uuid' => $object->getUuid(),
				'frozen' => $object->getFrozen(),
			];
		}

		$before = clone $object;
		$object->freeze(userSession: $this->userSession, reason: $reason, state: $state);

		$saved = $this->persist(object: $object, context: $context, action: 'freeze', before: $before);

		return [
			'uuid' => $saved->getUuid(),
			'frozen' => $saved->getFrozen(),
		];
	}//end freeze()

	/**
	 * Unfreeze an object.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 * @param string|null $reason Why it is being unfrozen.
	 * @param string|null $register The register the url names, so an object in
	 *                              another one is not reachable from this address.
	 * @param string|null $schema The schema the url names, checked the same way.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
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
		// A frozen object never left the working views, so the ordinary lookup
		// still finds it and no archive lens is needed here.
		$context = $this->resolveAndAuthorize(identifier: $identifier, register: $register, schema: $schema);
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
			object: $object,
			context: $context,
			action: 'unfreeze',
			before: $before,
			reason: $reason
		);

		return [
			'uuid' => $saved->getUuid(),
			'frozen' => null,
		];
	}//end unfreeze()

	/**
	 * Find the object, refuse a schema that does not offer archiving, and
	 * refuse a caller without `update`.
	 *
	 * All four verbs go through here so the three refusals are decided once.
	 * Four copies of the same gate is how one of them ends up checking `read`.
	 *
	 * @param string $identifier Object id, uuid, slug or uri.
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{object: ObjectEntity, register: Register, schema: Schema} The resolved context.
	 */
	private function resolveAndAuthorize(
		string $identifier,
		?string $register = null,
		?string $schema = null,
	): array {
		$result = $this->magicMapper->findAcrossAllSources(
			identifier: $identifier,
			includeDeleted: false,
			_rbac: true,
			_multitenancy: true
		);

		$object = $result['object'];
		$registerEntity = $result['register'];
		$schemaEntity = $result['schema'];

		if ($schemaEntity instanceof Schema === false || $registerEntity instanceof Register === false) {
			throw new ArchiveNotOfferedException(
				message: 'Cannot archive this object: its register and schema could not be resolved.'
			);
		}

		// The url names a register and a schema, so the object had better be in
		// them. `findAcrossAllSources()` resolves a uuid wherever it lives, which
		// is right for a lookup and wrong for an authorization boundary: without
		// this, an identifier belonging to a register the caller is merely
		// pointing at could be archived through another register's url, and the
		// `update` check below would be asked about the WRONG schema. Answered
		// as not-found rather than as a refusal, because from the caller's
		// position that object is not at that address.
		$this->assertInScope(entity: $registerEntity, named: $register, kind: 'register', identifier: $identifier);
		$this->assertInScope(entity: $schemaEntity, named: $schema, kind: 'schema', identifier: $identifier);

		// The schema decides whether the action exists at all (ADR-031). A
		// schema with no finished state does not grow an action nobody uses,
		// and a leaf app rendering an Archive button reads this rather than
		// deciding for itself.
		if ($schemaEntity->isArchivingEnabled() === false) {
			throw new ArchiveNotOfferedException(
				message: 'Schema "' . (string)$schemaEntity->getTitle()
					. '" does not declare x-openregister-archive, so its objects cannot be archived.'
			);
		}

		$userId = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$this->permissionHandler->checkPermission(
			schema: $schemaEntity,
			action: 'update',
			userId: $userId,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);

		return [
			'object' => $object,
			'register' => $registerEntity,
			'schema' => $schemaEntity,
		];
	}//end resolveAndAuthorize()

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
	 * @param Register|Schema $entity The entity the object actually belongs to.
	 * @param string|null $named The register or schema named in the url.
	 * @param string $kind `register` or `schema`, for the message.
	 * @param string $identifier The object identifier, for the message.
	 *
	 * @throws DoesNotExistException When the object is not in the named scope.
	 *
	 * @return void
	 */
	private function assertInScope(
		Register|Schema $entity,
		?string $named,
		string $kind,
		string $identifier,
	): void {
		if ($named === null || $named === '') {
			return;
		}

		$id = (string)$entity->getId();
		$slug = (string)$entity->getSlug();

		if ($named === $id || $named === $slug) {
			return;
		}

		throw new DoesNotExistException(
			'Object "' . $identifier . '" is not in ' . $kind . ' "' . $named . '".'
		);
	}//end assertInScope()

	/**
	 * Write the changed marker and its audit entry.
	 *
	 * @param ObjectEntity $object The object carrying the new marker.
	 * @param array{object: ObjectEntity, register: Register, schema: Schema} $context The resolved context.
	 * @param string $action The audit action name.
	 * @param ObjectEntity $before The object as it was, for the audit changeset.
	 * @param string|null $reason The reason, for the log line on a clearing verb.
	 *
	 * @return ObjectEntity The saved object.
	 */
	private function persist(
		ObjectEntity $object,
		array $context,
		string $action,
		ObjectEntity $before,
		?string $reason = null,
	): ObjectEntity {
		$saved = $this->magicMapper->updateObjectEntity(
			entity: $object,
			register: $context['register'],
			schema: $context['schema'],
			oldEntity: $before
		);

		// Each transition is an audit fact on the hash chain, not a silent flag
		// flip (openregister ADR-003). Both directions are recorded: an object
		// that shows no archive marker today is a different thing from one that
		// was never archived, and only the trail can tell them apart.
		$this->auditTrailMapper->createAuditTrail(old: $before, new: $saved, action: $action);

		$this->logger->info(
			message: '[ArchiveHandler] ' . $action,
			context: [
				'file' => __FILE__,
				'line' => __LINE__,
				'uuid' => $saved->getUuid(),
				'reason' => $reason,
			]
		);

		return $saved;
	}//end persist()
}//end class
