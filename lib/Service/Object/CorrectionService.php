<?php

/**
 * OpenRegister corrections.
 *
 * Correcting a mis-registered value is its own act: it needs a right, it needs
 * a reason, and it is recorded as a correction rather than as an update.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\CorrectionRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * Corrects a value on a record and records that it was a correction.
 *
 * WHY THIS IS A SEPARATE VERB.
 *
 * Every municipality corrects a mis-registered case, and the alternative today
 * is a database edit. An auditor's question is not "what changed" but "which
 * of these changes were corrections", and a trail that records a correction as
 * an ordinary update can only answer it by reading every diff and guessing
 * (D-3). Making it a distinct verb also lets an organisation grant correcting
 * narrowly, to the handful of people who should hold it, without granting
 * ordinary editing to nobody.
 *
 * WHY THE ENTRY IS RELABELLED RATHER THAN ADDED.
 *
 * The save runs normally, so inverse relations, events and everything else the
 * ordinary write path does still happen. The audit entry it wrote is then
 * relabelled a correction in place, which leaves exactly one entry for one
 * act. That is safe for one specific reason: `AuditTrailMapper` deliberately
 * does NOT seal a row on insert, leaving sealing to `AuditSealJob` five
 * minutes later, so the row this service amends is not yet part of the hash
 * chain. A row that IS sealed is never touched: the fallback writes a separate
 * correction entry instead, because rewriting a sealed row is tampering
 * whoever does it and for whatever reason.
 */
class CorrectionService implements CorrectionServiceInterface {

	/**
	 * The named right a correction requires.
	 *
	 * Seeded into the action matrix as admin-only rather than
	 * `@authenticated`, unlike the flow rights beside it. Those were seeded
	 * open because they were already open and naming them would have locked
	 * people out of something they could do yesterday. Correcting is new, so
	 * the narrow default is the honest one and an administrator widens it.
	 *
	 * @var string
	 */
	public const RIGHT = 'object.correct';

