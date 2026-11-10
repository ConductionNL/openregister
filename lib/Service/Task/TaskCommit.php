<?php

/**
 * How a lifecycle verb commits: one transaction, failure reporting, and the after-commit announcement.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Task
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Task;

use OCP\EventDispatcher\IEventDispatcher;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * The transaction a verb runs in, the logger its failures go to, and the
 * dispatcher that announces the committed transition.
 *
 * @spec openspec/specs/flow-tasks/spec.md#requirement-every-lifecycle-verb-is-authorized-fail-closed
 */
final class TaskCommit {
	/**
	 * Bundle the commit collaborators.
	 *
	 * @param IDBConnection $db Holds the one transaction per verb.
	 * @param LoggerInterface $logger Failure reporting.
	 * @param IEventDispatcher|null $dispatcher Announces the committed
	 *                                          transition and terminality
	 *                                          AFTER the commit; absent,
	 *                                          nothing is announced and the
	 *                                          lifecycle is unchanged.
	 */
	public function __construct(
		public readonly IDBConnection $db,
		public readonly LoggerInterface $logger,
		public readonly ?IEventDispatcher $dispatcher = null,
	) {
	}//end __construct()
}//end class
