<?php

/**
 * Golden test for NotesController.
 *
 * Drives every action through the paths that answer differently. Allowed paths:
 * found object, public and internal notes, a visibility the caller may set.
 * Refused paths: unknown object, missing note, unknown visibility, a visibility
 * the caller may not set, a locked note, a note the caller may not rewrite, a
 * filtered-out note. Failure paths: failing service calls with and without a
 * 404 code. The test records the response and every collaborator call, in
 * order, so the checks stay pinned before the writes they guard. Re-record
 * with UPDATE_GOLDEN=1.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Notifications\Controller
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Notifications\Controller;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\NoteEditForbiddenException;
use OCA\OpenRegister\Exception\NoteLockedException;
use OCA\OpenRegister\Files\Service\NoteService;
use OCA\OpenRegister\Notifications\Controller\NotesController;
use OCA\OpenRegister\Notifications\Controller\Support\NotesControllerServices;
use OCA\OpenRegister\Notifications\Db\TimelineEntry;
use OCA\OpenRegister\Notifications\Service\Timeline\NoteProjection;
use OCA\OpenRegister\Notifications\Service\Timeline\NoteRewrite;
use OCA\OpenRegister\Notifications\Service\Timeline\TimelineWriteService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use OCA\OpenRegister\Notifications\Service\NoteVisibilityMove;

/**
 * @covers \OCA\OpenRegister\Notifications\Controller\NotesController
 */
