<?php

/**
 * NotesController PDF journal test.
 *
 * Pins the PDF journal sheet: `?format=pdf` renders the notes the caller may
 * list through the PDF export format, and is refused when no PDF can be made
 * (notes-leaf-rich-text-lock-export, task 3.1).
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Notifications\Controller
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

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\ExportTooLargeException;
use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Files\Service\NoteJournalExporter;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectNotesQuery;
use OCA\OpenRegister\Notifications\Controller\NotesController;
use OCA\OpenRegister\Notifications\Controller\Support\NotePath;
use OCA\OpenRegister\Notifications\Controller\Support\NotesControllerServices;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Service\Export\RowsTable;
use OCA\OpenRegister\Service\ExportService;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[CoversClass(NotesController::class)]
#[UsesClass(NotesControllerServices::class)]
#[UsesClass(NoteJournalExporter::class)]
#[UsesClass(ObjectEntity::class)]
#[UsesClass(ObjectNotesQuery::class)]
#[UsesClass(ListQuery::class)]
#[UsesClass(NotePath::class)]
#[UsesClass(RowsTable::class)]
#[UsesClass(OfficeServerRequiredException::class)]
#[UsesClass(ExportTooLargeException::class)]
class NotesControllerJournalPdfTest extends TestCase {

	private NoteService&MockObject $notes;

	private ExportService&MockObject $export;

	private function controller(string $format = 'pdf', bool $withExport = true): NotesController {
		$object = new ObjectEntity();
		$object->setUuid('uuid-1');
		$object->setName('Vergunning 42');
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('setRegister')->willReturnSelf();
		$objects->method('setSchema')->willReturnSelf();
		$objects->method('setObject')->willReturnSelf();
		$objects->method('getObject')->willReturn($object);
		$this->notes = $this->createMock(NoteService::class);
		$this->notes->method('countNotesForObject')->willReturn(2);
		$this->notes->method('listNotes')->willReturn(
			[
				['id' => 2, 'message' => 'Tweede', 'actorDisplayName' => 'Bram', 'createdAt' => '2026-11-13T10:00:00+00:00', 'locked' => true, 'lockedByDisplayName' => 'Tess', 'lockedAt' => '2026-11-13T11:00:00+00:00'],
				['id' => 1, 'message' => 'Eerste', 'actorDisplayName' => 'Anna', 'createdAt' => '2026-11-13T09:00:00+00:00'],
			]
		);
		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('effectiveFilter')->willReturn('public');
		$this->export = $this->createMock(ExportService::class);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnMap([['format', null, $format]]);

		return new NotesController(
			$request,
			new NotesControllerServices(
				$this->notes,
				$objects,
				$visibility,
				$this->createMock(TimelineWriteService::class),
				$withExport === true ? $this->export : null
			)
		);
	}

	public function testThePdfJournalRendersTheNotesOldestFirstThroughThePdfExport(): void {
		$controller = $this->controller();
		$this->export->method('isPdfAvailable')->willReturn(true);
		$tables = [];
		$this->export->expects($this->once())->method('renderTableToPdf')->willReturnCallback(
			function (RowsTable $table) use (&$tables): string {
				$tables[] = $table;
				return '%PDF-1.7 journal';
			}
		);

		$response = $controller->journal('reg', 'sch', 'uuid-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('journal-uuid-1.pdf', $response->getHeaders()['Content-Disposition']);
		$this->assertSame('%PDF-1.7 journal', $response->render());
		$this->assertSame('Journal: Vergunning 42', $tables[0]->title);
		$this->assertSame(['Eerste', 'Tweede'], array_column($tables[0]->rows, 'message'));
		$this->assertSame('Locked by Tess on 2026-11-13T11:00:00+00:00', $tables[0]->rows[1]['lock']);
		$this->assertTrue($tables[0]->wholeCells);
	}

	public function testWithoutAnOfficeServerThePdfJournalIsRefused501AndReadsNoNotes(): void {
		$controller = $this->controller();
		$this->export->method('isPdfAvailable')->willReturn(false);
		$this->export->expects($this->never())->method('renderTableToPdf');
		$this->notes->expects($this->never())->method('listNotes');

		$response = $controller->journal('reg', 'sch', 'uuid-1');

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(501, $response->getStatus());
		$this->assertSame((new OfficeServerRequiredException())->toResponseData(), $response->getData());
	}

	public function testWithoutAnExportServiceThePdfJournalIsRefused501(): void {
		$response = $this->controller(withExport: false)->journal('reg', 'sch', 'uuid-1');

		$this->assertSame(501, $response->getStatus());
	}

	public function testAConversionThatFailsIsRefused501(): void {
		$controller = $this->controller();
		$this->export->method('isPdfAvailable')->willReturn(true);
		$this->export->method('renderTableToPdf')->willThrowException(new OfficeServerRequiredException());

		$this->assertSame(501, $controller->journal('reg', 'sch', 'uuid-1')->getStatus());
	}

	public function testTooManyNotesForOnePdfAreRefusedWithTheCounts(): void {
		$controller = $this->controller();
		$this->export->method('isPdfAvailable')->willReturn(true);
		$this->export->method('renderTableToPdf')->willThrowException(new ExportTooLargeException(rowCount: 6000, maxRows: 5000));

		$response = $controller->journal('reg', 'sch', 'uuid-1');

		$this->assertSame(400, $response->getStatus());
		$this->assertSame('export_too_large', $response->getData()['error']);
		$this->assertSame(6000, $response->getData()['rowCount']);
		$this->assertSame(5000, $response->getData()['maxRows']);
	}

	public function testAnotherFormatStaysMarkdownAndNeverAsksForAPdf(): void {
		$controller = $this->controller(format: 'md');
		$this->export->expects($this->never())->method('renderTableToPdf');

		$response = $controller->journal('reg', 'sch', 'uuid-1');

		$this->assertSame('text/markdown; charset=utf-8', $response->getHeaders()['Content-Type']);
	}
}//end class
