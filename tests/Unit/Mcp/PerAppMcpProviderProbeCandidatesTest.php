<?php

/**
 * PerAppMcpProviderProbe builds and resolves per-app provider candidates.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp;

use OCA\OpenRegister\Mcp\PerAppMcpProviderProbe;
use OCA\OpenRegister\Mcp\RegisterMcpToolProvidersEvent;
use OCP\App\IAppManager;
use OCP\ICache;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Mcp\PerAppMcpProviderProbe
 * @uses   \OCA\OpenRegister\Mcp\RegisterMcpToolProvidersEvent
 */
class PerAppMcpProviderProbeCandidatesTest extends TestCase {

	/**
	 * Temporary app directory holding an appinfo/info.xml.
	 *
	 * @var string
	 */
	private string $appDir = '';

	/**
	 * Remove the temporary app directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->appDir !== '') {
			@unlink($this->appDir . '/appinfo/info.xml');
			@rmdir($this->appDir . '/appinfo');
			@rmdir($this->appDir);
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Invoke a non-public PerAppMcpProviderProbe method.
	 *
	 * @param string $method The method name.
	 * @param array<int, mixed> $arguments Positional arguments.
	 *
	 * @return mixed The method's return value.
	 */
	private function invoke(string $method, array $arguments): mixed {
		$reflection = new ReflectionMethod(PerAppMcpProviderProbe::class, $method);
		$reflection->setAccessible(true);

		return $reflection->invokeArgs(new PerAppMcpProviderProbe(), $arguments);
	}//end invoke()

	/**
	 * An app manager whose app lives in a temporary directory with the given info.xml.
	 *
	 * @param string $infoXml The info.xml body.
	 *
	 * @return IAppManager
	 */
	private function appManagerWithInfoXml(string $infoXml): IAppManager {
		$this->appDir = sys_get_temp_dir() . '/or-mcp-probe-' . uniqid();
		mkdir($this->appDir . '/appinfo', 0777, true);
		file_put_contents($this->appDir . '/appinfo/info.xml', $infoXml);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willReturn($this->appDir);

		return $appManager;
	}//end appManagerWithInfoXml()

	/**
	 * A namespace in info.xml that differs from ucfirst(appId) adds a third candidate.
	 *
	 * @return void
	 */
	public function testADeclaredNamespaceAddsAThirdCandidate(): void {
		$appManager = $this->appManagerWithInfoXml('<info><namespace>OpenBuild</namespace></info>');

		$this->assertSame(
			[
				'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::openbuild',
				'OCA\\Openbuild\\Mcp\\OpenbuildToolProvider',
				'OCA\\OpenBuild\\Mcp\\OpenBuildToolProvider',
			],
			$this->invoke('buildMcpProviderCandidates', ['openbuild', $appManager])
		);
	}//end testADeclaredNamespaceAddsAThirdCandidate()

	/**
	 * An unresolvable app path leaves the two default candidates.
	 *
	 * @return void
	 */
	public function testAnUnresolvableAppPathKeepsTheDefaultCandidates(): void {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->willThrowException(new RuntimeException('not installed'));

		$this->assertCount(2, $this->invoke('buildMcpProviderCandidates', ['pipelinq', $appManager]));
	}//end testAnUnresolvableAppPathKeepsTheDefaultCandidates()

	/**
	 * A candidate that resolves to something other than a provider is logged and ignored.
	 *
	 * @return void
	 */
	public function testACandidateThatIsNotAProviderIsIgnored(): void {
		$key = RegisterMcpToolProvidersEvent::class;
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn(new RegisterMcpToolProvidersEvent());

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('Resolved but not IMcpToolProvider'));

		$this->assertNull($this->invoke('tryResolveMcpProviderCandidate', [$container, $logger, 'pipelinq', $key]));
	}//end testACandidateThatIsNotAProviderIsIgnored()

	/**
	 * A candidate class that does not exist is skipped without asking the container.
	 *
	 * @return void
	 */
	public function testAMissingCandidateClassIsSkippedSilently(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$this->assertNull(
			$this->invoke(
				'tryResolveMcpProviderCandidate',
				[$container, $this->createMock(LoggerInterface::class), 'pipelinq', 'OCA\\Pipelinq\\Mcp\\PipelinqToolProvider']
			)
		);
	}//end testAMissingCandidateClassIsSkippedSilently()

	/**
	 * A registered alias whose factory throws is logged and yields no provider.
	 *
	 * @return void
	 */
	public function testAFailingCandidateFactoryIsLogged(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('factory failed'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[Application] Resolve failed');

		$this->assertNull(
			$this->invoke(
				'tryResolveMcpProviderCandidate',
				[$container, $logger, 'pipelinq', 'OCA\\OpenRegister\\Mcp\\IMcpToolProvider::pipelinq']
			)
		);
	}//end testAFailingCandidateFactoryIsLogged()

	/**
	 * A cached map with an empty winning key is discarded as corrupt.
	 *
	 * @return void
	 */
	public function testACachedMapWithAnEmptyKeyIsDiscarded(): void {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturn(json_encode(['pipelinq' => '']));

		$this->assertNull($this->invoke('readMcpDiscoveryMap', [$cache, 'key']));
	}//end testACachedMapWithAnEmptyKeyIsDiscarded()

	/**
	 * Without a readable TTL setting the cache is written with the default TTL.
	 *
	 * @return void
	 */
	public function testTheDefaultTtlIsUsedWhenTheSettingCannotBeRead(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no app config'));

		$cache = $this->createMock(ICache::class);
		$cache->expects($this->once())->method('set')->with('key', '{"pipelinq":null}', 60);

		$this->invoke('writeMcpDiscoveryMap', [$container, $cache, 'key', ['pipelinq' => null]]);
	}//end testTheDefaultTtlIsUsedWhenTheSettingCannotBeRead()
}//end class
