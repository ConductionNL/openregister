<?php

/**
 * OpenRegister LifecycleActionProviderRegistry tests
 *
 * Exercises the fail-closed policy the provider mode depends on: a tag that
 * resolves to nothing throws, a tag that resolves to the wrong type throws,
 * and a resolved provider is cached for the rest of the request.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Lifecycle
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Lifecycle;

use OCA\OpenRegister\Exception\LifecycleProviderException;
use OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface;
use OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry;
use OCP\IServerContainer;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;

/**
 * @coversDefaultClass \OCA\OpenRegister\Service\Lifecycle\LifecycleActionProviderRegistry
 */
class LifecycleActionProviderRegistryTest extends TestCase {
	private ContainerInterface $container;

	private IServerContainer $serverContainer;

	private LifecycleActionProviderRegistry $registry;

	protected function setUp(): void {
		$this->container = $this->createMock(ContainerInterface::class);
		$this->serverContainer = $this->createMock(IServerContainer::class);

		$this->registry = new LifecycleActionProviderRegistry(
			$this->container,
			$this->serverContainer,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * A provider registered in OpenRegister's own container resolves.
	 */
	public function testProviderResolvesFromTheAppContainer(): void {
		$provider = $this->createMock(LifecycleActionProviderInterface::class);
		$this->container->method('get')->with('dossiq.case.actions')->willReturn($provider);

		$this->assertSame($provider, $this->registry->resolve('dossiq.case.actions'));
	}//end testProviderResolvesFromTheAppContainer()

	/**
	 * An FQCN another app owns is unknown to OpenRegister's container and
	 * resolves through the server container instead. This is the case the
	 * annotation's documented shape actually uses.
	 */
	public function testProviderResolvesFromTheServerContainerAsFallback(): void {
		$notFound = new class extends \Exception implements NotFoundExceptionInterface {
		};
		$provider = $this->createMock(LifecycleActionProviderInterface::class);
		$this->container->method('get')->willThrowException($notFound);
		$this->serverContainer->method('get')->willReturn($provider);

		$this->assertSame($provider, $this->registry->resolve('OCA\\Dossiq\\Lifecycle\\CaseActionProvider'));
	}//end testProviderResolvesFromTheServerContainerAsFallback()

	/**
	 * FAIL CLOSED: a tag nothing answers to throws rather than resolving to
	 * null. A null provider would have to be reported as an empty action
	 * list, which reads as a legitimate answer.
	 */
	public function testMissingProviderFailsClosed(): void {
		$notFound = new class extends \Exception implements NotFoundExceptionInterface {
		};
		$this->container->method('get')->willThrowException($notFound);
		$this->serverContainer->method('get')->willThrowException($notFound);

		$this->expectException(LifecycleProviderException::class);
		$this->expectExceptionMessage('is not registered');

		$this->registry->resolve('nobody.answers.to.this');
	}//end testMissingProviderFailsClosed()

	/**
	 * FAIL CLOSED: a tag that resolves to something not implementing the
	 * interface throws, naming the interface it fails to implement.
	 */
	public function testWrongTypeFailsClosed(): void {
		$this->container->method('get')->willReturn(new \stdClass());

		$this->expectException(LifecycleProviderException::class);
		$this->expectExceptionMessage('does not implement');

		$this->registry->resolve('wrong.type');
	}//end testWrongTypeFailsClosed()

	/**
	 * Resolved once per request: several reads of the same object during one
	 * request reuse the instance rather than rebuilding it.
	 */
	public function testResolutionIsCachedPerRequest(): void {
		$provider = $this->createMock(LifecycleActionProviderInterface::class);
		$this->container->expects($this->once())->method('get')->willReturn($provider);

		$this->assertSame($provider, $this->registry->resolve('dossiq.case.actions'));
		$this->assertSame($provider, $this->registry->resolve('dossiq.case.actions'));
	}//end testResolutionIsCachedPerRequest()
}//end class
