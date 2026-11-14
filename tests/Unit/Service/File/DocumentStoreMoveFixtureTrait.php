<?php

/**
 * The fixture of the document store move tests: one register, one object folder, three files.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/register-document-store/specs/file-actions/spec.md
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- test fixture.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock builders use positional args.

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
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Builds the move over an in-memory tree with recording doubles.
 */
trait DocumentStoreMoveFixtureTrait {
	use DocumentTreeFakeTrait;

	/** @var array<string, string> */
	private array $config = [];

	/** @var list<DocumentMove> */
	private array $rows = [];

	/** @var list<string> */
	private array $updates = [];

	/** @var array<int, list<object>> */
	private array $versionsByFile = [];

	/** @var list<IShare> */
	private array $sharesByNode = [];

	private bool $keepsVersions = true;

	private Folder $home;

	private Folder $root;

	private Folder $target;

	/** @var list<File> */
	private array $files = [];

	/** @var list<Register> */
	private array $registers = [];

	private IShareManager&MockObject $shareManager;

	private ISystemTagObjectMapper&MockObject $tags;

	private DocumentStoreObjects&MockObject $objects;

	private IDBConnection&MockObject $db;

	private function setUpMove(): void {
		$base = '/openregister/files/Open Registers/Woo/obj-1';
		$this->files = [
			$this->fakeFile(id: 101, path: $base . '/besluit.pdf', bytes: 'besluit'),
			$this->fakeFile(id: 102, path: $base . '/bijlage.pdf', bytes: 'bijlage'),
			$this->fakeFile(id: 103, path: $base . '/inventaris.csv', bytes: 'inventaris'),
		];
		$object = $this->fakeFolder(id: 11, path: $base, children: $this->files);
		$register = $this->fakeFolder(id: 10, path: '/openregister/files/Open Registers/Woo', children: [$object]);
		$this->root = $this->fakeFolder(id: 2, path: '/openregister/files/Open Registers', children: [$register]);
		$this->target = $this->fakeFolder(id: 3, path: '/openregister/files/New store', storageId: 'local::/mnt/store/');
		$this->home = $this->fakeFolder(id: 1, path: '/openregister/files', children: [$this->root, $this->target]);

		$this->registers = [self::register(id: 5, folder: '10')];
		$this->shareManager = $this->createMock(IShareManager::class);
		$this->shareManager->method('getSharesBy')->willReturnCallback(
			fn (string $uid, int $type, $node) => array_values(array_filter(
				$this->sharesByNode,
				fn (IShare $share) => $share->getShareType() === $type && $share->getNode()->getId() === $node->getId()
			))
		);
		$this->tags = $this->createMock(ISystemTagObjectMapper::class);
		$this->objects = $this->createMock(DocumentStoreObjects::class);
		$this->db = $this->recordingDb();
	}//end setUpMove()

	private static function register(int $id, string $folder): Register {
		$register = new Register();
		$register->setId($id);
		$register->setTitle('Woo');
		$register->setFolder($folder);
		return $register;
	}//end register()

	private function move(): DocumentStoreMove {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(fn (string $app, string $key, string $default = '') => ($this->config[$key] ?? $default));
		$appConfig->method('setValueString')->willReturnCallback(function (string $app, string $key, string $value): bool {
			$this->config[$key] = $value;
			return true;
		});
		$appConfig->method('deleteKey')->willReturnCallback(function (string $app, string $key): void {
			unset($this->config[$key]);
		});
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->with('openregister')->willReturn($this->home);

		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('findAll')->willReturnCallback(fn () => $this->registers);

		$versions = $this->createMock(DocumentVersions::class);
		$versions->method('keepsVersions')->willReturnCallback(fn () => $this->keepsVersions);
		$versions->method('versionsOf')->willReturnCallback(fn (File $file) => ($this->versionsByFile[$file->getId()] ?? $this->copiedVersions(file: $file)));
		$versions->method('read')->willReturnCallback(fn (object $version) => self::stream(bytes: $version->bytes));

		return new DocumentStoreMove(
			store: new DocumentStore(appConfig: $appConfig, rootFolder: $rootFolder),
			moves: $this->recordingMapper(),
			guard: new DocumentStoreMoveGuard(appConfig: $appConfig),
			map: new DocumentReferenceMap(),
			versions: $versions,
			objects: $this->objects,
			registers: $registers,
			platform: new DocumentStoreMovePlatform(
				rootFolder: $rootFolder,
				appConfig: $appConfig,
				db: $this->db,
				shares: $this->shareManager,
				tags: $this->tags,
			),
		);
	}//end move()

