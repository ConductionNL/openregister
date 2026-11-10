<?php

/**
 * Where a task step's declared form is checked against: the subject schema and the transition's declared inputs.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;

/**
 * Where a task step's declared form is checked against: the subject schema and the transition's declared inputs.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-field-that-cannot-be-rendered-is-refused-when-the-step-is-saved
 */
final class TaskFormSources {
	/**
	 * Bundle the services.
	 *
	 * @param SchemaMapperInterface      $schemas  Resolves the subject schema.
	 * @param TransitionEngine           $engine   Reads a transition's declared inputs.
	 */
	public function __construct(
		public readonly SchemaMapperInterface $schemas,
		public readonly TransitionEngine $engine,
	) {
	}//end __construct()
}//end class
