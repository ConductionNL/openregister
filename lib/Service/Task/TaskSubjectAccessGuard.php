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
 * @spec openspec/changes/flow-task-subject-authorization/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Exception\TaskSubjectNotFoundException;
use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuses a task whose subject object the caller may not read.
 *
 * @spec openspec/changes/flow-task-subject-authorization/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
 */
class TaskSubjectAccessGuard {

	/**
	 * Constructor.
	 *
	 * @param ObjectService|null $objects The canonical object read path, the
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
		private readonly ?ObjectService $objects = null,
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
	 * @spec openspec/changes/flow-task-subject-authorization/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	public function assertReadable(array $data): void {
		$subjects = $this->subjectsIn(data: $data);
		if ($subjects === []) {
			// A standalone task, about nothing. There is no object to be
			// entitled to, so there is nothing here to refuse.
			return;
		}

		foreach ($subjects as $subject) {
			$this->assertOne(
				uuid: $subject['uuid'],
				register: $subject['register'],
				schema: $subject['schema']
			);
		}

	}//end assertReadable()

	/**
	 * Every object the payload attaches the task to, anchor and relations.
	 *
	 * @param array<string, mixed> $data The creation payload.
	 *
	 * @return array<int, array{uuid: string, register: int|null, schema: int|null}>
	 *         One entry per named object, deduplicated on the uuid.
	 *
	 * @spec openspec/changes/flow-task-subject-authorization/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function subjectsIn(array $data): array {
		$subjects = [];

		$anchor = trim((string)($data['objectUuid'] ?? ''));
		if ($anchor !== '') {
			$subjects[$anchor] = [
				'uuid' => $anchor,
				'register' => $this->intOrNull(value: ($data['registerId'] ?? null)),
				'schema' => $this->intOrNull(value: ($data['schemaId'] ?? null)),
			];
		}

		$relations = ($data['relations'] ?? null);
		if (is_array($relations) === false) {
			return array_values($subjects);
		}

		foreach ($relations as $relation) {
			if (is_array($relation) === false) {
				continue;
			}

			$uuid = trim((string)($relation['objectUuid'] ?? ''));
			if ($uuid === '' || array_key_exists($uuid, $subjects) === true) {
				continue;
			}

			$subjects[$uuid] = [
				'uuid' => $uuid,
				'register' => $this->intOrNull(value: ($relation['registerId'] ?? null)),
				'schema' => $this->intOrNull(value: ($relation['schemaId'] ?? null)),
			];
		}

		return array_values($subjects);

	}//end subjectsIn()

	/**
	 * Assert that one object is readable by the caller.
	 *
	 * The register and schema are passed WHEN the payload named them, so the
	 * lookup stays scoped to one magic table; omitted, the read resolves the
	 * uuid across tables the way every other uuid-addressed read does.
	 *
	 * @param string $uuid The object uuid.
	 * @param integer|null $register The register the payload named, if any.
	 * @param integer|null $schema The schema the payload named, if any.
	 *
	 * @return void
	 *
	 * @throws TaskSubjectNotFoundException When absent or unreadable.
	 *
	 * @spec openspec/changes/flow-task-subject-authorization/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function assertOne(string $uuid, ?int $register, ?int $schema): void {
		$found = null;

		if ($this->objects !== null) {
			try {
				$found = $this->objects->find(
					id: $uuid,
					files: false,
					register: $register,
					schema: $schema,
					_rbac: true,
					_multitenancy: true,
					_render: false,
					_audit: false
				);
			} catch (Throwable $failure) {
				// A refusal arrives here as DoesNotExistException, which is
				// the answer; anything else is breakage, and breakage that
				// cannot be told apart from a refusal must land on the
				// refusing side. It is recorded so it is not invisible.
				$this->logger?->debug(
					'[TaskSubjectAccessGuard] Subject read did not answer: ' . $failure->getMessage(),
					['uuid' => $uuid, 'exception' => $failure]
				);
				$found = null;
			}//end try
		}

		if ($found === null) {
			// The same words `ObjectsController::show()` answers this
			// principal, so the two refusals are indistinguishable and
			// creating a task tells nobody whether an object exists.
			throw new TaskSubjectNotFoundException(
				message: sprintf('Object with id %s not found', $uuid)
			);
		}

	}//end assertOne()

	/**
	 * An integer, or null for anything that is not one.
	 *
	 * @param mixed $value The incoming value.
	 *
	 * @return integer|null The integer, or null.
	 *
	 * @spec openspec/changes/flow-task-subject-authorization/specs/flow-tasks/spec.md#requirement-a-task-may-only-be-created-on-an-object-its-creator-may-read
	 */
	private function intOrNull(mixed $value): ?int {
		if (is_numeric($value) === false) {
			return null;
		}

		return (int)$value;

	}//end intOrNull()

}//end class
