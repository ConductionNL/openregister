<?php

/**
 * Golden characterization test for FilePublishingHandler::publishFile() and ::unpublishFile().
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\File
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Files\Db\FileMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FilePublishingHandler;
use OCA\OpenRegister\Files\Service\File\FileRestrictionGuard;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Party\PartyIndicatorGuard;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IServerContainer;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Runs publishFile() and unpublishFile() over a fixed corpus of file lookups
 * and compares every log line, every share call and the outcome with
 * `fixtures/file-publishing-golden.json`. Set `OR_GOLDEN_WRITE=1` to
 * regenerate it.
 *
 * @covers \OCA\OpenRegister\Service\File\FilePublishingHandler
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Service\File\ObjectFileFinder
 * @uses \OCA\OpenRegister\Service\File\ObjectFileLookup
 */
class FilePublishingHandlerGoldenTest extends TestCase {

	/**
	 * Calls recorded during one case.
	 *
	 * @var array<int, mixed>
	 */
	private array $calls = [];

	/**
	 * Path of the golden file.
	 *
	 * @return string
	 */
	private static function goldenPath(): string {
		return __DIR__.'/fixtures/file-publishing-golden.json';
	}//end goldenPath()

	/**
	 * The corpus: name => [file argument, folder kind, mapper fails, shares deleted].
	 *
	 * @return array<string, array{0: int|string, 1: string, 2: bool, 3: int}>
	 */
	private static function corpus(): array {
		return [
			'id-found' => [7, 'normal', false, 2],
			'id-missing' => [8, 'normal', false, 0],
			'name-found' => ['docs/a.txt', 'normal', false, 0],
			'name-equals-path' => ['a.txt', 'normal', false, 1],
			'full-path-found' => ['deep/b.txt', 'normal', false, 0],
			'nothing-found' => ['deep/none.txt', 'normal', false, 0],
			'get-throws' => ['deep/boom.txt', 'normal', false, 0],
			'listing-fails' => ['docs/a.txt', 'listing-fails', false, 0],
			'no-folder' => ['docs/a.txt', 'none', false, 0],
			'path-is-folder' => ['docs/sub', 'normal', false, 0],
			'mapper-fails' => ['docs/a.txt', 'normal', true, 0],
		];
	}//end corpus()

