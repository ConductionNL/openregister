<?php

/**
 * The door between a task and the object it is about.
 *
 * `POST /api/flow-tasks` accepted any `objectUuid` from any authenticated
 * caller. An ordinary account with no relationship to a case, one that gets
 * 404 from `GET /api/objects/{register}/{schema}/{uuid}`, could put a task on
 * that case and on a named colleague's work list. Neither the subject nor the
 * assignee was looked at, so the refusal every other read of that object
 * gives was simply not asked for here.
 *
 * This guard asks for it, through the canonical read path
 * ({@see ObjectService::find()} with `_rbac: true, _multitenancy: true`) and
 * not through a second authorization vocabulary. Whatever the object
 * endpoint decides about a principal, this decides identically, because it
 * is the same call.
 *
 * Two deliberate limits:
 *
 * - It runs on the HTTP create path only ({@see TaskService::create()}),
 *   where the acting identity IS the session user RBAC resolves against.
 *   {@see TaskService::import()}, the trusted in-process path the flow
 *   engine's user-task node uses, stays unguarded: there the actor is a
 *   flow's attribution, not the session, so an RBAC read would answer about
 *   the wrong principal.
 * - It does not run for administrators, exactly as
 *   `ObjectsController::show()` sets `$rbac = $isAdmin === false`. An
 *   administrator reads every object, so the check could only ever pass.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\TaskSubjectNotFoundException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a task whose subject object the caller may not read.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
 */
class TaskSubjectAccessGuard {

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface|null $objects The canonical object read path, the
	 *                                    one authority on who may read an
	 *                                    object. Nullable so the service
	 *                                    stays constructible without a
	 *                                    container; ABSENT, a payload that
	 *                                    names a subject is REFUSED rather
	 *                                    than admitted, because a check that
	 *                                    cannot run has not passed.
	 * @param LoggerInterface|null $logger Where a read that BROKE (rather
	 *                                     than refused) is recorded. The
	 *                                     caller still gets the refusal: a
	 *                                     guard that cannot reach its
	 *                                     authority denies.
	 */
	public function __construct(
		private readonly ?ObjectOperationsInterface $objects = null,
		private readonly ?LoggerInterface $logger = null,
	) {

	}//end __construct()