	/**
	 * The audit action a correction is recorded under.
	 *
	 * Filterable straight off `GET /api/objects/{r}/{s}/{id}/audit-trails`,
	 * because `action` is already in the mapper's filter allowlist.
	 *
	 * @var string
	 */
	public const ACTION = 'correction';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface     $objectService The write path a correction goes through.
	 * @param OpenRegisterActionAuthService $actionAuth    The action-rights matrix.
	 * @param IUserSession                  $userSession   Resolves the correcting user.
	 * @param CorrectionAuditing            $auditing      The audit trail and the logger.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly OpenRegisterActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly CorrectionAuditing $auditing,
	) {
	}//end __construct()

	/**
	 * Correct one or more values on a record.
	 *
	 * @param CorrectionTarget $target The register, schema and object the correction is made on.
	 * @param array $values The properties to correct, mapped to their corrected values.
	 * @param string|null $reason Why the values are wrong. Required.
	 *
	 * @return array{object: ObjectEntity, audit: AuditTrail|null} The corrected record and its entry.
	 *
	 * @throws NotAuthorizedException When nobody is signed in, or the right is not held.
	 * @throws CorrectionRefusedException When there is no reason, or nothing to correct.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	public function correct(CorrectionTarget $target, array $values, ?string $reason): array {
		$user = $this->correctingUser();

		return $this->applyCorrection(
			user: $user,
			correction: new Correction(target: $target, reason: self::requiredReason(reason: $reason), values: self::correctableValues(values: $values))
		);
	}//end correct()

	/**
	 * Write a checked correction through the save path and record it on the audit trail.
	 *
	 * @param IUser      $user       The correcting user, who holds the right.
	 * @param Correction $correction The record, the reason and the values.
	 *
	 * @return array{object: ObjectEntity, audit: AuditTrail|null} The corrected record and its entry.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function applyCorrection(IUser $user, Correction $correction): array {
		$target    = $correction->target;
		$existing  = $this->storedRecord(target: $target);
		$before    = ($existing->getObject() ?? []);
		$merged    = array_merge($before, $correction->values);
		$corrected = $this->objectService->saveObject(object: $merged, register: $target->register, schema: $target->schema, uuid: $existing->getUuid());

		$fields  = self::fieldChanges(before: $before, values: $correction->values);
		$context = ['correction' => ['reason' => $correction->reason, 'right' => self::RIGHT, 'actor' => $user->getUID(), 'fields' => $fields]];
		return ['object' => $corrected, 'audit' => $this->recordCorrection(object: $corrected, context: $context)];
	}//end applyCorrection()

	/**
	 * The record as stored, read without RBAC: the right was checked on the caller.
	 *
	 * @param CorrectionTarget $target The register, schema and object id.
	 *
	 * @return ObjectEntity The stored record.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function storedRecord(CorrectionTarget $target): ObjectEntity {
		$this->objectService->setRegister(register: $target->register);
		$this->objectService->setSchema(schema: $target->schema);

		return $this->objectService->findSilent(
			id: $target->id, _extend: [], files: false, register: $target->register, schema: $target->schema, _rbac: false, _multitenancy: false
		);
	}//end storedRecord()

	/**
	 * The signed-in user, who must hold the correction right.
	 *
	 * The right is named in the refusal, so a principal who does not hold it
	 * reads its name rather than "forbidden" and can ask an administrator for it.
	 *
	 * @return IUser
	 *
	 * @throws NotAuthorizedException When nobody is signed in, or the right is not held.
	 */
	private function correctingUser(): IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotAuthorizedException(message: 'Sign in to correct a value.');
		}

		try {
			$this->actionAuth->requireAction(user: $user, action: self::RIGHT);
		} catch (Throwable $e) {
			$message = "Correcting a value needs the '" . self::RIGHT . "' right. " . $e->getMessage();
			throw new NotAuthorizedException(message: $message, code: 403, previous: $e);
		}

		return $user;
	}//end correctingUser()

	/**
	 * The trimmed reason, which a correction cannot do without.
	 *
	 * @param string|null $reason Why the values are wrong.
	 *
	 * @return string
	 *
	 * @throws CorrectionRefusedException When there is no reason.
	 */
	private static function requiredReason(?string $reason): string {
		$reason = trim((string)$reason);
		if ($reason === '') {
			throw new CorrectionRefusedException(message: 'A correction needs a reason. Say why the recorded value is wrong.');
		}

		return $reason;
	}//end requiredReason()

	/**
	 * The values a correction may change: no metadata, internal or identity keys.
	 *
	 * @param array $values The values sent.
	 *
	 * @return array The values to correct.
	 *
	 * @throws CorrectionRefusedException When none is left.
	 */
	private static function correctableValues(array $values): array {
		$values = array_filter($values, self::isCorrectable(...), ARRAY_FILTER_USE_KEY);
		if ($values === []) {
			throw new CorrectionRefusedException(message: 'A correction needs at least one value to correct.');
		}

		return $values;
	}//end correctableValues()

	/**
	 * Whether a key names a value a correction may change.
	 *
	 * @param int|string $key The key.
	 *
	 * @return bool
	 */
	private static function isCorrectable(int|string $key): bool {
		return str_starts_with((string)$key, '@') === false
			&& str_starts_with((string)$key, '_') === false
			&& in_array((string)$key, ['id', 'uuid', 'register', 'schema'], true) === false;
	}//end isCorrectable()

	/**
	 * Each corrected property with its stored and its corrected value.
	 *
	 * @param array $before The values as they were stored.
	 * @param array $values The values as corrected.
	 *
	 * @return array<string, array{old: mixed, new: mixed}>
	 */
	private static function fieldChanges(array $before, array $values): array {
		$fields = [];
		foreach ($values as $property => $value) {
			$fields[(string)$property] = ['old' => ($before[$property] ?? null), 'new' => $value];
		}

		return $fields;
	}//end fieldChanges()

	/**
	 * Record that the write just made was a correction.
	 *
	 * Amends the entry the save wrote when it is still unsealed, which is the
	 * normal case, and writes a separate entry when it is not. Either way the
	 * trail ends up with an entry whose `action` is `correction` and whose
	 * `changed` carries the reason and both values.
	 *
	 * Fail-soft on the amend, and deliberately so: the object HAS been
	 * corrected by the time this runs, and losing the label is a smaller harm
	 * than a 500 that leaves the caller believing the correction did not land.
	 * The fallback entry covers the loss in every case it can.
	 *
	 * @param ObjectEntity $object  The corrected record.
	 * @param array        $context The correction context: reason, right, actor and fields.
	 *
	 * @return AuditTrail|null The correction entry, or null when the trail is switched off.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function recordCorrection(ObjectEntity $object, array $context): ?AuditTrail {
		// No amendable entry: either the audit trail is switched off for this
		// schema, or the row was sealed between the save and here. Write the
		// correction as its own entry so the act is recorded either way.
		return $this->amendLastEntry(object: $object, context: $context) ?? $this->writeCorrectionEntry(object: $object, context: $context);
	}//end recordCorrection()

	/**
	 * Write the correction as an audit entry of its own.
	 *
	 * @param ObjectEntity $object  The corrected record.
	 * @param array        $context The correction context, carrying the actor.
	 *
	 * @return AuditTrail|null The entry, or null when it could not be written.
	 */
	private function writeCorrectionEntry(ObjectEntity $object, array $context): ?AuditTrail {
		$actor = $context['correction']['actor'];
		try {
			return $this->auditing->auditTrailMapper->createAuditTrailEntry(object: $object, action: self::ACTION, context: $context, actorId: $actor);
		} catch (Throwable $e) {
			$this->auditing->logger->error(
				message: '[CorrectionService] The correction landed and the audit entry did not',
				context: ['file' => __FILE__, 'line' => __LINE__, 'uuid' => $object->getUuid(), 'error' => $e->getMessage()]
			);

			return null;
		}
	}//end writeCorrectionEntry()

	/**
	 * Relabel the entry the save just wrote, when it is still unsealed.
	 *
	 * Returns null when there is nothing to amend, which is the signal for the
	 * caller to write a separate entry instead.
	 *
	 * @param ObjectEntity $object The corrected record, carrying its last log.
	 * @param array $context The correction context to fold into `changed`.
	 *
	 * @return AuditTrail|null The amended entry, or null.
	 *
	 * @spec openspec/specs/enhanced-audit-trail/spec.md
	 */
	private function amendLastEntry(ObjectEntity $object, array $context): ?AuditTrail {
		$entry = $this->unsealedLastEntry(object: $object);
		if ($entry === null) {
			return null;
		}

		$auditId = (int)$object->getLastLog()['id'];
		$entry->setAction(self::ACTION);
		$entry->setChanged(array_merge($entry->getChanged(), $context));
		try {
			return $this->auditing->auditTrailMapper->update($entry);
		} catch (Throwable $e) {
			$this->auditing->logger->warning(
				message: '[CorrectionService] Could not relabel the audit entry as a correction',
				context: ['file' => __FILE__, 'line' => __LINE__, 'auditId' => $auditId, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end amendLastEntry()

	/**
	 * The entry the save just wrote, when it can be found and is not sealed.
	 *
	 * A sealed row is part of the hash chain. Rewriting one is tampering
	 * whoever does it and for whatever reason, so it is left alone and the
	 * caller writes a separate entry.
	 *
	 * @param ObjectEntity $object The corrected record, carrying its last log.
	 *
	 * @return AuditTrail|null The entry, or null when there is none to amend.
	 */
	private function unsealedLastEntry(ObjectEntity $object): ?AuditTrail {
		$auditId = ($object->getLastLog()['id'] ?? null);
		if (is_numeric($auditId) === false) {
			return null;
		}

		try {
			$entry = $this->auditing->auditTrailMapper->find((int)$auditId);
		} catch (Throwable $e) {
			return null;
		}

		if ($entry->getHash() !== null) {
			return null;
		}

		return $entry;
	}//end unsealedLastEntry()
}//end class
