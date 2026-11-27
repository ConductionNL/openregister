<?php

/**
 * The object source registry runs its loaders once, on the first read or registration.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\ObjectSource
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-source-providers/tasks.md#task-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\ObjectSource;

use OCA\OpenRegister\Service\ObjectSource\ObjectSourceProvider;
use OCA\OpenRegister\Service\ObjectSource\ObjectSourceRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Loaders: lazy, once, in order, and with the same first-wins order as eager registration.
 */
class ObjectSourceRegistryLoaderTest extends TestCase {

	/**
	 * A provider double with the given id.
	 *
	 * @param string $id The provider id.
	 *
	 * @return ObjectSourceProvider
	 */
	private function provider(string $id): ObjectSourceProvider {
		$provider = $this->createMock(originalClassName: ObjectSourceProvider::class);
		$provider->method('getId')->willReturn($id);
		return $provider;
	}//end provider()

	/**
	 * Adding a loader runs nothing; the first read runs it once.
	 *
	 * @return void
	 */
	public function testALoaderRunsOnceOnTheFirstRead(): void {
		$registry = new ObjectSourceRegistry(logger: $this->createMock(originalClassName: LoggerInterface::class));
		$runs     = 0;
		$caldav   = $this->provider(id: 'caldav-vtodo');
		$registry->addLoader(
			function (ObjectSourceRegistry $given) use (&$runs, $caldav): void {
				$runs++;
				$given->addProvider(provider: $caldav);
			}
		);

		$this->assertSame(expected: 0, actual: $runs, message: 'addLoader() runs nothing.');
		$this->assertSame(expected: $caldav, actual: $registry->get(id: 'caldav-vtodo'));
		$this->assertSame(expected: ['caldav-vtodo'], actual: $registry->listIds());
		$this->assertSame(expected: [$caldav], actual: $registry->list());
		$this->assertSame(expected: 1, actual: $runs, message: 'the loader runs once.');
	}//end testALoaderRunsOnceOnTheFirstRead()

	/**
	 * Loaders run in order before a later addProvider(), so the built-ins keep first-wins on a duplicate id.
	 *
	 * @return void
	 */
	public function testPendingLoadersRunBeforeALaterRegistration(): void {
		$registry = new ObjectSourceRegistry(logger: $this->createMock(originalClassName: LoggerInterface::class));
		$builtin  = $this->provider(id: 'files');
		$second   = $this->provider(id: 'deck');
		$registry->addLoader(static fn (ObjectSourceRegistry $given): bool => $given->addProvider(provider: $builtin));
		$registry->addLoader(static fn (ObjectSourceRegistry $given): bool => $given->addProvider(provider: $second));

		$late = $this->provider(id: 'files');
		$this->assertFalse(condition: $registry->addProvider(provider: $late), message: 'the built-in id was taken first.');
		$this->assertSame(expected: ['files', 'deck'], actual: $registry->listIds());
		$this->assertSame(expected: $builtin, actual: $registry->get(id: 'files'));
	}//end testPendingLoadersRunBeforeALaterRegistration()

	/**
	 * A loader that throws is logged and dropped; the next loader still runs.
	 *
	 * @return void
	 */
	public function testAFailingLoaderIsLoggedAndTheNextStillRuns(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains(string: 'tables is gone'));
		$registry = new ObjectSourceRegistry(logger: $logger);
		$talk     = $this->provider(id: 'talk');
		$registry->addLoader(
			static function (): void {
				throw new RuntimeException(message: 'tables is gone');
			}
		);
		$registry->addLoader(static fn (ObjectSourceRegistry $given): bool => $given->addProvider(provider: $talk));

		$this->assertSame(expected: ['talk'], actual: $registry->listIds());
		$this->assertSame(expected: ['talk'], actual: $registry->listIds(), message: 'nothing runs twice.');
	}//end testAFailingLoaderIsLoggedAndTheNextStillRuns()

	/**
	 * withProviders() replaces the whole set, pending loaders included.
	 *
	 * @return void
	 */
	public function testWithProvidersDropsPendingLoaders(): void {
		$registry = new ObjectSourceRegistry(logger: $this->createMock(originalClassName: LoggerInterface::class));
		$registry->addLoader(
			function (): void {
				$this->fail(message: 'a replaced set must not load the built-ins.');
			}
		);
		$only = $this->provider(id: 'only');

		$registry->withProviders(providers: [$only]);

		$this->assertSame(expected: ['only'], actual: $registry->listIds());
	}//end testWithProvidersDropsPendingLoaders()
}//end class