	/**
	 * Assert that every object a creation payload names is readable by the
	 * caller; throw when one is not.
	 *
	 * Asserting rather than returning a boolean, for the reason
	 * {@see TaskAuthorizationService::assertMay()} gives: a caller that could
	 * ask without consequence could also forget to act on the answer.
	 *
	 * @param array<string, mixed> $data The creation payload: the one generic
	 *                                   anchor `objectUuid`, plus every
	 *                                   `relations[].objectUuid`, which is
	 *                                   the same attachment under a role and
	 *                                   needs the same permission.
	 *
	 * @return void
	 *
	 * @throws TaskSubjectNotFoundException When any named object is absent or
	 *                                      unreadable for this caller.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	public function assertReadable(array $data): void {
		$subjects = $this->subjectsIn(data: $data);
		if ($subjects === []) {
			// A standalone task, about nothing. There is no object to be
			// entitled to, so there is nothing here to refuse.
			return;
		}

		foreach ($subjects as $subject) {
			$this->assertOne(subject: $subject);
		}

	}//end assertReadable()

	/**
	 * Every object the payload attaches the task to, anchor and relations.
	 *
	 * @param array<string, mixed> $data The creation payload.
	 *
	 * @return array<int, array{uuid: string, register: int|null, schema: int|null}>
	 *         One entry per named object, deduplicated on the uuid; the
	 *         anchor first, then the relations in their order.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function subjectsIn(array $data): array {
		$subjects = [];
		foreach ($this->entriesIn(data: $data) as $entry) {
			$subject = $this->subjectOf(entry: $entry);
			if ($subject !== null && array_key_exists($subject['uuid'], $subjects) === false) {
				$subjects[$subject['uuid']] = $subject;
			}
		}

		return array_values($subjects);

	}//end subjectsIn()

	/**
	 * The payload itself (it carries the anchor under the same keys a
	 * relation does), followed by every relation when `relations` is a list.
	 *
	 * @param array<string, mixed> $data The creation payload.
	 *
	 * @return array<int, mixed> The entries that may name an object.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function entriesIn(array $data): array {
		$relations = ($data['relations'] ?? null);
		if (is_array($relations) === false) {
			return [$data];
		}

		return array_merge([$data], array_values($relations));

	}//end entriesIn()

	/**
	 * The object one entry names, or null when it names none.
	 *
	 * @param mixed $entry The anchor payload or one relation.
	 *
	 * @return array{uuid: string, register: int|null, schema: int|null}|null The subject.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function subjectOf(mixed $entry): ?array {
		if (is_array($entry) === false) {
			return null;
		}

		$uuid = trim((string)($entry['objectUuid'] ?? ''));
		if ($uuid === '') {
			return null;
		}

		return [
			'uuid' => $uuid,
			'register' => $this->intOrNull(value: ($entry['registerId'] ?? null)),
			'schema' => $this->intOrNull(value: ($entry['schemaId'] ?? null)),
		];

	}//end subjectOf()

	/**
	 * Assert that one object is readable by the caller.
	 *
	 * The register and schema are passed WHEN the payload named them, so the
	 * lookup stays scoped to one magic table; omitted, the read resolves the
	 * uuid across tables the way every other uuid-addressed read does.
	 *
	 * @param array{uuid: string, register: int|null, schema: int|null} $subject The object named.
	 *
	 * @return void
	 *
	 * @throws TaskSubjectNotFoundException When absent or unreadable.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function assertOne(array $subject): void {
		if ($this->objects === null || $this->read(objects: $this->objects, subject: $subject) === null) {
			// The same words `ObjectsController::show()` answers this
			// principal, so the two refusals are indistinguishable and
			// creating a task tells nobody whether an object exists.
			throw new TaskSubjectNotFoundException(
				message: sprintf('Object with id %s not found', $subject['uuid'])
			);
		}

	}//end assertOne()

	/**
	 * Read one object through the canonical, RBAC- and tenancy-scoped path.
	 *
	 * A refusal arrives as DoesNotExistException, which is the answer;
	 * anything else is breakage, and breakage that cannot be told apart from
	 * a refusal must land on the refusing side. It is recorded so it is not
	 * invisible.
	 *
	 * @param ObjectOperationsInterface                                $objects The read path.
	 * @param array{uuid: string, register: int|null, schema: int|null} $subject The object named.
	 *
	 * @return ObjectEntity|null The object, or null when absent, refused or broken.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function read(ObjectOperationsInterface $objects, array $subject): ?ObjectEntity {
		try {
			return $this->find(objects: $objects, subject: $subject);
		} catch (Throwable $failure) {
			$this->logger?->debug(
				'[TaskSubjectAccessGuard] Subject read did not answer: ' . $failure->getMessage(),
				['uuid' => $subject['uuid'], 'exception' => $failure]
			);
			return null;
		}

	}//end read()

	/**
	 * The read itself: `_rbac: true, _multitenancy: true`, exactly what the
	 * object endpoint asks for this principal; no render, no audit entry.
	 *
	 * @param ObjectOperationsInterface                                $objects The read path.
	 * @param array{uuid: string, register: int|null, schema: int|null} $subject The object named.
	 *
	 * @return ObjectEntity|null The object, or null.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function find(ObjectOperationsInterface $objects, array $subject): ?ObjectEntity {
		return $objects->find(
			id: $subject['uuid'],
			files: false,
			register: $subject['register'],
			schema: $subject['schema'],
			_rbac: true,
			_multitenancy: true,
			_render: false,
			_audit: false
		);

	}//end find()

	/**
	 * An integer, or null for anything that is not one.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return integer|null The integer, or null.
	 *
	 * @spec openspec/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function intOrNull(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;

	}//end intOrNull()

}//end class
