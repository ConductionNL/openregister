<?php

/**
 * Unit tests for the page search cache of {@see \OCA\OpenRegister\Service\XwikiLinkService}.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/integration-xwiki/spec.md
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Db\XwikiLinkMapper;
use OCA\OpenRegister\Exception\ProviderUnavailableException;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Service\Integration\BuiltinIntegrationServices;
use OCA\OpenRegister\Service\Integration\Providers\XwikiProvider;
use OCA\OpenRegister\Service\LinkServiceRuntime;
use OCA\OpenRegister\Service\XwikiLinkService;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionProperty;

/**
 * A page search answer is reused per user and query; a degraded answer is never stored.
 */
class XwikiLinkServiceSearchCacheTest extends TestCase {

	private XwikiProvider&MockObject $provider;

	private IUserSession&MockObject $userSession;

	/**
	 * In-memory stand-in for the distributed cache.
	 *
	 * @var array<string,mixed>
	 */
	private array $store = [];

	private XwikiLinkService $service;

	protected function setUp(): void {
		$this->provider = $this->createMock(XwikiProvider::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $class) => $this->provider);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $id): bool => $id === 'openconnector');

		$this->userSession = $this->createMock(IUserSession::class);

		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->store[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl): bool {
				$this->store[$key] = $value;
				return true;
			}
		);

		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('isAvailable')->willReturn(true);
		$cacheFactory->method('createDistributed')->willReturn($cache);

		$this->service = new XwikiLinkService(
			$this->createMock(XwikiLinkMapper::class),
			$container,
			new LinkServiceRuntime($appManager, $this->userSession, $this->createMock(LoggerInterface::class)),
			$cacheFactory
		);
	}//end setUp()

	private function signIn(string $uid): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	public function testARepeatedSearchIsAnsweredFromTheCache(): void {
		$this->signIn('pieter');
		$this->provider->expects($this->once())
			->method('list')
			->willReturn([['reference' => 'Policies.Street lighting', 'title' => 'Street lighting policy', 'space' => 'Policies']]);

		$first  = $this->service->searchPages(new ListQuery(limit: 5, offset: 0, search: 'street'));
		$second = $this->service->searchPages(new ListQuery(limit: 5, offset: 0, search: 'street'));

		$this->assertSame($first, $second);
		$this->assertSame('Policies.Street lighting', $second['results'][0]['reference']);
	}//end testARepeatedSearchIsAnsweredFromTheCache()

	public function testAnotherQueryAsksTheSourceAgain(): void {
		$this->signIn('pieter');
		$this->provider->expects($this->exactly(2))->method('list')->willReturn([]);

		$this->service->searchPages(new ListQuery(limit: 5, offset: 0, search: 'street'));
		$this->service->searchPages(new ListQuery(limit: 5, offset: 0, search: 'policy'));
	}//end testAnotherQueryAsksTheSourceAgain()

	public function testADegradedAnswerIsNotCached(): void {
		$this->signIn('pieter');
		$this->provider->expects($this->exactly(2))
			->method('list')
			->willThrowException(new ProviderUnavailableException('down', ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN));

		$this->service->searchPages(new ListQuery(limit: 5, offset: 0, search: 'street'));
		$result = $this->service->searchPages(new ListQuery(limit: 5, offset: 0, search: 'street'));

		$this->assertTrue($result['unavailable']);
		$this->assertSame([], $this->store);
	}//end testADegradedAnswerIsNotCached()

	public function testTheWiredServiceGetsTheCacheFactory(): void {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$container    = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id) use ($cacheFactory): object {
				if ($id === ICacheFactory::class) {
					return $cacheFactory;
				}

				if ($id === LinkServiceRuntime::class) {
					return new LinkServiceRuntime(
						$this->createMock(IAppManager::class),
						$this->createMock(IUserSession::class),
						$this->createMock(LoggerInterface::class)
					);
				}

				return $this->createMock($id);
			}
		);

		$service = BuiltinIntegrationServices::build(container: $container, class: XwikiLinkService::class);

		$this->assertSame($cacheFactory, (new ReflectionProperty(XwikiLinkService::class, 'cacheFactory'))->getValue($service));
	}//end testTheWiredServiceGetsTheCacheFactory()
}//end class
