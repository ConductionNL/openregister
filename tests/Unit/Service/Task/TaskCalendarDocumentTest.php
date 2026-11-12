<?php

/**
 * TaskCalendarDocument answers what TaskCalendarProjector answers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Service\Task\TaskCalendarDocument;
use OCA\OpenRegister\Service\Task\TaskCalendarProjector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Task\TaskCalendarDocument
 */
final class TaskCalendarDocumentTest extends TestCase {

	/**
	 * Calendar documents, projected and not.
	 *
	 * @return array<string, array{string}>
	 */
	public static function documents(): array {
		$vtodo = static fn (string $extra): string => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\nBEGIN:VTODO\r\n"
			. "UID:abc\r\nSUMMARY:Review\r\n" . $extra . "END:VTODO\r\nEND:VCALENDAR\r\n";

		return [
			'projected' => [$vtodo(TaskCalendarProjector::PROP_TASK . ":0d6c3f5e-4c1e-4a43-9f0e-0f5b8c0b1a11\r\n")],
			'blank task' => [$vtodo(TaskCalendarProjector::PROP_TASK . ": \r\n")],
			'foreign vtodo' => [$vtodo('')],
			'garbage' => ['not a calendar'],
			'vcard' => ["BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Someone\r\nEND:VCARD\r\n"],
		];
	}//end documents()

	/**
	 * The answer is the projector's, document for document.
	 *
	 * @param string $document The calendar document.
	 *
	 * @return void
	 */
	#[DataProvider('documents')]
	public function testItAnswersWhatTheProjectorAnswers(string $document): void {
		$this->assertSame(TaskCalendarProjector::taskUuidOf(calendarData: $document), (new TaskCalendarDocument())->taskUuidOf(calendarData: $document));
	}//end testItAnswersWhatTheProjectorAnswers()

	/**
	 * A projected document yields its task uuid.
	 *
	 * @return void
	 */
	public function testAProjectedDocumentYieldsItsTask(): void {
		$document = self::documents()['projected'][0];
		$this->assertSame('0d6c3f5e-4c1e-4a43-9f0e-0f5b8c0b1a11', (new TaskCalendarDocument())->taskUuidOf(calendarData: $document));
	}//end testAProjectedDocumentYieldsItsTask()

	/**
	 * The calendar write-back listener reads the document without naming the projector.
	 *
	 * @return void
	 */
	public function testTheWriteBackListenerDoesNotNameTheProjector(): void {
		$source = (string) file_get_contents(__DIR__ . '/../../../../lib/Listener/TaskVtodoWriteBackListener.php');
		$this->assertStringNotContainsString('TaskCalendarProjector', $source);
	}//end testTheWriteBackListenerDoesNotNameTheProjector()
}//end class
