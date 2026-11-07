<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Service\Task\VtodoReader;
use PHPUnit\Framework\TestCase;
use Sabre\VObject\Reader;

/**
 * @covers \OCA\OpenRegister\Service\Task\VtodoReader
 */
class VtodoReaderTest extends TestCase {

	/**
	 * Parse one VTODO.
	 *
	 * @param string $body VTODO lines.
	 *
	 * @return mixed
	 */
	private static function vtodo(string $body): mixed {
		return Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//t//EN\r\nBEGIN:VTODO\r\nDTSTAMP:20260101T000000Z\r\n" . $body . "END:VTODO\r\nEND:VCALENDAR\r\n")->VTODO;
	}

	public function testDefaultsForABareTask(): void {
		$this->assertSame(
			[
				'id' => 'x.ics', 'uid' => null, 'calendarId' => '5', 'summary' => '', 'description' => '', 'status' => 'needs-action',
				'priority' => 0, 'due' => null, 'completed' => null, 'created' => null,
				'objectUuid' => null, 'registerId' => null, 'schemaId' => null, 'fields' => [],
			],
			(new VtodoReader())->taskArray(self::vtodo(''), '5', 'x.ics')
		);
	}

	public function testReadsLinkAndDataBlob(): void {
		$data = base64_encode((string)json_encode(['kenmerk' => 'Z-1']));
		$task = (new VtodoReader())->taskArray(
			self::vtodo("UID:t\r\nSTATUS:COMPLETED\r\nPRIORITY:2\r\nDUE:20260301T090000Z\r\nX-OPENREGISTER-OBJECT:o-1\r\nX-OPENREGISTER-REGISTER:3\r\nX-OPENREGISTER-DATA:" . $data . "\r\n"),
			'5',
			't.ics'
		);

		$this->assertSame(['completed', 2, '2026-03-01T09:00:00+00:00'], [$task['status'], $task['priority'], $task['due']]);
		$this->assertSame(['o-1', 3, null, ['kenmerk' => 'Z-1']], [$task['objectUuid'], $task['registerId'], $task['schemaId'], $task['fields']]);
	}

	public function testIgnoresADataBlobThatIsNotBase64Json(): void {
		$task = (new VtodoReader())->taskArray(self::vtodo("X-OPENREGISTER-DATA:@@@\r\n"), '5', 'x.ics');

		$this->assertSame([], $task['fields']);
	}
}
