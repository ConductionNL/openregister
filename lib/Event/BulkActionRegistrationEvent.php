<?php

/**
 * OpenRegister Bulk Action Registration Event
 *
 * Dispatched when OpenRegister is collecting the bulk actions available on
 * this instance. A leaf app listens and registers its own action, which is
 * all it has to build: the job record, the preview, the progress, the audit
 * entries and the retry are the platform's (ADR-022).
 *
 * EXAMPLE USAGE IN YOUR APP:
 *
 * In your app's lib/AppInfo/Application.php:
 *
 * ```php
 * public function boot(IBootContext $context): void {
 *     $context->injectFn(function (IEventDispatcher $dispatcher) {
 *         $dispatcher->addListener(
 *             BulkActionRegistrationEvent::class,
 *             function (BulkActionRegistrationEvent $event) {
 *                 $event->registerAction(\OCP\Server::get(ReleaseCaseloadAction::class));
 *             }
 *         );
 *     });
 * }
 * ```
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\BulkAction\BulkActionInterface;
use OCA\OpenRegister\Service\BulkActionRegistry;
use OCP\EventDispatcher\Event;

/**
 * Bulk Action Registration Event
 */
class BulkActionRegistrationEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param BulkActionRegistry $registry The registry to register actions with.
	 */
	public function __construct(
		private readonly BulkActionRegistry $registry,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * Register a bulk action.
	 *
	 * @param BulkActionInterface $action Your action implementation.
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException When the id is malformed or taken.
	 *
	 * @spec openspec/changes/bulk-action-jobs/specs/bulk-action-jobs/spec.md
	 */
	public function registerAction(BulkActionInterface $action): void {
		$this->registry->register(action: $action);
	}//end registerAction()
}//end class