	/**
	 * A copy's earlier versions are the writes before its last one, as the versions app keeps them.
	 *
	 * @return list<object>
	 */
	private function copiedVersions(File $file): array {
		$writes = $this->writesOf(node: $file);
		array_pop($writes);
		return array_map(fn (string $bytes) => self::version(time: 0, bytes: $bytes), $writes);
	}//end copiedVersions()

	private static function version(int $time, string $bytes): object {
		return new class($time, $bytes) {
			public function __construct(public int $time, public string $bytes) {
			}

			public function getTimestamp(): int {
				return $this->time;
			}
		};
	}//end version()

	private function recordingMapper(): DocumentMoveMapper&MockObject {
		$mapper = $this->createMock(DocumentMoveMapper::class);
		$mapper->method('insert')->willReturnCallback(function (DocumentMove $row): DocumentMove {
			$row->setId(count($this->rows) + 1);
			$this->rows[] = $row;
			return $row;
		});
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('forRegister')->willReturnCallback(
			fn (int $registerId) => array_values(array_filter($this->rows, fn (DocumentMove $row) => $row->getRegisterId() === $registerId))
		);
		$mapper->method('switchRegister')->willReturnCallback(function (int $registerId): int {
			$count = 0;
			foreach ($this->rows as $row) {
				if ($row->getRegisterId() === $registerId && $row->getState() === DocumentMove::STATE_VERIFIED) {
					$row->setState(DocumentMove::STATE_SWITCHED);
					$count++;
				}
			}

			return $count;
		});
		$mapper->method('switchedOldIds')->willReturnCallback(function (): array {
			$ids = [];
			foreach ($this->rows as $row) {
				if ($row->getState() === DocumentMove::STATE_SWITCHED && $row->getKind() !== DocumentMove::KIND_VERSION) {
					$ids[$row->getOldId()] = true;
				}
			}

			return $ids;
		});
		return $mapper;
	}//end recordingMapper()

	/**
	 * A connection whose query builders record each UPDATE as "table.column: old -> new".
	 */
	private function recordingDb(): IDBConnection&MockObject {
		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturn(true);
		$db->method('getQueryBuilder')->willReturnCallback(function (): IQueryBuilder {
			$statement = ['table' => '', 'set' => '', 'where' => []];
			$qb = $this->createMock(IQueryBuilder::class);
			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturnCallback(fn (string $column, $value) => $column . '=' . $value);
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnCallback(fn ($value) => (string)$value);
			$qb->method('update')->willReturnCallback(function (string $table) use (&$statement, $qb) {
				$statement['table'] = $table;
				return $qb;
			});
			$qb->method('set')->willReturnCallback(function (string $column, $value) use (&$statement, $qb) {
				$statement['set'] = $column . '=' . $value;
				return $qb;
			});
			$qb->method('where')->willReturnCallback(function ($where) use (&$statement, $qb) {
				$statement['where'][] = $where;
				return $qb;
			});
			$qb->method('andWhere')->willReturnCallback(function ($where) use (&$statement, $qb) {
				$statement['where'][] = $where;
				return $qb;
			});
			$qb->method('executeStatement')->willReturnCallback(function () use (&$statement): int {
				$this->updates[] = $statement['table'] . ' SET ' . $statement['set'] . ' WHERE ' . implode(' AND ', $statement['where']);
				return 1;
			});
			return $qb;
		});
		return $db;
	}//end recordingDb()

	private function linkShare(int $nodeId, string $token, string $password = ''): IShare&MockObject {
		$share = $this->createMock(IShare::class);
		$share->method('getShareType')->willReturn(IShare::TYPE_LINK);
		$share->method('getNode')->willReturn($this->nodes[$nodeId]);
		$share->method('getToken')->willReturn($token);
		$share->method('getPassword')->willReturn($password === '' ? null : $password);
		$share->method('getPermissions')->willReturn(1);
		$share->method('getSharedBy')->willReturn('openregister');
		$share->method('getShareOwner')->willReturn('openregister');
		$share->method('getLabel')->willReturn('Publicatie');
		$share->method('getNote')->willReturn('');
		return $share;
	}//end linkShare()

	private function options(bool $dryRun = false, bool $withoutVersions = false): DocumentStoreMoveOptions {
		return new DocumentStoreMoveOptions(target: 'New store', dryRun: $dryRun, withoutVersions: $withoutVersions);
	}//end options()

	private function copyOf(string $name): ?File {
		$copy = $this->target->get('Woo/obj-1/' . $name);
		return $copy instanceof File ? $copy : null;
	}//end copyOf()
}//end trait
