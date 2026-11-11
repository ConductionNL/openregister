<?php

/**
 * Behaviour tests for ExampleSetFiles: which fragment files may be attached, and
 * how attach, presence and delete report their outcome.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\ExampleSet
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\ExampleSet;


use OCA\OpenRegister\Service\ExampleSet\ExampleSetFiles;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\ObjectFilesInterface;
use OCP\Files\File;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use OCA\OpenRegister\Service\ExampleSet\AttachedFile;

/**
 * Behaviour of the files an example set attaches to its objects.
 */
class ExampleSetFilesBehaviourTest extends TestCase {

	/** @var ObjectFilesInterface&MockObject */
	private ObjectFilesInterface $fileService;

	private string $dir;

	private string $outside;

	protected function setUp(): void {
		$this->fileService = $this->createMock(ObjectFilesInterface::class);
		$base              = sys_get_temp_dir().'/t12files-'.getmypid().'-'.bin2hex(random_bytes(4));
		$this->dir         = $base.'/fragment';
		$this->outside     = $base.'/outside.txt';
		mkdir($this->dir.'/docs', 0777, true);
		file_put_contents($this->dir.'/docs/brief.txt', 'Geachte heer');
		file_put_contents($this->outside, 'secret');
	}//end setUp()

	protected function tearDown(): void {
		@unlink($this->dir.'/docs/brief.txt');
		@rmdir($this->dir.'/docs');
		@rmdir($this->dir);
		@unlink($this->outside);
		@rmdir(dirname($this->outside));
	}//end tearDown()

	private function files(): ExampleSetFiles {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(FileService::class)->willReturn($this->fileService);
		return new ExampleSetFiles($container);
	}//end files()

