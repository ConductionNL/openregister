<?php

/**
 * Uploads in parts: stated boundaries, exact part lengths, parts kept out of
 * the object's folder, and a completion that fails closed.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-a-large-file-uploads-in-parts-with-stated-boundaries-req-lfh-002
 * @spec openspec/changes/large-file-handling/specs/file-actions/spec.md#requirement-an-interrupted-upload-resumes-and-states-its-completeness-req-lfh-003
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use DateTime;
use DateTimeImmutable;
use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\UploadSession;
use OCA\OpenRegister\Db\UploadSessionMapper;
use OCA\OpenRegister\Files\Controller\Support\FileActionRefusal;
use OCA\OpenRegister\Service\File\ChunkedUploadService;
use OCA\OpenRegister\Service\File\FileTarget;
use OCA\OpenRegister\Service\File\FileUpload;
use OCA\OpenRegister\Service\FileWriterInterface;
use OCA\OpenRegister\Service\Settings\FileSettingsHandler;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\File;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\File\ChunkedUploadService
 * @covers \OCA\OpenRegister\Db\UploadSession
 * @uses   \OCA\OpenRegister\Service\Settings\FileSettingsHandler
 * @uses   \OCA\OpenRegister\Service\File\FileTarget
 * @uses   \OCA\OpenRegister\Service\File\FileUpload
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class ChunkedUploadServiceTest extends TestCase {

	/**
	 * The stored parts, by folder path and file name.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $store = [];

	/**
	 * The folders deleted.
	 *
	 * @var list<string>
	 */
	private array $deletedFolders = [];

	/**
	 * The sessions table.
	 *
	 * @var UploadSessionMapper&MockObject
	 */
	private UploadSessionMapper&MockObject $sessions;

	/**
	 * The file writer the object's files go through.
	 *
	 * @var FileWriterInterface&MockObject
	 */
	private FileWriterInterface&MockObject $files;

	/**
	 * The stored file settings.
	 *
	 * @var array<string, mixed>
	 */
	private array $fileSettings = [];

	protected function setUp(): void {
		parent::setUp();
		$this->sessions = $this->createMock(UploadSessionMapper::class);
		$this->files    = $this->createMock(FileWriterInterface::class);
	}//end setUp()

	/**
	 * The service over an in-memory app data store, the stored settings and a clock at 2026-11-14 12:00 UTC.
	 *
	 * @return ChunkedUploadService
	 */
	private function service(): ChunkedUploadService {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($key === 'fileManagement' && $this->fileSettings !== [] ? (string) json_encode($this->fileSettings) : $default)
		);

		$appData = $this->createMock(IAppData::class);
		$appData->method('getFolder')->willReturnCallback(
			function (string $path): ISimpleFolder {
				if (array_key_exists($path, $this->store) === false) {
					throw new NotFoundException($path);
				}

				return $this->folder(path: $path);
			}
		);
		$appData->method('newFolder')->willReturnCallback(
			function (string $path): ISimpleFolder {
				$this->store[$path] = [];
				return $this->folder(path: $path);
			}
		);
		$factory = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('openregister')->willReturn($appData);

		$clock = $this->createMock(ITimeFactory::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-14T12:00:00+00:00'));

		return new ChunkedUploadService(
			sessions: $this->sessions,
			appDataFactory: $factory,
			settings: new FileSettingsHandler(appConfig: $appConfig),
			files: $this->files,
			clock: $clock,
			logger: new NullLogger()
		);
	}//end service()

	/**
	 * A folder of the in-memory store.
	 *
	 * @param string $path The folder path.
	 *
	 * @return ISimpleFolder
	 */
	private function folder(string $path): ISimpleFolder {
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('newFile')->willReturnCallback(
			function (string $name, $content = null) use ($path): ISimpleFile {
				$this->store[$path][$name] = (string) $content;
				return $this->createMock(ISimpleFile::class);
			}
		);
		$folder->method('getFile')->willReturnCallback(
			function (string $name) use ($path): ISimpleFile {
				if (isset($this->store[$path][$name]) === false) {
					throw new NotFoundException($name);
				}

				$stream = fopen('php://memory', 'w+b');
				fwrite($stream, $this->store[$path][$name]);
				rewind($stream);
				$file = $this->createMock(ISimpleFile::class);
				$file->method('read')->willReturn($stream);
				return $file;
			}
		);
		$folder->method('delete')->willReturnCallback(
			function () use ($path): void {
				unset($this->store[$path]);
				$this->deletedFolders[] = $path;
			}
		);

		return $folder;
	}//end folder()

	/**
	 * The object the uploads are for.
	 *
	 * @return ObjectEntity
	 */
	private function object(): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('5f0c0a1e-0000-4000-8000-000000000001');
		return $object;
	}//end object()

	/**
	 * A session of user A over the object.
	 *
	 * @param int    $size     The declared size.
	 * @param int    $partSize The part size.
	 * @param string $sha256   The declared SHA-256.
	 *
	 * @return UploadSession
	 */
	private function session(int $size, int $partSize, string $sha256 = ''): UploadSession {
		$session = new UploadSession();
		$session->setUploadId('0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f');
		$session->setUserId('user-a');
		$session->setObjectUuid('5f0c0a1e-0000-4000-8000-000000000001');
		$session->setName('mail-export.mbox');
		$session->setSize($size);
		$session->setSha256($sha256);
		$session->setPartSize($partSize);
		$session->setReceived('[]');
		$session->setExpiresAt(new DateTime('2026-11-15T12:00:00+00:00'));
		return $session;
	}//end session()

	/**
	 * A 95 MiB file at the default 10 MiB parts states ten parts with their
	 * ranges, the last one shorter, and the session is stored for its user.
	 *
	 * @return void
	 */
	public function testTheSessionStatesItsPartBoundaries(): void {
		$stored = null;
		$this->sessions->expects($this->once())->method('insert')->willReturnCallback(
			function (UploadSession $session) use (&$stored): UploadSession {
				$stored = $session;
				return $session;
			}
		);

		$plan = $this->service()->open(
			object: $this->object(),
			userId: 'user-a',
			declared: ['name' => 'mail-export.mbox', 'size' => 99614720, 'sha256' => str_repeat('AB', 32)]
		);

		$this->assertSame(10485760, $plan['partSize']);
		$this->assertSame(10, $plan['partCount']);
		$this->assertCount(10, $plan['parts']);
		$this->assertSame(['n' => 1, 'start' => 0, 'end' => 10485759], $plan['parts'][0]);
		$this->assertSame(['n' => 10, 'start' => 94371840, 'end' => 99614719], $plan['parts'][9]);
		$this->assertSame('2026-11-15T12:00:00+00:00', $plan['expiresAt']);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $plan['uploadId']);
		$this->assertSame(['user-a', '5f0c0a1e-0000-4000-8000-000000000001', str_repeat('ab', 32)], [$stored->getUserId(), $stored->getObjectUuid(), $stored->getSha256()]);
	}//end testTheSessionStatesItsPartBoundaries()

	/**
	 * A declaration without a name, a positive size or a SHA-256 opens nothing.
	 *
	 * @return void
	 */
	public function testAnIncompleteDeclarationIsRefused(): void {
		$this->sessions->expects($this->never())->method('insert');
		$service = $this->service();
		foreach ([
			[['size' => 5, 'sha256' => str_repeat('a', 64)], 'A file name without a slash is required'],
			[['name' => 'a/b', 'size' => 5, 'sha256' => str_repeat('a', 64)], 'A file name without a slash is required'],
			[['name' => 'a', 'size' => 0, 'sha256' => str_repeat('a', 64)], 'size must be a positive whole number of bytes'],
			[['name' => 'a', 'size' => 5, 'sha256' => 'abc'], 'sha256 must be the 64 hex digit SHA-256 of the file'],
		] as [$declared, $message]) {
			try {
				$service->open(object: $this->object(), userId: 'user-a', declared: $declared);
				$this->fail('Opened a session for ' . json_encode($declared));
			} catch (FileActionRefusal $e) {
				$this->assertSame([400, $message], [$e->getCode(), $e->getMessage()]);
			}
		}
	}//end testAnIncompleteDeclarationIsRefused()

	/**
	 * The part size is the organisation's setting.
	 *
	 * @return void
	 */
	public function testThePartSizeComesFromTheSetting(): void {
		$this->fileSettings = ['upload' => ['partSizeBytes' => 1048576, 'sessionTtl' => 'PT2H']];

		$plan = $this->service()->open(
			object: $this->object(),
			userId: 'user-a',
			declared: ['name' => 'scan.pdf', 'size' => 3000000, 'sha256' => str_repeat('a', 64)]
		);

		$this->assertSame([1048576, 3, '2026-11-14T14:00:00+00:00'], [$plan['partSize'], $plan['partCount'], $plan['expiresAt']]);
	}//end testThePartSizeComesFromTheSetting()

	/**
	 * A part 3 of 9000000 bytes where 10485760 are due is refused naming the length, and nothing is stored.
	 *
	 * @return void
	 */
	public function testAPartOfTheWrongLengthIsRefused(): void {
		$this->sessions->expects($this->never())->method('update');
		$session = $this->session(size: 99614720, partSize: 10485760);

		try {
			$this->service()->storePart(session: $session, n: 3, bytes: str_repeat('x', 9000000));
			$this->fail('A part of the wrong length was stored');
		} catch (FileActionRefusal $e) {
			$this->assertSame([400, 'Part 3 must be 10485760 bytes, received 9000000'], [$e->getCode(), $e->getMessage()]);
		}

		$this->assertSame([], $this->store);
		$this->assertSame([], $session->receivedParts());
	}//end testAPartOfTheWrongLengthIsRefused()

	/**
	 * A part number outside the plan is refused.
	 *
	 * @return void
	 */
	public function testAPartOutsideThePlanIsRefused(): void {
		$this->expectExceptionObject(new FileActionRefusal(message: 'Part 4 is not in this upload; it has parts 1 to 3', code: 400));

		$this->service()->storePart(session: $this->session(size: 25, partSize: 10), n: 4, bytes: 'xxxxx');
	}//end testAPartOutsideThePlanIsRefused()

	/**
	 * Parts go to the app data folder of the session, never through the object's file writer,
	 * and each part moves the expiry on.
	 *
	 * @return void
	 */
	public function testPartsAreNotWrittenIntoTheObjectFolder(): void {
		$this->files->expects($this->never())->method('addFile');
		$this->sessions->expects($this->exactly(2))->method('update');
		$session = $this->session(size: 25, partSize: 10);
		$session->setExpiresAt(new DateTime('2026-11-14T13:00:00+00:00'));
		$service = $this->service();

		$service->storePart(session: $session, n: 3, bytes: 'ccccc');
		$status = $service->storePart(session: $session, n: 1, bytes: 'aaaaaaaaaa');

		$this->assertSame(
			['uploads/0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f' => ['part-000003' => 'ccccc', 'part-000001' => 'aaaaaaaaaa']],
			$this->store
		);
		$this->assertSame([[1, 3], [2], false], [$status['received'], $status['missing'], $status['complete']]);
		$this->assertSame('2026-11-15T12:00:00+00:00', $status['expiresAt']);
	}//end testPartsAreNotWrittenIntoTheObjectFolder()

	/**
	 * Receiving parts in any order assembles the declared bytes, the file is
	 * created on the object with the declared name, and the parts and session go.
	 *
	 * @return void
	 */
	public function testCompletionCreatesTheFileWithTheDeclaredChecksum(): void {
		$bytes   = 'aaaaaaaaaabbbbbbbbbbccccc';
		$session = $this->session(size: 25, partSize: 10, sha256: hash('sha256', $bytes));
		$service = $this->service();
		$service->storePart(session: $session, n: 2, bytes: 'bbbbbbbbbb');
		$service->storePart(session: $session, n: 3, bytes: 'ccccc');
		$service->storePart(session: $session, n: 1, bytes: 'aaaaaaaaaa');

		$created = $this->createMock(File::class);
		$written = null;
		$this->files->expects($this->once())->method('addFile')->willReturnCallback(
			function (FileTarget $target, FileUpload $upload) use (&$written, $created): File {
				$written = [$target->object->getUuid(), $upload->fileName, stream_get_contents($upload->content), $upload->share];
				return $created;
			}
		);
		$this->sessions->expects($this->once())->method('delete')->with($session);

		$this->assertSame($created, $service->complete(session: $session, object: $this->object()));
		$this->assertSame(['5f0c0a1e-0000-4000-8000-000000000001', 'mail-export.mbox', $bytes, false], $written);
		$this->assertSame(['uploads/0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f'], $this->deletedFolders);
		$this->assertSame([], $this->store);
	}//end testCompletionCreatesTheFileWithTheDeclaredChecksum()

	/**
	 * One part altered in transit: 409 naming the checksum, no file, parts kept.
	 *
	 * @return void
	 */
	public function testAChecksumMismatchCreatesNoFile(): void {
		$session = $this->session(size: 20, partSize: 10, sha256: hash('sha256', 'aaaaaaaaaabbbbbbbbbb'));
		$service = $this->service();
		$service->storePart(session: $session, n: 1, bytes: 'aaaaaaaaaa');
		$service->storePart(session: $session, n: 2, bytes: 'bbbbbbbbbX');
		$this->files->expects($this->never())->method('addFile');
		$this->sessions->expects($this->never())->method('delete');

		try {
			$service->complete(session: $session, object: $this->object());
			$this->fail('A mismatched upload was completed');
		} catch (FileActionRefusal $e) {
			$this->assertSame([409, 'Checksum mismatch: the assembled bytes do not have the declared SHA-256'], [$e->getCode(), $e->getMessage()]);
		}

		$this->assertCount(2, $this->store['uploads/0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f']);
	}//end testAChecksumMismatchCreatesNoFile()

	/**
	 * Completing with parts outstanding names them, and creates nothing.
	 *
	 * @return void
	 */
	public function testAMissingPartIsNamed(): void {
		$session = $this->session(size: 45, partSize: 10, sha256: str_repeat('a', 64));
		$service = $this->service();
		$service->storePart(session: $session, n: 1, bytes: 'aaaaaaaaaa');
		$service->storePart(session: $session, n: 3, bytes: 'cccccccccc');
		$this->files->expects($this->never())->method('addFile');

		$status = $service->status(session: $session);
		$this->assertSame([[1, 3], [2, 4, 5], false], [$status['received'], $status['missing'], $status['complete']]);

		$this->expectExceptionObject(new FileActionRefusal(message: 'The upload is not complete; missing parts: 2, 4, 5', code: 409));
		$service->complete(session: $session, object: $this->object());
	}//end testAMissingPartIsNamed()

	/**
	 * A part recorded as received but gone from the store is named too.
	 *
	 * @return void
	 */
	public function testAPartGoneFromTheStoreIsNamed(): void {
		$session = $this->session(size: 20, partSize: 10, sha256: str_repeat('a', 64));
		$service = $this->service();
		$service->storePart(session: $session, n: 1, bytes: 'aaaaaaaaaa');
		$session->markReceived(n: 2);
		$this->files->expects($this->never())->method('addFile');

		$this->expectExceptionObject(new FileActionRefusal(message: 'The upload is not complete; missing parts: 2', code: 409));
		$service->complete(session: $session, object: $this->object());
	}//end testAPartGoneFromTheStoreIsNamed()

	/**
	 * The shared upload checks refuse the bytes: no file, the refusal surfaces, parts and session kept.
	 *
	 * @return void
	 */
	public function testARefusedUploadCheckCreatesNoFile(): void {
		$bytes   = "MZ\x90\x00executable";
		$session = $this->session(size: strlen($bytes), partSize: 1048576, sha256: hash('sha256', $bytes));
		$service = $this->service();
		$service->storePart(session: $session, n: 1, bytes: $bytes);
		$this->files->method('addFile')->willThrowException(new Exception('File mail-export.mbox contains executable code (Windows executable (PE/EXE))'));
		$this->sessions->expects($this->never())->method('delete');

		try {
			$service->complete(session: $session, object: $this->object());
			$this->fail('A refused upload was completed');
		} catch (FileActionRefusal $e) {
			$this->fail('The refusal of the upload check was replaced: ' . $e->getMessage());
		} catch (Exception $e) {
			$this->assertStringContainsString('contains executable code', $e->getMessage());
		}

		$this->assertSame([], $this->deletedFolders);
		$this->assertArrayHasKey('uploads/0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f', $this->store);
	}//end testARefusedUploadCheckCreatesNoFile()

	/**
	 * Only the user who opened a session, on its own object and before it expires, gets it.
	 *
	 * @return void
	 */
	public function testOnlyItsOwnerGetsTheSession(): void {
		$session = $this->session(size: 20, partSize: 10);
		$this->sessions->method('findByUploadId')->willReturnCallback(
			static fn (string $id): ?UploadSession => ($id === '0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f' ? $session : null)
		);
		$service = $this->service();
		$this->assertSame($session, $service->sessionFor(uploadId: '0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f', userId: 'user-a', object: $this->object()));

		$other = new ObjectEntity();
		$other->setUuid('another-object');
		foreach ([
			['0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f', 'user-b', $this->object()],
			['0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f', '', $this->object()],
			['0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f', 'user-a', $other],
			['00000000-0000-4000-8000-000000000000', 'user-a', $this->object()],
		] as [$uploadId, $userId, $object]) {
			try {
				$service->sessionFor(uploadId: $uploadId, userId: $userId, object: $object);
				$this->fail('Handed the session to ' . $userId);
			} catch (FileActionRefusal $e) {
				$this->assertSame([404, 'Upload session not found'], [$e->getCode(), $e->getMessage()]);
			}
		}

		$session->setExpiresAt(new DateTime('2026-11-14T11:59:59+00:00'));
		$this->expectExceptionObject(new FileActionRefusal(message: 'Upload session not found', code: 404));
		$service->sessionFor(uploadId: '0f6c2b6e-1d2a-4c3b-9e8f-7a6b5c4d3e2f', userId: 'user-a', object: $this->object());
	}//end testOnlyItsOwnerGetsTheSession()
}//end class
