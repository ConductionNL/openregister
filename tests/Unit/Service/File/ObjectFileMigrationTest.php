<?php

/**
 * Existing object files move into the openregister account, keeping their ids.
 *
 * The file tree is an in-memory tree of paths and ids. A move rewrites the
 * paths under the moved node and keeps every id, which is what a rename on
 * local storage does.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/object-files-follow-object-access/specs/file-actions/spec.md#requirement-existing-files-move-into-openregisters-own-account-req-ofoa-004
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\ObjectFileMigration;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Encryption\IManager as IEncryptionManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Merge, suffix, keep ids, refuse on other storage.
 */
class ObjectFileMigrationTest extends TestCase {

	/**
	 * The tree: path => ['dir' => bool, 'id' => int].
	 *
	 * @var array<string, array{dir: bool, id: int}>
	 */
	private array $tree = [];

	private int $nextId = 1000;

	private bool $local = true;

	private bool $encrypted = false;

	private string $quota = 'default';

	/**
	 * @var array<string, string>
	 */
	private array $appConfigValues = [];

	protected function setUp(): void {
		parent::setUp();
		$this->tree = [];
		foreach (['openregister', 'alice', 'bob', 'carol'] as $uid) {
			$this->tree['/' . $uid . '/files'] = ['dir' => true, 'id' => $this->nextId++];
		}
	}//end setUp()

	private function add(string $path, bool $dir, ?int $id = null): int {
		$id = ($id ?? $this->nextId++);
		$this->tree[$path] = ['dir' => $dir, 'id' => $id];
		return $id;
	}//end add()

	private function node(string $path): Node {
		$entry = $this->tree[$path];
		$mock = $this->createMock($entry['dir'] === true ? Folder::class : File::class);
		$mock->method('getPath')->willReturn($path);
		$mock->method('getName')->willReturn(basename($path));
		$mock->method('getId')->willReturn($entry['id']);

		$storage = $this->createMock(IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(fn (): bool => $this->local);
		$mock->method('getStorage')->willReturn($storage);

		$mock->method('move')->willReturnCallback(
			function (string $target) use ($path, $mock): Node {
				if (isset($this->tree[$target]) === true) {
					throw new \RuntimeException('target exists: ' . $target);
				}

				foreach (array_keys($this->tree) as $p) {
					if ($p === $path || str_starts_with($p, $path . '/') === true) {
						$this->tree[$target . substr($p, strlen($path))] = $this->tree[$p];
						unset($this->tree[$p]);
					}
				}

				return $mock;
			}
		);
		$mock->method('delete')->willReturnCallback(
			function () use ($path): void {
				unset($this->tree[$path]);
			}
		);

		if ($entry['dir'] === true) {
			$mock->method('getDirectoryListing')->willReturnCallback(
				function () use ($path): array {
					$children = [];
					foreach (array_keys($this->tree) as $p) {
						if (dirname($p) === $path) {
							$children[] = $this->node($p);
						}
					}

					return $children;
				}
			);
			$mock->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($this->tree[$path . '/' . $name]));
			$mock->method('get')->willReturnCallback(
				function (string $name) use ($path): Node {
					if (isset($this->tree[$path . '/' . $name]) === false) {
						throw new NotFoundException($name);
					}

					return $this->node($path . '/' . $name);
				}
			);
			$mock->method('newFolder')->willReturnCallback(
				function (string $name) use ($path): Node {
					$this->add($path . '/' . $name, true);
					return $this->node($path . '/' . $name);
				}
			);
		}//end if

		return $mock;
	}//end node()

	private function migration(): ObjectFileMigration {
		$users = [];
		foreach (['openregister', 'alice', 'bob', 'carol'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$user->method('setQuota')->willReturnCallback(function (string $quota): void {
				$this->quota = $quota;
			});
			$users[$uid] = $user;
		}

		$root = $this->createMock(IRootFolder::class);
		$root->method('getUserFolder')->willReturnCallback(fn (string $uid): Node => $this->node('/' . $uid . '/files'));

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			function (\Closure $callback) use ($users): void {
				foreach ($users as $user) {
					$callback($user);
				}
			}
		);

		$ownership = $this->createMock(FileOwnershipHandler::class);
		$ownership->method('getUser')->willReturn($users['openregister']);

		$expr = $this->createMock(IExpressionBuilder::class);
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['update', 'set', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('executeStatement')->willReturn(0);
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->appConfigValues[$key] = $value;
				return true;
			}
		);

		$encryption = $this->createMock(IEncryptionManager::class);
		$encryption->method('isEnabled')->willReturnCallback(fn (): bool => $this->encrypted);

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(fn (): string => $this->quota);

