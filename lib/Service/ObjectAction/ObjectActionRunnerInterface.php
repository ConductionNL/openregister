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
	 * Run an action on an object.
	 *
	 * @param ObjectEntity         $object  The subject.
	 * @param Schema               $schema  Its schema.
	 * @param string               $key     The action key.
	 * @param array<string, mixed> $given   The inputs the caller sent.
	 * @param array<string, mixed> $subject `{uuid, register, schema}` as the caller named them.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 */
	public function run(ObjectEntity $object, Schema $schema, string $key, array $given, array $subject): array;
}//end interface
