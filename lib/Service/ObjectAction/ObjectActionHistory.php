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
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCA\OpenRegister\Kernel\Contracts\AuditTrailMapperInterface;

/**
 * Lists the action runs recorded on an object.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */
class ObjectActionHistory {

	/**
	 * The most entries one read answers.
	 *
	 * @var int
	 */
	public const LIMIT = 100;

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface<AuditTrail, ObjectEntity> $auditTrail The audit trail.
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
		$run = ($context['run'] ?? null);
		$links = [];
		if (is_string($run) === true && $run !== '') {
			$links['run'] = '/api/flow-runs/' . $run;
		}

		$kind = ($context['kind'] ?? null);
		if ($audit->getAction() === ObjectActionAudit::ACTION_MACRO) {
			$kind = ObjectActionDefinition::KIND_FLOW;
		}

		$created = $audit->getCreated();
		if ($created instanceof DateTimeInterface) {
			$created = $created->format(DateTimeInterface::ATOM);
		}

		return [
			'id' => $audit->getUuid(),
			'action' => ($context['declaredAction'] ?? null),
			'kind' => $kind,
			'run' => $run,
			'outcome' => ($context['outcome'] ?? null),
			'changes' => (array)($context['changes'] ?? []),
			'user' => $audit->getUser(),
			'userName' => $audit->getUserName(),
			'created' => $created,
			'auditAction' => $audit->getAction(),
			'links' => $links,
		];
	}//end entry()
}//end class
