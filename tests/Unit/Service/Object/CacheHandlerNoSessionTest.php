<?php

declare(strict_types=1);

/**
 * CacheHandler without a user session
 *
 * The session is optional in the constructor. Without one the handler acts for
 * no user: it never takes the admin override and scopes names to the default
 * organisation.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 * @author   Conduction Development Team <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\OrganisationMapperInterface;
use OCA\OpenRegister\Service\Object\CacheHandler;
use OCA\OpenRegister\Service\Object\CacheHandlerRuntime;
use OCA\OpenRegister\Service\Object\CacheHandlerStores;
use OCA\OpenRegister\Service\Object\CacheNameScopeSources;
use OCP\AppFramework\IAppContainer;
use OCP\IAppConfig;
use OCP\IGroupManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * Controls for the handler's behaviour when no session was passed.
 */
class CacheHandlerNoSessionTest extends TestCase {
	private const DEFAULT_ORG = 'dddddddd-0000-0000-0000-000000000004';

	/**
	 * Without a session the admin override is out of reach, whatever the config says.
	 *
	 * @return void
	 */
	public function testNoSessionNeverTakesTheAdminOverride(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('{"adminOverride":true}');
		$groupManager = $this->createMock(IGroupManager::class);
		$groupManager->expects($this->never())->method('isAdmin');

		$organisations = $this->organisations();
		$handler = new CacheHandler(
			stores: new CacheHandlerStores(
				organisationMapper: $organisations
			),
			runtime: new CacheHandlerRuntime(
				logger: $this->createMock(LoggerInterface::class)
			),
			nameScope: new CacheNameScopeSources(
				groupManager: $groupManager,
				appConfig: $appConfig
			)
		);

		$this->assertSame([self::DEFAULT_ORG], $this->resolveScope($handler));
	}

	/**
	 * Without a session the object cache key falls back to the no-organisation sentinel.
	 *
	 * @return void
	 */
	public function testNoSessionKeysTheObjectCacheOnNoOrganisation(): void {
		$organisations = $this->createMock(OrganisationMapperInterface::class);
		$organisations->expects($this->never())->method('getActiveOrganisationWithFallback');
		$handler = new CacheHandler(
			stores: new CacheHandlerStores(
				organisationMapper: $organisations
			),
			runtime: new CacheHandlerRuntime(
				logger: $this->createMock(LoggerInterface::class)
			),
			nameScope: new CacheNameScopeSources()
		);

		$method = (new ReflectionClass($handler))->getMethod('getActiveOrganisationCacheScope');
		$method->setAccessible(true);

		$this->assertSame('__no_org__', $method->invoke($handler));
	}

	/**
	 * Without a session a cached object name is answered by the read path, for no user.
	 *
	 * @return void
	 */
	public function testNoSessionAsksTheReadPathForACachedName(): void {
		$uuid = 'aaaaaaaa-0000-0000-0000-000000000001';
		$objects = $this->createMock(MagicMapperInterface::class);
		$objects->expects($this->once())->method('filterReadableUuids')->willReturn([$uuid]);
		$container = $this->createMock(IAppContainer::class);
		$container->method('get')->willReturn($objects);

		$handler = new CacheHandler(
			stores: new CacheHandlerStores(
				organisationMapper: $this->createMock(OrganisationMapperInterface::class)
			),
			runtime: new CacheHandlerRuntime(
				logger: $this->createMock(LoggerInterface::class),
				container: $container
			),
			nameScope: new CacheNameScopeSources()
		);
		$handler->setObjectName(identifier: $uuid, name: 'A name', organisation: null, source: '1:5');

		$this->assertSame('A name', $handler->getSingleObjectName($uuid));
	}

	/**
	 * An organisation mapper that knows only the default organisation.
	 *
	 * @return OrganisationMapperInterface
	 */
	private function organisations(): OrganisationMapperInterface {
		$organisations = $this->createMock(OrganisationMapperInterface::class);
		$organisations->method('getDefaultOrganisationFromConfig')->willReturn(self::DEFAULT_ORG);
		$organisations->method('getOrganisationHierarchy')->willReturn([self::DEFAULT_ORG]);
		$organisations->expects($this->never())->method('getActiveOrganisationWithFallback');
		return $organisations;
	}

	/**
	 * Read the resolved name scope out of the handler.
	 *
	 * @param CacheHandler $handler The handler under test.
	 *
	 * @return array<int, string>|null
	 */
	private function resolveScope(CacheHandler $handler): ?array {
		$ref = new ReflectionClass($handler);
		$begin = $ref->getMethod('beginNameScope');
		$begin->setAccessible(true);
		$begin->invoke($handler);

		$method = $ref->getMethod('resolveNameScope');
		$method->setAccessible(true);
		return $method->invoke($handler);
	}
}
