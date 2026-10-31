<?php

/**
 * OpenRegister referenced calculation listener
 *
 * When an object is updated, refreshes the materialised calculations of the
 * objects that read one of its changed fields through `@ref` (a case's
 * `hasStartableFlows` after its case type lists flows).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Listener
 * @package  OCA\OpenRegister\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Listener;

use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refreshes the materialised values that read an updated object.
 *
 * @template-implements IEventListener<ObjectUpdatedEvent>
 *
 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */
class ReferencedCalculationListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param ReferencedCalculationRefresher $refresher Re-saves the objects that read the updated one.
	 * @param LoggerInterface                $logger    The logger.
	 */
	public function __construct(
		private readonly ReferencedCalculationRefresher $refresher,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refresh the objects that read the updated object; never fails the save that already happened.
	 *
	 * @param Event $event The inbound event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$this->refresher->refresh(updated: $event->getNewObject(), old: $event->getOldObject());
		} catch (Throwable $e) {
			$this->logger->warning('[ReferencedCalculationListener] refresh failed: '.$e->getMessage());
		}
	}//end handle()
}//end class
