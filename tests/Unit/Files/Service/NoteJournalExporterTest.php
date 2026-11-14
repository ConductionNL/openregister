<?php

/**
 * NoteJournalExporter test.
 *
 * Pins the journal sheet of an object's notes: oldest first, with author and
 * time, locked notes marked with who locked them and when, and the lines the
 * exporter writes itself kept from opening Markdown structure
 * (notes-leaf-rich-text-lock-export, task 3.1).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/notes-leaf-rich-text-lock-export/specs/object-interactions/spec.md#requirement-an-objects-notes-export-as-a-journal-sheet
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service;

use OCA\OpenRegister\Files\Service\NoteJournalExporter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NoteJournalExporter::class)]
class NoteJournalExporterTest extends TestCase {

	public function testThreeNotesAreListedOldestFirstWithTheSecondMarkedLocked(): void {
		$notes = [
			['id' => 3, 'message' => 'Derde', 'actorId' => 'anna', 'actorDisplayName' => 'Anna de Vries', 'createdAt' => '2026-11-13T12:00:00+00:00', 'locked' => false],
			['id' => 1, 'message' => 'Eerste', 'actorId' => 'bram', 'actorDisplayName' => 'Bram', 'createdAt' => '2026-11-13T09:00:00+00:00', 'locked' => false],
			[
				'id' => 2,
				'message' => "## Afspraak\nGebeld met de aanvrager.",
				'actorId' => 'anna',
				'actorDisplayName' => 'Anna de Vries',
				'createdAt' => '2026-11-13T10:00:00+00:00',
				'locked' => true,
				'lockedBy' => 'teamleider',
				'lockedByDisplayName' => 'Tess Leider',
				'lockedAt' => '2026-11-13T10:30:00+00:00',
			],
		];

		$markdown = (new NoteJournalExporter())->markdown(title: 'Vergunning 42', notes: $notes);

		$this->assertSame(
			implode("\n", [
				'# Journal: Vergunning 42',
				'',
				'## 2026-11-13T09:00:00+00:00 — Bram',
				'',
				'Eerste',
				'',
				'## 2026-11-13T10:00:00+00:00 — Anna de Vries',
				'',
				'_Locked by Tess Leider on 2026-11-13T10:30:00+00:00_',
				'',
				"## Afspraak\nGebeld met de aanvrager.",
				'',
				'## 2026-11-13T12:00:00+00:00 — Anna de Vries',
				'',
				'Derde',
				'',
			]),
			$markdown
		);
	}

	public function testNotesAtTheSameTimeKeepTheirIdOrderAndNamesFallBackToIds(): void {
		$notes = [
			['id' => 9, 'message' => 'B', 'actorId' => 'kees', 'actorDisplayName' => '', 'createdAt' => '2026-11-13T09:00:00+00:00', 'locked' => true, 'lockedBy' => 'kees', 'lockedAt' => 'later'],
			['id' => 4, 'message' => 'A', 'actorId' => 'joop', 'createdAt' => '2026-11-13T09:00:00+00:00'],
		];

		$markdown = (new NoteJournalExporter())->markdown(title: 'X', notes: $notes);

		$this->assertLessThan(strpos($markdown, "\nB\n"), strpos($markdown, "\nA\n"));
		$this->assertStringContainsString('— joop', $markdown);
		$this->assertStringContainsString('— kees', $markdown);
		$this->assertStringContainsString('_Locked by kees on later_', $markdown);
	}

	public function testTheExportersOwnLinesCannotOpenMarkdownStructure(): void {
		$notes = [
			['id' => 1, 'message' => 'Tekst', 'actorDisplayName' => "Eve\n# Kop *vet*", 'createdAt' => '2026-11-13T09:00:00+00:00'],
		];

		$markdown = (new NoteJournalExporter())->markdown(title: "Zaak\n## <script>", notes: $notes);

		$this->assertStringStartsWith('# Journal: Zaak \\#\\# \\<script\\>' . "\n", $markdown);
		$this->assertStringContainsString('— Eve \\# Kop \\*vet\\*', $markdown);
		$this->assertSame(1, preg_match_all('/^## /m', $markdown));
	}

	public function testAnObjectWithoutNotesSaysSo(): void {
		$this->assertSame("# Journal: Leeg\n\n_No notes._\n", (new NoteJournalExporter())->markdown(title: 'Leeg', notes: []));
	}
}//end class
