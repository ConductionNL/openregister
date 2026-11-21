<?php

/**
 * NotesController journal test.
 *
 * Pins the journal download: it reads every note the caller may list, with the
 * same visibility filter as the note list, walks the pages by the stored count,
 * and answers a Markdown file (notes-leaf-rich-text-lock-export, task 3.1).
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

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Service\NoteJournalExporter;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectNotesQuery;
use OCA\OpenRegister\Notifications\Controller\NotesController;
use OCA\OpenRegister\Notifications\Controller\Support\NotesControllerServices;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCP\AppFramework\Http\DataDownloadResponse;
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
#[UsesClass(\OCA\OpenRegister\Notifications\Controller\Support\NotePath::class)]
class NotesControllerJournalTest extends TestCase {

	private NoteService&MockObject $notes;

	private TimelineVisibilityService&MockObject $visibility;

	private ObjectOperationsInterface&MockObject $objects;

	private ObjectEntity $object;

	private function controller(bool $missing = false): NotesController {
		$this->object = new ObjectEntity();
		$this->object->setUuid('uuid-1');
		$this->object->setName('Vergunning 42');
		$this->objects = $this->createMock(ObjectOperationsInterface::class);
		$this->objects->method('setRegister')->willReturnSelf();
		$this->objects->method('setSchema')->willReturnSelf();
		$this->objects->method('setObject')->willReturnSelf();
		$this->objects->method('getObject')->willReturn($missing === true ? null : $this->object);
		$this->notes = $this->createMock(NoteService::class);
		$this->visibility = $this->createMock(TimelineVisibilityService::class);

		return new NotesController(
			$this->createMock(IRequest::class),
			new NotesControllerServices($this->notes, $this->objects, $this->visibility, $this->createMock(TimelineWriteService::class))
		);
	}

	public function testTheJournalIsAMarkdownFileOfTheNotesTheCallerMayList(): void {
		$controller = $this->controller();
		$this->visibility->expects($this->once())->method('effectiveFilter')->with($this->object, null)->willReturn('public');
		$this->notes->method('countNotesForObject')->with('uuid-1')->willReturn(150);

		$queries = [];
		$this->notes->expects($this->exactly(2))->method('listNotes')->willReturnCallback(
			function (ObjectNotesQuery $query) use (&$queries): array {
				$queries[] = [$query->objectUuid, $query->page->limit, $query->page->offset, $query->visibility];
				if ($query->page->offset === 0) {
					// A page the filter shortened: the walk must still read the next one.
					return [['id' => 2, 'message' => 'Tweede', 'actorDisplayName' => 'Bram', 'createdAt' => '2026-11-13T10:00:00+00:00']];
				}

				return [['id' => 1, 'message' => 'Eerste', 'actorDisplayName' => 'Anna', 'createdAt' => '2026-11-13T09:00:00+00:00']];
			}
		);

		$response = $controller->journal('reg', 'sch', 'uuid-1');

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame([['uuid-1', 100, 0, 'public'], ['uuid-1', 100, 100, 'public']], $queries);
		$this->assertSame('text/markdown; charset=utf-8', $response->getHeaders()['Content-Type']);
		$this->assertStringContainsString('journal-uuid-1.md', $response->getHeaders()['Content-Disposition']);
		$markdown = $response->render();
		$this->assertStringStartsWith("# Journal: Vergunning 42\n", $markdown);
		$this->assertLessThan(strpos($markdown, 'Tweede'), strpos($markdown, 'Eerste'));
	}

	public function testAnUnknownObjectIsNotFoundAndReadsNoNotes(): void {
		$controller = $this->controller(missing: true);
		$this->notes->expects($this->never())->method('listNotes');

		$this->assertSame(404, $controller->journal('reg', 'sch', 'missing')->getStatus());
	}

	public function testAFailingReadIsAnswered500WithoutTheNotes(): void {
		$controller = $this->controller();
		$this->visibility->method('effectiveFilter')->willReturn(null);
		$this->notes->method('countNotesForObject')->willThrowException(new Exception('db down'));

		$response = $controller->journal('reg', 'sch', 'uuid-1');

		$this->assertSame(500, $response->getStatus());
		$this->assertNotInstanceOf(DataDownloadResponse::class, $response);
	}
}//end class
