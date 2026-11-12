<?php

/**
 * Boundary behaviour of the Talk chat transcript exporter.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\Integration;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use OCA\OpenRegister\Files\Service\Integration\ChatTranscriptRequest;
use OCA\OpenRegister\Files\Service\Integration\TalkChatExporter;
use PHPUnit\Framework\TestCase;

class TalkChatExporterBehaviourTest extends TestCase {

	private function savedAt(): DateTimeImmutable {
		return new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC'));
	}//end savedAt()

	private function rows(int $count): array {
		$rows = [];
		for ($i = 0; $i < $count; $i++) {
			$rows[] = ['timestamp' => $i, 'actorDisplayName' => 'A', 'message' => 'm'];
		}

		return $rows;
	}//end rows()

	public function testMoreThanTheCapIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A saved chat holds at most 5000 messages.');
		(new TalkChatExporter())->exportTranscript(new ChatTranscriptRequest('Big', $this->rows(TalkChatExporter::MAX_MESSAGES + 1), 'Anna', $this->savedAt()));
	}//end testMoreThanTheCapIsRefused()

	public function testExactlyTheCapIsAccepted(): void {
		$file = (new TalkChatExporter())->exportTranscript(new ChatTranscriptRequest('Big', $this->rows(TalkChatExporter::MAX_MESSAGES), 'Anna', $this->savedAt()));
		$this->assertSame(TalkChatExporter::MAX_MESSAGES + 3, substr_count($file['content'], "\n"));
	}//end testExactlyTheCapIsAccepted()

	public function testNonArrayRowsAloneCountAsEmpty(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('The conversation has no messages to save.');
		(new TalkChatExporter())->exportTranscript(new ChatTranscriptRequest('X', ['text', 3, null], 'Anna', $this->savedAt()));
	}//end testNonArrayRowsAloneCountAsEmpty()

	public function testBlankTitleAuthorAndMultilineMessageFallBack(): void {
		$file = (new TalkChatExporter())->exportTranscript(new ChatTranscriptRequest(
			'   ',
			[['timestamp' => 60, 'actorDisplayName' => '  ', 'message' => "one\ntwo\nthree"]],
			'Anna',
			$this->savedAt()
		));

		$this->assertSame('Chat-2026-03-04-050607.txt', $file['filename']);
		$this->assertSame(
			"Chat\nSaved by Anna on 2026-03-04 05:06 UTC\n\n[1970-01-01 00:01 UTC] Unknown: one\n    two\n    three\n",
			$file['content']
		);
	}//end testBlankTitleAuthorAndMultilineMessageFallBack()

	public function testTitleOfOnlySymbolsFallsBackToChatSlug(): void {
		$file = (new TalkChatExporter())->exportTranscript(new ChatTranscriptRequest('%%%', [['timestamp' => 0, 'actorDisplayName' => 'Bo', 'message' => 'hi']], 'Anna', $this->savedAt()));

		$this->assertSame('chat-2026-03-04-050607.txt', $file['filename']);
		$this->assertStringStartsWith("%%%\n", $file['content']);
		$this->assertStringContainsString('] Bo: hi', $file['content']);
	}//end testTitleOfOnlySymbolsFallsBackToChatSlug()

	public function testTitleIsSluggedAndRowsMissingFieldsDefault(): void {
		$file = (new TalkChatExporter())->exportTranscript(new ChatTranscriptRequest(' Team / Zaak 12 ', [[]], 'Anna', $this->savedAt()));

		$this->assertSame('Team-Zaak-12-2026-03-04-050607.txt', $file['filename']);
		$this->assertStringEndsWith("[1970-01-01 00:00 UTC] Unknown: \n", $file['content']);
	}//end testTitleIsSluggedAndRowsMissingFieldsDefault()
}//end class
