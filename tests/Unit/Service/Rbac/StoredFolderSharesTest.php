<?php

/**
 * Stored folder shares admit only a share on the object's own stored folder.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
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

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\MagicCrossTableReaderInterface;
use OCA\OpenRegister\Service\Rbac\StoredFolderShares;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\StoredFolderShares
 */
class StoredFolderSharesTest extends TestCase {

	private function share(int $nodeId): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getNodeId')->willReturn($nodeId);

		return $share;
	}

	private function containerWith(mixed $reader): ContainerInterface {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(MagicCrossTableReaderInterface::class)->willReturn($reader);

		return $container;
	}

	public function testNoCandidatesReadsNothing(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$shares = new StoredFolderShares($this->createMock(LoggerInterface::class), $container);

		$this->assertSame([], $shares->admitted([]));
	}

	public function testOnlyAShareOnTheStoredFolderIsAdmitted(): void {
		$onFolder  = $this->share(11);
		$elsewhere = $this->share(99);
		$noFolder  = $this->share(13);

		$reader = $this->createMock(MagicCrossTableReaderInterface::class);
		$reader->expects($this->once())
			->method('findFolderIdsByUuids')
			->with(['a', 'b', 'c'])
			->willReturn(['a' => 11, 'b' => 12]);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');

		$shares   = new StoredFolderShares($logger, $this->containerWith($reader));
		$admitted = $shares->admitted([['a', $onFolder], ['b', $elsewhere], ['c', $noFolder]]);

		$this->assertSame([['a', $onFolder]], $admitted);
	}

	public function testAdmittedCandidatesAreReindexed(): void {
		$first  = $this->share(1);
		$second = $this->share(2);

		$reader = $this->createMock(MagicCrossTableReaderInterface::class);
		$reader->method('findFolderIdsByUuids')->willReturn(['x' => 7, 'y' => 2]);

		$shares = new StoredFolderShares($this->createMock(LoggerInterface::class), $this->containerWith($reader));

		$this->assertSame([0 => ['y', $second]], $shares->admitted([['x', $first], ['y', $second]]));
	}

	public function testAShareWhoseNodeCannotBeReadIsNotAdmitted(): void {
		$broken = $this->createMock(IShare::class);
		$broken->method('getNodeId')->willThrowException(new RuntimeException('node gone'));

		$reader = $this->createMock(MagicCrossTableReaderInterface::class);
		$reader->method('findFolderIdsByUuids')->willReturn(['a' => 5]);

		$shares = new StoredFolderShares($this->createMock(LoggerInterface::class), $this->containerWith($reader));

		$this->assertSame([], $shares->admitted([['a', $broken]]));
	}

	public function testAnUnreadableFolderTableGrantsNothingAndIsLogged(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('no service'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with($this->stringContains('Could not read the stored object folders'), $this->arrayHasKey('file'));

		$shares = new StoredFolderShares($logger, $container);

		$this->assertSame([], $shares->admitted([['a', $this->share(5)]]));
	}

	public function testAServiceOfTheWrongTypeGrantsNothingAndIsLogged(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$shares = new StoredFolderShares($logger, $this->containerWith(new \stdClass()));

		$this->assertSame([], $shares->admitted([['a', $this->share(5)]]));
	}

	public function testAThrowingReaderGrantsNothing(): void {
		$reader = $this->createMock(MagicCrossTableReaderInterface::class);
		$reader->method('findFolderIdsByUuids')->willThrowException(new RuntimeException('db down'));

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error');

		$shares = new StoredFolderShares($logger, $this->containerWith($reader));

		$this->assertSame([], $shares->admitted([['a', $this->share(5)]]));
	}
}
