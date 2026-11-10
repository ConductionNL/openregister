<?php

/**
 * A portal task that names the app which completes it, completed past that app.
 *
 * A task may carry `metadata.completeVia` = `{app, action}`: the app whose own
 * checks a completion must pass, and the portal action of that app that does
 * it. The portal seam then refuses a direct completion and names the action,
 * so a portal sends the party there instead. It is a conflict, not a
 * validation failure: the task is fine, the route is wrong, and retrying the
 * same call can never succeed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/a-portal-task-names-the-app-that-completes-it/specs/flow-portal-task/spec.md#requirement-a-task-may-name-the-app-action-that-completes-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * A direct completion of a task that must be completed through an app action.
 *
 * @spec openspec/changes/a-portal-task-names-the-app-that-completes-it/specs/flow-portal-task/spec.md#requirement-a-task-may-name-the-app-action-that-completes-it
 */
class TaskCompleteViaAppException extends TaskConflictException {

	/**
	 * Constructor.
	 *
	 * @param string $app    The app that completes the task.
	 * @param string $action The portal action of that app.
	 */
	public function __construct(
		private readonly string $app,
		private readonly string $action,
	) {
		parent::__construct(message: sprintf('This task is completed through the %s action of %s.', $action, $app));
	}//end __construct()

	/**
	 * Where the completion goes instead.
	 *
	 * @return array{app: string, action: string} The app and its action.
	 *
	 * @spec openspec/changes/a-portal-task-names-the-app-that-completes-it/specs/flow-portal-task/spec.md#requirement-a-task-may-name-the-app-action-that-completes-it
	 */
	public function completeVia(): array {
		return ['app' => $this->app, 'action' => $this->action];
	}//end completeVia()
}//end class
