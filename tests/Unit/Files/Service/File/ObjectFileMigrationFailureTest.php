<?php

/**
 * The failure paths of ObjectFileMigration: a home that cannot be opened, a
 * person's tree on other storage, an account whose quota cannot be read, a
 * folder that cannot be listed, a file or folder that cannot be moved and a
 * storage that cannot say whether it is local.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Files\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Files\Service\File;

use OCA\OpenRegister\Files\Service\File\FileOwnershipHandlerInterface;
use OCA\OpenRegister\Files\Service\File\ObjectFileMigration;
use OCA\OpenRegister\Files\Service\File\ObjectFileMigrationFiles;
use OCA\OpenRegister\Files\Service\File\ObjectFileMigrationSettings;
use OCA\OpenRegister\Files\Service\File\ObjectFileShareReowner;
use OCA\OpenRegister\Service\File\DocumentStore;
use OCP\Encryption\IManager as IEncryptionManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[CoversClass(ObjectFileMigration::class)]
#[UsesClass(ObjectFileMigrationFiles::class)]
#[UsesClass(ObjectFileMigrationSettings::class)]
#[UsesClass(DocumentStore::class)]
class ObjectFileMigrationFailureTest extends TestCase {

	/** @var IRootFolder&MockObject */
	private IRootFolder $root;

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var IConfig&MockObject */
	private IConfig $config;

	/** @var ObjectFileShareReowner&MockObject */
	private ObjectFileShareReowner $reowner;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var Folder&MockObject */
	private Folder $systemHome;

	/**
	 * Warnings logged, in order.
	 *
	 * @var string[]
	 */
	private array $warnings = [];

	protected function setUp(): void {
		$this->root      = $this->createMock(IRootFolder::class);
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $default
		);
		$this->config  = $this->createMock(IConfig::class);
		$this->reowner = $this->createMock(ObjectFileShareReowner::class);
		$this->logger  = $this->createMock(LoggerInterface::class);
		$this->logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$this->systemHome = $this->folder(path: '/openregister/files');
	}//end setUp()

	/**
	 * A storage that is local or not.
	 *
	 * @param bool $local Whether it is local.
	 *
	 * @return IStorage&MockObject
	 */
	private function storage(bool $local): IStorage {
		$storage = $this->createMock(IStorage::class);
		$storage->method('isLocal')->willReturn($local);

		return $storage;
	}//end storage()

	/**
	 * A folder on local storage at a path, holding no children unless told.
	 *
	 * @param string $path  The path.
	 * @param bool   $local Whether its storage is local.
	 *
	 * @return Folder&MockObject
	 */
	private function folder(string $path, bool $local = true): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getName')->willReturn(basename($path));
		$folder->method('getStorage')->willReturn($this->storage(local: $local));

		return $folder;
	}//end folder()

	/**
	 * A person's home holding the managed tree.
	 *
	 * @param Folder $tree The managed tree.
	 *
	 * @return Folder&MockObject
	 */
	private function homeWith(Folder $tree): Folder {
		$home = $this->folder(path: '/alice/files');
		$home->method('nodeExists')->with(DocumentStore::DEFAULT_ROOT)->willReturn(true);
		$home->method('get')->with(DocumentStore::DEFAULT_ROOT)->willReturn($tree);

		return $home;
	}//end homeWith()

	/**
	 * The migration over the openregister account and alice, whose home is given.
	 *
	 * @param callable   $aliceHome Returns alice's home, or throws.
	 * @param IUser|null $account   The openregister account.
	 *
	 * @return ObjectFileMigration
	 */
	private function migration(callable $aliceHome, ?IUser $account = null): ObjectFileMigration {
		if ($account === null) {
			$account = $this->createMock(IUser::class);
			$account->method('getUID')->willReturn('openregister');
		}

		$alice = $this->createMock(IUser::class);
		$alice->method('getUID')->willReturn('alice');

		$this->root->method('getUserFolder')->willReturnCallback(
			function (string $uid) use ($aliceHome): Folder {
				if ($uid === 'openregister') {
					return $this->systemHome;
				}

				return $aliceHome();
			}
		);

		$users = $this->createMock(IUserManager::class);
		$users->method('callForSeenUsers')->willReturnCallback(
			static function (\Closure $callback) use ($account, $alice): void {
				$callback($account);
				$callback($alice);
			}
		);

		$ownership = $this->createMock(FileOwnershipHandlerInterface::class);
		$ownership->method('getUser')->willReturn($account);

		$encryption = $this->createMock(IEncryptionManager::class);
		$encryption->method('isEnabled')->willReturn(false);

		return new ObjectFileMigration(
			new ObjectFileMigrationFiles($this->root, $users, $ownership, $this->reowner),
			new ObjectFileMigrationSettings($this->appConfig, $encryption, $this->config),
			$this->logger
		);
	}//end migration()

	/**
	 * The tally of a run that moved no folder.
	 *
	 * @param int  $foldersLeft Folders left behind.
	 * @param int  $filesMoved  Files moved.
	 * @param bool $refused     Whether the run was refused.
	 *
	 * @return array<string,int|bool>
	 */
	private static function tally(int $foldersLeft = 0, int $filesMoved = 0, bool $refused = false): array {
		return ['foldersMoved' => 0, 'filesMoved' => $filesMoved, 'sharesReowned' => 0, 'foldersLeft' => $foldersLeft, 'refused' => $refused];
	}//end tally()

	public function testAHomeThatCannotBeOpenedIsSkippedAndTheRunIsClear(): void {
		$this->appConfig->expects($this->once())->method('deleteKey')->with('openregister', ObjectFileMigration::REFUSAL_KEY);
		$this->appConfig->expects($this->never())->method('setValueString');

		$result = $this->migration(aliceHome: static fn () => throw new NotFoundException('no home'))->run();

		$this->assertSame(self::tally(), $result);
		$this->assertSame([], $this->warnings);
	}//end testAHomeThatCannotBeOpenedIsSkippedAndTheRunIsClear()

	public function testATreeOnOtherStorageIsLeftAndTheRunIsRefused(): void {
		$tree = $this->folder(path: '/alice/files/Open Registers', local: false);
		$tree->expects($this->never())->method('getDirectoryListing');
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('openregister', ObjectFileMigration::REFUSAL_KEY, ObjectFileMigration::REFUSAL_MESSAGE);
		$this->appConfig->expects($this->never())->method('deleteKey');

		$result = $this->migration(aliceHome: fn () => $this->homeWith(tree: $tree))->run();

		$this->assertSame(self::tally(foldersLeft: 1, refused: true), $result);
		$this->assertSame(
			['[ObjectFileMigration] ' . ObjectFileMigration::REFUSAL_MESSAGE . ' Reason: the home of alice is not on local storage'],
			$this->warnings
		);
	}//end testATreeOnOtherStorageIsLeftAndTheRunIsRefused()

	public function testAQuotaThatCannotBeReadIsLoggedAndTheRunGoesOn(): void {
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn('openregister');
		$account->expects($this->never())->method('setQuota');
		$this->config->method('getUserValue')->willThrowException(new RuntimeException('config table locked'));
		$this->appConfig->expects($this->once())->method('deleteKey');

		$result = $this->migration(aliceHome: static fn () => throw new NotFoundException('no home'), account: $account)->run();

		$this->assertSame(self::tally(), $result);
		$this->assertSame(['[ObjectFileMigration] Could not set the openregister quota: config table locked'], $this->warnings);
	}//end testAQuotaThatCannotBeReadIsLoggedAndTheRunGoesOn()

	public function testAStorageThatCannotSayWhetherItIsLocalRefusesTheRun(): void {
		$home = $this->createMock(Folder::class);
		$home->method('getStorage')->willThrowException(new RuntimeException('storage gone'));
		$this->systemHome = $home;
		$this->appConfig->expects($this->once())->method('setValueString')
			->with('openregister', ObjectFileMigration::REFUSAL_KEY, ObjectFileMigration::REFUSAL_MESSAGE);

		$result = $this->migration(aliceHome: fn () => $this->fail('no person is visited after a refusal'))->run();

		$this->assertSame(self::tally(refused: true), $result);
		$this->assertSame(
			['[ObjectFileMigration] ' . ObjectFileMigration::REFUSAL_MESSAGE . ' Reason: the openregister home is not on local storage'],
			$this->warnings
		);
	}//end testAStorageThatCannotSayWhetherItIsLocalRefusesTheRun()

	public function testATreeThatCannotBeListedIsLeftUnderAnUnknownPathAndKept(): void {
		$tree = $this->createMock(Folder::class);
		$tree->method('getStorage')->willReturn($this->storage(local: true));
		$tree->method('getDirectoryListing')->willThrowException(new RuntimeException('listing failed'));
		$tree->method('getPath')->willThrowException(new NotFoundException('gone'));
		$tree->expects($this->never())->method('delete');
		$target = $this->folder(path: '/openregister/files/Open Registers');
		$this->systemHome->method('nodeExists')->with(DocumentStore::DEFAULT_ROOT)->willReturn(true);
		$this->systemHome->method('get')->with(DocumentStore::DEFAULT_ROOT)->willReturn($target);
		$this->logger->expects($this->once())->method('info')
			->with('[ObjectFileMigration] Kept an emptied folder: listing failed', $this->anything());

		$result = $this->migration(aliceHome: fn () => $this->homeWith(tree: $tree))->run();

		$this->assertSame(self::tally(foldersLeft: 1), $result);
		$this->assertSame(['[ObjectFileMigration] Left (unknown path) where it is: listing failed'], $this->warnings);
	}//end testATreeThatCannotBeListedIsLeftUnderAnUnknownPathAndKept()

	public function testAFileThatCannotMoveAndAFolderThatCannotBeCheckedAreLeftWhileAFreeNameMoves(): void {
		$stuck = $this->createMock(File::class);
		$stuck->method('getName')->willReturn('stuck.pdf');
		$stuck->method('getId')->willReturn(71);
		$stuck->method('getPath')->willReturn('/alice/files/Open Registers/stuck.pdf');
		$stuck->method('move')->willThrowException(new RuntimeException('locked'));

		$free = $this->createMock(File::class);
		$free->method('getName')->willReturn('free.pdf');
		$free->method('getId')->willReturn(72);
		$free->expects($this->once())->method('move')->with('/openregister/files/Open Registers/free.pdf');

		$sub = $this->folder(path: '/alice/files/Open Registers/Zaken');

		$tree = $this->folder(path: '/alice/files/Open Registers');
		$tree->method('getDirectoryListing')->willReturnOnConsecutiveCalls([$stuck, $sub, $free], [$stuck]);
		$tree->expects($this->never())->method('delete');

		$target = $this->folder(path: '/openregister/files/Open Registers');
		$target->method('nodeExists')->willReturnCallback(
			static function (string $name): bool {
				if ($name === 'Zaken') {
					throw new RuntimeException('cache broken');
				}

				return false;
			}
		);
		$this->systemHome->method('nodeExists')->with(DocumentStore::DEFAULT_ROOT)->willReturn(true);
		$this->systemHome->method('get')->with(DocumentStore::DEFAULT_ROOT)->willReturn($target);
		$this->reowner->expects($this->once())->method('reown')->with([72], 'openregister')->willReturn(0);

		$result = $this->migration(aliceHome: fn () => $this->homeWith(tree: $tree))->run();

		$this->assertSame(self::tally(foldersLeft: 2, filesMoved: 1), $result);
		$this->assertSame(
			[
				'[ObjectFileMigration] Left /alice/files/Open Registers/stuck.pdf where it is: locked',
				'[ObjectFileMigration] Left /alice/files/Open Registers/Zaken where it is: cache broken',
			],
			$this->warnings
		);
	}//end testAFileThatCannotMoveAndAFolderThatCannotBeCheckedAreLeftWhileAFreeNameMoves()

	public function testAnEmptyFolderMovesWholeWithoutReowningShares(): void {
		$empty = $this->folder(path: '/alice/files/Open Registers/Leeg');
		$empty->method('getDirectoryListing')->willReturn([]);
		$empty->expects($this->once())->method('move')->with('/openregister/files/Open Registers/Leeg');

		$tree = $this->folder(path: '/alice/files/Open Registers');
		$tree->method('getDirectoryListing')->willReturnOnConsecutiveCalls([$empty], []);
		$tree->expects($this->once())->method('delete');

		$target = $this->folder(path: '/openregister/files/Open Registers');
		$target->method('nodeExists')->willReturn(false);
		$this->systemHome->method('nodeExists')->willReturn(false);
		$this->systemHome->expects($this->once())->method('newFolder')->with(DocumentStore::DEFAULT_ROOT)->willReturn($target);
		$this->reowner->expects($this->never())->method('reown');

		$result = $this->migration(aliceHome: fn () => $this->homeWith(tree: $tree))->run();

		$this->assertSame(['foldersMoved' => 1, 'filesMoved' => 0, 'sharesReowned' => 0, 'foldersLeft' => 0, 'refused' => false], $result);
		$this->assertSame([], $this->warnings);
	}//end testAnEmptyFolderMovesWholeWithoutReowningShares()
}//end class
