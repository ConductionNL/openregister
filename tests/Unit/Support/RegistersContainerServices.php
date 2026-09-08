<?php

/**
 * Test helper: a container mock that answers with registered factories.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;

/**
 * Registers services on a per-test container mock.
 *
 * Classes that resolve collaborators at run time take a ContainerInterface in
 * their constructor. Tests used to register their mocks on the process-wide
 * `\OC::$server` fake instead; that registry never resets, so mocks leaked from
 * one test into the next, and on a half-booted Nextcloud the lookup recursed
 * until memory ran out (19 GB on 2026-09-08). This trait keeps the registry
 * per test instance, so nothing leaks and nothing touches the global server.
 */
trait RegistersContainerServices {

	/**
	 * Factories the container hands out, keyed by service id.
	 *
	 * @var array<string, callable>
	 */
	private array $containerServices = [];

	/**
	 * Register a factory the container mock will answer with.
	 *
	 * @param string   $id      Service id.
	 * @param callable $factory Factory returning the service.
	 *
	 * @return void
	 */
	protected function registerService(string $id, callable $factory): void {
		$this->containerServices[$id] = $factory;
	}//end registerService()

	/**
	 * A container mock wired to the registered factories; unknown ids yield null.
	 *
	 * @return ContainerInterface&MockObject
	 */
	protected function containerMock(): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			function (string $id): mixed {
				if (isset($this->containerServices[$id]) === false) {
					return null;
				}

				return ($this->containerServices[$id])();
			}
		);
		$container->method('has')->willReturnCallback(
			fn (string $id): bool => isset($this->containerServices[$id])
		);

		return $container;
	}//end containerMock()
}//end trait
