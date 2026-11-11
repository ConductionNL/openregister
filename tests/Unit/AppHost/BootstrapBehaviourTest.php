<?php

/**
 * Behaviour test for the store-controller factory Bootstrap registers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppHost
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

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Bootstrap;
use OCA\OpenRegister\AppHost\LeafAdoption;
use OCA\OpenRegister\AppHost\Controller\GenericStoreController;
use OCA\OpenRegister\AppHost\Controller\Support\GenericStoreControllerServices;
use OCA\OpenRegister\AppHost\Controller\Support\GenericStoreInstall;
use OCA\OpenRegister\AppHost\Controller\Support\GenericStorePlatform;
use OCA\OpenRegister\AppHost\Controller\Support\GenericStoreSources;
use OCA\OpenRegister\AppHost\Observability\ManifestLoader;
use OCA\OpenRegister\AppHost\Store\FederatedStoreCatalog;
use OCA\OpenRegister\AppHost\Service\GenericStoreService;
use OCA\OpenRegister\AppHost\Store\GenericStoreInstaller;
use OCA\OpenRegister\AppHost\Store\Source\GitHubStoreSource;
use OCA\OpenRegister\AppHost\Store\StoreActionAuthorizer;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

require_once __DIR__ . '/RecordingRegistrationContext.php';

/**
 * Resolves the aliased store controller through its factory.
 */
class BootstrapBehaviourTest extends TestCase {

	/**
	 * The store controller factory builds the generic store controller for
	 * the CALLING app, from exactly the request and the services bundle.
	 *
	 * @return void
	 */
	public function testStoreControllerFactoryBuildsTheGenericControllerForTheLeafApp(): void {
		$context = new RecordingRegistrationContext();
		Bootstrap::aliasStoreController(context: $context, adoption: new LeafAdoption(appId: 'decidiq', options: ['namespace' => 'OCA\\DecidiqFixture']));

		$services = new GenericStoreControllerServices(
			sources: new GenericStoreSources(
				manifestLoader: $this->createMock(ManifestLoader::class),
				storeService: $this->createMock(GenericStoreService::class),
				gitHubSource: $this->createMock(GitHubStoreSource::class),
				catalog: $this->createMock(FederatedStoreCatalog::class)
			),
			install: new GenericStoreInstall(
				installer: $this->createMock(GenericStoreInstaller::class),
				actionAuthorizer: $this->createMock(StoreActionAuthorizer::class)
			),
			platform: new GenericStorePlatform(
				userSession: $this->createMock(IUserSession::class),
				groupManager: $this->createMock(IGroupManager::class),
				logger: $this->createMock(LoggerInterface::class)
			)
		);
		$request = $this->createMock(IRequest::class);

		$requested = [];
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use (&$requested, $request, $services) {
				$requested[] = $id;
				return match ($id) {
					'OCP\\IRequest' => $request,
					GenericStoreControllerServices::class => $services,
				};
			}
		);

		$this->assertArrayHasKey('OCA\\DecidiqFixture\\Controller\\StoreController', $context->factories);
		$controller = $context->factories['OCA\\DecidiqFixture\\Controller\\StoreController']($container);

		$this->assertInstanceOf(GenericStoreController::class, $controller);
		$this->assertSame(['OCP\\IRequest', GenericStoreControllerServices::class], $requested);
		$this->assertSame('decidiq', (new ReflectionProperty($controller, 'appName'))->getValue($controller));
	}//end testStoreControllerFactoryBuildsTheGenericControllerForTheLeafApp()
}//end class
