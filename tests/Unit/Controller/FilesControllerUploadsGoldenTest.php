<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use Exception;
use OCA\OpenRegister\Files\Controller\FilesController;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for how FilesController normalizes the uploads of a request.
 *
 * Every case feeds the request's `files` and `file` uploads and parameters and
 * compares the normalized files, or the failure, with
 * fixtures/files-uploads-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Files\Controller\FilesController
 */
class FilesControllerUploadsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/files-uploads-golden.json';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$one = ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 3];
		$many = ['name' => ['x.png', 'y.png'], 'type' => ['image/png', 'image/jpeg'], 'tmp_name' => ['/tmp/x', '/tmp/y'], 'error' => [0, 4], 'size' => [1, 2]];
		return [
			'nothing' => [['params' => []]],
			'one in files' => [['files' => $one, 'params' => []]],
			'one in file' => [['file' => $one, 'params' => []]],
			'files and file' => [['files' => $many, 'file' => $one, 'params' => []]],
			'files without name, no file' => [['files' => ['type' => 'x'], 'params' => []]],
			'files without name, with file' => [['files' => ['type' => 'x'], 'file' => $one, 'params' => []]],
			'bare single' => [['file' => ['name' => 'b'], 'params' => []]],
			'single share and tags' => [['file' => $one, 'params' => ['share' => 'yes', 'tags' => 'a, b,,  ,c']]],
			'single tag array' => [['file' => $one, 'params' => ['share' => true, 'tags' => ['a', ' ', 'b']]]],
			'single share off' => [['file' => $one, 'params' => ['share' => 'no']]],
			'many' => [['files' => $many, 'params' => []]],
			'many share' => [['files' => $many, 'params' => ['share' => '1']]],
			'many tags per file' => [['files' => $many, 'params' => ['tags' => ['a,b', ['c', ' ', 'd']]]]],
			'many tags for one file' => [['files' => $many, 'params' => ['tags' => ['a']]]],
			'many missing columns' => [['files' => ['name' => ['x', 'y']], 'params' => []]],
			'many scalar columns' => [['files' => ['name' => ['x', 'y'], 'type' => 'image/png', 'tmp_name' => '/tmp/z', 'error' => 2, 'size' => 9], 'params' => []]],
			'many text error and size' => [['files' => ['name' => ['x'], 'error' => '3', 'size' => '8'], 'params' => []]],
			'many number columns' => [['files' => ['name' => ['x'], 'type' => 5, 'tmp_name' => 6], 'params' => []]],
			'many short columns' => [['files' => ['name' => ['x', 'y'], 'type' => ['t'], 'tmp_name' => ['p'], 'error' => [1], 'size' => [5]], 'params' => []]],
			'many short columns with scalars' => [['files' => ['name' => ['x', 'y'], 'error' => 6, 'size' => 7], 'params' => []]],
			'many null in columns' => [['files' => ['name' => ['x'], 'type' => [null], 'error' => [null], 'size' => [null]], 'params' => []]],
			'names with gaps' => [['files' => ['name' => [1 => 'x', 3 => 'y'], 'type' => [1 => 'a', 3 => 'b']], 'params' => []]],
			'names with text keys' => [['files' => ['name' => ['k' => 'x'], 'type' => ['k' => 'a']], 'params' => []]],
			'empty names' => [['files' => ['name' => []], 'params' => []]],
		];
	}

	#[DataProvider('cases')]
	public function testNormalizedFilesMatchTheRecording(array $case): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params']);
		$request->method('getUploadedFile')->willReturnCallback(fn (string $key) => $case[$key] ?? null);

		$reflection = new ReflectionClass(FilesController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('request')->setValue($controller, $request);

		try {
			$answer = $reflection->getMethod('extractUploadedFiles')->invoke($controller);
		} catch (Exception $failure) {
			$answer = ['failure' => $failure->getMessage()];
		}

		$this->compare(key: $this->dataName(), actual: $answer);
	}

	private function compare(string $key, mixed $actual): void {
		$actual = json_decode((string)json_encode($actual), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= [];
			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
