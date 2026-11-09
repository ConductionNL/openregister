<?php

/**
 * FilePropertyHandler stores, refuses and parses files exactly as recorded.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object\SaveObject;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\FileService;
use OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionMethod;
use Throwable;

/**
 * Serves `http://` from a fixed body so the URL branch runs without a network.
 */
class GoldenHttpStreamWrapper {

	/**
	 * Bytes to serve, or false to make the fetch fail.
	 *
	 * @var string|false
	 */
	public static string|false $body = '';

	/**
	 * Read cursor.
	 *
	 * @var int
	 */
	private int $position = 0;

	/**
	 * Stream context, assigned by PHP.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * Open the stream.
	 *
	 * @param string      $path       The URL.
	 * @param string      $mode       The mode.
	 * @param int         $options    The options.
	 * @param string|null $openedPath The opened path.
	 *
	 * @return bool
	 */
	public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
		return self::$body !== false;
	}//end stream_open()

	/**
	 * Read from the stream.
	 *
	 * @param int $count Bytes requested.
	 *
	 * @return string
	 */
	public function stream_read(int $count): string {
		$data            = substr((string) self::$body, $this->position, $count);
		$this->position += strlen($data);
		return $data;
	}//end stream_read()

	/**
	 * Whether the stream is exhausted.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return $this->position >= strlen((string) self::$body);
	}//end stream_eof()

	/**
	 * Stat the stream.
	 *
	 * @return array<never>
	 */
	public function stream_stat(): array {
		return [];
	}//end stream_stat()

	/**
	 * Stat the URL.
	 *
	 * @param string $path  The URL.
	 * @param int    $flags The flags.
	 *
	 * @return array<never>
	 */
	public function url_stat(string $path, int $flags): array {
		return [];
	}//end url_stat()
}//end class

