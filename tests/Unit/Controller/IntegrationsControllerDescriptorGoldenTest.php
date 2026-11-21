<?php

/**
 * Golden test pinning the integration descriptors IntegrationsController and
 * IntegrationsCapability answer, per caller role.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Capabilities\IntegrationsCapability;
use OCA\OpenRegister\Capabilities\IntegrationsCapabilityServices;
use OCA\OpenRegister\Controller\IntegrationsController;
use OCA\OpenRegister\Service\Integration\IntegrationCatalogueInterface;
use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\LeafRegistry;
use OCA\OpenRegister\Tests\Unit\Controller\Support\IntegrationsControllerFactory;
use OCP\App\IAppManager;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class IntegrationsControllerDescriptorGoldenTest extends TestCase {

	private array $params = [];

	private array $errors = [];

	private function provider(string $id, bool $healthThrows): IntegrationProvider {
		$provider = $this->createMock(IntegrationProvider::class);
		$provider->method('getId')->willReturn($id);
		$provider->method('getLabel')->willReturn(ucfirst($id));
		$provider->method('getIcon')->willReturn('Cube');
		$provider->method('getGroup')->willReturn($id === 'files' ? 'core' : 'external');
		$provider->method('isEnabled')->willReturn($id === 'files');
		$provider->method('getRequiredApp')->willReturn($id === 'files' ? null : 'xwiki');
		$provider->method('getStorageStrategy')->willReturn('magic-column');
		$provider->method('requiresPermission')->willReturn($id === 'files' ? null : 'xwiki.read');
		$provider->method('getOpenConnectorSource')->willReturn($id === 'files' ? null : 'xwiki-source');
		if ($healthThrows === true) {
			$provider->method('health')->willThrowException(new RuntimeException('down'));
		} else {
			$provider->method('health')->willReturn(['status' => 'ok', 'authStatus' => 'configured', 'message' => null]);
		}

		return $provider;
	}

	private function registry(): IntegrationCatalogueInterface {
		$files = $this->provider('files', false);
		$xwiki = $this->provider('xwiki', true);
		$registry = $this->createMock(IntegrationCatalogueInterface::class);
		$registry->method('list')->willReturn([$files, $xwiki]);
		$registry->method('listIds')->willReturn(['files', 'xwiki']);
		$registry->method('get')->willReturnCallback(
			fn (string $id) => ['files' => $files, 'xwiki' => $xwiki][$id] ?? null
		);
		return $registry;
	}

	private function session(?string $uid): IUserSession {
		$session = $this->createMock(IUserSession::class);
		if ($uid === null) {
			$session->method('getUser')->willReturn(null);
			return $session;
		}

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session->method('getUser')->willReturn($user);
		return $session;
	}

	private function groupManager(): IGroupManager {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid) => $uid === 'admin');
		return $groups;
	}

	private function controller(?string $uid, ?IntegrationCatalogueInterface $registry = null): IntegrationsController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key) => $this->params[$key] ?? null);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->errors[] = [$message, get_class($context['exception'])];
			}
		);

		return IntegrationsControllerFactory::create(
			'openregister',
			$request,
			$registry ?? $this->registry(),
			$this->session($uid),
			$this->groupManager(),
			$logger
		);
	}

	private function capabilityRows(?string $uid): array {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isEnabledForUser')->willReturnCallback(fn (string $app) => $app === 'xwiki');
		$capability = new IntegrationsCapability(
			registry: $this->registry(),
			userSession: $this->session($uid),
			groupManager: $this->groupManager(),
			services: new IntegrationsCapabilityServices(
				appManager: $apps,
				leafRegistry: $this->createMock(LeafRegistry::class)
			)
		);

		return $capability->getCapabilities()['openregister']['integrations']['providers'];
	}

	private static function publicControllerRow(string $id): array {
		return [
			'id' => $id,
			'label' => ucfirst($id),
			'icon' => 'Cube',
			'group' => $id === 'files' ? 'core' : 'external',
			'enabled' => $id === 'files',
			'storageStrategy' => 'magic-column',
			'surfaces' => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
		];
	}

	private static function adminFields(string $id): array {
		if ($id === 'files') {
			return [
				'requiresPermission' => null,
				'openConnectorSource' => null,
				'authStatus' => ['status' => 'ok', 'authStatus' => 'configured', 'message' => null],
			];
		}

		return [
			'requiresPermission' => 'xwiki.read',
			'openConnectorSource' => 'xwiki-source',
			'authStatus' => ['status' => 'unavailable', 'authStatus' => 'unknown', 'message' => 'Provider health check threw'],
		];
	}

	public function testIndexAnswersAdminsTheOperationalFieldsWithTheHealthFallback(): void {
		$response = $this->controller('admin')->index();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(
			['integrations' => [
				self::publicControllerRow('files') + self::adminFields('files'),
				self::publicControllerRow('xwiki') + self::adminFields('xwiki'),
			]],
			$response->getData()
		);
	}

	public function testIndexOmitsTheOperationalFieldsForMembersAndAnonymous(): void {
		foreach (['bob', null] as $uid) {
			$this->assertSame(
				['integrations' => [self::publicControllerRow('files'), self::publicControllerRow('xwiki')]],
				$this->controller($uid)->index()->getData()
			);
		}
	}

	public function testIndexFiltersOnEnabledAndGroup(): void {
		$this->params = ['enabled' => 'true'];
		$this->assertSame(['integrations' => [self::publicControllerRow('files')]], $this->controller('bob')->index()->getData());

		$this->params = ['group' => 'external'];
		$this->assertSame(['integrations' => [self::publicControllerRow('xwiki')]], $this->controller('bob')->index()->getData());

		$this->params = ['enabled' => 'true', 'group' => 'external'];
		$this->assertSame(['integrations' => []], $this->controller('bob')->index()->getData());
	}

	public function testIndexFailureIsLoggedAndAnswers500(): void {
		$registry = $this->createMock(IntegrationCatalogueInterface::class);
		$registry->method('list')->willThrowException(new RuntimeException('boom'));

		$response = $this->controller('admin', $registry)->index();

		$this->assertSame(500, $response->getStatus());
		$this->assertSame(['message' => 'Failed to list integrations'], $response->getData());
		$this->assertSame([['[IntegrationsController] index failed', RuntimeException::class]], $this->errors);
	}

	public function testShowAnswersOneDescriptorPerRoleOr404(): void {
		$this->assertSame(
			self::publicControllerRow('xwiki') + self::adminFields('xwiki'),
			$this->controller('admin')->show('xwiki')->getData()
		);
		$this->assertSame(self::publicControllerRow('xwiki'), $this->controller('bob')->show('xwiki')->getData());

		$missing = $this->controller('admin')->show('nope');
		$this->assertSame(404, $missing->getStatus());
		$this->assertSame(['message' => "Integration 'nope' not registered"], $missing->getData());
	}

	public function testCapabilityRowsCarryTheSameOperationalFieldsForAdminsOnly(): void {
		$public = [
			'files' => ['id' => 'files', 'label' => 'Files', 'group' => 'core', 'enabled' => true, 'requiredApp' => null, 'available' => true],
			'xwiki' => ['id' => 'xwiki', 'label' => 'Xwiki', 'group' => 'external', 'enabled' => false, 'requiredApp' => 'xwiki', 'available' => true],
		];
		$tail = [
			'storageStrategy' => 'magic-column',
			'surfaces' => ['user-dashboard', 'app-dashboard', 'detail-page', 'single-entity'],
		];

		$this->assertSame(
			[
				$public['files'] + $tail + self::adminFields('files'),
				$public['xwiki'] + $tail + self::adminFields('xwiki'),
			],
			$this->capabilityRows('admin')
		);
		$this->assertSame([$public['files'] + $tail, $public['xwiki'] + $tail], $this->capabilityRows('bob'));
		$this->assertSame([$public['files'] + $tail, $public['xwiki'] + $tail], $this->capabilityRows(null));
	}
}//end class
