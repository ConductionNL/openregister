<?php

/**
 * DestructionScopeService — previews and carries out what a schema declares is
 * destroyed with an object.
 *
 * Three rules hold here and nowhere else:
 *
 * 1. A schema that declares nothing destroys exactly what it destroyed before
 *    this change. An empty scope is a valid answer, not an oversight.
 * 2. A schema that declares a member nobody can honour refuses. An unclear
 *    scope is the case ADR-005 says must fail closed, because the alternative
 *    is reporting a destruction that did not happen.
 * 3. The evidence of the destruction is outside the scope by construction. No
 *    member names it, and every audit-touching member excludes the destruction
 *    action from {@see DestructionScope::EVIDENCE_ACTIONS}.
 *
 * Audit rows are TOMBSTONED rather than deleted, for the reason
 * {@see \OCA\OpenRegister\Db\AuditTrailMapper::clearLogs()} gives: the table is
 * a hash chain and a hole in it looks exactly like tampering.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Deletion
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Deletion;

use OCA\OpenRegister\Db\AuditTrail\ActionScope;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Edepot\AuditRecording;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Previews and carries out a declared destruction scope.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Deletion
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A destruction scope spans the
 * entity services that hold what hangs off an object. The coupling is the
 * scope; the alternative is a second, quietly different list of what survives.
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */
class DestructionScopeService {
	/**
	 * Versions and content-bearing audit rows.
	 *
	 * @var AuditTrailMapperInterface
	 */
	private readonly AuditTrailMapperInterface $auditTrailMapper;

	/**
	 * PSR logger.
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Wire the sources a scope member can be counted and destroyed through.
	 *
	 * Each is optional so the service stays constructible in a unit test and
	 * on an instance where a collaborator is unavailable. A member whose
	 * source is missing is reported unavailable and refuses, never silently
	 * counted as zero.
	 *
	 * @param AuditRecording        $audit      Versions and content-bearing audit rows, and the PSR logger.
	 * @param DestructionScopeReader $reader     Reads the scope a schema declares.
	 * @param DestructionCompanions $companions The notes, files, tasks and relations that go with a destroyed object.
	 *
	 * @return void
	 */
	public function __construct(
		AuditRecording $audit,
		private readonly DestructionScopeReader $reader,
		private readonly DestructionCompanions $companions,
	) {
		$this->auditTrailMapper = $audit->auditTrailMapper;
		$this->logger           = $audit->logger;
	}//end __construct()

	/**
	 * Preview the scope with a count per member, before anything is destroyed.
	 *
	 * @param ObjectEntity $object The object about to be destroyed.
	 * @param Schema|null  $schema The object's schema, when it resolves.
	 *
	 * @return array{scope: array<int, string>, counts: array<string, int>,
	 *     total: int, unknown: array<int, string>,
	 *     unavailable: array<int, string>, destroyable: bool} The preview.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function preview(ObjectEntity $object, ?Schema $schema): array {
		$declared = $this->reader->declaredOn(schema: $schema);
		$counts = [];
		$unavailable = [];
		$total = 0;

		foreach ($declared['scope'] as $member) {
			if ($this->isAvailable(member: $member) === false) {
				$unavailable[] = $member;
				continue;
			}

			$count = $this->countMember(member: $member, object: $object);
			$counts[$member] = $count;
			$total += $count;
		}

		return [
			'scope' => $declared['scope'],
			'counts' => $counts,
			'total' => $total,
			'unknown' => $declared['unknown'],
			'unavailable' => $unavailable,
			'destroyable' => ($declared['unknown'] === [] && $unavailable === []),
		];
	}//end preview()

	/**
	 * Destroy everything the schema declares, and report what went.
	 *
	 * Refuses before touching anything when the scope names a member nobody
	 * can honour, so a caller never gets a half-destroyed object with a report
	 * that reads as complete.
	 *
	 * @param ObjectEntity $object The object being destroyed.
	 * @param Schema|null  $schema The object's schema, when it resolves.
	 *
	 * @return array{scope: array<int, string>, destroyed: array<string, int>,
	 *     total: int, failed: array<string, string>} What was destroyed.
	 *
	 * @throws DestructionRefusedException When the scope cannot be honoured.
	 *
	 * @spec openspec/specs/deletion-audit-trail/spec.md
	 */
	public function destroy(ObjectEntity $object, ?Schema $schema): array {
		$preview = $this->preview(object: $object, schema: $schema);
		self::refuseUnclearScope(preview: $preview);

		$outcome = $this->destroyMembers(preview: $preview, object: $object);

		return [
			'scope' => $preview['scope'],
			'destroyed' => $outcome['destroyed'],
			'total' => array_sum($outcome['destroyed']),
			'failed' => $outcome['failed'],
		];
	}//end destroy()

