<?php

/**
 * The runs of actions on one object, newest first.
 *
 * Reads both `action.run` (every run since object actions) and `action.macro`
 * (macro runs before them, which the hash chain forbids rewriting) as one
 * kind of entry, so a "last run" line and a history list never lose the
 * older runs.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ObjectAction;

use DateTimeInterface;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;

/**
 * Lists the action runs recorded on an object.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */
class ObjectActionHistory implements ObjectActionHistoryInterface {

	/**
	 * The most entries one read answers.
	 *
	 * @var int
	 */
	public const LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $auditTrail The audit trail.
	 * @param ObjectActionGate $gate       The read right.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrail,
		private readonly ObjectActionGate $gate,
	) {
	}//end __construct()

	/**
	 * The action runs on an object, newest first.
	 *
	 * @param ObjectEntity $object The object.
	 * @param Schema       $schema Its schema.
	 *
	 * @return list<array<string, mixed>> The entries.
	 *
	 * @throws NotAuthorizedException When the caller may not read the object.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public function forObject(ObjectEntity $object, Schema $schema): array {
		if ($this->gate->may(schema: $schema, right: 'read', object: $object) === false) {
			throw new NotAuthorizedException(message: 'You do not have permission to read this object.');
		}

		$entries = $this->auditTrail->findAll(
			limit: self::LIMIT,
			filters: [
				'object_uuid' => (string)$object->getUuid(),
				'action' => ObjectActionAudit::ACTION_RUN . ',' . ObjectActionAudit::ACTION_MACRO,
			],
			sort: ['created' => 'DESC', 'id' => 'DESC']
		);

		return array_values(array_map(fn (AuditTrail $entry): array => $this->entry(audit: $entry), $entries));
	}//end forObject()

	/**
	 * One history entry from one audit row.
	 *
	 * @param AuditTrail $audit The audit row.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function entry(AuditTrail $audit): array {
		$context = $audit->getChanged();
		$run     = ($context['run'] ?? null);

		return [
			'id' => $audit->getUuid(),
			'action' => ($context['declaredAction'] ?? null),
			'kind' => $this->kindOf(audit: $audit, context: $context),
			'run' => $run,
			'outcome' => ($context['outcome'] ?? null),
			'changes' => (array)($context['changes'] ?? []),
		] + $this->rowFields(audit: $audit, run: $run);
	}//end entry()

	/**
	 * Who wrote the row, when, as which audit action, and the links.
	 *
	 * @param AuditTrail $audit The audit row.
	 * @param mixed      $run   The recorded run uuid.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function rowFields(AuditTrail $audit, mixed $run): array {
		return [
			'user' => $audit->getUser(),
			'userName' => $audit->getUserName(),
			'created' => $this->createdOf(audit: $audit),
			'auditAction' => $audit->getAction(),
			'links' => $this->linksOf(run: $run),
		];
	}//end rowFields()

	/**
	 * The links of an entry: its run, when it has one.
	 *
	 * @param mixed $run The recorded run uuid.
	 *
	 * @return array<string, string> The links.
	 */
	private function linksOf(mixed $run): array {
		if (is_string($run) === true && $run !== '') {
			return ['run' => '/api/flow-runs/' . $run];
		}

		return [];
	}//end linksOf()

	/**
	 * The kind of an entry; an old macro entry is a flow.
	 *
	 * @param AuditTrail $audit   The audit row.
	 * @param mixed      $context The recorded context.
	 *
	 * @return mixed The kind.
	 */
	private function kindOf(AuditTrail $audit, mixed $context): mixed {
		if ($audit->getAction() === ObjectActionAudit::ACTION_MACRO) {
			return ObjectActionDefinition::KIND_FLOW;
		}

		return ($context['kind'] ?? null);
	}//end kindOf()

	/**
	 * When the entry was written, as ATOM when it is a date.
	 *
	 * @param AuditTrail $audit The audit row.
	 *
	 * @return mixed The time.
	 */
	private function createdOf(AuditTrail $audit): mixed {
		$created = $audit->getCreated();
		if ($created instanceof DateTimeInterface) {
			return $created->format(DateTimeInterface::ATOM);
		}

		return $created;
	}//end createdOf()
}//end class
