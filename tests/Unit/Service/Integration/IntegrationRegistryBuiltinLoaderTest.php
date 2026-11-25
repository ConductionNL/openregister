<?php

/**
 * The lazy built-in loader of the integration registry: it runs once, on the
 * first read, its providers keep their place ahead of providers another app
 * added earlier and win a duplicate id, and a loader that throws is logged
 * without dropping the providers added before it ran.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class IntegrationRegistryBuiltinLoaderTest extends TestCase {

	private function provider(string $id): IntegrationProvider {
		$provider = $this->createMock(IntegrationProvider::class);
		$provider->method('getId')->willReturn($id);
		$provider->method('getStorageStrategy')->willReturn('link-table');
		return $provider;
	}//end provider()

	/**
	 * The ids the registry lists, in order.
	 *
	 * @param IntegrationRegistry $registry The registry.
	 *
	 * @return array<int, string>
	 */
	private static function ids(IntegrationRegistry $registry): array {
		return array_map(static fn (IntegrationProvider $provider): string => $provider->getId(), $registry->list());
	}//end ids()

	public function testTheBuiltinLoaderRunsOnceOnTheFirstRead(): void {
		$registry = new IntegrationRegistry($this->createMock(LoggerInterface::class));
		$runs = 0;
		$registry->setBuiltinLoader(
			function () use ($registry, &$runs): void {
				$runs++;
				$registry->addProvider($this->provider('files'));
			}
		);

		$this->assertSame(0, $runs);
		$this->assertTrue($registry->isValidIntegrationId('files'));
		$this->assertSame(['files'], $registry->listIds());
		$this->assertNotNull($registry->get('files'));
		$this->assertSame(1, $runs);
	}//end testTheBuiltinLoaderRunsOnceOnTheFirstRead()

	public function testBuiltinsPrecedeEarlierProvidersAndWinADuplicateId(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[IntegrationRegistry] duplicate provider id "files" — keeping first registration'
		);
		$registry = new IntegrationRegistry($logger);
		$builtin  = $this->provider('files');
		$registry->setBuiltinLoader(
			static function () use ($registry, $builtin): void {
				$registry->addProvider($builtin);
			}
		);
		$registry->addProvider($this->provider('sibling'));
		$registry->addProvider($this->provider('files'));

		$this->assertSame(['files', 'sibling'], self::ids(registry: $registry));
		$this->assertSame($builtin, $registry->get('files'));
	}//end testBuiltinsPrecedeEarlierProvidersAndWinADuplicateId()

	public function testBuiltinsAreLoadedBeforeTheLeaves(): void {
		$registry = new IntegrationRegistry($this->createMock(LoggerInterface::class));
		$registry->setLeafLoader(
			function () use ($registry): void {
				$registry->addProvider($this->provider('leaf'));
			}
		);
		$registry->setBuiltinLoader(
			function () use ($registry): void {
				$registry->addProvider($this->provider('files'));
			}
		);

		$this->assertSame(['files', 'leaf'], self::ids(registry: $registry));
	}//end testBuiltinsAreLoadedBeforeTheLeaves()

	public function testAThrowingBuiltinLoaderIsLoggedAndEarlierProvidersRemain(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[IntegrationRegistry] built-in loader failed: {message}',
			$this->callback(static fn (array $context): bool => $context['message'] === 'provider broken')
		);
		$registry = new IntegrationRegistry($logger);
		$registry->addProvider($this->provider('sibling'));
		$registry->setBuiltinLoader(
			static function (): void {
				throw new RuntimeException('provider broken');
			}
		);

		$this->assertSame(['sibling'], self::ids(registry: $registry));
	}//end testAThrowingBuiltinLoaderIsLoggedAndEarlierProvidersRemain()
}//end class
