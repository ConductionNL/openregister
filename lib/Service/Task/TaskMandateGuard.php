<?php

/**
 * Asks the permission layer whether the person a task would go to holds the
 * mandate the task requires, and returns the evidence when they do.
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
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Db\Task;
use OCA\OpenRegister\Exception\TaskMandateRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\ProvenanceRequest;
use OCA\OpenRegister\Service\Rbac\PermissionCatalogueInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The mandate check of a delegation or a substitution (design D-2).
 *
 * FAILS CLOSED. A subject that cannot be loaded, a verb the catalogue does not
 * know, a missing collaborator, or a permission check that throws all refuse.
 * The check never runs as the system: it asks about the named person, through
 * the same per-user permission question the object endpoints ask.
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */
class TaskMandateGuard {

	/**
	 * The verb asked when the task names no mandate but has a subject:
	 * nobody receives work on a case they cannot open.
	 *
	 * @var string
	 */
	public const READ_VERB = 'read';

	/**
	 * The evidence source of a verb an app decided through its own vote.
	 *
	 * @var string
	 */
	public const SOURCE_CUSTOM = 'custom';

	/**
	 * Constructor.
	 *
	 * @param PermissionHandlerInterface|null   $permissions The per-user permission check and its provenance.
	 * @param ObjectOperationsInterface|null    $objects     Loads the task's subject object.
	 * @param SchemaCatalogInterface|null       $catalog     Loads the subject's schema.
	 * @param PermissionCatalogueInterface|null $catalogue   The grantable verbs, and who declared each.
	 * @param LoggerInterface|null              $logger      Where a check that broke is recorded.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function __construct(
		private readonly ?PermissionHandlerInterface $permissions = null,
		private readonly ?ObjectOperationsInterface $objects = null,
		private readonly ?SchemaCatalogInterface $catalog = null,
		private readonly ?PermissionCatalogueInterface $catalogue = null,
		private readonly ?LoggerInterface $logger = null,
	) {
	}//end __construct()

	/**
	 * Assert that a person holds the task's mandate; return the evidence.
	 *
	 * - Subject and `requiredMandate`: that verb, on the subject object.
	 * - Subject and no `requiredMandate`: read, on the subject object.
	 * - `requiredMandate` and no subject: that verb, on the schema the task
	 *   names; refused when it names none.
	 * - Neither: nothing to check, no evidence.
	 *
	 * @param Task   $task The task being handed over.
	 * @param string $uid  The person it would go to.
	 *
	 * @return array{verb: string, source: string, rule: mixed, until: string|null, app?: string}|null
	 *         The evidence, or null when there was nothing to check.
	 *
	 * @throws TaskMandateRefusedException When the person does not hold it, or it cannot be confirmed.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function assertHolds(Task $task, string $uid): ?array {
		$required = trim((string)$task->getRequiredMandate());
		$subject = trim((string)$task->getObjectUuid());
		if ($required === '' && $subject === '') {
			return null;
		}

		$verb = $required;
		if ($verb === '') {
			$verb = self::READ_VERB;
		}

		$evidence = null;
		try {
			$evidence = $this->evidence(task: $task, uid: $uid, verb: $verb);
		} catch (Throwable $failure) {
			$this->logger?->warning(
				'[TaskMandateGuard] The mandate check did not answer; refusing: ' . $failure->getMessage(),
				['task' => $task->getUuid(), 'verb' => $verb, 'exception' => $failure]
			);
		}

		if ($evidence === null) {
			throw new TaskMandateRefusedException(verb: $verb, uid: $uid);
		}

		return $evidence;
	}//end assertHolds()

	/**
	 * Whether a person holds the task's mandate, never throwing.
	 *
	 * @param Task   $task The task.
	 * @param string $uid  The person.
	 *
	 * @return array|null The evidence when held (an empty array when there was nothing to check), null when not.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-goes-to-the-stand-in-of-an-absent-assignee
	 */
	public function evidenceFor(Task $task, string $uid): ?array {
		try {
			return ($this->assertHolds(task: $task, uid: $uid) ?? []);
		} catch (TaskMandateRefusedException) {
			return null;
		}
	}//end evidenceFor()

