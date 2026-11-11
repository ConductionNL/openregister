<?php

/**
 * Tests for TransferObjectGatherer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\MagicMapperInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Edepot\PackagedFileChecksum;
use OCA\OpenRegister\Service\Edepot\TransferListService;
use OCA\OpenRegister\Service\Edepot\TransferObjectGatherer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\TransferObjectGatherer
 */
class TransferObjectGathererTest extends TestCase {

	/**
	 * A gatherer over objects with the given archival states; a missing uuid is unloadable.
	 *
	 * @param array<string, string> $states Uuid => archiefstatus.
	 *
	 * @return TransferObjectGatherer
	 */
	private function gatherer(array $states): TransferObjectGatherer {
		$mapper = $this->createMock(MagicMapperInterface::class);
		$mapper->method('find')->willReturnCallback(
			static function (string|int $uuid) use ($states): ObjectEntity {
				if (isset($states[$uuid]) === false) {
					throw new \RuntimeException('gone');
				}

				$object = new ObjectEntity();
				$object->setUuid((string)$uuid);
				$object->setRetention(['archiefstatus' => $states[$uuid]]);
				return $object;
			}
		);

		return new TransferObjectGatherer(new \OCA\OpenRegister\Service\Edepot\TransferStores(objects: $mapper, records: $this->createMock(\OCA\OpenRegister\Service\Edepot\TransferRecordService::class), settings: $this->createMock(\OCP\IAppConfig::class), logger: new NullLogger()), new PackagedFileChecksum());
	}//end gatherer()

	/**
	 * A transfer list over the given references.
	 *
	 * @param array<mixed> $refs The references.
	 *
	 * @return array<string, mixed>
	 */
	private static function listOf(array $refs): array {
		return ['uuid' => 'l', 'objectReferences' => $refs];
	}//end listOf()

	public function testOutstandingLeavesOutTransferredObjectsInBothVocabularies(): void {
		$gatherer = $this->gatherer(states: ['a' => 'transferred', 'b' => 'overgebracht', 'c' => 'active']);

		$refs        = [['uuid' => 'a'], ['uuid' => 'b'], ['uuid' => 'c'], ['uuid' => 'ghost'], 'junk', []];
		$outstanding = $gatherer->outstanding(transferList: self::listOf(refs: $refs));

		$this->assertSame([['uuid' => 'c'], ['uuid' => 'ghost']], $outstanding);
	}

	public function testOutstandingOfAMalformedListIsEmpty(): void {
		$this->assertSame([], $this->gatherer(states: [])->outstanding(transferList: ['objectReferences' => 'x']));
	}

	public function testTerminalStatusCompletesOnlyWhenEveryObjectIsConfirmed(): void {
		$gatherer = $this->gatherer(states: ['a' => 'transferred', 'b' => 'active']);

		$this->assertSame(TransferListService::STATUS_COMPLETED, $gatherer->terminalStatus(transferList: self::listOf(refs: [['uuid' => 'a'], 'junk'])));
		$both = self::listOf(refs: [['uuid' => 'a'], ['uuid' => 'b']]);
		$this->assertSame(TransferListService::STATUS_FAILED, $gatherer->terminalStatus(transferList: $both));
		$this->assertSame(TransferListService::STATUS_FAILED, $gatherer->terminalStatus(transferList: self::listOf(refs: [['uuid' => 'ghost']])));
		$this->assertSame(TransferListService::STATUS_FAILED, $gatherer->terminalStatus(transferList: self::listOf(refs: [])));
		$this->assertSame(TransferListService::STATUS_COMPLETED, $gatherer->terminalStatus(transferList: self::listOf(refs: ['junk'])));
	}

	public function testGatherLeavesOutAnUnloadableObject(): void {
		$gathered = $this->gatherer(states: ['a' => 'active'])->gather(objectRefs: [['uuid' => 'a'], ['uuid' => 'ghost']]);

		$this->assertCount(1, $gathered);
		$this->assertSame('a', $gathered[0]['object']->getUuid());
		$this->assertSame([], $gathered[0]['files']);
	}

	public function testFilesOfSkipsMissingAndMalformedReferences(): void {
		$object = new ObjectEntity();
		$object->setObject(['bijlagen' => ['junk', ['path' => ''], ['path' => '/nonexistent/x.pdf']]]);

		$this->assertSame([], $this->gatherer(states: [])->filesOf(object: $object));
	}
}//end class