	private function named(string $name, int $id=0): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		return $file;
	}//end named()

	public function testAFileInsideTheFragmentFolderIsNotRefused(): void {
		$this->assertSame([], $this->files()->refusals($this->dir, ['files' => [['path' => 'docs/brief.txt']]]));
	}

	public function testAnObjectWithoutFilesHasNoRefusals(): void {
		$this->assertSame([], $this->files()->refusals($this->dir, []));
	}

	public function testRefusesMissingEscapingAndDirectoryPaths(): void {
		$refusals = $this->files()->refusals(
			$this->dir,
			['files' => [['path' => 'docs/missing.txt'], ['path' => '../outside.txt'], ['path' => 'docs']]]
		);

		$this->assertSame(
			[
				'file "docs/missing.txt" is not a readable file inside the fragment\'s folder',
				'file "../outside.txt" is not a readable file inside the fragment\'s folder',
				'file "docs" is not a readable file inside the fragment\'s folder',
			],
			$refusals
		);
	}

	public function testRefusesEverythingWhenTheFragmentFolderDoesNotExist(): void {
		$this->assertCount(1, $this->files()->refusals($this->dir.'/nope', ['files' => [['path' => 'docs/brief.txt']]]));
	}

	public function testAttachAddsReadableFilesAndCollectsErrorsForTheRest(): void {
		$this->fileService->expects($this->exactly(2))->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload): File {
				$args = [$target->object, $upload->fileName, $upload->content, $upload->share];
				if ($args[3] === true) {
					throw new \RuntimeException('quota exceeded');
				}

				$this->assertSame(['obj-1', 'brief.txt', 'Geachte heer', false], array_slice($args, 0, 4));
				return $this->named(name: 'brief.txt', id: 88);
			}
		);

		$result = $this->files()->attach(
			$this->dir,
			['files' => [['path' => 'docs/brief.txt'], ['path' => 'gone.txt'], ['path' => 'docs/brief.txt', 'share' => true]]],
			'obj-1'
		);

		$this->assertSame([['id' => '88', 'name' => 'brief.txt', 'shared' => false]], $result['files']);
		$this->assertSame(['file "gone.txt" is not there', 'file "docs/brief.txt": quota exceeded'], $result['errors']);
	}

	public function testAShareFlagThatIsNotLiterallyTrueDoesNotShare(): void {
		$this->fileService->expects($this->once())->method('addFile')
			->with(
				$this->callback(fn (FileTarget $target): bool => $target->object === 'obj-1'),
				$this->callback(fn (FileUpload $upload): bool => $upload->fileName === 'brief.txt' && $upload->content === 'Geachte heer' && $upload->share === false)
			)
			->willReturn($this->named(name: 'brief.txt', id: 1));

		$result = $this->files()->attach($this->dir, ['files' => [['path' => 'docs/brief.txt', 'share' => 'yes']]], 'obj-1');

		$this->assertFalse($result['files'][0]['shared']);
	}

	public function testNamesListsTheNamesOfTheObjectsFiles(): void {
		$this->fileService->method('getFiles')->with('obj-1')->willReturn([$this->named('a.pdf'), 'junk', $this->named('b.txt')]);

		$this->assertSame(['a.pdf', 'b.txt'], $this->files()->names('obj-1'));
	}

	public function testNamesIsEmptyWhenTheFilesCannotBeRead(): void {
		$this->fileService->method('getFiles')->willThrowException(new \RuntimeException('no folder'));

		$this->assertSame([], $this->files()->names('obj-1'));
	}

	public function testPresentByNameDoesNotLookUpTheId(): void {
		$this->fileService->expects($this->never())->method('getFileById');

		$this->assertTrue($this->files()->present(['name' => 'a.pdf', 'id' => 5], ['a.pdf']));
	}

	public function testPresentFallsBackToTheFileId(): void {
		$this->fileService->method('getFileById')->willReturnCallback(fn (int $id): ?File => ($id === 5 ? $this->named('x') : null));

		$this->assertTrue($this->files()->present(['name' => 'renamed.pdf', 'id' => 5], ['a.pdf']));
		$this->assertFalse($this->files()->present(['name' => 'renamed.pdf', 'id' => 6], ['a.pdf']));
	}

	public function testPresentIsFalseWhenTheLookupFails(): void {
		$this->fileService->method('getFileById')->willThrowException(new \RuntimeException('down'));

		$this->assertFalse($this->files()->present([], []));
	}

	public function testDeleteRemovesAnExistingFile(): void {
		$file = $this->named('a.pdf', 9);
		$this->fileService->method('getFileById')->with(9)->willReturn($file);
		$this->fileService->expects($this->once())->method('deleteFile')->with($file)->willReturn(true);

		$this->assertSame('', $this->files()->deleteAttached(new AttachedFile('9', 'a.pdf'), 'obj-1'));
	}

	public function testDeleteReportsAFileServiceThatDidNotDelete(): void {
		$this->fileService->method('getFileById')->willReturn($this->named('a.pdf', 9));
		$this->fileService->method('deleteFile')->willReturn(false);

		$this->assertSame('OpenRegister did not delete it (see the Nextcloud log)', $this->files()->deleteAttached(new AttachedFile('9', 'a.pdf'), 'obj-1'));
	}

	public function testDeleteOfAGoneFileIsFineWhenTheObjectNoLongerHasIt(): void {
		$this->fileService->method('getFileById')->willReturn(null);
		$this->fileService->method('getFiles')->willReturn([$this->named('other.pdf')]);
		$this->fileService->expects($this->never())->method('deleteFile');

		$this->assertSame('', $this->files()->deleteAttached(new AttachedFile('9', 'a.pdf'), 'obj-1'));
	}

	public function testDeleteOfAnUnfindableFileTheObjectStillHasIsRefused(): void {
		$this->fileService->method('getFileById')->willReturn(null);
		$this->fileService->method('getFiles')->willReturn([$this->named('a.pdf')]);

		$this->assertSame(
			'OpenRegister cannot find file id 9 although the object still has a.pdf',
			$this->files()->deleteAttached(new AttachedFile('9', 'a.pdf'), 'obj-1')
		);
	}

	public function testDeleteReportsAThrownMessage(): void {
		$this->fileService->method('getFileById')->willThrowException(new \RuntimeException('locked'));

		$this->assertSame('locked', $this->files()->deleteAttached(new AttachedFile('9', 'a.pdf'), 'obj-1'));
	}
}//end class
