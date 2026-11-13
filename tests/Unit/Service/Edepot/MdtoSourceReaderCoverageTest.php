<?php

/**
 * Unit test for MdtoSourceReader's dekkingInTijd list and legal-hold wording:
 * only complete coverage entries of a declared list are emitted, and an active
 * hold without a reason reads as a plain legal hold.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\ObjectArchivalAnnotation;
use OCA\OpenRegister\Service\Edepot\MdtoSourceReader;
use OCA\OpenRegister\Service\Edepot\MdtoValueReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\MdtoSourceReader
 * @uses   \OCA\OpenRegister\Service\Edepot\MdtoValueReader
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\Edepot\ArchivalFactKey
 * @uses \OCA\OpenRegister\Service\Edepot\ArchivalSource
 */
class MdtoSourceReaderCoverageTest extends TestCase {

	/**
	 * The reader, with no schema annotation for any object.
	 *
	 * @return MdtoSourceReader
	 */
	private function reader(): MdtoSourceReader {
		$annotations = $this->createMock(ObjectArchivalAnnotation::class);
		$annotations->method('forObject')->willReturn([]);
		return new MdtoSourceReader(new MdtoValueReader(), $annotations);
	}//end reader()

	/**
	 * An object with the given retention block.
	 *
	 * @param array<string, mixed> $retention The retention block.
	 *
	 * @return ObjectEntity
	 */
	private static function object(array $retention): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setRetention($retention);
		return $object;
	}//end object()

	/**
	 * A declared list keeps its complete entries, with or without an end, and drops non-entries and incomplete ones.
	 *
	 * @return void
	 */
	public function testOnlyCompleteCoverageEntriesOfAListAreEmitted(): void {
		$coverage = [
			'1998',
			['type' => 'Looptijd', 'start' => '2001-02-03', 'end' => '2004'],
			['type' => 'Onderwerp'],
			['start' => '2001'],
			['type' => 'Periode', 'start' => 'gisteren'],
			['type' => 'Periode', 'start' => '2010'],
		];

		$this->assertSame(
			[
				['type' => 'Looptijd', 'start' => '2001-02-03', 'end' => '2004'],
				['type' => 'Periode', 'start' => '2010', 'end' => null],
			],
			$this->reader()->temporalCoverage(object: self::object(retention: ['temporalCoverage' => $coverage]))
		);
	}//end testOnlyCompleteCoverageEntriesOfAListAreEmitted()

	/**
	 * A single declared entry is read as a list of one.
	 *
	 * @return void
	 */
	public function testASingleEntryIsAListOfOne(): void {
		$this->assertSame(
			[['type' => 'Looptijd', 'start' => '2001', 'end' => null]],
			$this->reader()->temporalCoverage(object: self::object(retention: ['temporalCoverage' => ['type' => 'Looptijd', 'start' => '2001']]))
		);
	}//end testASingleEntryIsAListOfOne()

	/**
	 * An active hold without a reason is a plain legal hold; with a reason it names it; an inactive one is no restriction.
	 *
	 * @return void
	 */
	public function testTheLegalHoldWording(): void {
		$reader = $this->reader();

		$plain = $reader->useRestriction(object: self::object(retention: ['legalHold' => ['active' => true, 'placedDate' => '2026-01-02T10:00:00Z']]));
		$this->assertSame(['type' => MdtoSourceReader::USE_RESTRICTION_OTHER, 'description' => 'Legal hold', 'startDate' => '2026-01-02'], $plain);

		$named = $reader->useRestriction(object: self::object(retention: ['legalHold' => ['active' => true, 'reason' => 'WOO-verzoek']]));
		$this->assertSame('Legal hold: WOO-verzoek', $named['description']);
		$this->assertNull($named['startDate']);

		$this->assertNull($reader->useRestriction(object: self::object(retention: ['legalHold' => ['active' => false]])));
	}//end testTheLegalHoldWording()
}//end class