		return new ObjectFileMigration($root, $userManager, $ownership, $db, $appConfig, $encryption, new NullLogger(), $config);
	}//end migration()

	public function testAnIntakesAttachmentsMoveWithTheirIds(): void {
		$this->add('/alice/files/Open Registers', true);
		$registerId = $this->add('/alice/files/Open Registers/OpenConnector Register', true);
		$folderId = $this->add('/alice/files/Open Registers/OpenConnector Register/a184fccf', true);
		$photo = $this->add('/alice/files/Open Registers/OpenConnector Register/a184fccf/foto.jpg', false);
		$pdf = $this->add('/alice/files/Open Registers/OpenConnector Register/a184fccf/aanvraag.pdf', false);

		$tally = $this->migration()->run();

		$base = '/openregister/files/Open Registers/OpenConnector Register';
		$this->assertSame($registerId, $this->tree[$base]['id']);
		$this->assertSame($folderId, $this->tree[$base . '/a184fccf']['id']);
		$this->assertSame($photo, $this->tree[$base . '/a184fccf/foto.jpg']['id']);
		$this->assertSame($pdf, $this->tree[$base . '/a184fccf/aanvraag.pdf']['id']);
		$this->assertArrayNotHasKey('/alice/files/Open Registers', $this->tree);
		$this->assertSame(2, $tally['filesMoved']);
		$this->assertFalse($tally['refused']);
	}//end testAnIntakesAttachmentsMoveWithTheirIds()

	public function testTwoHomesForOneRegisterMergeAndAClashGetsASuffix(): void {
		$this->add('/alice/files/Open Registers', true);
		$this->add('/alice/files/Open Registers/zaken', true);
		$this->add('/alice/files/Open Registers/zaken/o1', true);
		$aliceReport = $this->add('/alice/files/Open Registers/zaken/o1/report.pdf', false);
		$this->add('/bob/files/Open Registers', true);
		$this->add('/bob/files/Open Registers/zaken', true);
		$this->add('/bob/files/Open Registers/zaken/o1', true);
		$bobReport = $this->add('/bob/files/Open Registers/zaken/o1/report.pdf', false);
		$this->add('/bob/files/Open Registers/zaken/o2', true);
		$bobOther = $this->add('/bob/files/Open Registers/zaken/o2/brief.odt', false);

		$tally = $this->migration()->run();

		$base = '/openregister/files/Open Registers/zaken';
		$this->assertSame($aliceReport, $this->tree[$base . '/o1/report.pdf']['id']);
		$this->assertSame($bobReport, $this->tree[$base . '/o1/report (1).pdf']['id']);
		$this->assertSame($bobOther, $this->tree[$base . '/o2/brief.odt']['id']);
		$this->assertArrayNotHasKey('/bob/files/Open Registers', $this->tree);
		$this->assertSame(0, $tally['foldersLeft']);
	}//end testTwoHomesForOneRegisterMergeAndAClashGetsASuffix()

	public function testOtherStorageRefusesAndLeavesTheFiles(): void {
		$this->local = false;
		$this->add('/alice/files/Open Registers', true);
		$this->add('/alice/files/Open Registers/zaken', true);
		$this->add('/alice/files/Open Registers/zaken/o1', true);
		$file = $this->add('/alice/files/Open Registers/zaken/o1/report.pdf', false);

		$tally = $this->migration()->run();

		$this->assertTrue($tally['refused']);
		$this->assertSame($file, $this->tree['/alice/files/Open Registers/zaken/o1/report.pdf']['id']);
		$this->assertArrayNotHasKey('/openregister/files/Open Registers', $this->tree);
		$this->assertSame(ObjectFileMigration::REFUSAL_MESSAGE, $this->appConfigValues[ObjectFileMigration::REFUSAL_KEY]);
	}//end testOtherStorageRefusesAndLeavesTheFiles()

	public function testEncryptionRefusesAndLeavesTheFiles(): void {
		$this->encrypted = true;
		$this->add('/alice/files/Open Registers', true);
		$this->add('/alice/files/Open Registers/zaken', true);

		$tally = $this->migration()->run();

		$this->assertTrue($tally['refused']);
		$this->assertArrayHasKey('/alice/files/Open Registers/zaken', $this->tree);
	}//end testEncryptionRefusesAndLeavesTheFiles()

	public function testTheAccountGetsAnUnlimitedQuotaUnlessAnAdminSetOne(): void {
		$this->migration()->run();
		$this->assertSame('none', $this->quota);

		$this->quota = '5 GB';
		$this->migration()->run();
		$this->assertSame('5 GB', $this->quota);
	}//end testTheAccountGetsAnUnlimitedQuotaUnlessAnAdminSetOne()

	public function testASecondRunMovesOnlyWhatIsLeft(): void {
		$this->add('/alice/files/Open Registers', true);
		$this->add('/alice/files/Open Registers/zaken', true);
		$this->add('/alice/files/Open Registers/zaken/o1', true);
		$this->add('/alice/files/Open Registers/zaken/o1/report.pdf', false);

		$this->migration()->run();
		$second = $this->migration()->run();

		$this->assertSame(0, $second['filesMoved']);
		$this->assertSame(0, $second['foldersMoved']);
	}//end testASecondRunMovesOnlyWhatIsLeft()
}//end class
