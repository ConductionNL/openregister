<?php

/**
 * Golden record of what the file lock, publication and upload guards allow and refuse.
 *
 * Pins, for FileLockHandler, FilePublishingHandler and FileValidationHandler,
 * which callers and inputs are let through and which are refused, with the
 * refusal message, and that each refusal comes before the operation it guards
 * (no share created, no lock written, no content read).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/file-actions/spec.md
 */

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\FileSession;
use Exception;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuardInterface;
use OCA\OpenRegister\Service\File\FileLockHandler;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCP\Files\File;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\File\FileLockHandler
 * @covers \OCA\OpenRegister\Service\File\FilePublishingHandler
 * @covers \OCA\OpenRegister\Service\File\FileValidationHandler
 */
class FileAccessGuardsGoldenTest extends TestCase {

	/**
	 * The user the session returns; null for none.
	 *
	 * @var string|null
	 */
	private ?string $currentUser = null;

	/**
	 * What the collaborators were asked, in order.
	 *
	 * @var list<string>
	 */
	private array $trace = [];

	// ---- locks --------------------------------------------------------------

	public function testOnlyTheOwnerMayRelockModifyOrUnlock(): void {
		$locks = $this->lockHandler(admins: []);

		$this->currentUser = 'alice';
		$this->assertSame('alice', $locks->lockFile(fileId: 7)['lockedBy']);
		$this->assertSame('alice', $locks->lockFile(fileId: 7, ttlMinutes: 5)['lockedBy']);
		$locks->assertCanModify(fileId: 7);

		$this->currentUser = 'bob';
		$this->assertSame('File is locked by alice', $this->refusal(fn () => $locks->lockFile(fileId: 7)));
		$this->assertSame('File is locked by alice', $this->refusal(fn () => $locks->assertCanModify(fileId: 7)));
		$this->assertSame('Only the lock owner or an admin can unlock this file', $this->refusal(fn () => $locks->unlockFile(fileId: 7)));
		$this->assertSame('Only administrators can force-unlock files', $this->refusal(fn () => $locks->unlockFile(fileId: 7, force: true)));
		$this->assertTrue($locks->isLocked(fileId: 7));

		$this->currentUser = 'alice';
		$this->assertSame('Only administrators can force-unlock files', $this->refusal(fn () => $locks->unlockFile(fileId: 7, force: true)));
		$this->assertSame(['locked' => false], $locks->unlockFile(fileId: 7));
		$this->assertFalse($locks->isLocked(fileId: 7));
	}//end testOnlyTheOwnerMayRelockModifyOrUnlock()

	public function testAnAdministratorMayForceUnlockSomeoneElsesLock(): void {
		$locks = $this->lockHandler(admins: ['root']);
		$this->currentUser = 'alice';
		$locks->lockFile(fileId: 9);

		$this->currentUser = 'root';
		$this->assertSame('Only the lock owner or an admin can unlock this file', $this->refusal(fn () => $locks->unlockFile(fileId: 9)));
		$this->assertSame('File is locked by alice', $this->refusal(fn () => $locks->assertCanModify(fileId: 9)));
		$this->assertSame(['locked' => false], $locks->unlockFile(fileId: 9, force: true));
		$this->assertFalse($locks->isLocked(fileId: 9));
	}//end testAnAdministratorMayForceUnlockSomeoneElsesLock()

	public function testAnUnlockedFileIsFreeForAnyoneAndNeedsASession(): void {
		$locks = $this->lockHandler(admins: []);

		$this->currentUser = 'bob';
		$this->assertSame(['locked' => false], $locks->unlockFile(fileId: 3, force: true));
		$locks->assertCanModify(fileId: 3);

		$this->currentUser = null;
		$locks->assertCanModify(fileId: 3);
		$this->assertSame('No user logged in', $this->refusal(fn () => $locks->lockFile(fileId: 3)));
		$this->assertSame('No user logged in', $this->refusal(fn () => $locks->unlockFile(fileId: 3)));
	}//end testAnUnlockedFileIsFreeForAnyoneAndNeedsASession()

