<?php

/**
 * Records a declared subject onto the run row, from inside a step.
 *
 * A node knows its run only as a uuid in the context array, and the subject set
 * lives on the run row — because it must survive a three-week suspension, which
 * a per-node resume slot does not. This is the one place that turns the uuid
 * into the row, records, and stores.
 *
 * 🔑 A NODE THAT DECLARES NO ROLE RECORDS NOTHING. Every step this is wired
 * into already did its work without it, and a subject nobody asked for would be
 * addressable by `attachTo` — inventing a declaration the author never made.
 * Opting in is the whole interface.
 *
 * 🔴 RECORDING MUST NOT FAIL THE STEP THAT DID THE WORK. The object HAS been
 * written or locked by the time this runs; failing here would report a step as
 * failed after its effect landed, which is the worst of both. A run that cannot
 * be read is logged and the step carries on.
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

use OCA\OpenRegister\Db\FlowRun;
use OCA\OpenRegister\Db\FlowRunMapper;
use Psr\Log\LoggerInterface;
use Throwable;
use UnexpectedValueException;

/**
 * Turns a run uuid into a recorded subject.
 */
class FlowRunSubjectRecorder {

	/**
	 * Constructor.
	 *
	 * @param FlowRunMapper   $runs     Reads and stores the run row.
	 * @param FlowRunSubjects $subjects The subject rules.
	 * @param LoggerInterface $logger   Where a failure to record is reported.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function __construct(
		private readonly FlowRunMapper $runs,
		private readonly FlowRunSubjects $subjects,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Record an object under a role, when the step declared one.
	 *
	 * @param array<string, mixed> $context  The run context, carrying the run uuid.
	 * @param string               $role     The role the step declared, or ''.
	 * @param string               $uuid     The object it worked on.
	 * @param string               $register Its register, when known.
	 * @param string               $schema   Its schema, when known.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function record(
		array $context,
		string $role,
		string $uuid,
		string $register = '',
		string $schema = ''
	): void {
		$role = trim($role);
		if ($role === '' || trim($uuid) === '') {
			// No role declared, or nothing to record under it. Both are the
			// ordinary case: recording is opt-in.
			return;
		}

		$run = $this->runFor(context: $context);
		if ($run === null) {
			return;
		}

		try {
			$this->subjects->record(
				run: $run,
				role: $role,
				uuid: $uuid,
				register: $register,
				schema: $schema
			);
			$this->runs->update($run);
		} catch (Throwable $e) {
			// See the class docblock: the work already landed.
			$this->logger->warning(
				message: '[FlowRunSubjectRecorder] Could not record "' . $role . '" on run "'
					. (string)$run->getUuid() . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
		}

	}//end record()

	/**
	 * Record the one object a step acted on, under the role it named.
	 *
	 * 🔑 THE COUNT IS THE DECISION, AND IT IS MADE HERE rather than in each
	 * calling node. A role is a singular name — `case` is one case — so a step
	 * that wrote or locked forty objects has no single one to mean by it.
	 * Recording the fortieth would be arbitrary, and recording all forty in turn
	 * would fire thirty-nine replacement warnings for a set that ends up holding
	 * one entry anyway.
	 *
	 * 🔴 A MULTI-OBJECT STEP RECORDS NOTHING AND SAYS SO ONCE. It is a log line
	 * and not a failure, because the author finds out at the point of use
	 * instead: the later `attachTo` fails naming the roles the run DOES hold,
	 * which is louder and closer to the mistake than a write step refusing after
	 * its write already landed.
	 *
	 * @param array<string, mixed> $context  The run context.
	 * @param string               $role     The role the step named, or '' for none.
	 * @param array<int, string>   $uuids    The distinct objects the step acted on.
	 * @param string               $register Their register, when the step resolved one.
	 * @param string               $schema   Their schema, when the step resolved one.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function recordOne(
		array $context,
		string $role,
		array $uuids,
		string $register = '',
		string $schema = ''
	): void {
		$role = trim($role);
		if ($role === '') {
			// Recording is opt-in. See the class docblock.
			return;
		}

		if (count($uuids) === 1) {
			$this->record(
				context: $context,
				role: $role,
				uuid: $uuids[array_key_first($uuids)],
				register: $register,
				schema: $schema
			);

			return;
		}

		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));

		$this->logger->info(
			message: '[FlowRunSubjectRecorder] A step on run "' . $runUuid . '" records its object as "'
				. $role . '" but acted on ' . (string)count($uuids) . ' of them, so nothing was recorded. '
				. 'A subject role names one object; act on one per step, or split the step.',
			context: ['file' => __FILE__, 'line' => __LINE__, 'run' => $runUuid]
		);

	}//end recordOne()

	/**
	 * The run a context belongs to, or null when it cannot be read.
	 *
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return FlowRun|null The run.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function runFor(array $context): ?FlowRun {
		$runUuid = trim((string)($context[FlowRunContext::CONTEXT_RUN] ?? ($context['runUuid'] ?? '')));
		if ($runUuid === '') {
			return null;
		}

		try {
			return $this->runs->findByUuid(uuid: $runUuid);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: '[FlowRunSubjectRecorder] Could not read run "' . $runUuid . '": ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);

			return null;
		}

	}//end runFor()

	/**
	 * The task fields that attach a task to a declared subject.
	 *
	 * 🔑 THROUGH THE TASK'S EXISTING OBJECT FIELDS, NOT A NEW COLUMN. `Task`
	 * already has `objectUuid`, `registerId` and `schemaId`, and THREE things
	 * already read them: the subject-anchored inbox query, the case sidebar and
	 * the portal visibility rule. Filling those makes the attachment work
	 * everywhere immediately; a new `attachedSubjectRole` column would have
	 * needed all three taught about it, and the two that were not taught would
	 * have been found by a user.
	 *
	 * 🔴 AN UNHELD ROLE THROWS, so the step fails and NO task is created. A
	 * task attached to nothing looks fine in every list and is wrong in the one
	 * place it matters.
	 *
	 * @param array<string, mixed> $context The run context.
	 * @param string               $role    The role to attach to, or '' for none.
	 *
	 * @return array<string, mixed> The anchor fields, empty when no role was named.
	 *
	 * @throws UnexpectedValueException When the role is named and not held.
	 *
	 * @spec openspec/changes/flow-run-subjects-and-answers/specs/flow-run-subjects/spec.md
	 */
	public function anchorFor(array $context, string $role): array {
		$role = trim($role);
		if ($role === '') {
			return [];
		}

		$run = $this->runFor(context: $context);
		if ($run === null) {
			throw new UnexpectedValueException(
				'This step attaches its task to the subject "' . $role
					. '", and its run could not be read, so the attachment cannot be made.'
			);
		}

		// Throws, naming the roles the run DOES hold.
		$subject = $this->subjects->addressed(run: $run, role: $role);

		$anchor = ['objectUuid' => trim((string)($subject['uuid'] ?? ''))];

		$register = trim((string)($subject['register'] ?? ''));
		if (is_numeric($register) === true) {
			$anchor['registerId'] = (int)$register;
		}

		$schema = trim((string)($subject['schema'] ?? ''));
		if (is_numeric($schema) === true) {
			$anchor['schemaId'] = (int)$schema;
		}

		return $anchor;

	}//end anchorFor()
}//end class
