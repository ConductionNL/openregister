<?php

/**
 * The optional collaborators of the user-task step: performer resolution, events and subject recording.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
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

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCA\OpenRegister\Service\Flow\FlowRunSubjectRecorder;
use OCA\OpenRegister\Service\Flow\Principal\PrincipalResolverRegistry;
use OCP\EventDispatcher\IEventDispatcher;

/**
 * The optional collaborators of the user-task step: performer resolution, events and subject recording.
 *
 * @spec openspec/specs/flow-user-task-node/spec.md#requirement-a-user-task-step-creates-exactly-one-task-and-suspends-the-run
 */
final class UserTaskHooks {
	/**
	 * Bundle the services.
	 *
	 * @param PrincipalResolverRegistry|null $principals Resolves typed candidate principals.
	 * @param IEventDispatcher|null          $events     Where an agent performer's turn is asked for.
	 * @param FlowRunSubjectRecorder|null    $subjects   Records the task as a run subject.
	 */
	public function __construct(
		public readonly ?PrincipalResolverRegistry $principals = null,
		public readonly ?IEventDispatcher $events = null,
		public readonly ?FlowRunSubjectRecorder $subjects = null,
	) {
	}//end __construct()
}//end class
