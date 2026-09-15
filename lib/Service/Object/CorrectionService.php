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
 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\CorrectionRefusedException;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\OpenRegisterActionAuthService;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
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
class CorrectionService {

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
	 * @param ObjectService $objectService The write path a correction goes through.
	 * @param AuditTrailMapper $auditTrailMapper The audit trail.
	 * @param OpenRegisterActionAuthService $actionAuth The action-rights matrix.
	 * @param IUserSession $userSession Resolves the correcting user.
	 * @param LoggerInterface $logger PSR logger.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly OpenRegisterActionAuthService $actionAuth,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Correct one or more values on a record.
	 *
	 * @param string $register The register slug or id.
	 * @param string $schema The schema slug or id.
	 * @param string $id The object's id or uuid.
	 * @param array $values The properties to correct, mapped to their corrected values.
	 * @param string|null $reason Why the values are wrong. Required.
	 *
	 * @return array{object: ObjectEntity, audit: AuditTrail|null} The corrected record and its entry.
	 *
	 * @throws NotAuthorizedException When nobody is signed in, or the right is not held.
	 * @throws CorrectionRefusedException When there is no reason, or nothing to correct.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	public function correct(string $register, string $schema, string $id, array $values, ?string $reason): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new NotAuthorizedException('Sign in to correct a value.');
		}

		// Named first, so a principal who does not hold it reads the right's
		// name rather than "forbidden". Somebody has to be able to ask an
		// administrator for a specific thing.
		try {
			$this->actionAuth->requireAction(user: $user, action: self::RIGHT);
		} catch (Throwable $e) {
			throw new NotAuthorizedException(
				"Correcting a value needs the '" . self::RIGHT . "' right. " . $e->getMessage(),
				403,
				$e
			);
		}

		$reason = trim((string)$reason);
		if ($reason === '') {
			throw new CorrectionRefusedException(
				'A correction needs a reason. Say why the recorded value is wrong.'
			);
		}

		$values = array_filter(
			$values,
			static function ($key): bool {
				return str_starts_with((string)$key, '@') === false
					&& str_starts_with((string)$key, '_') === false
					&& in_array((string)$key, ['id', 'uuid', 'register', 'schema'], true) === false;
			},
			ARRAY_FILTER_USE_KEY
		);

		if ($values === []) {
			throw new CorrectionRefusedException('A correction needs at least one value to correct.');
		}

		$this->objectService->setRegister(register: $register);
		$this->objectService->setSchema(schema: $schema);

		$existing = $this->objectService->findSilent(
			id: $id,
			_extend: [],
			files: false,
			register: $register,
			schema: $schema,
			_rbac: false,
			_multitenancy: false
		);

		$before = ($existing->getObject() ?? []);
		$merged = array_merge($before, $values);

		$corrected = $this->objectService->saveObject(
			object: $merged,
			register: $register,
			schema: $schema,
			uuid: $existing->getUuid()
		);

		$audit = $this->recordCorrection(
			object: $corrected,
			before: $before,
			values: $values,
			reason: $reason,
			actor: $user->getUID()
		);

		return [
			'object' => $corrected,
			'audit' => $audit,
		];
	}//end correct()

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
	 * @param ObjectEntity $object The corrected record.
	 * @param array $before The values as they were stored.
	 * @param array $values The values as corrected.
	 * @param string $reason Why they were wrong.
	 * @param string $actor The correcting user.
	 *
	 * @return AuditTrail|null The correction entry, or null when the trail is switched off.
	 *
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	private function recordCorrection(
		ObjectEntity $object,
		array $before,
		array $values,
		string $reason,
		string $actor,
	): ?AuditTrail {
		$fields = [];
		foreach ($values as $property => $value) {
			$fields[(string)$property] = [
				'old' => ($before[$property] ?? null),
				'new' => $value,
			];
		}

		$context = [
			'correction' => [
				'reason' => $reason,
				'right' => self::RIGHT,
				'actor' => $actor,
				'fields' => $fields,
			],
		];

		$entry = $this->amendLastEntry(object: $object, context: $context);
		if ($entry !== null) {
			return $entry;
		}

		// No amendable entry: either the audit trail is switched off for this
		// schema, or the row was sealed between the save and here. Write the
		// correction as its own entry so the act is recorded either way.
		try {
			return $this->auditTrailMapper->createAuditTrailEntry(
				object: $object,
				action: self::ACTION,
				context: $context,
				actorId: $actor
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[CorrectionService] The correction landed and the audit entry did not',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'uuid' => $object->getUuid(),
					'error' => $e->getMessage(),
				]
			);

			return null;
		}
	}//end recordCorrection()

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
	 * @spec openspec/changes/repeating-groups-and-recorded-corrections/specs/enhanced-audit-trail/spec.md
	 */
	private function amendLastEntry(ObjectEntity $object, array $context): ?AuditTrail {
		$lastLog = $object->getLastLog();
		$auditId = ($lastLog['id'] ?? null);
		if (is_numeric($auditId) === false) {
			return null;
		}

		try {
			$entry = $this->auditTrailMapper->find((int)$auditId);
		} catch (Throwable $e) {
			return null;
		}

		// A sealed row is part of the hash chain. Rewriting one is tampering
		// whoever does it and for whatever reason, so it is left alone and the
		// caller writes a separate entry.
		if ($entry->getHash() !== null) {
			return null;
		}

		$entry->setAction(self::ACTION);
		$entry->setChanged(array_merge($entry->getChanged(), $context));

		try {
			return $this->auditTrailMapper->update($entry);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[CorrectionService] Could not relabel the audit entry as a correction',
				context: [
					'file' => __FILE__,
					'line' => __LINE__,
					'auditId' => (int)$auditId,
					'error' => $e->getMessage(),
				]
			);

			return null;
		}
	}//end amendLastEntry()
}//end class