class A26NotesControllerGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/a26-notes-controller-golden.json';

	/** @var array<int, string> */
	private array $calls = [];

	/** @var array<string, mixed> */
	private array $knobs = [];

	public function testNotesOutcomesMatchGolden(): void {
		$actual = [];
		foreach ($this->scenarios() as $name => [$action, $args, $params, $knobs]) {
			$actual[$name] = $this->outcome(action: $action, args: $args, params: $params, knobs: $knobs);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testNotesOutcomesMatchGolden()

	/**
	 * @return array<string, array{0: string, 1: array, 2: array, 3: array}>
	 */
	private function scenarios(): array {
		$path = ['reg', 'case', 'o-1'];
		$note = ['reg', 'case', 'o-1', '12'];
		$manage = ['mayManage' => true];
		return [
			'index'                     => ['index', $path, ['limit' => '5', 'offset' => '2'], []],
			'index manager public'      => ['index', $path, ['visibility' => 'public'], $manage],
			'index visibility array'    => ['index', $path, ['visibility' => ['x']], []],
			'index object null'         => ['index', $path, [], ['object' => 'null']],
			'index object missing'      => ['index', $path, [], ['object' => 'missing']],
			'index failing'             => ['index', $path, [], ['list' => 'fail']],
			'create no message'         => ['create', $path, ['message' => ''], []],
			'create'                    => ['create', $path, ['message' => 'Hi'], []],
			'create projected'          => ['create', $path, ['message' => 'Hi'], ['projected' => true]],
			'create visibility unknown' => ['create', $path, ['message' => 'Hi', 'visibility' => 'secret'], $manage + ['known' => false]],
			'create visibility refused' => ['create', $path, ['message' => 'Hi', 'visibility' => 'public'], []],
			'create visibility nonstr'  => ['create', $path, ['message' => 'Hi', 'visibility' => 5], $manage + ['known' => false]],
			'create visibility'         => ['create', $path, ['message' => 'Hi', 'visibility' => 'public'], $manage],
			'create object missing'     => ['create', $path, ['message' => 'Hi'], ['object' => 'missing']],
			'create failing'            => ['create', $path, ['message' => 'Hi'], ['create' => 'fail']],
			'update nothing'            => ['update', $note, [], []],
			'update message'            => ['update', $note, ['message' => 'New'], []],
			'update visibility refused' => ['update', $note, ['visibility' => 'public'], []],
			'update visibility unknown' => ['update', $note, ['visibility' => 'x'], $manage + ['known' => false]],
			'update visibility'         => ['update', $note, ['visibility' => 'public'], $manage],
			'update both'               => ['update', $note, ['message' => 'New', 'visibility' => 'internal'], $manage],
			'update locked'             => ['update', $note, ['message' => 'New'], ['update' => 'locked']],
			'update forbidden'          => ['update', $note, ['message' => 'New'], ['update' => 'forbidden']],
			'update fails 404'          => ['update', $note, ['message' => 'New'], ['update' => 'fail404']],
			'update fails'              => ['update', $note, ['message' => 'New'], ['update' => 'fail']],
			'update not of object'      => ['update', $note, ['message' => 'New'], ['require' => 'fail404']],
			'update object null'        => ['update', $note, ['message' => 'New'], ['object' => 'null']],
			'update object missing'     => ['update', $note, ['message' => 'New'], ['object' => 'missing']],
			'patch'                     => ['patch', $note, ['message' => 'New'], []],
			'versions'                  => ['versions', $note, [], []],
			'versions filtered out'     => ['versions', $note, [], ['filter' => 'drop']],
			'versions not of object'    => ['versions', $note, [], ['require' => 'fail']],
			'versions note missing'     => ['versions', $note, [], ['getNote' => 'fail']],
			'versions failing'          => ['versions', $note, [], ['versions' => 'fail']],
			'versions object null'      => ['versions', $note, [], ['object' => 'null']],
			'versions object missing'   => ['versions', $note, [], ['object' => 'missing']],
			'destroy'                   => ['destroy', $note, [], ['projected' => true]],
			'destroy no entry'          => ['destroy', $note, [], []],
			'destroy not of object'     => ['destroy', $note, [], ['require' => 'fail404']],
			'destroy failing'           => ['destroy', $note, [], ['delete' => 'fail']],
			'destroy object null'       => ['destroy', $note, [], ['object' => 'null']],
			'destroy object missing'    => ['destroy', $note, [], ['object' => 'missing']],
		];
	}//end scenarios()

	private function outcome(string $action, array $args, array $params, array $knobs): array {
		$this->calls = [];
		$this->knobs = $knobs + ['object' => 'found', 'mayManage' => false, 'known' => true, 'projected' => false];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$services = new NotesControllerServices(
			noteService: $this->noteService(),
			objectService: $this->objectService(),
			visibility: $this->visibility(),
			timeline: $this->timeline(),
		);
		$controller = new NotesController($request, $services);
		try {
			$response = $controller->$action(...$args);
		} catch (\Throwable $e) {
			return ['thrown' => get_class($e) . ': ' . $e->getMessage(), 'calls' => $this->calls];
		}

		$this->assertInstanceOf(JSONResponse::class, $response);
		return ['status' => $response->getStatus(), 'data' => $response->getData(), 'calls' => $this->calls];
	}//end outcome()

	private function failWhen(string $knob, string $call): void {
		$mode = $this->knobs[$knob] ?? null;
		if ($mode === 'fail') {
			throw new Exception($call . ' failed');
		}

		if ($mode === 'fail404') {
			throw new Exception($call . ' not found', 404);
		}
	}//end failWhen()

	private function noteService(): NoteService {
		$notes = $this->createMock(NoteService::class);
		$notes->method('getNotesForObject')->willReturnCallback(function (string $objectUuid, int $limit, int $offset, ?string $visibility = null): array {
			$this->log(call: 'getNotesForObject', args: [$objectUuid, $limit, $offset, $visibility]);
			$this->failWhen(knob: 'list', call: 'list');
			return [['id' => 1, 'visibility' => 'public']];
		});
		$notes->method('createNote')->willReturnCallback(function (string $objectUuid, string $message, ?string $visibility = null): array {
			$this->log(call: 'createNote', args: [$objectUuid, $message, $visibility]);
			$this->failWhen(knob: 'create', call: 'create');
			return ['id' => 12, 'message' => $message];
		});
		$notes->method('requireNoteOf')->willReturnCallback(function (string $objectUuid, int $noteId): void {
			$this->log(call: 'requireNoteOf', args: [$objectUuid, $noteId]);
			$this->failWhen(knob: 'require', call: 'require');
		});
		$notes->method('getNote')->willReturnCallback(function (int $noteId): array {
			$this->log(call: 'getNote', args: [$noteId]);
			$this->failWhen(knob: 'getNote', call: 'getNote');
			return ['id' => $noteId, 'visibility' => 'internal'];
		});
		$notes->method('updateNote')->willReturnCallback(function (int $noteId, ?string $message = null, ?string $visibility = null, bool $mayManage = false): array {
			$this->log(call: 'updateNote', args: [$noteId, $message, $visibility, $mayManage]);
			match ($this->knobs['update'] ?? null) {
				'locked'    => throw new NoteLockedException(12),
				'forbidden' => throw new NoteEditForbiddenException(),
				default     => $this->failWhen(knob: 'update', call: 'update'),
			};
			return ['id' => $noteId, 'visibility' => $visibility ?? 'internal', 'versionCount' => '3'];
		});
		$notes->method('auditEdit')->willReturnCallback(fn (mixed $object, int $noteId, int $versions): bool => $this->log(call: 'auditEdit', args: [$noteId, $versions], result: true));
		$notes->method('noteVersions')->willReturnCallback(function (int $noteId): array {
			$this->log(call: 'noteVersions', args: [$noteId]);
			$this->failWhen(knob: 'versions', call: 'versions');
			return [['message' => 'old']];
		});
		$notes->method('deleteNote')->willReturnCallback(function (int $noteId): void {
			$this->log(call: 'deleteNote', args: [$noteId]);
			$this->failWhen(knob: 'delete', call: 'delete');
		});
		return $notes;
	}//end noteService()

	private function objectService(): ObjectService {
		$objects = $this->createMock(ObjectService::class);
		foreach (['setRegister', 'setSchema', 'setObject'] as $setter) {
			$objects->method($setter)->willReturnCallback(function (mixed $value) use ($setter, $objects): ObjectService {
				$this->log(call: $setter, args: [$value]);
				return $objects;
			});
		}

		$objects->method('getObject')->willReturnCallback(function (): ?ObjectEntity {
			$this->log(call: 'getObject', args: []);
			if ($this->knobs['object'] === 'missing') {
				throw new DoesNotExistException('no such object');
			}

			if ($this->knobs['object'] === 'null') {
				return null;
			}

			$object = new ObjectEntity();
			$object->setUuid('o-1');
			return $object;
		});
		return $objects;
	}//end objectService()

	private function visibility(): TimelineVisibilityService {
		$visibility = $this->createMock(TimelineVisibilityService::class);
		$visibility->method('isKnownValue')->willReturnCallback(fn (?string $value): bool => $this->log(call: 'isKnownValue', args: [$value], result: $this->knobs['known']));
		$visibility->method('mayManage')->willReturnCallback(fn (mixed $object): bool => $this->log(call: 'mayManage', args: [], result: $this->knobs['mayManage']));
		$visibility->method('mayManageObject')->willReturnCallback(fn (mixed $object): bool => $this->log(call: 'mayManageObject', args: [], result: $this->knobs['mayManage']));
		$visibility->method('effectiveFilter')->willReturnCallback(function (mixed $object, ?string $requested): ?string {
			$filter = $this->knobs['mayManage'] === true ? $requested : 'public';
			return $this->log(call: 'effectiveFilter', args: [$requested], result: $filter);
		});
		$visibility->method('filterRows')->willReturnCallback(fn (array $rows, ?string $filter): array => $this->log(call: 'filterRows', args: [$rows, $filter], result: ($this->knobs['filter'] ?? null) === 'drop' ? [] : $rows));
		$visibility->method('auditNoteMove')->willReturnCallback(
			fn (mixed $object, NoteVisibilityMove $move): bool => $this->log(call: 'auditVisibilityChange', args: [$move->noteId, $move->from, $move->to], result: true)
		);
		return $visibility;
	}//end visibility()

	private function timeline(): TimelineWriteService {
		$timeline = $this->createMock(TimelineWriteService::class);
		$entry = new TimelineEntry();
		$entry->setUuid('e-1');
		$timeline->method('projectNote')->willReturnCallback(function (mixed $object, NoteProjection $note) use ($entry): ?TimelineEntry {
			$this->log(call: 'projectNote', args: [$note->message(), $note->recordData()]);
			return $this->knobs['projected'] === true ? $entry : null;
		});
		$timeline->method('rewrite')->willReturnCallback(function (mixed $object, NoteRewrite $rewrite): ?TimelineEntry {
			$this->log(call: 'rewrite', args: [$rewrite->commentId, $rewrite->message, $rewrite->visibility]);
			return null;
		});
		$timeline->method('entryForNote')->willReturnCallback(function (int $commentId) use ($entry): ?TimelineEntry {
			$this->log(call: 'entryForNote', args: [$commentId]);
			return $this->knobs['projected'] === true ? $entry : null;
		});
		$timeline->method('forgetNote')->willReturnCallback(function (int $commentId, ?string $entryUuid = null): void {
			$this->log(call: 'forgetNote', args: [$commentId, $entryUuid]);
		});
		return $timeline;
	}//end timeline()

	private function log(string $call, array $args, mixed $result = null): mixed {
		$this->calls[] = $call . '(' . json_encode($args, JSON_UNESCAPED_SLASHES) . ')';
		return $result;
	}//end log()
}//end class
