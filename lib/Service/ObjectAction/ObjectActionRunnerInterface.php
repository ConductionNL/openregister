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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;

/**
 * Checking and running a declared object action, as the object-actions
 * controller and the run-action bulk action use it. Only the methods those
 * callers call.
 */
interface ObjectActionRunnerInterface {

	/**
	 * Check whether an action may run on an object, without running it.
	 *
	 * @param ObjectEntity         $object The subject.
	 * @param Schema               $schema Its schema.
	 * @param string               $key    The action key.
	 * @param array<string, mixed> $given  The inputs the caller sent.
	 *
	 * @return array{refusal: array{status: int, body: array<string, mixed>}|null, definition: ObjectActionDefinition|null, inputs: array<string, mixed>}
	 */
	public function check(ObjectEntity $object, Schema $schema, string $key, array $given): array;

	/**
	 * The target of a run: the object, its schema, and the register and schema as the caller named them.
	 *
	 * @param ObjectEntity $object    The subject.
	 * @param Schema       $schema    Its schema.
	 * @param string       $register  The register as the caller named it.
	 * @param string       $schemaRef The schema as the caller named it.
	 *
	 * @return ObjectActionTarget The target.
	 *
	 * @spec openspec/changes/object-actions-declared-and-run/specs/object-actions/spec.md#requirement-running-an-action-validates-inputs-checks-the-right-and-the-precondition-then-executes-its-body
	 */
	public function target(ObjectEntity $object, Schema $schema, string $register, string $schemaRef): ObjectActionTarget;

	/**
	 * Run an action on an object.
	 *
	 * @param ObjectActionTarget   $target The subject, its schema and its address as the caller named it.
	 * @param string               $key    The action key.
	 * @param array<string, mixed> $given  The inputs the caller sent.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 */
	public function run(ObjectActionTarget $target, string $key, array $given): array;
}//end interface