	/**
	 * The verb the check asks for this task.
	 *
	 * @param Task $task The task.
	 *
	 * @return string The required mandate, or read.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function verbFor(Task $task): string {
		$required = trim((string)$task->getRequiredMandate());
		if ($required === '') {
			return self::READ_VERB;
		}

		return $required;
	}//end verbFor()

	/**
	 * The evidence of the grant, or null when the person does not hold it.
	 *
	 * @param Task   $task The task.
	 * @param string $uid  The person.
	 * @param string $verb The verb asked.
	 *
	 * @return array|null The evidence, or null.
	 */
	private function evidence(Task $task, string $uid, string $verb): ?array {
		if ($this->permissions === null || $this->catalog === null || $this->catalogue === null) {
			return null;
		}

		if ($this->catalogue->isGrantable(verb: $verb) === false) {
			return null;
		}

		$object = $this->subjectOf(task: $task);
		$schema = $this->schemaOf(task: $task, object: $object);
		if ($schema === null) {
			return null;
		}

		$register = $object?->getRegister();
		if ($register === null && $task->getRegisterId() !== null) {
			$register = (string)$task->getRegisterId();
		}

		$holds = $this->permissions->hasPermission(
			schema: $schema,
			action: $verb,
			userId: $uid,
			object: $object,
			register: $register
		);
		if ($holds !== true) {
			return null;
		}

		return $this->provenance(verb: $verb, uid: $uid, schema: $schema, object: $object);
	}//end evidence()

	/**
	 * The task's subject object, read without the caller's rights: the
	 * question is the delegate's rights, asked next.
	 *
	 * @param Task $task The task.
	 *
	 * @return ObjectEntity|null The subject, or null when the task has none.
	 *
	 * @throws \RuntimeException When the task names a subject that cannot be loaded.
	 */
	private function subjectOf(Task $task): ?ObjectEntity {
		$uuid = trim((string)$task->getObjectUuid());
		if ($uuid === '') {
			return null;
		}

		$object = $this->objects?->find(
			id: $uuid,
			options: new ObjectFindOptions(register: $task->getRegisterId(), schema: $task->getSchemaId(), render: false, audit: false),
			_rbac: false,
			_multitenancy: false
		);
		if ($object === null) {
			throw new \RuntimeException(sprintf('The subject %s of the task could not be loaded.', $uuid));
		}

		return $object;
	}//end subjectOf()

	/**
	 * The schema the check runs against: the subject's, else the task's.
	 *
	 * @param Task              $task   The task.
	 * @param ObjectEntity|null $object The subject.
	 *
	 * @return Schema|null The schema, or null when neither names one.
	 */
	private function schemaOf(Task $task, ?ObjectEntity $object): ?Schema {
		$schemaId = $object?->getSchema();
		if (($schemaId === null || $schemaId === '') && $task->getSchemaId() !== null) {
			$schemaId = (string)$task->getSchemaId();
		}

		if ($schemaId === null || $schemaId === '') {
			return null;
		}

		return $this->catalog?->schemas()->find(id: $schemaId, _rbac: false, _multitenancy: false);
	}//end schemaOf()

	/**
	 * What granted the verb: the rule the provenance names, or the app that
	 * decided its own verb.
	 *
	 * @param string            $verb   The verb.
	 * @param string            $uid    The person.
	 * @param Schema            $schema The schema.
	 * @param ObjectEntity|null $object The subject.
	 *
	 * @return array{verb: string, source: string, rule: mixed, until: string|null, app?: string} The evidence.
	 */
	private function provenance(string $verb, string $uid, Schema $schema, ?ObjectEntity $object): array {
		$record = ($this->permissions?->provenanceFor(
			request: new ProvenanceRequest(schema: $schema, actions: [$verb], userId: $uid, object: $object)
		)[$verb] ?? []);

		if (($record['granted'] ?? false) === true) {
			$rule = ($record['role'] ?? null) ?? ($record['rule'] ?? null);

			return [
				'verb' => $verb,
				'source' => (string)($record['source'] ?? 'none'),
				'rule' => $rule,
				'until' => $this->untilOf(rule: ($record['rule'] ?? null)),
			];
		}

		// Granted by hasPermission yet named by no rule in the cascade: an
		// app decided its own verb through its vote, or nothing narrowed it.
		$declaration = $this->catalogue?->declarationFor(verb: $verb);
		if ($declaration !== null && ($declaration['canonical'] ?? true) === false) {
			return [
				'verb' => $verb,
				'source' => self::SOURCE_CUSTOM,
				'rule' => null,
				'until' => null,
				'app' => (string)($declaration['app'] ?? ''),
			];
		}

		return [
			'verb' => $verb,
			'source' => (string)($record['source'] ?? 'none'),
			'rule' => null,
			'until' => null,
		];
	}//end provenance()

	/**
	 * The end date a grant entry carries, when it carries one.
	 *
	 * @param mixed $rule The grant entry.
	 *
	 * @return string|null The `until`, or null.
	 */
	private function untilOf(mixed $rule): ?string {
		if (is_array($rule) === false || isset($rule['until']) === false || is_scalar($rule['until']) === false) {
			return null;
		}

		return (string)$rule['until'];
	}//end untilOf()
}//end class
