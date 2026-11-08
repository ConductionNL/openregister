<?php

/**
 * FlowRunSubjectsInterface: what callers outside the Service component ask of FlowRunSubjects.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\FlowRun;
use UnexpectedValueException;

/**
 * Reading and recording a run's declared subject set, as the repair step that
 * seeds the trigger subject uses it. Only the role and the methods it uses.
 *
 * @spec openspec/specs/flow-run-subjects/spec.md
 */
interface FlowRunSubjectsInterface {

	/**
	 * The role a trigger's own object is recorded under.
	 *
	 * @var string
	 */
	public const ROLE_TRIGGER = 'trigger';

	/**
	 * Everything the run has declared, by role.
	 *
	 * @param FlowRun $run The run.
	 *
	 * @return array<string, array<string, mixed>> The declared subjects.
	 *
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function all(FlowRun $run): array;

	/**
	 * Record an object under a role on the run; a replacement is logged.
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
	 * @spec openspec/specs/flow-run-subjects/spec.md
	 */
	public function record(
		FlowRun $run,
		string $role,
		string $uuid,
		string $register = '',
		string $schema = ''
	): void;
}//end interface