	/**
	 * Refuse a scope that names members this instance cannot honour.
	 *
	 * @param array<string, mixed> $preview The preview of the scope.
	 *
	 * @return void
	 *
	 * @throws DestructionRefusedException When the scope cannot be honoured.
	 */
	private static function refuseUnclearScope(array $preview): void {
		if ($preview['destroyable'] === false) {
			throw new DestructionRefusedException(
				rule: 'destruction-scope-unclear',
				reason: 'The declared destruction scope names members this instance cannot honour: '
					. implode(', ', array_merge($preview['unknown'], $preview['unavailable'])) . '.',
				statusCode: 409,
				context: [
					'unknown' => $preview['unknown'],
					'unavailable' => $preview['unavailable'],
				]
			);
		}
	}//end refuseUnclearScope()

	/**
	 * Destroy every member of the scope, carrying on past a member that fails.
	 *
	 * @param array<string, mixed> $preview The preview of the scope.
	 * @param ObjectEntity         $object  The object being destroyed.
	 *
	 * @return array{destroyed: array<string, int>, failed: array<string, string>} What went and what failed.
	 */
	private function destroyMembers(array $preview, ObjectEntity $object): array {
		$outcome = ['destroyed' => [], 'failed' => []];
		foreach ($preview['scope'] as $member) {
			try {
				$outcome['destroyed'][$member] = $this->destroyLogged(
					member: new ScopeMember(name: $member, expected: ($preview['counts'][$member] ?? 0)),
					object: $object
				);
			} catch (Throwable $e) {
				$outcome['failed'][$member] = $e->getMessage();
			}
		}

		return $outcome;
	}//end destroyMembers()

