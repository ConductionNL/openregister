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

use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\NoteService;
use OCA\OpenRegister\Service\ObjectRelationCleanupService;
use OCA\OpenRegister\Service\TaskService;
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
	 * Wire the sources a scope member can be counted and destroyed through.
	 *
	 * Each is optional so the service stays constructible in a unit test and
	 * on an instance where a collaborator is unavailable. A member whose
	 * source is missing is reported unavailable and refuses, never silently
	 * counted as zero.
	 *
	 * @param AuditTrailMapper                  $auditTrailMapper Versions and content-bearing audit rows.
	 * @param LoggerInterface                   $logger           PSR logger.
	 * @param NoteService|null                  $noteService      Notes on the object.
	 * @param FileService|null                  $fileService      The object's bound folder.
	 * @param TaskService|null                  $taskService      CalDAV tasks linked to the object.
	 * @param ObjectRelationCleanupService|null $relationCleanup  The remaining timeline links.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrailMapper,
		private readonly LoggerInterface $logger,
		private readonly ?NoteService $noteService = null,
		private readonly ?FileService $fileService = null,
		private readonly ?TaskService $taskService = null,
		private readonly ?ObjectRelationCleanupService $relationCleanup = null,
	) {
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
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function preview(ObjectEntity $object, ?Schema $schema): array {
		$declared = DestructionScope::declaredOn(schema: $schema);
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
	 * @spec openspec/changes/delete-window-and-recorded-destruction/specs/deletion-audit-trail/spec.md
	 */
	public function destroy(ObjectEntity $object, ?Schema $schema): array {
		$preview = $this->preview(object: $object, schema: $schema);
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

		$destroyed = [];
		$failed = [];
		$total = 0;
		foreach ($preview['scope'] as $member) {
			try {
				$went = $this->destroyMember(member: $member, object: $object, expected: ($preview['counts'][$member] ?? 0));
				$destroyed[$member] = $went;
				$total += $went;
			} catch (Throwable $e) {
				$failed[$member] = $e->getMessage();
				$this->logger->error(
					message: '[DestructionScope] Failed to destroy scope member',
					context: [
						'member' => $member,
						'uuid' => $object->getUuid(),
						'error' => $e->getMessage(),
					]
				);
			}
		}

		return [
			'scope' => $preview['scope'],
			'destroyed' => $destroyed,
			'total' => $total,
			'failed' => $failed,
		];
	}//end destroy()

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
			DestructionScope::NOTES => ($this->noteService !== null),
			DestructionScope::FILES => ($this->fileService !== null),
			DestructionScope::TASKS => ($this->taskService !== null),
			DestructionScope::TIMELINE => ($this->relationCleanup !== null),
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
		$uuid = (string)$object->getUuid();

		try {
			return match ($member) {
				DestructionScope::VERSIONS => $this->auditTrailMapper->countForObject(
					objectUuid: $uuid,
					onlyActions: DestructionScope::VERSION_ACTIONS,
					excludeActions: DestructionScope::EVIDENCE_ACTIONS
				),
				DestructionScope::AUDIT_CONTENT => $this->auditTrailMapper->countForObject(
					objectUuid: $uuid,
					onlyActions: null,
					excludeActions: DestructionScope::EVIDENCE_ACTIONS
				),
				DestructionScope::NOTES => $this->noteService?->countNotesForObject($uuid) ?? 0,
				DestructionScope::FILES => count($this->fileService?->getFiles($object) ?? []),
				DestructionScope::TASKS => count($this->taskService?->getTasksForObject($uuid) ?? []),
				DestructionScope::TIMELINE => $this->relationCleanup?->countTimelineLinks($uuid) ?? 0,
				default => 0,
			};
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[DestructionScope] Could not count scope member',
				context: [
					'member' => $member,
					'uuid' => $uuid,
					'error' => $e->getMessage(),
				]
			);
			return 0;
		}//end try
	}//end countMember()

	/**
	 * Destroy one scope member and report how many rows went.
	 *
	 * @param string       $member   The declared member.
	 * @param ObjectEntity $object   The object.
	 * @param int          $expected The count the preview promised.
	 *
	 * @return int The number destroyed.
	 *
	 * @throws Throwable When the source fails.
	 */
	private function destroyMember(string $member, ObjectEntity $object, int $expected): int {
		$uuid = (string)$object->getUuid();

		return match ($member) {
			DestructionScope::VERSIONS => $this->auditTrailMapper->tombstoneForObject(
				objectUuid: $uuid,
				onlyActions: DestructionScope::VERSION_ACTIONS,
				excludeActions: DestructionScope::EVIDENCE_ACTIONS
			),
			DestructionScope::AUDIT_CONTENT => $this->auditTrailMapper->tombstoneForObject(
				objectUuid: $uuid,
				onlyActions: null,
				excludeActions: DestructionScope::EVIDENCE_ACTIONS
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
		$this->noteService?->deleteNotesForObject($uuid);
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
		$folder = $this->fileService?->getObjectFolder(objectEntity: $object);
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
		$tasks = ($this->taskService?->getTasksForObject($uuid) ?? []);
		$went = 0;
		foreach ($tasks as $task) {
			$this->taskService?->deleteTask((string)$task['calendarId'], (string)$task['id']);
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
		$this->relationCleanup?->destroyTimelineLinks($uuid);
		return $expected;
	}//end destroyTimeline()
}//end class
