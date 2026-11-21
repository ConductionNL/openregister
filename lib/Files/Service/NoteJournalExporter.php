<?php

/**
 * NoteJournalExporter — an object's notes as a Markdown journal sheet.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Files\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-an-objects-notes-export-as-a-journal-sheet
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Files\Service;

/**
 * Writes the notes of one object, oldest first, with author, time and lock state.
 *
 * The notes are the rows NoteService lists (already filtered to what the
 * caller may see). A note's body is Markdown and is written as it is stored;
 * the lines this exporter writes itself (title, author, lock) are flattened to
 * one line so a name cannot open a heading or a list.
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-an-objects-notes-export-as-a-journal-sheet
 */
class NoteJournalExporter {

	/**
	 * The journal of the notes as Markdown.
	 *
	 * @param string                           $title The object's title for the heading.
	 * @param array<int, array<string, mixed>> $notes The notes as NoteService lists them.
	 *
	 * @return string The Markdown document.
	 *
	 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-an-objects-notes-export-as-a-journal-sheet
	 */
	public function markdown(string $title, array $notes): string {
		$lines = ['# Journal: ' . $this->inline(value: $title), ''];
		$ordered = $this->chronological(notes: $notes);
		if ($ordered === []) {
			$lines[] = '_No notes._';
			$lines[] = '';
		}

		foreach ($ordered as $note) {
			$lines[] = '## ' . $this->inline(value: (string)($note['createdAt'] ?? '')) . ' — ' . $this->author(note: $note);
			if (($note['locked'] ?? false) === true) {
				$lines[] = '';
				$lines[] = '_Locked by ' . $this->inline(value: $this->locker(note: $note))
					. ' on ' . $this->inline(value: (string)($note['lockedAt'] ?? '')) . '_';
			}

			$lines[] = '';
			$lines[] = rtrim((string)($note['message'] ?? ''));
			$lines[] = '';
		}

		return implode("\n", $lines);
	}//end markdown()

	/**
	 * The notes oldest first; notes created at the same time keep their id order.
	 *
	 * @param array<int, array<string, mixed>> $notes The notes.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function chronological(array $notes): array {
		$notes = array_values(array_filter($notes, 'is_array'));
		usort(
			$notes,
			static function (array $left, array $right): int {
				$byTime = strtotime((string)($left['createdAt'] ?? '')) <=> strtotime((string)($right['createdAt'] ?? ''));
				if ($byTime !== 0) {
					return $byTime;
				}

				return (int)($left['id'] ?? 0) <=> (int)($right['id'] ?? 0);
			}
		);

		return $notes;
	}//end chronological()

	/**
	 * The author's display name, else their id.
	 *
	 * @param array<string, mixed> $note The note.
	 *
	 * @return string
	 */
	private function author(array $note): string {
		$name = trim((string)($note['actorDisplayName'] ?? ''));
		if ($name === '') {
			$name = (string)($note['actorId'] ?? '');
		}

		return $this->inline(value: $name);
	}//end author()

	/**
	 * Who locked the note: their display name, else their id.
	 *
	 * @param array<string, mixed> $note The note.
	 *
	 * @return string
	 */
	private function locker(array $note): string {
		$name = trim((string)($note['lockedByDisplayName'] ?? ''));
		if ($name === '') {
			$name = (string)($note['lockedBy'] ?? '');
		}

		return $name;
	}//end locker()

	/**
	 * A value on one line, with the characters that would start Markdown structure escaped.
	 *
	 * @param string $value The value.
	 *
	 * @return string
	 */
	private function inline(string $value): string {
		$value = trim((string)preg_replace('/\s+/u', ' ', $value));

		return (string)preg_replace('/([\\\\`*_\[\]#<>|])/', '\\\\$1', $value);
	}//end inline()
}//end class