	/**
	 * Destroy one scope member, logging a failure before passing it on.
	 *
	 * @param ScopeMember  $member The member and how many rows are expected.
	 * @param ObjectEntity $object The object being destroyed.
	 *
	 * @return int How many rows went.
	 *
	 * @throws Throwable When the member could not be destroyed.
	 */
	private function destroyLogged(ScopeMember $member, ObjectEntity $object): int {
		try {
			return $this->destroyMember(member: $member, object: $object);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[DestructionScope] Failed to destroy scope member',
				context: [
					'member' => $member->name,
					'uuid' => $object->getUuid(),
					'error' => $e->getMessage(),
				]
			);
			throw $e;
		}
	}//end destroyLogged()

	/**
	 * Whether this instance can honour a scope member at all.
	 *
	 * @param string $member The declared member.
	 *
	 * @return bool True when the member has a source wired.
	 */
	private function isAvailable(string $member): bool {
		return match ($member) {
			DestructionScope::VERSIONS, DestructionScope::AUDIT_CONTENT => true,
			DestructionScope::NOTES => ($this->companions->noteService !== null),
			DestructionScope::FILES => ($this->companions->fileService !== null),
			DestructionScope::TASKS => ($this->companions->taskService !== null),
			DestructionScope::TIMELINE => ($this->companions->relationCleanup !== null),
			default => false,
		};
	}//end isAvailable()

	/**
	 * Count one scope member.
	 *
	 * @param string       $member The declared member.
	 * @param ObjectEntity $object The object.
	 *
	 * @return int The count, zero when the source cannot answer.
	 */
	private function countMember(string $member, ObjectEntity $object): int {
		try {
			return $this->memberCount(member: $member, object: $object);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[DestructionScope] Could not count scope member',
				context: [
					'member' => $member,
					'uuid' => (string)$object->getUuid(),
					'error' => $e->getMessage(),
				]
			);
			return 0;
		}//end try
	}//end countMember()

	/**
	 * How many of one scope member an object holds; throws when the member's store cannot be read.
	 *
	 * @param string       $member One of DestructionScope's members.
	 * @param ObjectEntity $object The object.
	 *
	 * @return integer The count; 0 for a member that is not counted.
	 */
	private function memberCount(string $member, ObjectEntity $object): int {
		$uuid = (string)$object->getUuid();

		return match ($member) {
			DestructionScope::VERSIONS => $this->trailCount(uuid: $uuid, onlyActions: DestructionScope::VERSION_ACTIONS),
			DestructionScope::AUDIT_CONTENT => $this->trailCount(uuid: $uuid, onlyActions: null),
			DestructionScope::NOTES => $this->companions->noteService?->countNotesForObject($uuid) ?? 0,
			DestructionScope::FILES => count($this->companions->fileService?->getFiles($object) ?? []),
			DestructionScope::TASKS => count($this->companions->taskService?->getTasksForObject($uuid) ?? []),
			DestructionScope::TIMELINE => $this->companions->relationCleanup?->countTimelineLinks($uuid) ?? 0,
			default => 0,
		};
	}//end memberCount()

	/**
	 * How many audit trail entries an object holds, evidence excluded.
	 *
	 * @param string                  $uuid        The object uuid.
	 * @param array<int, string>|null $onlyActions The actions to count, or null for every action.
	 *
	 * @return integer The count.
	 */
	private function trailCount(string $uuid, ?array $onlyActions): int {
		return $this->auditTrailMapper->countObjectTrail(
			objectUuid: $uuid,
			scope: new ActionScope(onlyActions: $onlyActions, excludeActions: DestructionScope::EVIDENCE_ACTIONS)
		);
	}//end trailCount()

	/**
	 * Destroy one scope member and report how many rows went.
	 *
	 * @param ScopeMember  $member The declared member and the count the preview promised.
	 * @param ObjectEntity $object The object.
	 *
	 * @return int The number destroyed.
	 *
	 * @throws Throwable When the source fails.
	 */
	private function destroyMember(ScopeMember $member, ObjectEntity $object): int {
		$uuid     = (string)$object->getUuid();
		$expected = $member->expected;

		return match ($member->name) {
			DestructionScope::VERSIONS => $this->auditTrailMapper->tombstoneObjectTrail(
				objectUuid: $uuid,
				scope: new ActionScope(onlyActions: DestructionScope::VERSION_ACTIONS, excludeActions: DestructionScope::EVIDENCE_ACTIONS)
			),
			DestructionScope::AUDIT_CONTENT => $this->auditTrailMapper->tombstoneObjectTrail(
				objectUuid: $uuid,
				scope: new ActionScope(onlyActions: null, excludeActions: DestructionScope::EVIDENCE_ACTIONS)
			),
			DestructionScope::NOTES => $this->destroyNotes(uuid: $uuid, expected: $expected),
			DestructionScope::FILES => $this->destroyFiles(object: $object, expected: $expected),
			DestructionScope::TASKS => $this->destroyTasks(uuid: $uuid),
			DestructionScope::TIMELINE => $this->destroyTimeline(uuid: $uuid, expected: $expected),
			default => 0,
		};
	}//end destroyMember()

	/**
	 * Delete every note on the object.
	 *
	 * @param string $uuid     The object UUID.
	 * @param int    $expected The count the preview promised.
	 *
	 * @return int The number destroyed.
	 */
	private function destroyNotes(string $uuid, int $expected): int {
		$this->companions->noteService?->deleteNotesForObject($uuid);
		return $expected;
	}//end destroyNotes()

	/**
	 * Destroy the object's bound folder and its contents.
	 *
	 * @param ObjectEntity $object   The object.
	 * @param int          $expected The count the preview promised.
	 *
	 * @return int The number destroyed.
	 */
	private function destroyFiles(ObjectEntity $object, int $expected): int {
		$folder = $this->companions->fileService?->getObjectFolder(objectEntity: $object);
		if ($folder === null) {
			return 0;
		}

		$folder->delete();
		return $expected;
	}//end destroyFiles()

	/**
	 * Delete every CalDAV task linked to the object.
	 *
	 * @param string $uuid The object UUID.
	 *
	 * @return int The number destroyed.
	 */
	private function destroyTasks(string $uuid): int {
		$tasks = ($this->companions->taskService?->getTasksForObject($uuid) ?? []);
		$went = 0;
		foreach ($tasks as $task) {
			$this->companions->taskService?->deleteTask((string)$task['calendarId'], (string)$task['id']);
			$went++;
		}

		return $went;
	}//end destroyTasks()

	/**
	 * Destroy the timeline links that are not notes and not tasks.
	 *
	 * @param string $uuid     The object UUID.
	 * @param int    $expected The count the preview promised.
	 *
	 * @return int The number destroyed.
	 */
	private function destroyTimeline(string $uuid, int $expected): int {
		$this->companions->relationCleanup?->destroyTimelineLinks($uuid);
		return $expected;
	}//end destroyTimeline()
}//end class
