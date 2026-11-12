<?php

/**
 * Behaviour tests for ExampleSetExportRuns: which export rows a set may write,
 * and how a row is written, checked and reverted.
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


use DateTime;
use OCA\OpenRegister\Db\ExportRun;
use OCA\OpenRegister\Db\ExportRunMapper;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetExportRuns;
use OCA\OpenRegister\Service\ExampleSet\ExampleSetRow;
use OCA\OpenRegister\Service\Export\ExportRunContent;
use OCA\OpenRegister\Service\Export\ExportRunFile;
use OCA\OpenRegister\Service\Export\ExportRunOrigin;
use OCA\OpenRegister\Service\Export\ExportRunRecorder;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Behaviour of the export-run rows an example set writes.
 */
class ExampleSetExportRunsBehaviourTest extends TestCase {

	/** @var Folder&MockObject */
	private Folder $folder;

	/** @var ExportRunRecorder&MockObject */
	private ExportRunRecorder $recorder;

	/** @var ExportRunMapper&MockObject */
	private ExportRunMapper $mapper;

	/** @var IRootFolder&MockObject */
	private IRootFolder $root;

	protected function setUp(): void {
		$this->folder   = $this->createMock(Folder::class);
		$this->recorder = $this->createMock(ExportRunRecorder::class);
		$this->mapper   = $this->createMock(ExportRunMapper::class);
		$this->root     = $this->createMock(IRootFolder::class);
		$this->root->method('getUserFolder')->willReturn($this->folder);
	}//end setUp()

	private function rows(): ExampleSetExportRuns {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap(
			[
				[IRootFolder::class, $this->root],
				[ExportRunRecorder::class, $this->recorder],
				[ExportRunMapper::class, $this->mapper],
			]
		);
		return new ExampleSetExportRuns($container);
	}//end rows()

	private static function valid(array $override=[]): array {
		return array_merge(['user' => 'alice', 'fileName' => 'cases.csv', 'content' => "a,b\n1,2\n"], $override);
	}//end valid()

	private function file(): File {
		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(321);
		$file->method('getPath')->willReturn('/alice/files/Exports/cases.csv');
		return $file;
	}//end file()

	private static function exportRun(?int $retention=null): ExportRun {
		$run = new ExportRun();
		$run->setUuid('run-1');
		$run->setRetentionSeconds($retention);
		return $run;
	}//end run()

	public function testNamesThePartAndNoun(): void {
		$this->assertSame('exportRuns', $this->rows()->part());
		$this->assertSame('export', $this->rows()->noun());
	}

	public function testAValidRowHasNoRefusal(): void {
		$this->assertSame('', $this->rows()->refusal(self::valid(['producedAt' => '2026-01-01T10:00:00+00:00']), ['alice']));
		$this->assertSame('', $this->rows()->refusal(self::valid(['content' => null, 'contentBase64' => base64_encode('bin')]), ['alice']));
	}

	public function testRefusesUnknownFields(): void {
		$this->assertSame(
			'field "secret", "x" is not an export field a set may write',
			$this->rows()->refusal(self::valid(['secret' => 1, 'x' => 2]), ['alice'])
		);
	}

	public function testRefusesAUserTheSetDoesNotCreate(): void {
		$this->assertSame(
			'user "mallory" is not an account the set creates or adopts',
			$this->rows()->refusal(self::valid(['user' => 'mallory']), ['alice'])
		);
		$this->assertSame('user "" is not an account the set creates or adopts', $this->rows()->refusal(['fileName' => 'a', 'content' => 'x'], ['alice']));
	}

	/**
	 * @return array<string, array{string}>
	 */
	public static function badNames(): array {
		return [
			'empty' => [''],
			'nested' => ['sub/cases.csv'],
			'backslash' => ['sub\\cases.csv'],
			'hidden' => ['.hidden'],
		];
	}//end badNames()

	/**
	 * @dataProvider badNames
	 */
	public function testRefusesAFileNameThatIsNotPlain(string $name): void {
		$this->assertSame('fileName is a plain file name in the Exports folder', $this->rows()->refusal(self::valid(['fileName' => $name]), ['alice']));
	}

