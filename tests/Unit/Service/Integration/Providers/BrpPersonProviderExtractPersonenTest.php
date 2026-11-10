<?php

/**
 * Characterisation tests for BrpPersonProvider::extractPersonen.
 *
 * Pins which HaalCentraal envelope shapes yield person rows, in which order of
 * preference, and that non-array rows are dropped.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Integration\Providers
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Integration\Providers;

use OCA\OpenRegister\Service\Integration\ExternalIntegrationRouterInterface;
use OCA\OpenRegister\Service\Integration\Providers\BrpPersonProvider;
use OCP\App\IAppManager;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Pins the envelope unwrapping of a HaalCentraal response.
 */
final class BrpPersonProviderExtractPersonenTest extends TestCase {

	/**
	 * @param array<string, mixed>             $response The decoded response.
	 * @param array<int, array<string, mixed>> $expected The person rows.
	 */
	#[DataProvider('envelopes')]
	public function testExtractPersonen(array $response, array $expected): void {
		$provider = new BrpPersonProvider(
			$this->createMock(ExternalIntegrationRouterInterface::class),
			$this->createMock(IAppManager::class),
			$this->createMock(IL10N::class),
			$this->createMock(LoggerInterface::class)
		);
		$method = new \ReflectionMethod(BrpPersonProvider::class, 'extractPersonen');
		$method->setAccessible(true);

		$this->assertSame($expected, $method->invoke($provider, $response));
	}//end testExtractPersonen()

	/**
	 * @return array<string, array{0: array<mixed>, 1: array<int, array<string, mixed>>}>
	 */
	public static function envelopes(): array {
		$a = ['burgerservicenummer' => '1'];
		$b = ['burgerservicenummer' => '2'];
		return [
			'personen' => [['personen' => [$a, $b]], [$a, $b]],
			'personen wins over _embedded' => [['personen' => [$a], '_embedded' => ['personen' => [$b]]], [$a]],
			'personen not a list falls back to _embedded' => [['personen' => 'x', '_embedded' => ['personen' => [$b]]], [$b]],
			'personen null falls back to _embedded' => [['personen' => null, '_embedded' => ['personen' => [$b]]], [$b]],
			'_embedded personen' => [['_embedded' => ['personen' => [$a]]], [$a]],
			'_embedded personen not an array' => [['_embedded' => ['personen' => 'x']], []],
			'_embedded not an array' => [['_embedded' => 'x'], []],
			'bare list' => [[$a, $b], [$a, $b]],
			'bare list drops non-array rows' => [[$a, 'x', 3, null, $b], [$a, $b]],
			'personen drops non-array rows' => [['personen' => [$a, 'x', $b]], [$a, $b]],
			'empty response' => [[], []],
			'other keys' => [['total' => 2], []],
			'personen keeps its keys out' => [['personen' => ['k' => $a]], [$a]],
		];
	}//end envelopes()
}//end class
