<?php

/**
 * The readers the task form resolver resolves a task's form through.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCA\OpenRegister\Db\FlowMapperInterface;
use OCA\OpenRegister\Db\FlowRunMapperInterface;
use OCA\OpenRegister\Db\FormLinkMapper;
use OCA\OpenRegister\Service\Flow\FlowPublishedGraph;

/**
 * The dependency bundle of {@see TaskFormResolver}.
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-the-form-a-task-presents-is-the-one-its-flow-version-declared
 */
final class TaskFormResolverServices {
	/**
	 * Bundle the services.
	 *
	 * @param FlowRunMapperInterface $runs Reads the run for its flow and version pin.
	 * @param FlowMapperInterface $flows Reads the live flow, for an unpinned draft test run only.
	 * @param FlowPublishedGraph $published Resolves the pinned graph of a run.
	 * @param FormLinkMapper $formLinks Reads the subject's bound Forms form.
	 */
	public function __construct(
		public readonly FlowRunMapperInterface $runs,
		public readonly FlowMapperInterface $flows,
		public readonly FlowPublishedGraph $published,
		public readonly FormLinkMapper $formLinks,
	) {
	}//end __construct()
}//end class
