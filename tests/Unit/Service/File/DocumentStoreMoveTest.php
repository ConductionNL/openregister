<?php

/**
 * The document store move: copy and verify, versions, shares and tags, references, switch and purge.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Db\DocumentMove;
use OCA\OpenRegister\Db\DocumentMoveMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Service\File\DocumentCopyRun;
use OCA\OpenRegister\Service\File\DocumentMoveOutcome;
use OCA\OpenRegister\Service\File\DocumentReferenceMap;
use OCA\OpenRegister\Service\File\DocumentStore;
use OCA\OpenRegister\Service\File\DocumentStoreMove;
use OCA\OpenRegister\Service\File\DocumentStoreMoveGuard;
use OCA\OpenRegister\Service\File\DocumentStoreMoveOptions;
use OCA\OpenRegister\Service\File\DocumentStoreMovePlatform;
use OCA\OpenRegister\Service\File\DocumentStoreObjects;
use OCA\OpenRegister\Service\File\DocumentVersions;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Drives the move over an in-memory tree: one register, one object folder, three files.
 */
#[CoversClass(DocumentStoreMove::class)]
#[CoversClass(DocumentCopyRun::class)]
#[CoversClass(DocumentMoveOutcome::class)]
#[UsesClass(DocumentStoreMoveOptions::class)]
#[UsesClass(DocumentStoreMovePlatform::class)]
#[UsesClass(DocumentStore::class)]
#[UsesClass(DocumentStoreMoveGuard::class)]
#[UsesClass(DocumentReferenceMap::class)]
#[UsesClass(DocumentMove::class)]
#[UsesClass(Register::class)]
class DocumentStoreMoveTest extends TestCase {
	use DocumentStoreMoveFixtureTrait;

	protected function setUp(): void {
		$this->setUpMove();
	}//end setUp()

	public function testAFailedCopySwitchesNothing(): void {
		$this->corrupt['local::/mnt/store/'] = '!';
		$this->objects->expects($this->never())->method('rewrite');

		$result = $this->move()->move(options: $this->options());

		$outcome = $result['outcomes'][0];
		$this->assertSame(DocumentMoveOutcome::REFUSED, $outcome->state);
		$this->assertCount(3, $outcome->refusals);
		$this->assertStringContainsString('besluit.pdf: the copy does not match its source (SHA-256)', $outcome->refusals[0]);
		$this->assertSame([], $this->updates, 'No reference is rewritten.');
		$this->assertFalse($result['rootSwitched']);
		$this->assertArrayNotHasKey(DocumentStore::ROOT_KEY, $this->config);
		$this->assertSame([], $this->deleted);
		$this->assertSame([], $this->movedTo, 'The old tree is untouched.');
		$this->assertSame('besluit', $this->bytesOf(node: $this->files[0]));
		foreach ($this->rows as $row) {
			$this->assertNotSame(DocumentMove::STATE_SWITCHED, $row->getState());
		}

		$this->assertArrayNotHasKey(DocumentStoreMoveGuard::KEY_PREFIX . '5', $this->config, 'Uploads are let through again.');
	}//end testAFailedCopySwitchesNothing()

	public function testVersionsAreCarriedOldestFirst(): void {
		$this->versionsByFile[101] = [self::version(time: 1700000000, bytes: 'v1'), self::version(time: 1710000000, bytes: 'v2')];

		$outcome = $this->move()->move(options: $this->options())['outcomes'][0];

		$this->assertSame(DocumentMoveOutcome::SWITCHED, $outcome->state, implode("\n", $outcome->refusals));
		$this->assertSame(['v1', 'v2', 'besluit'], $this->writesOf(node: $this->copyOf(name: 'besluit.pdf')));
		$versionRows = array_values(array_filter($this->rows, fn (DocumentMove $row) => $row->getKind() === DocumentMove::KIND_VERSION));
		$this->assertSame([1700000000, 1710000000], array_map(fn (DocumentMove $row) => $row->getVersionTime(), $versionRows));
		$this->assertSame([hash('sha256', 'v1'), hash('sha256', 'v2')], array_map(fn (DocumentMove $row) => $row->getSha256(), $versionRows));
		$this->assertSame(2, $outcome->versions);
	}//end testVersionsAreCarriedOldestFirst()

