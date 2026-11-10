<?php

/**
 * SourceFetcherRegistry: the first registered fetcher that supports a source type answers for it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Sync
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Sync;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Service\Sync\SourceFetcherInterface;
use OCA\OpenRegister\Service\Sync\SourceFetcherRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Sync\SourceFetcherRegistry
 */
class SourceFetcherRegistryTest extends TestCase {

	/**
	 * A fetcher supporting exactly the given types.
	 *
	 * @param array<int, string> $types The supported types.
	 *
	 * @return SourceFetcherInterface
	 */
	private function fetcher(array $types): SourceFetcherInterface {
		$fetcher = $this->createMock(SourceFetcherInterface::class);
		$fetcher->method('supports')->willReturnCallback(static fn (string $type): bool => in_array($type, $types, true));
		return $fetcher;
	}

	public function testAnEmptyRegistryHasNoFetcher(): void {
		$this->assertNull((new SourceFetcherRegistry())->get('rest-api'));
	}

	public function testConstructorFetchersAreLookedUpByType(): void {
		$rest = $this->fetcher(['rest-api']);
		$csv = $this->fetcher(['csv']);
		$registry = new SourceFetcherRegistry(new \ArrayIterator([$rest, $csv]));

		$this->assertSame($csv, $registry->get('csv'));
		$this->assertSame($rest, $registry->get('rest-api'));
		$this->assertNull($registry->get('soap'));
	}

	public function testTheFirstRegisteredSupportingFetcherWins(): void {
		$first = $this->fetcher(['rest-api', 'openregister']);
		$second = $this->fetcher(['openregister']);
		$registry = new SourceFetcherRegistry([$first]);
		$registry->register($second);

		$this->assertSame($first, $registry->get('openregister'));
	}

	public function testALaterRegisteredFetcherAnswersForANewType(): void {
		$registry = new SourceFetcherRegistry([$this->fetcher(['rest-api'])]);
		$soap = $this->fetcher(['soap']);
		$registry->register($soap);

		$this->assertSame($soap, $registry->get('soap'));
	}
}
