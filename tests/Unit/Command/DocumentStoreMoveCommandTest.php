<?php

/**
 * occ openregister:document-store:move over the real move and an in-memory tree.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Command;

use OCA\OpenRegister\Command\DocumentStoreMoveCommand;
use OCA\OpenRegister\Db\DocumentMove;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Service\File\DocumentCopyRun;
use OCA\OpenRegister\Service\File\DocumentMoveOutcome;
use OCA\OpenRegister\Service\File\DocumentReferenceMap;
use OCA\OpenRegister\Service\File\DocumentStore;
use OCA\OpenRegister\Service\File\DocumentStoreMove;
use OCA\OpenRegister\Service\File\DocumentStoreMoveGuard;
use OCA\OpenRegister\Service\File\DocumentStoreMoveOptions;
use OCA\OpenRegister\Service\File\DocumentStoreMovePlatform;
use OCA\OpenRegister\Tests\Unit\Service\File\DocumentStoreMoveFixtureTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Runs the command against one register with three files.
 */
#[CoversClass(DocumentStoreMoveCommand::class)]
#[CoversClass(DocumentStoreMoveOptions::class)]
#[UsesClass(DocumentStoreMove::class)]
#[UsesClass(DocumentCopyRun::class)]
#[UsesClass(DocumentMoveOutcome::class)]
#[UsesClass(DocumentStoreMovePlatform::class)]
#[UsesClass(DocumentStore::class)]
#[UsesClass(DocumentStoreMoveGuard::class)]
#[UsesClass(DocumentReferenceMap::class)]
#[UsesClass(DocumentMove::class)]
#[UsesClass(Register::class)]
class DocumentStoreMoveCommandTest extends TestCase {
	use DocumentStoreMoveFixtureTrait;

	protected function setUp(): void {
		$this->setUpMove();
	}//end setUp()

	private function runCommand(array $input): CommandTester {
		$tester = new CommandTester(new DocumentStoreMoveCommand($this->move()));
		$tester->execute($input);
		return $tester;
	}//end runCommand()

	public function testDryRunChangesNothing(): void {
		$this->versionsByFile[101] = [self::version(time: 1700000000, bytes: 'v1')];
		$this->objects->expects($this->never())->method('rewrite');
		$this->shareManager->expects($this->never())->method('createShare');

		$tester = $this->runCommand(['--to' => 'New store', '--dry-run' => true]);

		$this->assertSame(Command::SUCCESS, $tester->getStatusCode());
		$this->assertStringContainsString('Register 5 (Woo): dry run; 2 folders, 3 files, 1 earlier versions, 0 shares, 0 objects.', $tester->getDisplay());
		$this->assertSame([], $this->rows, 'No mapping is recorded.');
		$this->assertSame([], $this->state[3]['children'], 'Nothing is copied.');
		$this->assertSame([], $this->updates);
		$this->assertSame([], $this->config, 'Neither the root nor an upload flag is set.');
		$this->assertSame([], $this->movedTo);
	}//end testDryRunChangesNothing()

	public function testARerunContinuesWhereItStopped(): void {
		$this->corrupt['local::/mnt/store/'] = '!';
		$first = $this->runCommand(['--to' => 'New store']);
		$this->assertSame(Command::FAILURE, $first->getStatusCode());
		$this->corrupt = [];
		$this->objects->expects($this->once())->method('rewrite')->willReturn(1);

		$second = $this->runCommand(['--to' => 'New store']);

		$this->assertSame(Command::SUCCESS, $second->getStatusCode(), $second->getDisplay());
		$this->assertStringContainsString('Register 5 (Woo): switched; 2 folders, 3 files', $second->getDisplay());
		$this->assertStringContainsString('the document store root is now "New store"', $second->getDisplay());
		$this->assertSame('besluit', $this->bytesOf(node: $this->copyOf(name: 'besluit.pdf')), 'The refused copy is written again.');
		$this->assertCount(1, $this->state[3]['children'], 'The rerun reuses the folders it made.');
		$fileRows = array_filter($this->rows, fn (DocumentMove $row) => $row->getKind() === DocumentMove::KIND_FILE);
		$this->assertCount(3, $fileRows, 'Each file keeps one mapping row.');

		$this->registers[0]->setFolder((string)$this->target->get('Woo')->getId());
		$third = $this->runCommand(['--to' => 'Elsewhere']);
		$this->assertStringContainsString('does not exist', $third->getDisplay());
	}//end testARerunContinuesWhereItStopped()

	public function testARefusedRegisterExitsOne(): void {
		$this->sharesByNode = [$this->linkShare(nodeId: 103, token: 'tok', password: 'hashed')];

		$tester = $this->runCommand(['--to' => 'New store']);

		$this->assertSame(1, $tester->getStatusCode());
		$this->assertStringContainsString('Register 5 (Woo): refused;', $tester->getDisplay());
		$this->assertStringContainsString('refused: /openregister/files/Open Registers/Woo/obj-1/inventaris.csv: published through a password-protected link', $tester->getDisplay());
		$this->assertArrayNotHasKey(DocumentStore::ROOT_KEY, $this->config);
	}//end testARefusedRegisterExitsOne()

	public function testPurgeNamesWhatStopsIt(): void {
		$this->runCommand(['--to' => 'New store']);
		$this->state[2]['children'] = array_merge(
			$this->state[2]['children'],
			[$this->fakeFile(id: 150, path: '/openregister/files/Open Registers/stray.txt', bytes: 'x')]
		);
		$this->state[2]['name'] = 'Old';
		$this->config[DocumentStoreMove::MOVED_FROM_KEY] = 'Old';

		$tester = $this->runCommand(['--purge-source' => true]);

		$this->assertSame(Command::FAILURE, $tester->getStatusCode());
		$this->assertStringContainsString('Nothing deleted: 1 items in "Old" have no verified copy:', $tester->getDisplay());
		$this->assertStringContainsString('  /openregister/files/Open Registers/stray.txt', $tester->getDisplay());
	}//end testPurgeNamesWhatStopsIt()

	public function testABadRegisterIdMovesNothing(): void {
		$tester = $this->runCommand(['--to' => 'New store', '--register' => 'woo']);

		$this->assertSame(Command::FAILURE, $tester->getStatusCode());
		$this->assertStringContainsString('--register takes a register id.', $tester->getDisplay());
		$this->assertSame([], $this->rows);
	}//end testABadRegisterIdMovesNothing()

	public function testTheTargetPathIsTrimmed(): void {
		$this->assertSame('New store/docs', (new DocumentStoreMoveOptions(target: ' /New store/docs/ '))->targetPath());
	}//end testTheTargetPathIsTrimmed()
}//end class