	public function testATargetWithoutVersionsRefusesAVersionedRegister(): void {
		$this->keepsVersions = false;
		$this->versionsByFile[102] = [self::version(time: 1700000000, bytes: 'v1')];
		$this->objects->expects($this->never())->method('rewrite');

		$outcome = $this->move()->move(options: $this->options())['outcomes'][0];

		$this->assertSame(DocumentMoveOutcome::REFUSED, $outcome->state);
		$this->assertCount(1, $outcome->refusals);
		$this->assertStringContainsString('bijlage.pdf: has 1 earlier versions and the target keeps none', $outcome->refusals[0]);
		$this->assertSame([], $this->updates);
	}//end testATargetWithoutVersionsRefusesAVersionedRegister()

	public function testWithoutEarlierVersionsIsRecordedPerFile(): void {
		$this->keepsVersions = false;
		$this->versionsByFile[102] = [self::version(time: 1700000000, bytes: 'v1')];
		$this->objects->expects($this->once())->method('rewrite')
			->with($this->anything(), $this->anything(), [102 => 102])
			->willReturn(1);

		$outcome = $this->move()->move(options: $this->options(withoutVersions: true))['outcomes'][0];

		$this->assertSame(DocumentMoveOutcome::SWITCHED, $outcome->state, implode("\n", $outcome->refusals));
		$this->assertSame(['/openregister/files/Open Registers/Woo/obj-1/bijlage.pdf: moved without its 1 earlier versions.'], $outcome->notes);
		$this->assertSame(['bijlage'], $this->writesOf(node: $this->copyOf(name: 'bijlage.pdf')));
		$reasons = [];
		foreach ($this->rows as $row) {
			$reasons[$row->getOldId()] = $row->getReason();
		}

		$this->assertSame('moved without its earlier versions', $reasons[102]);
		$this->assertNull($reasons[101]);
	}//end testWithoutEarlierVersionsIsRecordedPerFile()

	public function testTheSameStorageIsRefused(): void {
		$this->target = $this->fakeFolder(id: 4, path: '/openregister/files/Same store');
		$this->home = $this->fakeFolder(id: 1, path: '/openregister/files', children: [$this->root, $this->target]);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('is on the same storage as the current root');

		try {
			$this->move()->move(options: new DocumentStoreMoveOptions(target: 'Same store'));
		} finally {
			$this->assertSame([], $this->rows, 'Nothing is copied.');
		}
	}//end testTheSameStorageIsRefused()

	public function testEveryReferenceFollowsTheFile(): void {
		$this->objects->expects($this->once())->method('rewrite')->willReturnCallback(
			function (Register $register, array $moved): int {
				$this->assertSame(5, $register->getId());
				$this->assertSame([10, 11, 101, 102, 103], array_keys($moved));
				return 1;
			}
		);
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');

		$outcome = $this->move()->move(options: $this->options())['outcomes'][0];

		$this->assertSame(DocumentMoveOutcome::SWITCHED, $outcome->state, implode("\n", $outcome->refusals));
		$newBesluit = $this->copyOf(name: 'besluit.pdf')->getId();
		$newRegister = $this->target->get('Woo')->getId();
		$this->assertContains('openregister_registers SET folder=' . $newRegister . ' WHERE folder=10', $this->updates);
		$this->assertContains('openregister_file_texts SET file_id=' . $newBesluit . ' WHERE file_id=101', $this->updates);
		$this->assertContains('openregister_chunks SET source_id=' . $newBesluit . ' WHERE source_id=101 AND source_type=file', $this->updates);
		$this->assertNotContains('openregister_file_texts SET file_id=' . $newRegister . ' WHERE file_id=10', $this->updates, 'A folder id is never written into a file column.');
		$fileColumns = count(array_filter((new DocumentReferenceMap())->columns(), fn (array $c) => $c['kind'] === 'file'));
		$this->assertCount(3 * $fileColumns + 2 * 2, $this->updates);
		$this->assertSame(1, $outcome->objects);
		$this->assertSame(3, $outcome->files);
	}//end testEveryReferenceFollowsTheFile()