	public function testAnExpiredOrMalformedCachedLockNoLongerGuards(): void {
		$entries = [
			'openregister:file-lock:1' => ['lockedBy' => 'alice', 'lockedAt' => '2000-01-01T00:00:00+00:00', 'expiresAt' => '2000-01-01T00:30:00+00:00'],
			'openregister:file-lock:2' => ['lockedBy' => 'alice', 'lockedAt' => 'x', 'expiresAt' => 'not a date'],
			'openregister:file-lock:3' => ['lockedBy' => 'alice', 'lockedAt' => '2000-01-01T00:00:00+00:00', 'expiresAt' => '9999-01-01T00:00:00+00:00'],
		];
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key) => ($entries[$key] ?? null));
		$cache->method('remove')->willReturnCallback(function (string $key): bool {
			$this->trace[] = 'remove ' . $key;
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willReturn($cache);
		$locks = new FileLockHandler($factory, new FileSession($this->session(), $this->createMock(IGroupManager::class), $this->createMock(LoggerInterface::class)));

		$this->currentUser = 'bob';
		$locks->assertCanModify(fileId: 1);
		$locks->assertCanModify(fileId: 2);
		$this->assertSame('File is locked by alice', $this->refusal(fn () => $locks->assertCanModify(fileId: 3)));
		$this->assertSame(['remove openregister:file-lock:1', 'remove openregister:file-lock:2'], $this->trace);
		$this->assertSame('alice', $locks->getLockInfo(fileId: 3)['lockedBy']);
	}//end testAnExpiredOrMalformedCachedLockNoLongerGuards()

	// ---- publication --------------------------------------------------------

	public function testAPartyIndicatorRefusesBeforeTheFileIsLookedUp(): void {
		$handler = $this->publishing(indicatorRefuses: true, restricted: false);

		$this->assertSame('403 Publication is refused', $this->refusal(fn () => $handler->publishFile($this->object(), 42), withCode: true));
		$this->assertSame(['indicator case-1'], $this->trace);
	}//end testAPartyIndicatorRefusesBeforeTheFileIsLookedUp()

	public function testARestrictedFileIsRefusedBeforeAShareIsCreated(): void {
		$handler = $this->publishing(indicatorRefuses: false, restricted: true);

		$this->assertSame('A restricted file cannot be published', $this->refusal(fn () => $handler->publishFile($this->object(), 42)));
		$this->assertSame(['indicator case-1', 'getFile 42', 'isRestricted 42'], $this->trace);
	}//end testARestrictedFileIsRefusedBeforeAShareIsCreated()

	public function testAnUnrestrictedFileIsShared(): void {
		$handler = $this->publishing(indicatorRefuses: false, restricted: false);

		$this->assertSame('/files/doc.pdf', $handler->publishFile($this->object(), 42)->getPath());
		$this->assertSame(['indicator case-1', 'getFile 42', 'isRestricted 42', 'share 42 by openregister'], $this->trace);
	}//end testAnUnrestrictedFileIsShared()

	public function testUnpublishingAsksNeitherTheIndicatorNorTheRestriction(): void {
		$handler = $this->publishing(indicatorRefuses: true, restricted: true);

		$this->assertSame('/files/doc.pdf', $handler->unpublishFile($this->object(), 42)->getPath());
		$this->assertSame(['getFile 42', 'depublish 42'], $this->trace);
	}//end testUnpublishingAsksNeitherTheIndicatorNorTheRestriction()

	public function testTheZipHoldsOnlyTheFilesTheRestrictionAdmits(): void {
		$handler = $this->publishing(indicatorRefuses: false, restricted: false);

		$zip = $handler->createObjectFilesZip($this->object(), 'a17-guards-golden.zip');
		$archive = new \ZipArchive();
		$archive->open($zip['path']);
		$names = [];
		for ($i = 0; $i < $archive->numFiles; $i++) {
			$names[] = $archive->getNameIndex($i);
		}

		$archive->close();
		unlink($zip['path']);

		$this->assertSame(['open.txt'], $names);
		$this->assertSame(['getFiles', 'readable open.txt,secret.txt', 'content open.txt'], $this->trace);
	}//end testTheZipHoldsOnlyTheFilesTheRestrictionAdmits()

	// ---- uploads ------------------------------------------------------------

	public function testExecutableUploadsAreRefusedAndOthersAllowed(): void {
		$log = [];
		$logger = new class($log) extends AbstractLogger {
			/**
			 * @param list<string> $log
			 */
			public function __construct(private array &$log) {
			}

			public function log($level, string|\Stringable $message, array $context = []): void {
				unset($context['file'], $context['line']);
				$this->log[] = $level . ': ' . $message . ' ' . json_encode($context);
			}
		};
		$validation = new FileValidationHandler($logger);

		$this->assertSame(
			"File 'run.EXE' is an executable file (.exe). Executable files are blocked for security reasons. Allowed formats: documents, images, archives, data files.",
			$this->refusal(fn () => $validation->blockExecutableFile('run.EXE', 'MZ'))
		);
		$this->assertSame(
			"File 'a.pdf' contains executable code (Windows executable (PE/EXE)). Executable files are blocked for security.",
			$this->refusal(fn () => $validation->blockExecutableFile('a.pdf', "MZ\x90\x00"))
		);
		$this->assertSame(
			"File 'a.txt' contains script shebang. Script files are blocked for security reasons.",
			$this->refusal(fn () => $validation->blockExecutableFile('a.txt', "notes\n#!/usr/bin/python\nprint(1)"))
		);
		$this->assertSame(
			"File 'a.txt' contains PHP code. PHP files are blocked for security reasons.",
			$this->refusal(fn () => $validation->blockExecutableFile('a.txt', 'hello <?php echo 1; ?>'))
		);
		$validation->blockExecutableFile('notes.txt', 'plain text');
		$validation->blockExecutableFile('empty.sh.txt', '');

		$this->assertSame(
			[
				'warning: [FileValidationHandler] Executable file upload blocked {"app":"openregister","filename":"run.EXE","extension":"exe"}',
				'warning: [FileValidationHandler] Executable magic bytes detected {"app":"openregister","filename":"a.pdf","type":"Windows executable (PE\/EXE)"}',
			],
			$log
		);
	}//end testExecutableUploadsAreRefusedAndOthersAllowed()

	// ---- helpers ------------------------------------------------------------

	private function refusal(callable $act, bool $withCode=false): string {
		try {
			$act();
		} catch (Exception $e) {
			if ($withCode === true) {
				return $e->getCode() . ' ' . $e->getMessage();
			}

			return $e->getMessage();
		}

		return 'allowed';
	}//end refusal()

	private function session(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(function (): ?IUser {
			if ($this->currentUser === null) {
				return null;
			}

			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($this->currentUser);
			return $user;
		});
		return $session;
	}//end session()

	/**
	 * @param list<string> $admins
	 */
	private function lockHandler(array $admins): FileLockHandler {
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('createDistributed')->willThrowException(new \RuntimeException('no cache'));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturnCallback(fn (string $uid): bool => in_array($uid, $admins, true));

		return new FileLockHandler($factory, new FileSession($this->session(), $groups, $this->createMock(LoggerInterface::class)));
	}//end lockHandler()

	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('case-1');
		return $object;
	}//end object()

	private function publishing(bool $indicatorRefuses, bool $restricted): FilePublishingHandler {
		$guard = $this->createMock(PartyIndicatorGuard::class);
		$guard->method('assertPublicationAllowed')->willReturnCallback(function (string $objectUuid) use ($indicatorRefuses): void {
			$this->trace[] = 'indicator ' . $objectUuid;
			if ($indicatorRefuses === true) {
				throw new Exception('Publication is refused', 403);
			}
		});
		$restrictions = $this->createMock(FileRestrictionGuard::class);
		$restrictions->method('isRestricted')->willReturnCallback(function (int $fileId) use ($restricted): bool {
			$this->trace[] = 'isRestricted ' . $fileId;
			return $restricted;
		});
		$restrictions->method('readableNodes')->willReturnCallback(function (array $nodes): array {
			$this->trace[] = 'readable ' . implode(',', array_map(fn ($node) => $node->getName(), $nodes));
			return array_values(array_filter($nodes, fn ($node): bool => $node->getName() !== 'secret.txt'));
		});
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === FileRestrictionGuardInterface::class ? $restrictions : $guard);

		$handler = new FilePublishingHandler($this->createMock(MagicMapper::class), $this->shareMapper(), $this->createMock(LoggerInterface::class), $container);
		$handler->setFileService($this->fileService());
		return $handler;
	}//end publishing()

	private function shareMapper(): FileMapper {
		$mapper = $this->createMock(FileMapper::class);
		$mapper->method('publishFile')->willReturnCallback(function (int $fileId, string $sharedBy): array {
			$this->trace[] = 'share ' . $fileId . ' by ' . $sharedBy;
			return ['id' => 1, 'token' => 't', 'accessUrl' => 'https://x/s/t'];
		});
		$mapper->method('depublishFile')->willReturnCallback(function (int $fileId): array {
			$this->trace[] = 'depublish ' . $fileId;
			return ['deleted_shares' => 1, 'file_id' => $fileId];
		});
		return $mapper;
	}//end shareMapper()

	private function fileService(): FileService {
		$doc = $this->file(name: 'doc.pdf', id: 42);
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFile')->willReturnCallback(function ($object, $file) use ($doc): File {
			$this->trace[] = 'getFile ' . $file;
			return $doc;
		});
		$fileService->method('getFiles')->willReturnCallback(function (): array {
			$this->trace[] = 'getFiles';
			return [$this->file(name: 'open.txt', id: 1), $this->file(name: 'secret.txt', id: 2)];
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('openregister');
		$fileService->method('getUser')->willReturn($user);
		return $fileService;
	}//end fileService()

	private function file(string $name, int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		$file->method('getPath')->willReturn('/files/' . $name);
		$file->method('getContent')->willReturnCallback(function () use ($name): string {
			$this->trace[] = 'content ' . $name;
			return 'bytes of ' . $name;
		});
		return $file;
	}//end file()
}//end class
