<?php

/**
 * The canary for container doubles left behind by an earlier test file.
 *
 * 🔴 WHY THIS FILE EXISTS. Two Service files override `IRequest`,
 * `IUserSession` and `IGroupManager` on the app container so their controllers
 * receive a payload and a caller. An override is process-global: if either
 * file forgets to put the container back, every file that runs after it
 * resolves a PHPUnit double instead of the real service, and the failures
 * surface somewhere else entirely, as somebody else's problem. The Service
 * suite has already paid for exactly this once, with a leaked admin session
 * that made ten "anonymous" assertions run as an administrator and twelve
 * import tests pass on borrowed rights.
 *
 * The name sorts after both `ControllersIntegrationTest` and
 * `ObjectsControllerIntegrationTest`, which is the whole point: PHPUnit runs
 * the files in that order, so this one is asked the question after they have
 * had their chance to leave a double behind.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Service;

use OCA\OpenRegister\AppInfo\Application;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Asserts the app container still answers with real services.
 */
class SessionAndRequestLeakCanaryTest extends TestCase {

	/**
	 * The services a test file may legitimately override, and must give back.
	 *
	 * @return array<string, array{0: string}> Service ids, keyed for the message.
	 */
	public static function overridableServiceProvider(): array {
		return [
			'request' => [IRequest::class],
			'user session' => [IUserSession::class],
			'group manager' => [IGroupManager::class],
		];
	}

	/**
	 * No earlier file may leave a double in the container.
	 *
	 * @param string $serviceId The service id to interrogate.
	 *
	 * @return void
	 *
	 * @dataProvider overridableServiceProvider
	 */
	public function testTheContainerStillAnswersWithARealService(string $serviceId): void {
		$service = (new Application())->getContainer()->get($serviceId);

		$this->assertNotInstanceOf(
			MockObject::class,
			$service,
			$serviceId . ' is a PHPUnit double: an earlier test file overrode it on the app '
				. 'container and did not restore it, so every file after that one is testing '
				. 'against that file\'s doubles. Look for a missing restoreContainerOverrides() '
				. 'in a tearDown.'
		);
	}
}//end class