	/**
	 * Case names for the data provider: every corpus entry, published and unpublished.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (array_keys(self::corpus()) as $name) {
			$cases["publish $name"] = [$name, 'publishFile'];
			$cases["unpublish $name"] = [$name, 'unpublishFile'];
		}

		return $cases;
	}//end cases()

	/**
	 * Each case looks up, shares and logs as recorded.
	 *
	 * @param string $name   The corpus entry.
	 * @param string $method The handler method.
	 *
	 * @return void
	 *
	 * @dataProvider cases
	 */
	public function testPublishingMatchesGolden(string $name, string $method): void {
		$actual = $this->runCase(case: self::corpus()[$name], method: $method);
		$key = "$method $name";

		if (getenv('OR_GOLDEN_WRITE') === '1') {
			$golden = [];
			if (is_file(self::goldenPath()) === true) {
				$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
			}

			$golden[$key] = $actual;
			ksort($golden);
			file_put_contents(self::goldenPath(), json_encode($golden, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
			$this->markTestSkipped('golden written');
		}

		$golden = json_decode((string)file_get_contents(self::goldenPath()), true);
		$this->assertArrayHasKey($key, $golden);
		$this->assertSame($golden[$key], $actual);
	}//end testPublishingMatchesGolden()

	/**
	 * Run one case and return what it recorded.
	 *
	 * @param array  $case   [file argument, folder kind, mapper fails, shares deleted].
	 * @param string $method The handler method.
	 *
	 * @return array
	 */
	private function runCase(array $case, string $method): array {
		[$file, $folderKind, $mapperFails, $deleted] = $case;
		$this->calls = [];
		$handler = $this->handler(folderKind: $folderKind, share: ['fails' => $mapperFails, 'deleted' => $deleted]);
		$object = new ObjectEntity();
		$object->setId(42);
		$object->setUuid('obj-uuid');
		try {
			$node = $handler->$method($object, $file);
			$this->calls[] = ['returned' => $node->getPath()];
		} catch (\Throwable $e) {
			$this->calls[] = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		return $this->calls;
	}//end runCase()

	/**
	 * The handler under test, its collaborators recording into $this->calls.
	 *
	 * @param string $folderKind normal, listing-fails or none.
	 * @param array  $share      `fails`: the share mapper throws; `deleted`: shares a depublish removes.
	 *
	 * @return FilePublishingHandler
	 */
	private function handler(string $folderKind, array $share): FilePublishingHandler {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function ($message, array $context = []) use ($level): void {
				unset($context['file'], $context['line']);
				$message = (string)preg_replace('/MockObject_(\w+)_[0-9a-f]+/', 'MockObject_$1', (string)$message);
				$this->calls[] = ['log' => $level, 'message' => $message, 'context' => $context];
			});
		}

		$restrictions = $this->createMock(FileRestrictionGuard::class);
		$restrictions->method('isRestricted')->willReturn(false);
		$guard = $this->createMock(PartyIndicatorGuard::class);
		$container = $this->createMock(IServerContainer::class);
		$container->method('get')->willReturnCallback(fn (string $id): object => $id === FileRestrictionGuard::class ? $restrictions : $guard);

		$fileMapper = $this->createMock(FileMapper::class);
		$fileMapper->method('publishFile')->willReturnCallback(function (int $fileId, string $sharedBy) use ($share): array {
			$this->calls[] = ['share.publish' => [$fileId, $sharedBy]];
			if ($share['fails'] === true) {
				throw new Exception('db down');
			}

			return ['id' => 3, 'token' => 'tok', 'accessUrl' => 'https://x/s/tok'];
		});
		$fileMapper->method('depublishFile')->willReturnCallback(function (int $fileId) use ($share): array {
			$this->calls[] = ['share.depublish' => $fileId];
			if ($share['fails'] === true) {
				throw new Exception('db down');
			}

			return ['deleted_shares' => $share['deleted'], 'file_id' => $fileId];
		});

		$handler = new FilePublishingHandler($this->createMock(MagicMapper::class), $fileMapper, $logger, $container);
		$handler->setFileService($this->fileService(folderKind: $folderKind));
		return $handler;
	}//end handler()

	/**
	 * The file service: file 7 is a file, 9 a folder, 8 missing.
	 *
	 * @param string $folderKind normal, listing-fails or none.
	 *
	 * @return FileService
	 */
	private function fileService(string $folderKind): FileService {
		$fileService = $this->createMock(FileService::class);
		$fileService->method('getFile')->willReturnCallback(fn ($object, $file) => match ($file) {
			7 => $this->file(name: 'seven.txt'),
			9 => $this->folder(kind: 'node'),
			default => null,
		});
		$fileService->method('extractFileNameFromPath')->willReturnCallback(fn (string $path): array => ['cleanPath' => trim($path, '/'), 'fileName' => basename($path)]);
		$fileService->method('getObjectFolder')->willReturnCallback(fn () => $folderKind === 'none' ? null : $this->folder(kind: $folderKind));
		$fileService->method('checkOwnership')->willReturnCallback(function ($node): void {
			$this->calls[] = ['checkOwnership' => $node->getPath()];
		});
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('openregister');
		$fileService->method('getUser')->willReturn($user);
		return $fileService;
	}//end fileService()

	/**
	 * A file node.
	 *
	 * @param string $name The file name.
	 *
	 * @return File
	 */
	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getPath')->willReturn('/objects/42/'.$name);
		$file->method('getId')->willReturn(strlen($name));
		return $file;
	}//end file()

	/**
	 * The object folder: `a.txt` by name, `deep/b.txt` by full path, `sub` a folder, `boom.txt` failing.
	 *
	 * @param string $kind normal, listing-fails or node.
	 *
	 * @return Folder
	 */
	private function folder(string $kind): Folder {
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/objects/42');
		$folder->method('getName')->willReturn('42');
		$folder->method('getId')->willReturn(42);
		if ($kind === 'listing-fails') {
			$folder->method('getDirectoryListing')->willThrowException(new Exception('listing failed'));
		} else {
			$folder->method('getDirectoryListing')->willReturn([$this->file(name: 'a.txt')]);
		}

		$folder->method('get')->willReturnCallback(function (string $path) {
			$this->calls[] = ['folder.get' => $path];
			return match ($path) {
				'a.txt', 'deep/b.txt' => $this->file(name: basename($path)),
				'sub' => $this->folder(kind: 'node'),
				'boom.txt' => throw new Exception('storage error'),
				default => throw new NotFoundException('no '.$path),
			};
		});
		return $folder;
	}//end folder()
}//end class
