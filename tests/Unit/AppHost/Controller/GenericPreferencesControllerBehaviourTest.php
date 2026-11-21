<?php

/**
 * AppHost GenericPreferencesController — invalid-key refusal on write.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppHost\Controller;

use OCA\OpenRegister\AppHost\Controller\GenericPreferencesController;
use OCP\AppFramework\Http;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * A key with nothing left after sanitising is refused with 400 on write, and
 * nothing is stored or deleted; a valid key on the same call is written.
 */
class GenericPreferencesControllerBehaviourTest extends TestCase {
	private function controller(IConfig $config): GenericPreferencesController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new GenericPreferencesController('leafapp', $this->createMock(IRequest::class), $config, $session);
	}

	public function testSetPreferenceWithUnsafeKeyIsRefusedWithoutWriting(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->never())->method('setUserValue');
		$config->expects($this->never())->method('deleteUserValue');

		$response = $this->controller($config)->setPreference('../$$', 'v');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['message' => 'Invalid key'], $response->getData());
	}

	public function testSetPreferenceWithSafeKeyWritesUnderPrefNamespace(): void {
		$config = $this->createMock(IConfig::class);
		$config->expects($this->once())->method('setUserValue')->with('alice', 'leafapp', 'pref_view-mode', 'grid');

		$response = $this->controller($config)->setPreference('View-Mode', 'grid');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['value' => 'grid'], $response->getData());
	}
}
