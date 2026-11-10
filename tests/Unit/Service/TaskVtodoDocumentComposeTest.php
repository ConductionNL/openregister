<?php

/**
 * Characterisation test for TaskVtodoDocument::composeFor().
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service
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

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\LinkedTaskDraft;
use OCA\OpenRegister\Service\TaskVtodoDocument;
use PHPUnit\Framework\TestCase;

/**
 * Pins the document a new linked task gets: its uri, and every line except the
 * random UID and the clock-dependent DTSTAMP, which are only checked for shape.
 */
class TaskVtodoDocumentComposeTest extends TestCase {

	public function testComposeLinksTheTaskToItsObject(): void {
		$document = (new TaskVtodoDocument())->composeFor(
			new ObjectRef(register: 7, schema: 9, id: 'abc-1'),
			new LinkedTaskDraft(
				objectTitle: 'Case "A"',
				data: ['summary' => 'Do it', 'description' => 'desc', 'priority' => 3, 'status' => 'needs-action', 'fields' => ['k' => 'v']]
			)
		);

		$lines = explode("\r\n", $document['calendarData']);
		$this->assertMatchesRegularExpression('/^UID:[0-9A-F]{32}$/', $lines[4]);
		$this->assertSame(substr($lines[4], 4) . '.ics', $document['uri']);
		$this->assertMatchesRegularExpression('/^DTSTAMP:\d{8}T\d{6}Z$/', $lines[5]);

		$lines[4] = 'UID:*';
		$lines[5] = 'DTSTAMP:*';
		$this->assertSame(
			[
				'BEGIN:VCALENDAR',
				'VERSION:2.0',
				'PRODID:-//OpenRegister//Tasks//EN',
				'BEGIN:VTODO',
				'UID:*',
				'DTSTAMP:*',
				'SUMMARY:Do it',
				'DESCRIPTION:desc',
				'STATUS:NEEDS-ACTION',
				'PRIORITY:3',
				'X-OPENREGISTER-REGISTER:7',
				'X-OPENREGISTER-SCHEMA:9',
				'X-OPENREGISTER-OBJECT:abc-1',
				'X-OPENREGISTER-DATA:eyJrIjoidiJ9',
				'LINK;LINKREL="related";LABEL="Case "A"";VALUE=URI:/apps/openregister/api/objects/7/9/abc-1',
				'END:VTODO',
				'END:VCALENDAR',
				'',
			],
			$lines
		);
	}//end testComposeLinksTheTaskToItsObject()

	public function testComposeWithoutSummaryOrFieldsUsesTheDefaults(): void {
		$document = (new TaskVtodoDocument())->composeFor(new ObjectRef(register: 1, schema: 2, id: 'u'), new LinkedTaskDraft(objectTitle: 'T', data: []));

		$this->assertStringContainsString("\r\nSUMMARY:Untitled task\r\n", $document['calendarData']);
		$this->assertStringContainsString("\r\nX-OPENREGISTER-OBJECT:u\r\n", $document['calendarData']);
		$this->assertStringContainsString('VALUE=URI:/apps/openregister/api/objects/1/2/u', $document['calendarData']);
	}//end testComposeWithoutSummaryOrFieldsUsesTheDefaults()
}//end class
