<?php

/**
 * The objects a run says it is working with, each under a role.
 *
 * 🔴 DECLARED, NOT DERIVED, AND THE TWO COEXIST. The audit trail already
 * answers "what did this run write", and it cannot serve this purpose: a case
 * flow READS a case it never writes, WRITES a task and a document it does not
 * consider subjects, and may write one object at three steps for three
 * different reasons. The derived list is too much and too little at once — and,
 * decisively, it carries no ROLE. `attachTo: case` needs a name, and a list of
 * things that happened has none to give.
 *
 * Derived answers the auditor. Declared answers the author.
 *
 * WHY A ROLE AND NOT AN INDEX
 * ---------------------------
 * `attachTo: 2` would work, would be unreadable, and would break the moment a
 * step is inserted. A role is the author's own word for what the object is TO
 * THIS FLOW, which is what later steps actually mean. Roles are per-run and
 * unconstrained on purpose: `case`, `besluit`, `aanvraag` are all fine, and a
 * typo is caught by the addressing rule failing loudly rather than by a
 * registry somebody has to maintain.
 *
 * 🔑 IDEMPOTENCY IS NOT OPTIONAL. A user-task node is re-entered on a
 * heartbeat, by design, with its task still open — so any node that records a
 * subject is re-entered too. A set that grew on each wake would report a run as
 * working on the same case forty times.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-run-subjects/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use DateTime;
use OCA\OpenRegister\Db\FlowRun;
use Psr\Log\LoggerInterface;
use UnexpectedValueException;

/**
 * Reads and records a run's declared subject set.
 */
class FlowRunSubjects implements FlowRunSubjectsInterface {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface|null $logger Where a REPLACEMENT is recorded.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function __construct(private readonly ?LoggerInterface $logger = null) {

	}//end __construct()

	/**
	 * Everything the run has declared, by role.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return array<string, array<string, mixed>> The declared subjects.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function all(FlowRun $run): array {
		$subjects = $run->getSubjects();

		if (is_array($subjects) === false) {
			return [];
		}

		return $subjects;

	}//end all()

	/**
	 * The roles this run currently holds.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return array<int, string> The roles, sorted so a refusal reads the same twice.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function roles(FlowRun $run): array {
		$roles = array_keys($this->all(run: $run));
		sort($roles);

		return $roles;

	}//end roles()

	/**
	 * Record an object under a role, in place on the run.
	 *
	 * 🔑 RE-RECORDING THE SAME OBJECT UNDER THE SAME ROLE CHANGES NOTHING, and
	 * that is what makes this safe on a heartbeat. Only a DIFFERENT object
	 * under a held role is a replacement.
	 *
	 * 🔴 A REPLACEMENT IS ALLOWED AND LOGGED, NEVER REFUSED. Re-pointing a role
	 * is legitimate — a flow that supersedes a draft decision with a final one
	 * genuinely means `decision` to be the new object, and refusing would force
	 * authors into `decision2`. But it is also exactly how a later step
	 * silently attaches to the wrong record, so the log entry is what makes
	 * "why is this task on the old decision" answerable in under a minute.
	 *
	 * @param FlowRun          $run   The run to record on.
	 * @param FlowSubjectClaim $claim The author's word for what the object is, the object, and its register and schema when known.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the role or the uuid is empty.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function record(FlowRun $run, FlowSubjectClaim $claim): void {
		$claim = $this->trimmed(claim: $claim);
		$this->refuseHalfNamed(claim: $claim);

		$subjects = $this->all(run: $run);
		$held = ($subjects[$claim->role] ?? null);

		if (is_array($held) === true && trim((string)($held['uuid'] ?? '')) === $claim->uuid) {
			// The same object under the same role. A heartbeat re-entering the
			// node must not make the run look like it touched it twice.
			return;
		}

		$this->noteRepointing(run: $run, claim: $claim);
		$subjects[$claim->role] = $this->entryFor(claim: $claim);
		$run->setSubjects($subjects);

	}//end record()

	/**
	 * The claim with every part trimmed.
	 *
	 * @param FlowSubjectClaim $claim The claim as declared.
	 *
	 * @return FlowSubjectClaim The trimmed claim.
	 */
	private function trimmed(FlowSubjectClaim $claim): FlowSubjectClaim {
		return new FlowSubjectClaim(
			role: trim($claim->role),
			uuid: trim($claim->uuid),
			register: trim($claim->register),
			schema: trim($claim->schema)
		);
	}//end trimmed()

