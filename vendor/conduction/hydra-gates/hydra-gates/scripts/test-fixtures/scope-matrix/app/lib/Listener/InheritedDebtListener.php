<?php
/**
 * INHERITED DEBT — deliberately planted for the gate-61 scope regression.
 *
 * This listener is registered on a POST event (`ObjectCreatedEvent`) and does a
 * synchronous `saveObject()` on the write path, with no `ListenerDeferralService`
 * and no `@listener-placement` annotation. That is exactly the ADR-078 violation
 * gate-61 exists to detect.
 *
 * It is present in the BASE commit as well as HEAD, so the diff never touches it.
 * A diff-scoped run is therefore RIGHT to pass over it (ADR-020: the backlog is a
 * work-list, not a reason to redden an unrelated PR). A `--full` run is NOT: the
 * whole point of `--full` is to report inherited debt.
 *
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

namespace OCA\ScopeFixture\Listener;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

class InheritedDebtListener implements IEventListener {

	public function __construct(
		private readonly \OCA\OpenRegister\Service\ObjectService $objectService,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof ObjectCreatedEvent) {
			return;
		}
		// A second write INSIDE the first write's request. No deferral, no
		// reason-bearing annotation.
		$this->objectService->saveObject('audit', 'trail', [
			'subject' => $event->getObject()->getUuid(),
		]);
	}
}
