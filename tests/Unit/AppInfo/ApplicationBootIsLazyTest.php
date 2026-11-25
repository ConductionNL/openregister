<?php

/**
 * Application::boot() runs on every Nextcloud request, so it installs loaders
 * and constructs nothing that only some requests read: it neither asks the
 * apps for their deep links nor builds the built-in integration providers.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\AppInfo
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\AppInfo;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use ReflectionClass;

class ApplicationBootIsLazyTest extends TestCase {

	public function testBootAsksNoAppForDeepLinksAndBuildsNoBuiltinProvider(): void {
		$asked    = [];
		$registry = $this->createMock(IntegrationRegistry::class);
		$registry->expects($this->never())->method('addProvider');
		$registry->expects($this->once())->method('setBuiltinLoader');

		$server = $this->createMock(ContainerInterface::class);
		$server->method('get')->willReturnCallback(
			function (string $id) use (&$asked, $registry): mixed {
				$asked[] = $id;
				if ($id === IntegrationRegistry::class) {
					return $registry;
				}

				if (interface_exists($id) === true || class_exists($id) === true) {
					return $this->createMock($id);
				}

				return null;
			}
		);

		$context = $this->createMock(IBootContext::class);
		$context->method('getServerContainer')->willReturn($server);

		$application = (new ReflectionClass(Application::class))->newInstanceWithoutConstructor();
		$application->boot($context);

		$this->assertNotContains(DeepLinkRegistryService::class, $asked);
		$this->assertNotContains(IEventDispatcher::class, $asked);
		$providers = array_filter(
			$asked,
			static fn (string $id): bool => str_contains($id, '\\Integration\\BuiltinProviders\\') || str_contains($id, '\\Integration\\Providers\\')
		);
		$this->assertSame([], array_values($providers), 'No built-in integration provider is constructed at boot.');
	}//end testBootAsksNoAppForDeepLinksAndBuildsNoBuiltinProvider()
}//end class
