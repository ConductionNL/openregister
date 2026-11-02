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

use OCA\OpenRegister\Db\AuditTrailMapper;
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
	 * @param AuditTrailMapper $auditTrail Writes the entry.
	 * @param LoggerInterface  $logger     Records a failure to write it.
	 */
	public function __construct(
		private readonly AuditTrailMapper $auditTrail,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Write the entry. A failure is logged and never undoes the run, which has happened.
	 *
	 * @param ObjectEntity                                  $object       The subject.
	 * @param string                                        $action       The declared action key.
	 * @param string                                        $kind         `transition`, `flow` or `steps`.
	 * @param string|null                                   $run          The run's uuid; null for a transition.
	 * @param string                                        $inputsDigest The digest of the storable inputs.
	 * @param array<int, array{step: string, kind: string}> $changes      What the run changed.
	 * @param string                                        $outcome      The outcome.
	 * @param string|null                                   $userId       The acting user.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) One argument per field of the entry's context.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-every-run-is-audited-once-and-the-objects-action-history-can-be-read
	 */
	public function record(
		ObjectEntity $object,
		string $action,
		string $kind,
		?string $run,
		string $inputsDigest,
		array $changes,
		string $outcome,
		?string $userId,
	): void {
		try {
			$this->auditTrail->createAuditTrailEntry(
				object: $object,
				action: self::ACTION_RUN,
				context: [
					'declaredAction' => $action,
					'kind' => $kind,
					'run' => $run,
					'inputsDigest' => $inputsDigest,
					'changes' => $changes,
					'outcome' => $outcome,
				],
				actorId: $userId
			);
		} catch (Throwable $e) {
			$this->logger->error(
				message: '[ObjectActionAudit] Could not write the audit entry for action "' . $action . '": ' . $e->getMessage(),
				context: ['action' => $action, 'run' => $run]
			);
		}
	}//end record()
}//end class
