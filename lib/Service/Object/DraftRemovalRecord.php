<?php

/**
 * The one audit-trail entry a removed draft leaves behind (decision 226).
 *
 * A draft is removed for good, with its files, also on a schema with an
 * archival block. What stays is one entry on OpenRegister's audit trail: who
 * removed it (the user, or which job), when, in which register and schema,
 * which object, and that it was a draft. Never what the draft held: the
 * entry is built from the object's identifiers alone, and no property value
 * is read.
 *
 * It is written through AuditTrailMapper::createAuditTrailEntry(), the entry
 * point every recorded moment that is not a plain change uses, so it is
 * hash-chained and shows where other audit rows show.
 *
 * Nothing here names an app or a procedure (decision 182).
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
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-removing-a-draft-must-leave-one-audit-trail-entry-without-its-content
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Kernel\Contracts\AuditTrailMapperInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the entry, and knows which job is removing drafts right now.
 *
 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-removing-a-draft-must-leave-one-audit-trail-entry-without-its-content
 */
class DraftRemovalRecord {

	/**
	 * The audit action of the entry.
	 *
	 * @var string
	 */
	public const ACTION = 'draft.removed';

	/**
	 * The prefix of the actor id when a job removed the draft.
	 *
	 * @var string
	 */
	public const JOB_ACTOR_PREFIX = 'job:';

	/**
	 * The job that is removing drafts in this process right now, or null.
	 *
	 * Request-scoped: set for the length of one removal by {@see asJob()} and
	 * put back afterwards. A job has no session, so without it the entry
	 * could only say "system".
	 *
	 * @var string|null
	 */
	private static ?string $actingJob = null;

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface<AuditTrail, ObjectEntity> $auditTrail Writes the hash-chained entry.
	 * @param LoggerInterface  $logger     Keeps the facts when the entry cannot be written.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrail,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Run a removal as a named job, so its entry names that job.
	 *
	 * @param string        $job       The job, by a name an administrator recognises in the job list.
	 * @param callable(): T $operation The removal.
	 *
	 * @return T Whatever the removal returned.
	 *
	 * @template T
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-removing-a-draft-must-leave-one-audit-trail-entry-without-its-content
	 */
	public static function asJob(string $job, callable $operation): mixed {
		$previous = self::$actingJob;
		self::$actingJob = $job;

		try {
			return $operation();
		} finally {
			self::$actingJob = $previous;
		}
	}//end asJob()

	/**
	 * Write the entry for a draft that was just removed.
	 *
	 * Called after the row is gone, with the entity that was read before the
	 * delete. A failure to write does not bring the draft back, so it is
	 * logged as an error with the same facts and never thrown.
	 *
	 * @param ObjectEntity  $draft    The removed draft, as read before the delete.
	 * @param Register|null $register Its register, for the slug.
	 * @param Schema|null   $schema   Its schema, for the slug.
	 *
	 * @return bool True when the entry was written.
	 *
	 * @spec openspec/changes/draft-owner-activation-and-lifetime/specs/form-destination/spec.md#requirement-removing-a-draft-must-leave-one-audit-trail-entry-without-its-content
	 */
	public function write(ObjectEntity $draft, ?Register $register, ?Schema $schema): bool {
		$facts = $this->facts(draft: $draft, register: $register, schema: $schema);

		$actorId = null;
		$actorName = null;
		if (self::$actingJob !== null) {
			$actorId = self::JOB_ACTOR_PREFIX . self::$actingJob;
			$actorName = self::$actingJob;
		}

		try {
			$this->auditTrail->createAuditTrailEntry(
				object: $draft,
				action: self::ACTION,
				context: $facts,
				actorId: $actorId,
				actorName: $actorName
			);

			return true;
		} catch (Throwable $exception) {
			$this->logger->error(
				message: '[DraftRemovalRecord] A draft was removed and its audit-trail entry could not be written',
				context: ($facts + ['uuid' => (string)$draft->getUuid(), 'exception' => $exception->getMessage()])
			);

			return false;
		}
	}//end write()

	/**
	 * What the entry says beside its own columns (actor, moment, register, schema, object).
	 *
	 * Identifiers only. The draft's property values are never read here, so
	 * no later change to this class can leak them by accident: there is no
	 * call to getObject() or jsonSerialize() to widen.
	 *
	 * @param ObjectEntity  $draft    The removed draft.
	 * @param Register|null $register Its register.
	 * @param Schema|null   $schema   Its schema.
	 *
	 * @return array<string, bool|string|null> The context of the entry.
	 */
	private function facts(ObjectEntity $draft, ?Register $register, ?Schema $schema): array {
		return [
			'status' => ObjectEntity::STATUS_DRAFT,
			'permanent' => true,
			'job' => self::$actingJob,
			'register' => $register?->getSlug(),
			'schema' => $schema?->getSlug(),
			'owner' => $draft->getOwner(),
		];
	}//end facts()
}//end class
