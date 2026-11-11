<?php

/**
 * The one audit entry every run of an action writes on its subject.
 *
 * ```json
 * { "declaredAction": "publish", "kind": "steps", "run": "<uuid>|null", "inputsDigest": "sha256:…",
 *   "changes": [ { "step": "Indiener geïnformeerd", "kind": "message" } ], "outcome": "completed" }
 * ```
 *
 * The writes inside a run audit as usual; this entry is what ties them to
 * the pressed action by name. `kind: transition` has no run: its change is
 * the transition's own audit entry. Earlier `action.macro` entries stay as
 * they are, because the hash chain forbids rewriting them, and the history
 * reads both names.
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

use OCA\OpenRegister\Db\AuditTrail\NamedActor;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEvent;
use OCA\OpenRegister\Db\AuditTrail\ObjectAuditEventOptions;
use OCA\OpenRegister\Db\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes `action.run` entries.
 *
 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
 */
class ObjectActionAudit {

	/**
	 * The audit action of a run of a declared action.
	 *
	 * @var string
	 */
	public const ACTION_RUN = 'action.run';

	/**
	 * The audit action earlier macro runs wrote.
	 *
	 * @var string
	 */
	public const ACTION_MACRO = 'action.macro';

	/**
	 * Constructor.
	 *
	 * @param AuditTrailMapperInterface $auditTrail Writes the entry.
	 * @param LoggerInterface           $logger     Records a failure to write it.
	 * @param ObjectActionGate          $gate       The acting user.
	 */
	public function __construct(
		private readonly AuditTrailMapperInterface $auditTrail,
		private readonly LoggerInterface $logger,
		private readonly ObjectActionGate $gate,
	) {
	}//end __construct()

	/**
	 * Write the entry. A failure is logged and never undoes the run, which has happened.
	 *
	 * @param ObjectEntity           $object       The subject.
	 * @param ObjectActionDefinition $definition   The declared action: its key and kind.
	 * @param ObjectActionRunOutcome $outcome      The run's uuid (null for a transition), its changes and its outcome.
	 * @param string                 $inputsDigest The digest of the storable inputs.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public function record(
		ObjectEntity $object,
		ObjectActionDefinition $definition,
		ObjectActionRunOutcome $outcome,
		string $inputsDigest,
	): void {
		try {
			$this->auditTrail->recordObjectEvent(
				event: new ObjectAuditEvent(
					object: $object,
					action: self::ACTION_RUN,
					context: [
						'declaredAction' => $definition->key,
						'kind' => (string)$definition->kind,
						'run' => $outcome->run,
						'inputsDigest' => $inputsDigest,
						'changes' => $outcome->changes,
						'outcome' => $outcome->outcome,
					],
					options: new ObjectAuditEventOptions(actor: new NamedActor(id: $this->gate->userId()))
				)
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ObjectActionAudit] Could not write the audit entry for action "' . $definition->key . '": ' . $e->getMessage(),
				context: ['action' => $definition->key, 'run' => $outcome->run]
			);
		}
	}//end record()
}//end class
