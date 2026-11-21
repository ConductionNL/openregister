<?php
/**
 * Unit tests for BagLoadCommand — occ openregister:bag:load.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Command
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Command;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
use InvalidArgumentException;
use OCA\OpenRegister\Command\BagLoadCommand;
use OCA\OpenRegister\Service\Bag\BagBulkLoader;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;

#[CoversClass(BagLoadCommand::class)]
final class BagLoadCommandTest extends TestCase {

	private const COUNTS = ['created' => 3, 'updated' => 2, 'withdrawn' => 1, 'unchanged' => 4, 'invalid' => 5, 'failed' => 0];

	private function tester(BagBulkLoader $loader, string $configured = ''): CommandTester {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(BagBulkLoader::class)->willReturn($loader);
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->with('openregister', 'bag_bulk_url', '')->willReturn($configured);

		return new CommandTester(new BagLoadCommand($container, $appConfig));
	}

	public function testItLoadsTheGivenSourceAndPrintsTheCounts(): void {
		$loader = $this->createMock(BagBulkLoader::class);
		$loader->expects($this->once())->method('loadFrom')->with('/srv/bag.csv')->willReturn(self::COUNTS);
		$tester = $this->tester($loader, 'https://ignored.example');

		$this->assertSame(0, $tester->execute(['--source' => ' /srv/bag.csv ']));
		$this->assertSame('Created 3, updated 2, withdrawn 1, unchanged 4, invalid 5, failed 0.', trim($tester->getDisplay()));
	}

	public function testWithoutASourceTheConfiguredUrlIsLoaded(): void {
		$loader = $this->createMock(BagBulkLoader::class);
		$loader->expects($this->once())->method('loadFrom')->with('https://bag.example/extract.csv')->willReturn(self::COUNTS);

		$this->assertSame(0, $this->tester($loader, 'https://bag.example/extract.csv')->execute([]));
	}

	public function testAnUnreadableSourceFailsWithItsReason(): void {
		$loader = $this->createMock(BagBulkLoader::class);
		$loader->method('loadFrom')->willThrowException(new InvalidArgumentException('No BAG extract source is given'));
		$tester = $this->tester($loader);

		$this->assertSame(1, $tester->execute([]));
		$this->assertStringContainsString('No BAG extract source is given', $tester->getDisplay());
	}

	public function testItIsNamedForOccWithASourceOption(): void {
		$command = new BagLoadCommand($this->createMock(ContainerInterface::class), $this->createMock(IAppConfig::class));

		$this->assertSame('openregister:bag:load', $command->getName());
		$this->assertTrue($command->getDefinition()->getOption('source')->isValueRequired());
	}
}
