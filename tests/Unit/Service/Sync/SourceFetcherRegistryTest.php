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
use OCA\OpenRegister\Service\Sync\RegisterSourceFetchersEvent;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\Sync\SourceFetcherRegistry
 * @uses \OCA\OpenRegister\Service\Sync\RegisterSourceFetchersEvent
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

	// ── App-registered fetchers (app-harvest-fetchers-and-flow-node) ──

	/**
	 * A fetcher declaring one type.
	 */
	private function typed(string $type, string $displayName = 'Name', array $schema = []): SourceFetcherInterface {
		$fetcher = $this->createMock(SourceFetcherInterface::class);
		$fetcher->method('getType')->willReturn($type);
		$fetcher->method('getDisplayName')->willReturn($displayName);
		$fetcher->method('getConfigSchema')->willReturn($schema);
		$fetcher->method('supports')->willReturnCallback(static fn (string $asked): bool => $asked === $type);
		return $fetcher;
	}

	public function testTheEventIsDispatchedOnceAndItsFetchersAreFound(): void {
		$dcat = $this->typed('opencatalogi.dcat-jsonld', 'DCAT (JSON-LD)', ['type' => 'object']);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->expects($this->once())->method('dispatchTyped')->willReturnCallback(
			function (RegisterSourceFetchersEvent $event) use ($dcat): void {
				$event->registerFetcher($dcat);
			}
		);
		$registry = new SourceFetcherRegistry([], $dispatcher, $this->createMock(LoggerInterface::class));

		$this->assertSame($dcat, $registry->get('opencatalogi.dcat-jsonld'));
		$this->assertSame($dcat, $registry->get('opencatalogi.dcat-jsonld'));
		$this->assertSame(
			[['type' => 'opencatalogi.dcat-jsonld', 'displayName' => 'DCAT (JSON-LD)', 'application' => 'opencatalogi', 'configSchema' => ['type' => 'object']]],
			$registry->types()
		);
	}

	public function testADuplicateTypeIsRefusedLoggedAndTheFirstKept(): void {
		$first = $this->typed('opencatalogi.dcat-jsonld');
		$second = $this->typed('opencatalogi.dcat-jsonld');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			$this->callback(
				static fn (string $message): bool => str_contains($message, get_class($first)) && str_contains($message, 'opencatalogi.dcat-jsonld')
			),
			$this->callback(
				static fn (array $context): bool => $context['existing'] === get_class($first) && $context['rejected'] === get_class($second)
			)
		);
		$registry = new SourceFetcherRegistry([], null, $logger);

		$this->assertTrue($registry->register($first));
		$this->assertFalse($registry->register($second));
		$this->assertSame($first, $registry->get('opencatalogi.dcat-jsonld'));
		$this->assertCount(1, $registry->types());
	}

	public function testABareTypeBelongsToOpenRegisterUnlessAnAppIsNamed(): void {
		$registry = new SourceFetcherRegistry();
		$registry->register($this->typed('rest-api', 'REST API'));
		$registry->register($this->typed('csv'), 'myapp');

		$apps = array_column($registry->types(), 'application', 'type');
		$this->assertSame(['rest-api' => 'openregister', 'csv' => 'myapp'], $apps);
	}

	public function testTheDeclaredTypeWinsOverAnEarlierFetcherThatSupportsIt(): void {
		$greedy = $this->fetcher(['rest-api', 'csv']);
		$csv = $this->typed('csv');
		$registry = new SourceFetcherRegistry([$greedy, $csv]);

		$this->assertSame($csv, $registry->get('csv'));
		$this->assertSame($greedy, $registry->get('rest-api'));
	}
}
