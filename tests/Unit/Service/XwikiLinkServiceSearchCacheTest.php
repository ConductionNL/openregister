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

	/**
	 * The time to live each stored key was given, in seconds.
	 *
	 * @var array<string,int>
	 */
	private array $ttls = [];

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
				$this->ttls[$key]  = $ttl;
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
		$this->assertSame([300], array_values($this->ttls));
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

	public function testTheAvailablePagesAreAnsweredFromTheCacheAndASearchDoesNotShareTheirEntry(): void {
		$this->signIn('pieter');
		$this->provider->expects($this->exactly(2))
			->method('list')
			->willReturn([['reference' => 'Workplace.Laptop', 'title' => 'Laptop', 'space' => 'Workplace']]);

		$first  = $this->service->getAvailablePages(null);
		$second = $this->service->getAvailablePages(null);
		$this->service->searchPages(new ListQuery(limit: 25, offset: 0, search: ''));

		$this->assertSame($first, $second);
		$this->assertSame(1, $second['total']);
	}//end testTheAvailablePagesAreAnsweredFromTheCacheAndASearchDoesNotShareTheirEntry()

	public function testUnavailablePagesAreNotCached(): void {
		$this->signIn('pieter');
		$this->provider->expects($this->exactly(2))
			->method('list')
			->willThrowException(new ProviderUnavailableException('down', ProviderUnavailableException::CAUSE_UPSTREAM_SERVICE_DOWN));

		$this->service->getAvailablePages('street');
		$this->assertTrue($this->service->getAvailablePages('street')['unavailable']);
		$this->assertSame([], $this->store);
	}//end testUnavailablePagesAreNotCached()

	public function testWithoutAnInjectedFactoryTheCacheComesFromTheContainer(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($this->store[$key] ?? null));
		$cache->method('set')->willReturnCallback(
			function (string $key, mixed $value, int $ttl): bool {
				$this->store[$key] = $value;
				return true;
			}
		);
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturn(true);
		$factory->method('createDistributed')->willReturn($cache);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(fn (string $class) => ($class === ICacheFactory::class ? $factory : $this->provider));
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('isInstalled')->willReturnCallback(static fn (string $id): bool => $id === 'openconnector');
		$service = new XwikiLinkService(
			$this->createMock(XwikiLinkMapper::class),
			$container,
			new LinkServiceRuntime($appManager, $this->userSession, $this->createMock(LoggerInterface::class))
		);
		$this->signIn('pieter');
		$this->provider->expects($this->once())->method('list')->willReturn([]);

		$service->getAvailablePages(null);
		$service->getAvailablePages(null);
	}//end testWithoutAnInjectedFactoryTheCacheComesFromTheContainer()
}//end class
