<?php

/**
 * Golden test: the merge report for every file, relation, property and reference outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use Exception;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\MergeHandler;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the actions, statistics, warnings, errors and success of a merge against
 * fixtures/merge-handler-report-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\MergeHandler
 */
class MergeHandlerReportGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/merge-handler-report-golden.json';

	private const SOURCE = 'src-11111111-2222-3333-4444-555555555555';

	private const TARGET = 'tgt-11111111-2222-3333-4444-555555555555';

	public static function merges(): array {
		return [
			'transfer everything' => [['fileAction' => 'transfer', 'relationAction' => 'transfer', 'referenceAction' => 'transfer'], 'files'],
			'delete files, drop relations, keep references' => [['fileAction' => 'delete', 'relationAction' => 'drop', 'referenceAction' => 'none'], 'files'],
			'source files unreadable on transfer' => [['fileAction' => 'transfer'], 'unreadable'],
			'source files unreadable on delete' => [['fileAction' => 'delete'], 'unreadable'],
			'source without a folder' => [['fileAction' => 'transfer'], 'no-folder'],
			'unknown file action' => [['fileAction' => 'keep'], 'files'],
		];
	}//end merges()

	#[DataProvider('merges')]
	public function testMergeReportMatchesGolden(array $options, string $files): void {
		$report = $this->handler(files: $files)->mergeObjects(
			self::SOURCE,
			$options + ['target' => self::TARGET, 'object' => ['title' => 'Merged', 'same' => 'x', 'new' => null]]
		);
		$actual = array_intersect_key($report, array_flip(['actions', 'statistics', 'warnings', 'errors', 'success']));

		$golden = (is_file(self::FIXTURE) === true ? json_decode((string) file_get_contents(self::FIXTURE), true) : []);
		$name   = $this->dataName();
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertSame($golden[$name], json_decode((string) json_encode($actual), true));
	}//end testMergeReportMatchesGolden()

	public function testAMissingTargetIdIsRefused(): void {
		$this->expectExceptionMessage('Target object ID is required');
		$this->handler(files: 'files')->mergeObjects(self::SOURCE, ['target' => '']);
	}//end testAMissingTargetIdIsRefused()

	private function handler(string $files): MergeHandler {
		$source = $this->object(uuid: self::SOURCE, data: ['title' => 'Old', 'same' => 'x'], relations: ['rel-a', 'rel-b']);
		$target = $this->object(uuid: self::TARGET, data: ['title' => 'Target', 'same' => 'x'], relations: ['rel-b']);
		if ($files !== 'no-folder') {
			$source->setFolder('42');
		}

		$referrer = $this->object(uuid: 'ref-1', data: ['link' => self::SOURCE, 'deep' => ['x' => self::SOURCE]], relations: [self::SOURCE]);
		$bystander = $this->object(uuid: 'ref-2', data: ['link' => 'other'], relations: []);

		$byId = [self::SOURCE => $source, self::TARGET => $target, 'ref-1' => $referrer, 'ref-2' => $bystander];
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAcrossAllSources')->willReturnCallback(fn ($identifier): array => [
			'object' => $byId[$identifier] ?? throw new Exception('not found'),
			'register' => new Register(),
			'schema' => new Schema(),
		]);
		$magic->method('update')->willReturnCallback(static fn ($entity) => $entity);
		$magic->method('findByRelation')->willReturn([clone $referrer, clone $bystander]);

		return new MergeHandler($magic, $this->files(mode: $files), $this->session());
	}//end handler()

	private function files(string $mode): FileService {
		$fileService = $this->createMock(FileService::class);
		if ($mode === 'unreadable') {
			$fileService->method('getFiles')->willThrowException(new RuntimeException('folder gone'));
			return $fileService;
		}

		$fileService->method('getFiles')->willReturn(
			[$this->file(name: 'a.pdf'), $this->createMock(Folder::class), $this->file(name: 'stuck.pdf'), $this->file(name: 'broken.pdf')]
		);
		$fileService->method('addFile')->willReturnCallback(function (FileTarget $target, FileUpload $upload): File {
			$fileName = $upload->fileName;
			if ($fileName === 'broken.pdf') {
				throw new RuntimeException('quota');
			}

			return $this->file(name: 'copy-' . $fileName);
		});
		$fileService->method('deleteFile')->willReturnCallback(
			static fn ($file): bool => in_array($file->getName(), ['stuck.pdf', 'broken.pdf'], true) === false
		);

		return $fileService;
	}//end files()

	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getContent')->willReturn('bytes of ' . $name);

		return $file;
	}//end file()

	private function object(string $uuid, array $data, array $relations): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setRegister('1');
		$object->setSchema('2');
		$object->setObject($data);
		$object->setRelations($relations);

		return $object;
	}//end object()

	private function session(): IUserSession {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('anna');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return $session;
	}//end session()
}//end class