/**
 * Golden record of processSingleFileProperty(), validateFileAgainstConfig(), blockExecutableFiles(),
 * processUploadedFiles(), parseFileData() and the MIME-to-extension map: accepted AND refused
 * outcomes, the files stored, the log lines. Generated filenames are normalised (they hold the
 * time and random bytes). Set OR_GOLDEN_RECORD=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Object\SaveObject\FilePropertyHandler
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 */
class FilePropertyHandlerStoreGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/file-property-handler-store-golden.json';

	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

	/**
	 * Recorded calls of the current scenario.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $calls = [];

	/**
	 * Whether `http://` was replaced.
	 *
	 * @var bool
	 */
	private bool $wrapped = false;

	/**
	 * Put the real `http://` wrapper back.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->wrapped === true) {
			stream_wrapper_restore('http');
			$this->wrapped = false;
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * Every scenario matches the recorded golden.
	 *
	 * @return void
	 */
	public function testScenariosMatchTheGolden(): void {
		$actual = [
			'store'    => $this->storeScenarios(),
			'validate' => $this->validateScenarios(),
			'block'    => $this->blockScenarios(),
			'uploads'  => $this->uploadScenarios(),
			'parse'    => $this->parseScenarios(),
			'mime'     => $this->mimeScenarios(),
		];

		if (getenv('OR_GOLDEN_RECORD') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		$this->assertSame($expected, json_decode((string) json_encode($actual, JSON_UNESCAPED_SLASHES), true));
	}//end testScenariosMatchTheGolden()

	/**
	 * processSingleFileProperty() over string, URL and file-object inputs.
	 *
	 * @return array<string, mixed>
	 */
	private function storeScenarios(): array {
		$plain   = ['type' => 'file'];
		$limited = ['type' => 'file', 'allowedTypes' => ['text/plain'], 'maxSize' => 10, 'autoShare' => true, 'autoTags' => ['a', 'b', 'a']];
		$text    = 'data:text/plain;base64,' . base64_encode('hello');
		return [
			'data uri'              => $this->store(input: $text, fileConfig: $plain),
			'data uri indexed'      => $this->store(input: $text, fileConfig: $limited, index: 3),
			'plain base64 png'      => $this->store(input: self::PNG, fileConfig: $plain),
			'wrong type'            => $this->store(input: 'data:image/png;base64,' . self::PNG, fileConfig: $limited),
			'too big'               => $this->store(input: 'data:text/plain;base64,' . base64_encode('hello world!'), fileConfig: $limited, index: 0),
			'editor mime refused'   => $this->store(input: $text, fileConfig: ['type' => 'file', 'fileConfiguration' => ['allowedMimeTypes' => ['image/png']]]),
			'exe mime'              => $this->store(input: 'data:application/x-msdownload;base64,' . base64_encode('abc'), fileConfig: $plain),
			'mz bytes'              => $this->store(input: 'data:image/png;base64,' . base64_encode("MZ\x90\x00rest"), fileConfig: $plain),
			'elf bytes'             => $this->store(input: 'data:image/png;base64,' . base64_encode("\x7fELF\x02\x01"), fileConfig: $plain),
			'shebang'               => $this->store(input: 'data:text/plain;base64,' . base64_encode("#!/bin/sh\necho"), fileConfig: $plain),
			'php tag'               => $this->store(input: 'data:text/plain;base64,' . base64_encode('x <?php echo 1;'), fileConfig: $plain),
			'executables allowed'   => $this->store(input: 'data:application/x-msdownload;base64,' . base64_encode("MZ\x90"), fileConfig: ['type' => 'file', 'allowExecutables' => true]),
			'invalid data uri'      => $this->store(input: 'data:nonsense', fileConfig: $plain),
			'invalid uri base64'    => $this->store(input: 'data:text/plain;base64,@@@', fileConfig: $plain),
			'invalid base64'        => $this->store(input: 'not base64 at all!', fileConfig: $plain),
			'unsupported'           => $this->store(input: 42, fileConfig: $plain),
			'non file array'        => $this->store(input: ['foo' => 'bar'], fileConfig: $plain),
			'existing file'         => $this->store(input: ['id' => 7, 'title' => 'x'], fileConfig: $plain),
			'existing string id'    => $this->store(input: ['id' => '8', 'path' => '/x'], fileConfig: $plain),
			'missing no url'        => $this->store(input: ['id' => 99, 'title' => 'x'], fileConfig: $plain),
			'lookup fails no url'   => $this->store(input: ['id' => 13, 'title' => 'x'], fileConfig: $plain),
			'non numeric no url'    => $this->store(input: ['id' => 'abc', 'title' => 'x'], fileConfig: $plain),
			'missing download url'  => $this->store(input: ['id' => 99, 'title' => 'x', 'downloadUrl' => 'http://93.184.216.34/r/report.pdf'], fileConfig: $plain, body: '%PDF-1.4 body'),
			'lookup fails access'   => $this->store(input: ['id' => 13, 'title' => 'x', 'accessUrl' => 'http://93.184.216.34/r/a.txt'], fileConfig: $plain, body: 'plain body'),
			'url with extension'    => $this->store(input: 'http://93.184.216.34/docs/a.csv', fileConfig: $plain, body: 'a,b'),
			'url no extension'      => $this->store(input: 'http://93.184.216.34/docs/img', fileConfig: $plain, body: base64_decode(self::PNG)),
			'url loopback refused'  => $this->store(input: 'http://127.0.0.1/secret.txt', fileConfig: $plain, body: 'secret'),
			'url mime refused'      => $this->store(input: 'http://93.184.216.34/docs/img.png', fileConfig: $limited, body: base64_decode(self::PNG)),
		];
	}//end storeScenarios()

	/**
	 * validateFileAgainstConfig() accepted and refused.
	 *
	 * @return array<string, mixed>
	 */
	private function validateScenarios(): array {
		$ok  = ['content' => 'abc', 'mimeType' => 'text/plain', 'extension' => 'txt', 'size' => 3];
		$big = ['content' => 'abc', 'mimeType' => 'text/plain', 'extension' => 'txt', 'size' => 3000000];
		$exe = ['content' => 'abc', 'mimeType' => 'application/x-sh', 'extension' => 'sh', 'size' => 3];
		return [
			'no rules'          => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($ok, [], 'f')),
			'allowed type'      => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($ok, ['allowedTypes' => ['text/plain']], 'f')),
			'refused type'      => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($ok, ['allowedTypes' => ['image/png', 'image/gif']], 'f', 2)),
			'empty types'       => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($ok, ['allowedTypes' => []], 'f')),
			'within size'       => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($ok, ['maxSize' => 3], 'f')),
			'over size'         => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($ok, ['maxSize' => 2], 'f')),
			'size zero'         => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($big, ['maxSize' => 0], 'f')),
			'editor megabytes'  => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($big, ['fileConfiguration' => ['maxSize' => 2]], 'f', 0)),
			'exe refused'       => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($exe, [], 'f')),
			'exe allowed'       => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($exe, ['allowExecutables' => true], 'f')),
			'exe before type'   => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($exe, ['allowedTypes' => ['image/png'], 'maxSize' => 1], 'f')),
			'type before size'  => $this->outcome(fn () => $this->handler()->validateFileAgainstConfig($big, ['allowedTypes' => ['image/png'], 'maxSize' => 1], 'f')),
		];
	}//end validateScenarios()

	/**
	 * blockExecutableFiles() over extensions, content and MIME types.
	 *
	 * @return array<string, mixed>
	 */
	private function blockScenarios(): array {
		$cases = [
			'safe'             => ['filename' => 'a.txt', 'content' => 'hello', 'mimeType' => 'text/plain'],
			'exe name'         => ['filename' => 'a.exe', 'content' => 'hello', 'mimeType' => 'text/plain'],
			'upper PHP name'   => ['filename' => 'A.PHP', 'mimeType' => 'text/plain'],
			'no name exe mime' => ['mimeType' => 'application/x-msdownload'],
			'jar mime'         => ['filename' => 'a.zip', 'content' => '', 'mimeType' => 'application/java-archive'],
			'php in png name'  => ['filename' => 'a.png', 'content' => 'x <?php 1;', 'mimeType' => 'text/plain'],
			'php in txt name'  => ['filename' => 'a.txt', 'content' => 'x <?php 1;', 'mimeType' => 'text/plain'],
			'name before mime' => ['filename' => 'run.sh', 'content' => "MZ\x90", 'mimeType' => 'application/x-sh'],
			'bytes before mime' => ['content' => "MZ\x90", 'mimeType' => 'application/x-sh'],
			'nothing'          => [],
		];
		$out = [];
		foreach ($cases as $name => $fileData) {
			$out[$name] = $this->outcome(fn () => $this->handler()->blockExecutableFiles($fileData, 'P'));
		}

		return $out;
	}//end blockScenarios()

	/**
	 * processUploadedFiles() over errors, array names and unreadable paths.
	 *
	 * @return array<string, mixed>
	 */
	private function uploadScenarios(): array {
		$tmp = tempnam(sys_get_temp_dir(), 'or-golden-');
		file_put_contents($tmp, 'upload');
		$ok = ['name' => 'a.txt', 'type' => 'text/plain', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 6];
		$noType = ['name' => 'a.txt', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 6];
		$out = [
			'single'        => $this->outcome(fn () => $this->handler()->processUploadedFiles(['doc' => $ok], ['doc' => 'old', 'x' => 1])),
			'error skipped' => $this->outcome(fn () => $this->handler()->processUploadedFiles(['doc' => ['error' => UPLOAD_ERR_NO_FILE] + $ok, 'b' => ['error' => UPLOAD_ERR_PARTIAL, 'name' => null] + $ok], [])),
			'array names'   => $this->outcome(fn () => $this->handler()->processUploadedFiles(['img[]' => $ok, 'img[1]' => $noType, 'z[x]' => $ok], ['img' => ['kept']])),
			'unreadable'    => $this->outcome(fn () => $this->handler()->processUploadedFiles(['doc' => ['tmp_name' => $tmp . '-missing'] + $ok], [])),
		];
		unlink($tmp);
		return $out;
	}//end uploadScenarios()

	/**
	 * parseFileData() over data URIs and base64.
	 *
	 * @return array<string, mixed>
	 */
	private function parseScenarios(): array {
		$out = [];
		foreach (['data:application/pdf;base64,' . base64_encode('%PDF'), 'data:foo/bar;base64,' . base64_encode('x'), self::PNG, 'data:text/plain,abc', '***'] as $input) {
			$out[$input] = $this->outcome(fn () => $this->handler()->parseFileData($input));
		}

		return $out;
	}//end parseScenarios()

	/**
	 * The MIME-to-extension map and the executable MIME list.
	 *
	 * @return array<string, mixed>
	 */
	private function mimeScenarios(): array {
		$mimes = [
			'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml', 'image/bmp', 'image/tiff', 'image/x-icon',
			'application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-powerpoint',
			'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/rtf', 'application/vnd.oasis.opendocument.text',
			'application/vnd.oasis.opendocument.spreadsheet', 'application/vnd.oasis.opendocument.presentation', 'text/plain', 'text/csv',
			'text/html', 'text/css', 'text/javascript', 'application/json', 'application/xml', 'text/xml', 'application/zip',
			'application/x-rar-compressed', 'application/x-7z-compressed', 'application/x-tar', 'application/gzip', 'audio/mpeg', 'audio/wav',
			'audio/ogg', 'audio/aac', 'audio/flac', 'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm',
			'application/x-unknown', '',
		];
		$out = [];
		foreach ($mimes as $mime) {
			$out[$mime] = $this->invoke(method: 'getExtensionFromMimeType', args: [$mime]);
		}

		$out['executable mime types'] = $this->invoke(method: 'getExecutableMimeTypes', args: []);
		return $out;
	}//end mimeScenarios()

	/**
	 * Run processSingleFileProperty() once and record what it did.
	 *
	 * @param mixed                $input      The file input.
	 * @param array<string, mixed> $fileConfig The file configuration.
	 * @param int|null             $index      The array index.
	 * @param string|false|null    $body       The http body to serve, null for no fake.
	 *
	 * @return array<string, mixed>
	 */
	private function store(mixed $input, array $fileConfig, ?int $index = null, string|false|null $body = null): array {
		if ($body !== null) {
			$this->serve(body: $body);
		}

		$entity = new ObjectEntity();
		$entity->setUuid('obj-1');
		$result = $this->outcome(fn () => $this->handler()->processSingleFileProperty($entity, $input, 'doc', $fileConfig, $index));
		return $result;
	}//end store()

	/**
	 * Serve `http://` from the given body.
	 *
	 * @param string|false $body The body, false to fail.
	 *
	 * @return void
	 */
	private function serve(string|false $body): void {
		GoldenHttpStreamWrapper::$body = $body;
		if ($this->wrapped === false) {
			stream_wrapper_unregister('http');
			stream_wrapper_register('http', GoldenHttpStreamWrapper::class);
			$this->wrapped = true;
		}
	}//end serve()

	/**
	 * Run a call and record its result or exception, with the calls made meanwhile.
	 *
	 * @param callable $call The call.
	 *
	 * @return array<string, mixed>
	 */
	private function outcome(callable $call): array {
		$this->calls = [];
		try {
			$result = ['result' => $this->normalise(value: $call())];
		} catch (Throwable $e) {
			$result = ['exception' => get_class($e), 'message' => $e->getMessage()];
		}

		return $result + ['calls' => $this->calls];
	}//end outcome()

	/**
	 * Replace binary content with its base64 so the record is valid JSON.
	 *
	 * @param mixed $value The value.
	 *
	 * @return mixed
	 */
	private function normalise(mixed $value): mixed {
		if (is_array($value) === true && isset($value['content']) === true && is_string($value['content']) === true) {
			$value['content'] = base64_encode($value['content']);
		}

		return $value;
	}//end normalise()

	/**
	 * Invoke a private method on a fresh handler.
	 *
	 * @param string            $method The method.
	 * @param array<int, mixed> $args   The arguments.
	 *
	 * @return mixed
	 */
	private function invoke(string $method, array $args): mixed {
		return (new ReflectionMethod(FilePropertyHandler::class, $method))->invokeArgs($this->handler(), $args);
	}//end invoke()

	/**
	 * A handler whose logger and file service record into $this->calls.
	 *
	 * @return FilePropertyHandler
	 */
	private function handler(): FilePropertyHandler {
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['debug', 'info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []) use ($level): void {
					unset($context['file'], $context['line']);
					$this->calls[] = ['log' => $level, 'message' => $message, 'context' => $context];
				}
			);
		}

		$fileService = $this->createMock(FileService::class);
		$fileService->method('addFile')->willReturnCallback(
			function (ObjectEntity $objectEntity, string $fileName, string $content, bool $share = false, array $tags = []): File {
				$this->calls[] = [
					'addFile' => (string) preg_replace('/_\d+_[0-9a-f]{8}\./', '_T_R.', $fileName),
					'object'  => $objectEntity->getUuid(),
					'content' => base64_encode($content),
					'share'   => $share,
					'tags'    => $tags,
				];
				$file = $this->createMock(File::class);
				$file->method('getId')->willReturn(500 + count($this->calls));
				return $file;
			}
		);
		$fileService->method('getFile')->willReturnCallback(
			function (mixed $object = null, string|int $file = ''): ?File {
				$this->calls[] = ['getFile' => $file];
				if ($file === 13) {
					throw new Exception('not accessible');
				}

				if ($file === 99) {
					return null;
				}

				return $this->createMock(File::class);
			}
		);

		return new FilePropertyHandler($logger, $fileService);
	}//end handler()
}//end class
