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
 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
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
class FlowRunSubjects {

	/**
	 * The role a trigger's own object is recorded under.
	 *
	 * @var string
	 */
	public const ROLE_TRIGGER = 'trigger';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface|null $logger Where a REPLACEMENT is recorded.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
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
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
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
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
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
	 * @param FlowRun $run      The run to record on.
	 * @param string  $role     The author's word for what this object is.
	 * @param string  $uuid     The object.
	 * @param string  $register Its register, when known.
	 * @param string  $schema   Its schema, when known.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the role or the uuid is empty.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function record(
		FlowRun $run,
		string $role,
		string $uuid,
		string $register = '',
		string $schema = ''
	): void {
		$role = trim($role);
		$uuid = trim($uuid);

		if ($role === '' || $uuid === '') {
			throw new UnexpectedValueException(
				'A declared subject needs both a role and an object; "' . $role . '" => "' . $uuid . '" has one of them.'
			);
		}

		$subjects = $this->all(run: $run);
		$held = ($subjects[$role] ?? null);

		if (is_array($held) === true && trim((string)($held['uuid'] ?? '')) === $uuid) {
			// The same object under the same role. A heartbeat re-entering the
			// node must not make the run look like it touched it twice.
			return;
		}

		if ($held !== null) {
			$this->logger?->info(
				message: '[FlowRunSubjects] Run "' . (string)$run->getUuid() . '" re-pointed the role "'
					. $role . '" from "' . trim((string)($held['uuid'] ?? '')) . '" to "' . $uuid
					. '". Anything attached to that role from here on addresses the new object.',
				context: ['file' => __FILE__, 'line' => __LINE__, 'run' => (string)$run->getUuid()]
			);
		}

		$subjects[$role] = [
			'uuid' => $uuid,
			'register' => trim($register),
			'schema' => trim($schema),
			'recordedAt' => (new DateTime())->format(DATE_ATOM),
		];

		$run->setSubjects($subjects);

	}//end record()

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
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
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
