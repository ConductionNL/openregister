<?php

/**
 * Edges of the unread badges on an object: an unparseable seen-stamp badges
 * everything, never-seen counts every dated entry, a failing file lookup
 * counts nought and is logged, and entries, files and moments that cannot be
 * read are not counted.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use OCA\OpenRegister\Service\Lifecycle\SchemaReading;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\ObjectReadState;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Files\Db\FileMapperInterface;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCA\OpenRegister\Service\Interaction\UnreadCountCalculator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * UnreadCountCalculator edges.
 */
class UnreadCountCalculatorEdgesBehaviourTest extends TestCase {

	private FileMapperInterface&MockObject $files;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->files = $this->createMock(FileMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function calculator(): UnreadCountCalculator {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setConfiguration(
			[SubstantiveChangeEvaluator::ANNOTATION => ['subResources' => ['notes' => ['property' => 'notes', 'dateField' => 'at']]]]
		);
		$schemas = $this->createMock(SchemaMapperInterface::class);
		$schemas->method('find')->willReturn($schema);

		return new UnreadCountCalculator(new SubstantiveChangeEvaluator(new SchemaReading($schemas, $this->createMock(LoggerInterface::class))), $this->files, $this->logger);
	}//end calculator()

	private static function object(mixed $notes): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('5');
		$object->setObject(['notes' => $notes]);
		return $object;
	}//end object()

	private static function seen(?string $notesStamp, string $lastSeen = '2026-03-01T00:00:00+00:00'): ObjectReadState {
		$state = new ObjectReadState();
		$state->setLastSeenAt(new DateTime($lastSeen));
		$state->setSubSeen($notesStamp === null ? null : ['notes' => $notesStamp]);
		return $state;
	}//end seen()

	public function testNeverSeenCountsEveryDatedEntryAndSkipsUnreadableOnes(): void {
		$this->files->method('getFilesForObject')->willReturn([['mtime' => 1700000000], 'not-a-file', ['mtime' => null], ['mtime' => 'garbage']]);
		$notes = [
			['at' => '2026-01-01T00:00:00+00:00'],
			['at' => '1700000000'],
			['at' => 1700000000],
			['at' => ''],
			['at' => ['nested']],
			['at' => 'not a date'],
			'not-an-entry',
		];

		$this->assertSame(['files' => 1, 'notes' => 3], $this->calculator()->countsFor(self::object($notes), null));
	}//end testNeverSeenCountsEveryDatedEntryAndSkipsUnreadableOnes()

	public function testAnUnparseableSeenStampBadgesEverythingInsteadOfHidingIt(): void {
		$this->files->method('getFilesForObject')->willReturn([]);
		$notes = [['at' => '2020-01-01T00:00:00+00:00'], ['at' => '2026-06-01T00:00:00+00:00']];

		$this->assertSame(2, $this->calculator()->countsFor(self::object($notes), self::seen('yesterday-ish?'))['notes']);
		$this->assertSame(1, $this->calculator()->countsFor(self::object($notes), self::seen('2026-01-01T00:00:00+00:00'))['notes']);
		$this->assertSame(1, $this->calculator()->countsFor(self::object($notes), self::seen(''))['notes']);
	}//end testAnUnparseableSeenStampBadgesEverythingInsteadOfHidingIt()

	public function testAFailingFileLookupCountsNoughtAndIsLogged(): void {
		$this->files->method('getFilesForObject')->willThrowException(new RuntimeException('no folder'));
		$this->logger->expects($this->once())->method('debug')
			->with('[ReadStateService] file count skipped for obj-1: no folder');

		$this->assertSame(['files' => 0, 'notes' => 0], $this->calculator()->countsFor(self::object('not-a-list'), self::seen(null)));
	}//end testAFailingFileLookupCountsNoughtAndIsLogged()

	public function testAnObjectWithoutABodyCountsNoEntries(): void {
		$this->files->method('getFilesForObject')->willReturn([]);
		$object = new ObjectEntity();
		$object->setUuid('obj-2');
		$object->setSchema('5');

		$this->assertSame(['files' => 0, 'notes' => 0], $this->calculator()->countsFor($object, null));
	}//end testAnObjectWithoutABodyCountsNoEntries()
}//end class