	public function testAnUnverifiedRegisterKeepsItsReferences(): void {
		$this->objects->method('rewrite')->willThrowException(new RuntimeException('object store down'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$result = $this->move()->move(options: $this->options());

		$outcome = $result['outcomes'][0];
		$this->assertSame(DocumentMoveOutcome::REFUSED, $outcome->state);
		$this->assertSame(['The references could not be rewritten: object store down'], $outcome->refusals);
		$this->assertFalse($result['rootSwitched']);
		foreach ($this->rows as $row) {
			$this->assertSame(DocumentMove::STATE_VERIFIED, $row->getState(), 'A rolled-back register is not switched.');
		}
	}//end testAnUnverifiedRegisterKeepsItsReferences()

	public function testALinkShareKeepsItsToken(): void {
		$old = $this->linkShare(nodeId: 101, token: 'aBcD1234');
		$this->sharesByNode = [$old];
		$new = $this->createMock(IShare::class);
		$new->method('setNode')->willReturnSelf();
		$token = 'random99';
		$new->method('getToken')->willReturnCallback(function () use (&$token) {
			return $token;
		});
		$new->expects($this->once())->method('setToken')->with('aBcD1234')->willReturnCallback(function (string $value) use (&$token, $new) {
			$token = $value;
			return $new;
		});
		$new->expects($this->once())->method('setLabel')->with('Publicatie');
		$new->expects($this->once())->method('setShareType')->with(IShare::TYPE_LINK);
		$this->shareManager->method('newShare')->willReturn($new);
		$this->shareManager->expects($this->once())->method('createShare')->with($new)->willReturn($new);
		$this->shareManager->expects($this->once())->method('deleteShare')->with($old);
		$this->shareManager->expects($this->once())->method('updateShare')->with($new)->willReturn($new);

		$outcome = $this->move()->move(options: $this->options())['outcomes'][0];

		$this->assertSame(DocumentMoveOutcome::SWITCHED, $outcome->state, implode("\n", $outcome->refusals));
		$this->assertSame(1, $outcome->shares);
		$this->assertSame('aBcD1234', $token);
	}//end testALinkShareKeepsItsToken()

	public function testAPasswordProtectedShareStopsTheRegister(): void {
		$this->sharesByNode = [$this->linkShare(nodeId: 102, token: 'secret01', password: 'hashed')];
		$this->shareManager->expects($this->never())->method('createShare');
		$this->shareManager->expects($this->never())->method('deleteShare');
		$this->objects->expects($this->never())->method('rewrite');

		$outcome = $this->move()->move(options: $this->options())['outcomes'][0];

		$this->assertSame(DocumentMoveOutcome::REFUSED, $outcome->state);
		$this->assertSame(
			['/openregister/files/Open Registers/Woo/obj-1/bijlage.pdf: published through a password-protected link, whose password cannot be carried over.'],
			$outcome->refusals
		);
		$this->assertSame([], $this->updates);
	}//end testAPasswordProtectedShareStopsTheRegister()

	public function testTagsAreCopied(): void {
		$this->tags->method('getTagIdsForObjects')->willReturnCallback(
			fn (array $ids) => ($ids === ['103'] ? ['103' => ['7', '9']] : [])
		);
		$assigned = [];
		$this->tags->method('assignTags')->willReturnCallback(function (string $id, string $type, $tagIds) use (&$assigned): void {
			$assigned[] = [$id, $type, $tagIds];
		});

		$this->move()->move(options: $this->options());

		$this->assertSame([[(string)$this->copyOf(name: 'inventaris.csv')->getId(), 'files', ['7', '9']]], $assigned);
	}//end testTagsAreCopied()

	public function testTheRootSwitchesOnlyWhenEveryRegisterDid(): void {
		$second = $this->fakeFolder(id: 20, path: '/openregister/files/Open Registers/Bezwaar', children: [
			$this->fakeFile(id: 201, path: '/openregister/files/Open Registers/Bezwaar/bezwaar.pdf', bytes: 'bezwaar'),
		]);
		$this->state[2]['children'] = array_merge($this->state[2]['children'], [$second]);
		$this->registers[] = self::register(id: 6, folder: '20');
		$move = $this->move();

		$first = $move->move(options: new DocumentStoreMoveOptions(target: 'New store', registerId: 5));
		$this->assertFalse($first['rootSwitched'], 'Register 6 has not moved yet.');
		$this->assertArrayNotHasKey(DocumentStore::ROOT_KEY, $this->config);
		// The switch rewrote register 5's folder column to its copy.
		$this->registers[0]->setFolder((string)$this->target->get('Woo')->getId());

		$rest = $move->move(options: new DocumentStoreMoveOptions(target: 'New store'));

		$this->assertSame([DocumentMoveOutcome::ALREADY_SWITCHED, DocumentMoveOutcome::SWITCHED], array_map(fn ($o) => $o->state, $rest['outcomes']));
		$this->assertTrue($rest['rootSwitched']);
		$this->assertSame('New store', $this->config[DocumentStore::ROOT_KEY]);
		$renamed = 'Open Registers (moved ' . date('Y-m-d') . ')';
		$this->assertSame(['/openregister/files/Open Registers' => '/openregister/files/' . $renamed], $this->movedTo);
		$this->assertSame($renamed, $this->config[DocumentStoreMove::MOVED_FROM_KEY]);
	}//end testTheRootSwitchesOnlyWhenEveryRegisterDid()

	public function testPurgeDeletesNothingWithAnUnmappedFile(): void {
		$this->move()->move(options: $this->options());
		$stray = $this->fakeFile(id: 150, path: '/openregister/files/Open Registers/stray.txt', bytes: 'stray');
		$this->state[2]['children'] = array_merge($this->state[2]['children'], [$stray]);
		$this->state[2]['name'] = 'Old';
		$this->config[DocumentStoreMove::MOVED_FROM_KEY] = 'Old';

		$result = $this->move()->purge();

		$this->assertFalse($result['deleted']);
		$this->assertSame(['/openregister/files/Open Registers/stray.txt'], $result['unmapped']);
		$this->assertSame([], $this->deleted);
		$this->assertSame('Old', $this->config[DocumentStoreMove::MOVED_FROM_KEY]);
	}//end testPurgeDeletesNothingWithAnUnmappedFile()

	public function testPurgeDeletesAFullyMappedTree(): void {
		$this->move()->move(options: $this->options());
		$this->state[2]['name'] = 'Old';
		$this->config[DocumentStoreMove::MOVED_FROM_KEY] = 'Old';

		$result = $this->move()->purge();

		$this->assertTrue($result['deleted']);
		$this->assertSame([], $result['unmapped']);
		$this->assertSame(['/openregister/files/Open Registers'], $this->deleted);
		$this->assertArrayNotHasKey(DocumentStoreMove::MOVED_FROM_KEY, $this->config);
	}//end testPurgeDeletesAFullyMappedTree()

	public function testUploadsAreHeldOffWhileTheRegisterMoves(): void {
		$seen = [];
		$this->objects->method('rewrite')->willReturnCallback(function () use (&$seen): int {
			$seen[] = ($this->config[DocumentStoreMoveGuard::KEY_PREFIX . '5'] ?? null);
			return 0;
		});

		$this->move()->move(options: $this->options());

		$this->assertSame(['1'], $seen, 'The flag is set while references are rewritten.');
		$this->assertArrayNotHasKey(DocumentStoreMoveGuard::KEY_PREFIX . '5', $this->config);
	}//end testUploadsAreHeldOffWhileTheRegisterMoves()
}//end class