	/**
	 * Refuse a claim that names only one of its role and its object.
	 *
	 * @param FlowSubjectClaim $claim The trimmed claim.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the role or the object is empty.
	 */
	private function refuseHalfNamed(FlowSubjectClaim $claim): void {
		if ($claim->role === '' || $claim->uuid === '') {
			throw new UnexpectedValueException(
				'A declared subject needs both a role and an object; "' . $claim->role . '" => "' . $claim->uuid . '" has one of them.'
			);
		}
	}//end refuseHalfNamed()

	/**
	 * Say so when a claim re-points a role the run already holds.
	 *
	 * @param FlowRun          $run   The run.
	 * @param FlowSubjectClaim $claim The trimmed claim taking the role over.
	 *
	 * @return void
	 */
	private function noteRepointing(FlowRun $run, FlowSubjectClaim $claim): void {
		$held = ($this->all(run: $run)[$claim->role] ?? null);
		if ($held === null) {
			return;
		}

		$this->logger?->info(
			message: '[FlowRunSubjects] Run "' . (string)$run->getUuid() . '" re-pointed the role "'
				. $claim->role . '" from "' . trim((string)($held['uuid'] ?? '')) . '" to "' . $claim->uuid
				. '". Anything attached to that role from here on addresses the new object.',
			context: ['file' => __FILE__, 'line' => __LINE__, 'run' => (string)$run->getUuid()]
		);
	}//end noteRepointing()

	/**
	 * The subject entry a trimmed claim records, stamped now.
	 *
	 * @param FlowSubjectClaim $claim The trimmed claim.
	 *
	 * @return array{uuid: string, register: string, schema: string, recordedAt: string} The entry.
	 */
	private function entryFor(FlowSubjectClaim $claim): array {
		return [
			'uuid' => $claim->uuid,
			'register' => $claim->register,
			'schema' => $claim->schema,
			'recordedAt' => (new DateTime())->format(DATE_ATOM),
		];
	}//end entryFor()

	/**
	 * Record the object the run fired on (its `subject_*` columns) under the `trigger` role.
	 *
	 * @param FlowRun $run The run to record on.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the run carries no subject uuid.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function recordTrigger(FlowRun $run): void {
		$claim = new FlowSubjectClaim(
			role: self::ROLE_TRIGGER,
			uuid: trim((string)$run->getSubjectUuid()),
			register: trim((string)$run->getSubjectRegister()),
			schema: trim((string)$run->getSubjectSchema())
		);
		$this->record(run: $run, claim: $claim);

	}//end recordTrigger()

	/**
	 * The object a role names, for a step that addresses one.
	 *
	 * 🔴 AN UNHELD ROLE FAILS, NAMING WHAT IS HELD. A typo in `attachTo` would
	 * otherwise attach the task to nothing and leave the author looking for a
	 * record that was never made — so the refusal lists the roles that exist,
	 * which is the whole reason no vocabulary has to be registered anywhere.
	 *
	 * @param FlowRun $run  The run.
	 * @param string  $role The role to address.
	 *
	 * @return array<string, mixed> The subject entry.
	 *
	 * @throws UnexpectedValueException When the run holds no such role.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function addressed(FlowRun $run, string $role): array {
		$role = trim($role);
		$subjects = $this->all(run: $run);

		if (is_array(($subjects[$role] ?? null)) === true) {
			return $subjects[$role];
		}

		$held = $this->roles(run: $run);
		$holds = 'it holds: ' . implode(', ', $held);
		if ($held === []) {
			$holds = 'it has declared none';
		}

		throw new UnexpectedValueException(
			'This step addresses the subject "' . $role . '", and this run has no such subject — ' . $holds . '.'
		);

	}//end addressed()
}//end class
