<?php

/**
 * ObjectActionRunnerInterface: what callers outside the Service component ask of ObjectActionRunner.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ObjectAction
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

namespace OCA\OpenRegister\Service\ObjectAction;

/**
 * Checking and running a declared object action, as the object-actions
 * controller and the run-action bulk action use it. Only the methods those
 * callers call.
 */
interface ObjectActionRunnerInterface {

	/**
	 * Check whether an action may run on an object, without running it.
	 *
	 * @param ObjectActionCall     $call  The subject, its schema and the action key.
	 * @param array<string, mixed> $given The inputs the caller sent.
	 *
	 * @return array{refusal: array{status: int, body: array<string, mixed>}|null, definition: ObjectActionDefinition|null, inputs: array<string, mixed>}
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function rehearse(ObjectActionCall $call, array $given): array;

	/**
	 * Run an action on an object.
	 *
	 * @param ObjectActionTarget  $target  The subject, its schema and its address as the caller named it.
	 * @param ObjectActionRequest $request The action key and the inputs the caller sent.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function run(ObjectActionTarget $target, ObjectActionRequest $request): array;
}//end interface
