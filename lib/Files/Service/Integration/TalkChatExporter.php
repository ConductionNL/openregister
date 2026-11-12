<?php

/**
 * A Talk conversation as a plain-text file.
 *
 * The "Save chat to object" action in Talk reads the conversation's messages
 * in the browser (through Talk's own API, as the signed-in user) and posts
 * them here; this class writes them as one line per message, oldest first,
 * with the author and the time, so the file reads the way the chat did.
 *
 * The transcript carries what the caller sent and claims nothing more: it
 * is the caller's own export, attached like any file they upload, and its
 * header says who saved it and when.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service\Integration;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Formats a conversation's messages as text.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */
class TalkChatExporter {

	/**
	 * The most messages one export writes.
	 *
	 * @var int
	 */
	public const MAX_MESSAGES = 5000;

	/**
	 * The transcript of a conversation.
	 *
	 * @param ChatTranscriptRequest $request The conversation, its messages, and who saved it when.
	 *
	 * @return array{filename: string, content: string} The file.
	 *
	 * @throws InvalidArgumentException When there are no messages or too many.
	 *
	 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
	 */
	public function exportTranscript(ChatTranscriptRequest $request): array {
		$title = $request->title();
		$lines = [
			$title,
			sprintf('Saved by %s on %s', $request->savedBy, $request->savedAt->format('Y-m-d H:i T')),
			'',
		];
		foreach ($this->sortedRows(messages: $request->messages) as $row) {
			$lines[] = $this->line(row: $row);
		}

		return [
			'filename' => sprintf('%s-%s.txt', $this->slug(title: $title), $request->savedAt->format('Y-m-d-His')),
			'content' => implode("\n", $lines) . "\n",
		];
	}//end exportTranscript()

	/**
	 * The message rows, oldest first.
	 *
	 * @param array<int, mixed> $messages The posted messages; anything that is not a map is dropped.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @throws InvalidArgumentException When there are no messages or too many.
	 */
	private function sortedRows(array $messages): array {
		$rows = array_values(array_filter($messages, 'is_array'));
		if ($rows === []) {
			throw new InvalidArgumentException('The conversation has no messages to save.');
		}

		if (count($rows) > self::MAX_MESSAGES) {
			throw new InvalidArgumentException(sprintf('A saved chat holds at most %d messages.', self::MAX_MESSAGES));
		}

		usort($rows, static fn (array $a, array $b): int => ((int)($a['timestamp'] ?? 0)) <=> ((int)($b['timestamp'] ?? 0)));
		return $rows;
	}//end sortedRows()

	/**
	 * One message as a transcript line, in UTC.
	 *
	 * @param array<string, mixed> $row The message.
	 *
	 * @return string
	 */
	private function line(array $row): string {
		$time = (new DateTimeImmutable('@' . (int)($row['timestamp'] ?? 0)))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i');
		$author = trim((string)($row['actorDisplayName'] ?? ''));
		if ($author === '') {
			$author = 'Unknown';
		}

		$text = str_replace(["\r\n", "\r", "\n"], "\n    ", (string)($row['message'] ?? ''));
		return sprintf('[%s UTC] %s: %s', $time, $author, $text);
	}//end line()

	/**
	 * The file-name stem of a title.
	 *
	 * @param string $title The transcript's title.
	 *
	 * @return string
	 */
	private function slug(string $title): string {
		$slug = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $title), '-');
		if ($slug === '') {
			return 'chat';
		}

		return $slug;
	}//end slug()
}//end class
