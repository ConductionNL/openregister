<?php
/**
 * @license EUPL-1.2
 * @copyright Conduction B.V.
 */

namespace OCA\ScopeFixture\AppInfo;

use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\ScopeFixture\Listener\InheritedDebtListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {

	public const APP_ID = 'scopefixture';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		// Registered in the BASE commit. The diff never touches this line, so a
		// diff-scoped run legitimately skips it and a --full run must not.
		$context->registerEventListener(ObjectCreatedEvent::class, InheritedDebtListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