	/**
	 * @return array<string, array{array<string, mixed>}>
	 */
	public static function badContent(): array {
		return [
			'none' => [['content' => null]],
			'empty' => [['content' => '']],
			'too big' => [['content' => str_repeat('x', 65537)]],
			'bad base64' => [['content' => null, 'contentBase64' => '***not base64***']],
			'not a string' => [['content' => 42]],
		];
	}//end badContent()

	/**
	 * @dataProvider badContent
	 */
	public function testRefusesMissingOrOversizedContent(array $override): void {
		$this->assertSame(
			'an export needs its content (text, or contentBase64 for a binary file), at most 65536 bytes',
			$this->rows()->refusal(self::valid($override), ['alice'])
		);
	}

	public function testAcceptsContentOfExactlyTheMaximum(): void {
		$this->assertSame('', $this->rows()->refusal(self::valid(['content' => str_repeat('x', 65536)]), ['alice']));
	}

	/**
	 * @return array<string, array{mixed}>
	 */
	public static function badMoments(): array {
		return ['blank' => ['  '], 'garbage' => ['not a date at all'], 'number' => [5]];
	}//end badMoments()

	/**
	 * @dataProvider badMoments
	 */
	public function testRefusesAProducedAtThatIsNotADate(mixed $value): void {
		$this->assertSame('producedAt is a date and time', $this->rows()->refusal(self::valid(['producedAt' => $value]), ['alice']));
	}

	public function testWriteCreatesTheFolderAndRecordsTheRunWithDefaults(): void {
		$this->folder->method('nodeExists')->willReturnMap([['Exports', false], ['Exports/cases.csv', false]]);
		$this->folder->expects($this->once())->method('newFolder')->with('Exports');
		$this->folder->expects($this->once())->method('newFile')->with('Exports/cases.csv', "a,b\n1,2\n")->willReturn($this->file());
		$this->recorder->expects($this->once())->method('record')->with(
			new ExportRunOrigin(source: 'example-set', actor: 'alice'),
			new ExportRunContent(format: 'csv', rowCount: 0, registerName: null, schemaName: null),
			new ExportRunFile(filename: 'cases.csv', fileId: 321, filePath: '/alice/files/Exports/cases.csv', downloadCount: 0)
		)->willReturn(self::exportRun());
		$this->mapper->expects($this->never())->method('update');

		$result = $this->rows()->write(new ExampleSetRow('app', 'k', self::valid()));

		$this->assertSame(['error' => '', 'entry' => ['uuid' => 'run-1', 'user' => 'alice', 'path' => 'Exports/cases.csv']], $result);
	}

	public function testWriteReplacesAnExistingFileAndBackdatesTheRun(): void {
		$old = $this->createMock(Node::class);
		$old->expects($this->once())->method('delete');
		$this->folder->method('nodeExists')->willReturn(true);
		$this->folder->expects($this->never())->method('newFolder');
		$this->folder->method('get')->with('Exports/cases.csv')->willReturn($old);
		$this->folder->expects($this->once())->method('newFile')->with('Exports/cases.csv', 'bin')->willReturn($this->file());
		$this->recorder->expects($this->once())->method('record')->with(
			$this->anything(),
			new ExportRunContent(format: 'xlsx', rowCount: 12, registerName: 'Zaken', schemaName: null),
			new ExportRunFile(filename: 'cases.csv', fileId: 321, filePath: '/alice/files/Exports/cases.csv', downloadCount: 3)
		)->willReturn(self::exportRun(retention: 3600));
		$saved = self::exportRun(retention: 3600);
		$this->mapper->expects($this->once())->method('update')->willReturnCallback(
			static function (ExportRun $run) use ($saved): ExportRun {
				$saved->setProducedAt($run->getProducedAt());
				$saved->setCreated($run->getCreated());
				$saved->setExpiresAt($run->getExpiresAt());
				$saved->setUuid('run-saved');
				return $saved;
			}
		);

		$result = $this->rows()->write(
			new ExampleSetRow(
				'app',
				'k',
				self::valid(
					[
						'content' => null,
						'contentBase64' => base64_encode('bin'),
						'format' => 'xlsx',
						'rowCount' => 12,
						'registerName' => 'Zaken',
						'schemaName' => '',
						'downloadCount' => 3,
						'producedAt' => '2026-01-01T10:00:00+00:00',
					]
				)
			)
		);

		$this->assertSame('run-saved', $result['entry']['uuid']);
		$this->assertSame('2026-01-01T10:00:00+00:00', $saved->getProducedAt()->format('c'));
		$this->assertSame('2026-01-01T10:00:00+00:00', $saved->getCreated()->format('c'));
		$this->assertSame('2026-01-01T11:00:00+00:00', $saved->getExpiresAt()->format('c'));
	}

