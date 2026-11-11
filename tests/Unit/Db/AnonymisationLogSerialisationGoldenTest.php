<?php

/**
 * Characterisation of AnonymisationLog::jsonSerialize(): every field in registration order,
 * the sanitization report decoded, the creation time as ISO 8601.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use DateTime;
use OCA\OpenRegister\Files\Db\AnonymisationLog;
use PHPUnit\Framework\TestCase;

/**
 * Golden serialised shape of an anonymisation log entry.
 *
 * @covers \OCA\OpenRegister\Files\Db\AnonymisationLog
 */
final class AnonymisationLogSerialisationGoldenTest extends TestCase {

	/**
	 * A filled entry serialises every field, in this order.
	 *
	 * @return void
	 */
	public function testFilledEntry(): void {
		$log = new AnonymisationLog();
		$log->setId(9);
		$log->setFileId(11);
		$log->setObjectUuid('o-1');
		$log->setRegisterId(2);
		$log->setSchemaId(3);
		$log->setMimeType('application/pdf');
		$log->setEngine('presidio');
		$log->setStatus('done');
		$log->setReason('policy');
		$log->setSanitization('{"names":2}');
		$log->setReplacements(4);
		$log->setDurationMs(120);
		$log->setCreated(new DateTime('2026-03-04T05:06:07+01:00'));

		$this->assertSame(
			[
				'id' => 9,
				'fileId' => 11,
				'objectUuid' => 'o-1',
				'registerId' => 2,
				'schemaId' => 3,
				'mimeType' => 'application/pdf',
				'engine' => 'presidio',
				'status' => 'done',
				'reason' => 'policy',
				'sanitization' => ['names' => 2],
				'replacements' => 4,
				'durationMs' => 120,
				'created' => '2026-03-04T05:06:07+01:00',
			],
			$log->jsonSerialize()
		);
	}//end testFilledEntry()

	/**
	 * A new entry carries its creation time and its defaults; an unreadable report is null.
	 *
	 * @return void
	 */
	public function testNewEntry(): void {
		$log = new AnonymisationLog();
		$log->setSanitization('not json');
		$json = $log->jsonSerialize();

		$this->assertNull($json['sanitization']);
		$this->assertSame(0, $json['fileId']);
		$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $json['created']);
	}//end testNewEntry()
}//end class
