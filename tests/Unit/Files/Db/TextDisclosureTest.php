<?php

/**
 * A text disclosure: a record that extracted text of a file was sent to an
 * outside service, and the mapper that lists a file's disclosures newest first.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Db;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use DateTime;
use OCA\OpenRegister\Files\Db\TextDisclosure;
use OCA\OpenRegister\Files\Db\TextDisclosureMapper;
use OCA\OpenRegister\Tests\Unit\Db\RecordsQueries;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * TextDisclosure and TextDisclosureMapper::findByFileId().
 *
 * @covers \OCA\OpenRegister\Files\Db\TextDisclosure
 * @covers \OCA\OpenRegister\Files\Db\TextDisclosureMapper
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJsonTrait
 * @uses   \OCA\OpenRegister\Db\TypedFieldsJson
 */
final class TextDisclosureTest extends TestCase {
	use RecordsQueries;

	public function testAFileDisclosuresAreListedNewestFirst(): void {
		$this->results[] = [
			['id' => 8, 'file_id' => 77, 'service' => 'ocr', 'host' => 'ocr.example.org', 'bytes' => 2048],
			['id' => 3, 'file_id' => 77, 'service' => 'translate', 'host' => 'tr.example.org', 'bytes' => 10],
		];

		$rows = (new TextDisclosureMapper(db: $this->recordingDb()))->findByFileId(77);

		$this->assertSame(
			expected: 'select(*) from(openregister_text_disclosures) where(eq(file_id, :p0)) orderBy(disclosed_at, DESC) addOrderBy(id, DESC) executeQuery()',
			actual: $this->sql()
		);
		$this->assertSame(expected: [77, IQueryBuilder::PARAM_INT], actual: $this->params[':p0']);
		$this->assertCount(expectedCount: 2, haystack: $rows);
		$this->assertContainsOnlyInstancesOf(className: TextDisclosure::class, haystack: $rows);
		$this->assertSame(expected: [8, 3], actual: [$rows[0]->getId(), $rows[1]->getId()]);
		$this->assertSame(expected: 'ocr.example.org', actual: $rows[0]->getHost());
		$this->assertSame(expected: 2048, actual: $rows[0]->getBytes());
	}//end testAFileDisclosuresAreListedNewestFirst()

	public function testAFileWithoutDisclosuresListsNone(): void {
		$this->assertSame(expected: [], actual: (new TextDisclosureMapper(db: $this->recordingDb()))->findByFileId(1));
	}//end testAFileWithoutDisclosuresListsNone()

	public function testADisclosureSerialisesEveryFieldWithItsTimeAsIso8601(): void {
		$disclosure = new TextDisclosure();
		$disclosure->setFileId(77);
		$disclosure->setSourceType('file');
		$disclosure->setSourceId(12);
		$disclosure->setService('ocr');
		$disclosure->setHost('ocr.example.org');
		$disclosure->setDisclosedAt(new DateTime('2026-03-04T05:06:07+00:00'));
		$disclosure->setBytes(2048);

		$json = $disclosure->jsonSerialize();

		$this->assertSame(expected: 77, actual: $json['fileId']);
		$this->assertSame(expected: 'file', actual: $json['sourceType']);
		$this->assertSame(expected: 12, actual: $json['sourceId']);
		$this->assertSame(expected: 'ocr', actual: $json['service']);
		$this->assertSame(expected: 'ocr.example.org', actual: $json['host']);
		$this->assertSame(expected: '2026-03-04T05:06:07+00:00', actual: $json['disclosedAt']);
		$this->assertSame(expected: 2048, actual: $json['bytes']);
	}//end testADisclosureSerialisesEveryFieldWithItsTimeAsIso8601()

	public function testANewDisclosureIsStampedNowAndTypesItsFields(): void {
		$before = new DateTime('-1 minute');
		$disclosure = new TextDisclosure();

		$this->assertGreaterThanOrEqual(expected: $before, actual: $disclosure->getDisclosedAt());
		$this->assertSame(
			expected: [
				'id' => 'integer',
				'fileId' => 'integer',
				'sourceType' => 'string',
				'sourceId' => 'integer',
				'service' => 'string',
				'host' => 'string',
				'disclosedAt' => 'datetime',
				'bytes' => 'integer',
			],
			actual: $disclosure->getFieldTypes()
		);
	}//end testANewDisclosureIsStampedNowAndTypesItsFields()
}//end class
