<?php

/**
 * Answers the mandate question for a named person: whether they hold a
 * grantable verb on an object or schema, and the evidence of what granted it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
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

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalogInterface;
use OCA\OpenRegister\Kernel\Contracts\MandateEvidenceInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindOptions;
use OCA\OpenRegister\Service\Object\PermissionAsk;
use OCA\OpenRegister\Service\Object\PermissionHandlerInterface;
use OCA\OpenRegister\Service\Object\ProvenanceRequest;

/**
 * The permission side of the task mandate check (design D-2).
 *
 * Never runs as the system: it asks about the named person, through the same
 * per-user permission question the object endpoints ask. A missing
 * collaborator answers null (not held); a check that breaks throws, and the
 * caller refuses.
 *
 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
 */
class MandateEvidence implements MandateEvidenceInterface {

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
	 * @param ObjectOperationsInterface|null    $objects     Loads the subject object.
	 * @param SchemaCatalogInterface|null       $catalog     Loads the subject's schema.
	 * @param PermissionCatalogueInterface|null $catalogue   The grantable verbs, and who declared each.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function __construct(
		private readonly ?PermissionHandlerInterface $permissions = null,
		private readonly ?ObjectOperationsInterface $objects = null,
		private readonly ?SchemaCatalogInterface $catalog = null,
		private readonly ?PermissionCatalogueInterface $catalogue = null,
	) {
	}//end __construct()

	/**
	 * Whether the permission catalogue knows the verb as grantable.
	 *
	 * @param string $verb The verb.
	 *
	 * @return bool True when grantable; false without a catalogue.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-task-may-name-the-mandate-its-performer-needs
	 */
	public function isGrantable(string $verb): bool {
		return $this->catalogue?->isGrantable(verb: $verb) === true;
	}//end isGrantable()

	/**
	 * The evidence of the grant, or null when the person does not hold it.
	 *
	 * @param string      $verb       The verb asked.
	 * @param string      $uid        The person.
	 * @param string|null $objectUuid The subject object, if any.
	 * @param int|null    $registerId The subject's register, if known.
	 * @param int|null    $schemaId   The schema when there is no subject.
	 *
	 * @return array{verb: string, source: string, rule: mixed, until: string|null, app?: string}|null The evidence, or null.
	 *
	 * @spec openspec/changes/tasks-delegation-and-substitution/specs/flow-tasks/spec.md#requirement-a-delegate-must-hold-the-tasks-mandate
	 */
	public function evidence(string $verb, string $uid, ?string $objectUuid, ?int $registerId, ?int $schemaId): ?array {
		if ($this->permissions === null || $this->catalog === null || $this->catalogue === null) {
			return null;
		}

		if ($this->catalogue->isGrantable(verb: $verb) === false) {
			return null;
		}

		$object = $this->subjectOf(uuid: $objectUuid, registerId: $registerId, schemaId: $schemaId);
		$schema = $this->schemaOf(schemaId: $schemaId, object: $object);
		if ($schema === null) {
			return null;
		}

		$register = $object?->getRegister();
		if ($register === null && $registerId !== null) {
			$register = (string)$registerId;
		}

		$holds = $this->permissions->hasPermission(
			ask: (new PermissionAsk(schema: $schema, action: $verb))
				->askedBy(userId: $uid, objectOwner: null)
				->about(object: $object, register: $register)
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
	 * @param string|null $uuid       The subject's uuid.
	 * @param int|null    $registerId The subject's register.
	 * @param int|null    $schemaId   The subject's schema.
	 *
	 * @return ObjectEntity|null The subject, or null when there is none.
	 *
	 * @throws \RuntimeException When a subject is named that cannot be loaded.
	 */
	private function subjectOf(?string $uuid, ?int $registerId, ?int $schemaId): ?ObjectEntity {
		$uuid = trim((string)$uuid);
		if ($uuid === '') {
			return null;
		}

		$object = $this->objects?->find(
			id: $uuid,
			options: new ObjectFindOptions(register: $registerId, schema: $schemaId, render: false, audit: false),
			_rbac: false,
			_multitenancy: false
		);
		if ($object === null) {
			throw new \RuntimeException(sprintf('The subject %s could not be loaded.', $uuid));
		}

		return $object;
	}//end subjectOf()

	/**
	 * The schema the check runs against: the subject's, else the named one.
	 *
	 * @param int|null          $schemaId The schema named when there is no subject.
	 * @param ObjectEntity|null $object   The subject.
	 *
	 * @return Schema|null The schema, or null when neither names one.
	 */
	private function schemaOf(?int $schemaId, ?ObjectEntity $object): ?Schema {
		$id = $object?->getSchema();
		if (($id === null || $id === '') && $schemaId !== null) {
			$id = (string)$schemaId;
		}

		if ($id === null || $id === '') {
			return null;
		}

		return $this->catalog?->schemas()->find(id: $id, _rbac: false, _multitenancy: false);
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
