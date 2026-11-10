<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for how ObjectsController collects the uploaded files of a request.
 *
 * Every case sets $_FILES and the request's parameters and uploads, records
 * each getUploadedFile() call and the collected files, and compares the
 * recording with fixtures/objects-uploads-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 */
class ObjectsControllerUploadsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-uploads-golden.json';

	/** @var list<mixed> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/** @var array<string, mixed> */
	private array $savedFiles = [];

	protected function setUp(): void {
		$this->savedFiles = $_FILES;
	}

	protected function tearDown(): void {
		$_FILES = $this->savedFiles;
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$one = ['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 3];
		$many = ['name' => ['x.png', 'y.png', 'z.png'], 'type' => ['image/png', 'image/png'], 'tmp_name' => ['/tmp/x', '', '/tmp/z'], 'error' => [0, 4, 0], 'size' => [1, 0, 2]];
		return [
			'nothing' => [['files' => [], 'params' => [], 'uploads' => []]],
			'one file' => [['files' => ['doc' => 1], 'params' => [], 'uploads' => ['doc' => $one]]],
			'system field skipped' => [['files' => ['_doc' => 1], 'params' => ['_x' => 1], 'uploads' => ['_doc' => $one, '_x' => $one]]],
			'empty tmp name' => [['files' => ['doc' => 1], 'params' => [], 'uploads' => ['doc' => ['name' => 'a', 'tmp_name' => '']]]],
			'no tmp name' => [['files' => ['doc' => 1], 'params' => [], 'uploads' => ['doc' => ['name' => 'a']]]],
			'no upload' => [['files' => ['doc' => 1], 'params' => ['doc' => 'text'], 'uploads' => []]],
			'multiple files' => [['files' => ['pics' => 1], 'params' => [], 'uploads' => ['pics' => $many]]],
			'multiple files also in params' => [['files' => ['pics' => 1], 'params' => ['pics' => '', 'title' => 'x'], 'uploads' => ['pics' => $many]]],
			'multiple with scalar parts' => [['files' => ['pics' => 1], 'params' => [], 'uploads' => ['pics' => ['name' => ['p', 'q'], 'type' => 'x', 'tmp_name' => ['/tmp/p', '/tmp/q'], 'error' => 0, 'size' => 9]]]],
			'multiple without tmp names' => [['files' => ['pics' => 1], 'params' => [], 'uploads' => ['pics' => ['name' => ['p'], 'tmp_name' => 'none']]]],
			'param-only upload' => [['files' => [], 'params' => ['attachment' => '', 'other' => 'v'], 'uploads' => ['attachment' => $one]]],
			'same field in both' => [['files' => ['doc' => 1], 'params' => ['doc' => ''], 'uploads' => ['doc' => $one]]],
			'numeric field names' => [['files' => ['7' => 1], 'params' => ['8' => ''], 'uploads' => ['7' => $one, '8' => $one]]],
			'mixed' => [['files' => ['doc' => 1, 'pics' => 1, '_skip' => 1], 'params' => ['extra' => '', 'doc' => ''], 'uploads' => ['doc' => $one, 'pics' => $many, 'extra' => $one]]],
		];
	}

	#[DataProvider('cases')]
	public function testCollectedUploadsMatchTheRecording(array $case): void {
		$this->calls = [];
		$_FILES = $case['files'];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params']);
		$request->method('getUploadedFile')->willReturnCallback(function (string $key) use ($case) {
			$this->calls[] = ['request.getUploadedFile' => $key];
			return $case['uploads'][$key] ?? null;
		});

		$reflection = new ReflectionClass(ObjectsController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$property = $reflection->getProperty('request');
		$property->setValue($controller, $request);
		$method = $reflection->getMethod('extractAllUploadedFiles');

		$files = $method->invoke($controller);
		$this->compare(key: $this->dataName(), actual: ['calls' => $this->calls, 'files' => $files, 'keys' => array_map('gettype', array_keys($files))]);
	}

	/**
	 * @param array<string, mixed> $actual
	 */
	private function compare(string $key, array $actual): void {
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
