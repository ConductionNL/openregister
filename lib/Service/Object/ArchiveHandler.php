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
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, archived: array|null} The stored marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function archive(string $identifier, ?string $reason = null): array {
		$context = $this->resolveAndAuthorize(identifier: $identifier);
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
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, archived: null} The cleared marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unarchive(string $identifier, ?string $reason = null): array {
		// A read by identifier is deliberately unaffected by the archive: the
		// exclusion is a property of the LIST, not of the record. That is what
		// keeps a `$ref` into an archived contact resolving, and it is also
		// what lets this endpoint find the object it exists to restore. Were
		// the lookup filtered too, restore would answer 404 for every object it
		// can act on — the state hiding the one verb that undoes it.
		$context = $this->resolveAndAuthorize(identifier: $identifier);
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
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: array|null} The stored marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function freeze(string $identifier, ?string $reason = null, ?string $state = null): array {
		$context = $this->resolveAndAuthorize(identifier: $identifier);
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
	 *
	 * @throws ArchiveNotOfferedException When the schema does not declare archiving.
	 * @throws \OCA\OpenRegister\Exception\NotAuthorizedException When the caller lacks `update`.
	 * @throws \OCP\AppFramework\Db\DoesNotExistException When no such object exists.
	 *
	 * @return array{uuid: string|null, frozen: null} The cleared marker.
	 *
	 * @spec openspec/changes/object-archive-state/specs/object-lifecycle/spec.md
	 */
	public function unfreeze(string $identifier, ?string $reason = null): array {
		// A frozen object never left the working views, so the ordinary lookup
		// still finds it and no archive lens is needed here.
		$context = $this->resolveAndAuthorize(identifier: $identifier);
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
	private function resolveAndAuthorize(string $identifier): array {
		$result = $this->magicMapper->findAcrossAllSources(
			identifier: $identifier,
			includeDeleted: false,
			_rbac: true,
			_multitenancy: true
		);

		$object = $result['object'];
		$register = $result['register'];
		$schema = $result['schema'];

		if ($schema instanceof Schema === false || $register instanceof Register === false) {
			throw new ArchiveNotOfferedException(
				message: 'Cannot archive this object: its register and schema could not be resolved.'
			);
		}

		// The schema decides whether the action exists at all (ADR-031). A
		// schema with no finished state does not grow an action nobody uses,
		// and a leaf app rendering an Archive button reads this rather than
		// deciding for itself.
		if ($schema->isArchivingEnabled() === false) {
			throw new ArchiveNotOfferedException(
				message: 'Schema "' . (string)$schema->getTitle()
					. '" does not declare x-openregister-archive, so its objects cannot be archived.'
			);
		}

		$userId = null;
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$this->permissionHandler->checkPermission(
			schema: $schema,
			action: 'update',
			userId: $userId,
			objectOwner: $object->getOwner(),
			_rbac: true,
			object: $object
		);

		return [
			'object' => $object,
			'register' => $register,
			'schema' => $schema,
		];
	}//end resolveAndAuthorize()

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