	public function testWriteUsesTheDefaultRetentionWhenTheRunKeepsForever(): void {
		$this->folder->method('nodeExists')->willReturn(false);
		$this->folder->method('newFile')->willReturn($this->file());
		$run = self::exportRun(retention: null);
		$this->recorder->method('record')->willReturn($run);
		$this->mapper->expects($this->once())->method('update')->with($run)->willReturn($run);

		$this->rows()->write(new ExampleSetRow('app', 'k', self::valid(['producedAt' => '2026-01-01T00:00:00+00:00'])));

		$this->assertSame('2026-01-08T00:00:00+00:00', $run->getExpiresAt()->format('c'));
	}

	public function testWriteReportsAFailureAsAnErrorWithoutEntry(): void {
		$this->folder->method('nodeExists')->willThrowException(new \RuntimeException('storage offline'));

		$this->assertSame(
			['error' => 'storage offline', 'entry' => null],
			$this->rows()->write(new ExampleSetRow('app', 'k', self::valid()))
		);
	}

	public function testLostIsFalseWhenRunAndFileStillExist(): void {
		$this->mapper->expects($this->once())->method('findByUuid')->with('run-1')->willReturn(self::exportRun());
		$this->folder->expects($this->once())->method('get')->with('Exports/cases.csv')->willReturn($this->file());

		$this->assertFalse($this->rows()->lost(['uuid' => 'run-1', 'user' => 'alice', 'path' => 'Exports/cases.csv']));
	}

	public function testLostIsTrueWhenTheRunIsGone(): void {
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->folder->expects($this->never())->method('get');

		$this->assertTrue($this->rows()->lost(['uuid' => 'run-1', 'user' => 'alice', 'path' => 'p']));
	}

	public function testLostIsTrueWhenTheFileIsGone(): void {
		$this->mapper->method('findByUuid')->willReturn(self::exportRun());
		$this->folder->method('get')->willThrowException(new \OCP\Files\NotFoundException('gone'));

		$this->assertTrue($this->rows()->lost(['uuid' => 'run-1', 'user' => 'alice', 'path' => 'p']));
	}

	public function testRevertDeletesTheFileAndTheRun(): void {
		$node = $this->createMock(Node::class);
		$node->expects($this->once())->method('delete');
		$run = self::exportRun();
		$this->folder->method('nodeExists')->with('p')->willReturn(true);
		$this->folder->method('get')->with('p')->willReturn($node);
		$this->mapper->method('findByUuid')->with('run-1')->willReturn($run);
		$this->mapper->expects($this->once())->method('delete')->with($run);

		$this->assertSame('removed', $this->rows()->revert(['uuid' => 'run-1', 'user' => 'alice', 'path' => 'p']));
	}

	public function testRevertOfAnAlreadyRemovedFileStillDeletesTheRun(): void {
		$this->folder->method('nodeExists')->willReturn(false);
		$this->folder->expects($this->never())->method('get');
		$this->mapper->method('findByUuid')->willReturn(self::exportRun());
		$this->mapper->expects($this->once())->method('delete');

		$this->assertSame('removed', $this->rows()->revert(['uuid' => 'run-1', 'user' => 'alice', 'path' => 'p']));
	}

	public function testRevertOfAMissingRunCountsAsRemoved(): void {
		$this->folder->method('nodeExists')->willReturn(false);
		$this->mapper->method('findByUuid')->willThrowException(new DoesNotExistException('gone'));
		$this->mapper->expects($this->never())->method('delete');

		$this->assertSame('removed', $this->rows()->revert(['uuid' => 'run-1', 'user' => 'alice', 'path' => 'p']));
	}

	public function testRevertReportsAnyOtherFailure(): void {
		$this->folder->method('nodeExists')->willThrowException(new \RuntimeException('offline'));

		$this->assertSame('failed', $this->rows()->revert(['uuid' => 'run-1', 'user' => 'alice', 'path' => 'p']));
	}
}//end class
