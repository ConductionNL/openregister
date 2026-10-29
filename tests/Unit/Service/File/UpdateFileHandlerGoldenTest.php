<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\File\FileOwnershipHandler;
use OCA\OpenRegister\Service\File\FileValidationHandler;
use OCA\OpenRegister\Service\File\FolderManagementHandler;
use OCA\OpenRegister\Service\File\ReadFileHandler;
use OCA\OpenRegister\Service\File\UpdateFileHandler;
use OCA\OpenRegister\Service\FileService;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for UpdateFileHandler::updateFile().
 *
 * Every case records each collaborator call (method and arguments, log lines
 * included) and the outcome, and compares the recording with
 * fixtures/update-file-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * Files and folders are described by a small world array:
 * - `objectFolder`: map of path => file spec in the object's folder, or the string `null` / `throw`
 * - `userFolder`: map of path => file spec in the OpenRegister user folder
 * - `userById`: map of id => file spec, or the string `throw`
 * - `byReadHandler`: file spec returned by ReadFileHandler::getFile
 */
class UpdateFileHandlerGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/update-file-golden.json';

	/** @var list<array<string, mixed>> */
	private array $calls = [];

	protected function setUp(): void {
		parent::setUp();

		if (interface_exists('OC\\Hooks\\Emitter') === false) {
			$this->markTestSkipped('Nextcloud server classes unavailable; run in the Docker test environment.');
		}
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$doc = ['id' => 7, 'name' => 'doc.txt', 'hash' => md5('old')];
		$withObject = ['object' => true];
		$cases = [
			'id via read handler, new content, tags' => [
				'path' => 7, 'object' => true, 'content' => 'new', 'tags' => ['blue', 'object:abc'],
				'byReadHandler' => $doc, 'existingTags' => ['object:abc', 'object:def', 'objective', 'red'],
			],
			'id via read handler, same content' => ['path' => 7, 'object' => true, 'content' => 'old', 'byReadHandler' => $doc],
			'id read handler misses, user folder by id' => ['path' => 7, 'object' => true, 'userById' => [7 => $doc]],
			'id without object, user folder by id' => ['path' => 7, 'userById' => [7 => $doc], 'content' => 'new'],
			'id not found' => ['path' => 9, 'userById' => []],
			'id user folder throws' => ['path' => 9, 'userFolderThrows' => 'exception'],
			'path in object folder by name' => $withObject + [
				'path' => '/8010/doc.txt', 'objectFolder' => ['doc.txt' => $doc], 'content' => 'new',
			],
			'path in object folder by full path' => $withObject + [
				'path' => 'sub/doc.txt', 'objectFolder' => ['sub/doc.txt' => $doc],
			],
			'path in object folder by full path after name misses' => $withObject + [
				'path' => '8010/doc.txt', 'objectFolder' => ['8010/doc.txt' => $doc],
			],
			'path not in object folder, found in user folder' => $withObject + [
				'path' => 'doc.txt', 'objectFolder' => [], 'userFolder' => ['doc.txt' => $doc],
			],
			'object folder listing fails' => $withObject + [
				'path' => 'doc.txt', 'objectFolder' => ['doc.txt' => $doc], 'listingThrows' => true,
			],
			'object folder missing' => $withObject + ['path' => 'doc.txt', 'objectFolder' => 'null', 'userFolder' => ['doc.txt' => $doc]],
			'object folder throws' => $withObject + ['path' => 'doc.txt', 'objectFolder' => 'throw', 'userFolder' => ['doc.txt' => $doc]],
			'object folder get not permitted' => $withObject + [
				'path' => 'doc.txt', 'objectFolder' => ['doc.txt' => 'notPermitted'], 'userFolder' => ['doc.txt' => $doc],
			],
			'no object, user folder' => ['path' => 'a%20b/doc.txt', 'userFolder' => ['a b/doc.txt' => $doc], 'tags' => ['x']],
			'no object, leading id fallback' => ['path' => '12/doc.txt', 'userFolder' => [], 'userById' => [12 => $doc]],
			'no object, leading id empty' => ['path' => '12/doc.txt', 'userFolder' => [], 'userById' => []],
			'no object, leading id throws' => ['path' => '12/doc.txt', 'userFolder' => [], 'userById' => 'throw'],
			'no object, no leading id' => ['path' => 'missing.txt', 'userFolder' => []],
			'user folder not permitted' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => 'notPermitted']],
			'user folder invalid path' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => 'invalidPath']],
			'base64 content is decoded' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => $doc], 'content' => base64_encode('hello')],
			'base64 content equal to stored hash' => [
				'path' => 'doc.txt', 'userFolder' => ['doc.txt' => ['id' => 7, 'name' => 'doc.txt', 'hash' => md5('aGVsbG8=')]], 'content' => 'aGVsbG8=',
			],
			'stream content changed' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => $doc], 'stream' => 'streamed bytes'],
			'stream content unchanged' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => $doc], 'stream' => 'old'],
			'not updateable' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => $doc + ['updateable' => false]], 'content' => 'new'],
			'executable blocked' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => $doc], 'content' => 'MZ', 'blockThrows' => true],
			'ownership refused' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => $doc], 'content' => 'new', 'ownershipThrows' => true],
			'put content not permitted' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => $doc + ['putThrows' => true]], 'content' => 'new'],
			'folder node with content and tags' => [
				'path' => 'dir', 'userFolder' => ['dir' => ['id' => 3, 'name' => 'dir', 'folder' => true]], 'content' => 'new', 'tags' => ['t'],
			],
			'nothing to change' => ['path' => 'doc.txt', 'userFolder' => ['doc.txt' => $doc]],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	/**
	 * @param array<string, mixed> $case
	 */
	#[DataProvider('cases')]
	public function testUpdateFileMatchesGolden(array $case): void {
		$name = $this->dataName();
		$handler = $this->buildHandler(case: $case);

		$object = null;
		if (($case['object'] ?? false) === true) {
			$object = new ObjectEntity();
			$object->setId(55);
			$object->setUuid('obj-55');
		}

		$content = ($case['content'] ?? null);
		if (isset($case['stream']) === true) {
			$content = fopen('php://memory', 'r+');
			fwrite($content, $case['stream']);
			rewind($content);
		}

		$outcome = [];
		try {
			$file = $handler->updateFile($case['path'], $content, $case['tags'] ?? [], $object);
			$outcome = ['returned' => $this->describe(value: $file)];
		} catch (\Throwable $e) {
			$outcome = ['threw' => get_class($e), 'message' => preg_replace('/MockObject_\w+/', 'MockObject', $e->getMessage())];
		}

		$actual = json_decode(json_encode(['calls' => $this->calls] + $outcome), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testUpdateFileMatchesGolden()

	/**
	 * @param array<string, mixed> $case
	 */
	private function buildHandler(array $case): UpdateFileHandler {
		$this->calls = [];

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning', 'error'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level) {
				unset($context['file'], $context['line']);
				$this->record(method: 'logger.' . $level, args: [$message, $context]);
			});
		}

		$readFileHandler = $this->createMock(ReadFileHandler::class);
		$readFileHandler->method('getFile')->willReturnCallback(function () use ($case) {
			$this->record(method: 'readFileHandler.getFile', args: func_get_args());
			if (isset($case['byReadHandler']) === true) {
				return $this->node(spec: $case['byReadHandler']);
			}

			return null;
		});

		$folders = $this->createMock(FolderManagementHandler::class);
		$folders->method('getObjectFolder')->willReturnCallback(function () use ($case) {
			$this->record(method: 'folders.getObjectFolder', args: func_get_args());
			$spec = ($case['objectFolder'] ?? []);
			if ($spec === 'null') {
				return null;
			}

			if ($spec === 'throw') {
				throw new Exception('Object folder unavailable');
			}

			return $this->folder(label: 'objectFolder', case: $case);
		});
		$folders->method('getOpenRegisterUserFolder')->willReturnCallback(function () use ($case) {
			$this->record(method: 'folders.getOpenRegisterUserFolder', args: []);
			if (($case['userFolderThrows'] ?? null) === 'exception') {
				throw new Exception('User folder unavailable');
			}

			return $this->folder(label: 'userFolder', case: $case);
		});

		$validation = $this->createMock(FileValidationHandler::class);
		$validation->method('blockExecutableFile')->willReturnCallback(function () use ($case) {
			$this->record(method: 'validation.blockExecutableFile', args: func_get_args());
			if (($case['blockThrows'] ?? false) === true) {
				throw new Exception('Executable files are not allowed');
			}
		});
		$validation->method('checkOwnership')->willReturnCallback(function () use ($case) {
			$this->record(method: 'validation.checkOwnership', args: func_get_args());
			if (($case['ownershipThrows'] ?? false) === true) {
				throw new NotPermittedException('Not your file');
			}
		});

		$ownership = $this->createMock(FileOwnershipHandler::class);
		$ownership->method('transferFileOwnershipIfNeeded')->willReturnCallback(function () {
			$this->record(method: 'ownership.transferFileOwnershipIfNeeded', args: func_get_args());
			return true;
		});

		$fileService = $this->createMock(FileService::class);
		$fileService->method('extractFileNameFromPath')->willReturnCallback(function (string $path) {
			$this->record(method: 'fileService.extractFileNameFromPath', args: func_get_args());
			$clean = urldecode(trim($path, '/'));
			$name = $clean;
			if (preg_match('/^\d+\/(.+)$/', $clean, $matches) === 1) {
				$name = $matches[1];
			}

			return ['cleanPath' => $clean, 'fileName' => $name];
		});
		$fileService->method('getFileTags')->willReturnCallback(function () use ($case) {
			$this->record(method: 'fileService.getFileTags', args: func_get_args());
			return ($case['existingTags'] ?? []);
		});
		$fileService->method('attachTagsToFile')->willReturnCallback(function () {
			$this->record(method: 'fileService.attachTagsToFile', args: func_get_args());
		});

		$handler = new UpdateFileHandler(
			$this->createMock(IRootFolder::class),
			$folders,
			$validation,
			$ownership,
			$readFileHandler,
			$this->createMock(ISystemTagManager::class),
			$this->createMock(ISystemTagObjectMapper::class),
			$logger
		);
		$handler->setFileService($fileService);

		return $handler;
	}//end buildHandler()

	/**
	 * @param array<string, mixed> $case
	 */
	private function folder(string $label, array $case): Folder {
		$entries = ($case[$label] ?? []);
		$folder = $this->createMock(Folder::class);
		$folder->method('getPath')->willReturn('/openregister/' . $label);
		$folder->method('getId')->willReturn($label === 'userFolder' ? 1 : 2);
		$folder->method('getDirectoryListing')->willReturnCallback(function () use ($entries, $case, $label) {
			$this->record(method: $label . '.getDirectoryListing', args: []);
			if (($case['listingThrows'] ?? false) === true) {
				throw new Exception('Listing failed');
			}

			$nodes = [];
			foreach ($entries as $spec) {
				if (is_array($spec) === true) {
					$nodes[] = $this->node(spec: $spec);
				}
			}

			return $nodes;
		});
		$folder->method('get')->willReturnCallback(function (string $path) use ($entries, $label) {
			$this->record(method: $label . '.get', args: func_get_args());
			$spec = ($entries[$path] ?? null);
			if ($spec === 'notPermitted') {
				throw new NotPermittedException('No access to ' . $path);
			}

			if ($spec === 'invalidPath') {
				throw new InvalidPathException('Invalid path ' . $path);
			}

			if ($spec === null) {
				throw new NotFoundException($path);
			}

			return $this->node(spec: $spec);
		});
		$folder->method('getById')->willReturnCallback(function (int $id) use ($case, $label) {
			$this->record(method: $label . '.getById', args: func_get_args());
			$byId = ($case['userById'] ?? []);
			if ($byId === 'throw') {
				throw new Exception('Lookup by id failed');
			}

			if (isset($byId[$id]) === true) {
				return [$this->node(spec: $byId[$id])];
			}

			return [];
		});

		return $folder;
	}//end folder()

	/**
	 * @param array<string, mixed> $spec
	 */
	private function node(array $spec): Node {
		if (($spec['folder'] ?? false) === true) {
			$node = $this->createMock(Folder::class);
		} else {
			$node = $this->createMock(File::class);
			$node->method('hash')->willReturn($spec['hash'] ?? '');
			$node->method('isUpdateable')->willReturn($spec['updateable'] ?? true);
			$node->method('putContent')->willReturnCallback(function ($data) use ($spec) {
				if (is_resource($data) === true) {
					$data = 'stream:' . stream_get_contents($data);
				}

				$this->record(method: 'file.putContent', args: [$data]);
				if (($spec['putThrows'] ?? false) === true) {
					throw new NotPermittedException('Storage is read-only');
				}
			});
		}

		$node->method('getId')->willReturn($spec['id']);
		$node->method('getName')->willReturn($spec['name']);
		$node->method('getPath')->willReturn('/openregister/files/' . $spec['name']);

		return $node;
	}//end node()

	/**
	 * @param list<mixed> $args
	 */
	private function record(string $method, array $args): void {
		$this->calls[] = ['method' => $method, 'args' => array_map(fn ($arg) => $this->describe(value: $arg), $args)];
	}//end record()

	private function describe(mixed $value): mixed {
		if ($value instanceof ObjectEntity) {
			return ['object' => $value->getId()];
		}

		if ($value instanceof Node) {
			return ['node' => $value->getId(), 'name' => $value->getName(), 'folder' => $value instanceof Folder];
		}

		if (is_resource($value) === true) {
			return 'resource';
		}

		if (is_array($value) === true) {
			return array_map(fn ($item) => $this->describe(value: $item), $value);
		}

		if (is_object($value) === true) {
			return ['object' => get_class($value)];
		}

		return $value;
	}//end describe()
}//end class
