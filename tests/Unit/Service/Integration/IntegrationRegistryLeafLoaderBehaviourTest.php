<?php

/**
 * The lazy leaf loader of the integration registry: it runs once, on the
 * first read, its providers count as registered, and a loader that throws is
 * logged without breaking the built-in registry.
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

use OCA\OpenRegister\Service\Integration\IntegrationProvider;
use OCA\OpenRegister\Service\Integration\IntegrationRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * IntegrationRegistry leaf loading.
 */
class IntegrationRegistryLeafLoaderBehaviourTest extends TestCase {

	private function provider(string $id): IntegrationProvider {
		$provider = $this->createMock(IntegrationProvider::class);
		$provider->method('getId')->willReturn($id);
		$provider->method('getStorageStrategy')->willReturn('link-table');
		return $provider;
	}//end provider()

	public function testTheLeafLoaderRunsOnceOnTheFirstReadAndItsProvidersAreValid(): void {
		$registry = new IntegrationRegistry($this->createMock(LoggerInterface::class));
		$runs = 0;
		$registry->setLeafLoader(
			function () use ($registry, &$runs): void {
				$runs++;
				$registry->addProvider($this->provider('leaf'));
			}
		);

		$this->assertSame(0, $runs);
		$this->assertTrue($registry->isValidIntegrationId('leaf'));
		$this->assertFalse($registry->isValidIntegrationId('other'));
		$this->assertSame(1, $runs);
	}//end testTheLeafLoaderRunsOnceOnTheFirstReadAndItsProvidersAreValid()

	public function testAThrowingLeafLoaderIsLoggedAndTheBuiltInsRemain(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'[IntegrationRegistry] leaf loader failed: {message}',
			$this->callback(static fn (array $context): bool => $context['message'] === 'sibling app broken')
		);
		$registry = new IntegrationRegistry($logger);
		$registry->addProvider($this->provider('builtin'));
		$registry->setLeafLoader(
			static function (): void {
				throw new RuntimeException('sibling app broken');
			}
		);

		$this->assertTrue($registry->isValidIntegrationId('builtin'));
		$this->assertTrue($registry->isValidIntegrationId('builtin'));
	}//end testAThrowingLeafLoaderIsLoggedAndTheBuiltInsRemain()

	public function testWithoutALoaderAReadIsAPlainLookup(): void {
		$registry = new IntegrationRegistry($this->createMock(LoggerInterface::class));

		$this->assertFalse($registry->isValidIntegrationId('leaf'));
	}//end testWithoutALoaderAReadIsAPlainLookup()
}//end class
