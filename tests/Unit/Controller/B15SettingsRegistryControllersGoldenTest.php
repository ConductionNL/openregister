<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ApplicationsController;
use OCA\OpenRegister\Controller\ContextsController;
use OCA\OpenRegister\Controller\ManifestController;
use OCA\OpenRegister\Controller\Settings\CacheSettingsController;
use OCA\OpenRegister\Db\Application;
use OCA\OpenRegister\Db\ApplicationMapperInterface;
use OCA\OpenRegister\Service\ApplicationServiceInterface;
use OCA\OpenRegister\Tests\Unit\Controller\Support\ApplicationsControllerFactory;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins who reaches the cache-settings, applications, manifest and JSON-LD
 * context actions, and that an application update never carries the fields
 * that decide its tenancy and ownership.
 *
 * The applications controller is built through its test factory, so the same
 * case runs against the flat constructor and against the bundled one.
 */
final class B15SettingsRegistryControllersGoldenTest extends TestCase {
	/**
	 * The route attributes and annotations of every action.
	 *
	 * @return void
	 */
	public function testTheRouteAttributesAreUnchanged(): void {
		$expected = [
			CacheSettingsController::class => [
				'getCacheStats' => ['@NoCSRFRequired'],
				'clearCache' => ['@NoCSRFRequired'],
				'warmupNamesCache' => ['@NoCSRFRequired'],
				'getWarmupInterval' => ['@NoCSRFRequired'],
				'setWarmupInterval' => ['@NoCSRFRequired'],
				'clearAppStoreCache' => ['@NoCSRFRequired'],
			],
			ApplicationsController::class => [
				'page' => ['@NoAdminRequired', '@NoCSRFRequired'],
				'index' => ['@NoAdminRequired', '@NoCSRFRequired'],
				'show' => ['@NoAdminRequired', '@NoCSRFRequired'],
				'create' => ['@NoAdminRequired', '@NoCSRFRequired'],
				'update' => ['@NoAdminRequired', '@NoCSRFRequired'],
				'patch' => ['@NoAdminRequired', '@NoCSRFRequired'],
				'destroy' => ['@NoAdminRequired', '@NoCSRFRequired'],
			],
			ManifestController::class => [
				'index' => ['NoAdminRequired', 'NoCSRFRequired', 'PublicPage', 'AnonRateLimit', '@NoAdminRequired', '@NoCSRFRequired', '@PublicPage'],
			],
			ContextsController::class => [
				'register' => ['AnonRateLimit', '@NoAdminRequired', '@NoCSRFRequired', '@PublicPage'],
				'schema' => ['AnonRateLimit', '@NoAdminRequired', '@NoCSRFRequired', '@PublicPage'],
			],
		];
		$actual = [];
		foreach (array_keys($expected) as $class) {
			$actual[$class] = [];
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() !== $class || $method->isConstructor() === true) {
					continue;
				}

				$names = [];
				foreach ($method->getAttributes() as $attribute) {
					$parts = explode('\\', $attribute->getName());
					$names[] = end($parts);
				}

				preg_match_all('/@(NoAdminRequired|NoCSRFRequired|PublicPage|AuthorizedAdminSetting)\b/', (string)$method->getDocComment(), $matches);
				foreach (array_unique($matches[0]) as $annotation) {
					$names[] = $annotation;
				}

				$actual[$class][$method->getName()] = $names;
			}
		}

		$this->assertSame($expected, $actual);
	}//end testTheRouteAttributesAreUnchanged()

	/**
	 * An update passes the editable fields on and drops id, organisation, owner and created.
	 *
	 * @return void
	 */
	public function testAnUpdateCannotMoveTheApplicationToAnotherTenantOrOwner(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(
			['_route' => 'r', 'id' => 1, 'organisation' => 'other-org', 'owner' => 'mallory', 'created' => 'c', 'name' => 'n']
		);
		$service = $this->createMock(ApplicationServiceInterface::class);
		$service->expects($this->once())->method('update')->with(9, ['name' => 'n'])->willReturn(new Application());
		$controller = ApplicationsControllerFactory::create(
			'openregister',
			$request,
			$service,
			$this->createMock(ApplicationMapperInterface::class),
			$this->createMock(LoggerInterface::class)
		);

		$this->assertSame(200, $controller->update(9)->getStatus());
	}//end testAnUpdateCannotMoveTheApplicationToAnotherTenantOrOwner()
}//end class
