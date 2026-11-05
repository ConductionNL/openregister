<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\ObjectRequestParams;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for how ObjectsController reads its listing and form parameters.
 *
 * Every case feeds one value to one parser and compares the answer with
 * fixtures/objects-request-params-golden.json. Set UPDATE_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @covers \OCA\OpenRegister\Controller\Support\ObjectRequestParams
 */
class ObjectsControllerRequestParamsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-request-params-golden.json';

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function configCases(): array {
		return [
			'empty' => [[]],
			'route keys dropped' => [['id' => 'x', '_route' => 'r', 'a' => 1]],
			'limit' => [['limit' => '5']],
			'underscore limit' => [['_limit' => '7']],
			'limit wins over underscore limit' => [['limit' => '5', '_limit' => '7']],
			'null limit falls through' => [['limit' => null, '_limit' => '7']],
			'zero limit' => [['limit' => 0, 'page' => 3]],
			'text limit' => [['limit' => 'many']],
			'offset' => [['offset' => '4']],
			'underscore offset' => [['_offset' => '6']],
			'offset wins over underscore offset' => [['offset' => '4', '_offset' => '6']],
			'null offset keeps underscore offset' => [['offset' => null, '_offset' => '6']],
			'zero offset stays' => [['offset' => 0, 'page' => 3]],
			'page derives offset' => [['page' => '3', 'limit' => '10']],
			'underscore page derives offset' => [['_page' => '2']],
			'page wins over underscore page' => [['page' => '3', '_page' => '2', 'limit' => 10]],
			'null page keeps underscore page' => [['page' => null, '_page' => '2', 'limit' => 10]],
			'offset wins over page' => [['page' => '3', 'offset' => '1', 'limit' => 10]],
			'page zero' => [['page' => 0, 'limit' => 10]],
			'order array' => [['order' => ['a' => 'asc']]],
			'order wins over underscore order' => [['order' => ['a' => 'asc'], '_order' => ['b' => 'desc']]],
			'underscore order json' => [['_order' => '{"deadline":"asc"}']],
			'order bad json' => [['order' => '{nope']],
			'order empty string' => [['order' => '']],
			'order scalar json' => [['order' => '5']],
			'order number' => [['order' => 5]],
			'search' => [['_search' => 'q']],
			'extend string' => [['extend' => 'a,@self.schema,@self.register']],
			'underscore extend array' => [['_extend' => ['@self.schema', 'b']]],
			'extend wins' => [['extend' => 'a', '_extend' => 'b']],
			'fields and unset' => [['fields' => 'a', '_fields' => 'b', 'unset' => 'c', '_unset' => 'd']],
			'underscore fields and unset' => [['_fields' => 'b', '_unset' => 'd']],
		];
	}

	/**
	 * @return array<string, array{0: mixed}>
	 */
	public static function extendCases(): array {
		return [
			'null' => [null],
			'empty string' => [''],
			'one' => ['a'],
			'list' => ['a,b'],
			'aliases in a string' => ['@self.schema,@self.register,c'],
			'spaces are kept' => ['a, @self.schema'],
			'array' => [['@self.register', 'x']],
			'keys are kept' => [['k' => '@self.schema', 'l' => 'y', 7 => '@self.register']],
			'non-strings are kept' => [[1, null, true, ['n'], '@self.schema']],
			'empty array' => [[]],
			'number' => [12],
			'true' => [true],
		];
	}

	/**
	 * @return array<string, array{0: mixed, 1: string}>
	 */
	public static function multiValueCases(): array {
		return [
			'null' => [null, 'd'],
			'empty string' => ['', 'd'],
			'empty default' => [null, ''],
			'one' => ['a', 'd'],
			'zero string' => ['0', 'd'],
			'number' => [5, 'd'],
			'false' => [false, 'd'],
			'array' => [['a', 'b'], 'd'],
			'array duplicates' => [['1', '1', '2'], 'd'],
			'array empties' => [['', 'a', null, '0', 0, 'b'], 'd'],
			'array keys dropped' => [['x' => 'a', 'y' => 'b'], 'd'],
			'empty array' => [[], 'd'],
			'comma' => ['a,b', 'd'],
			'comma spaces' => [' a , b ', 'd'],
			'comma duplicates' => ['a,a,b', 'd'],
			'comma empties' => ['a,,0,b,', 'd'],
			'only commas' => [',,', 'd'],
			'no comma keeps spaces' => [' a ', 'd'],
		];
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>}>
	 */
	public static function formCases(): array {
		$values = [
			'list' => '[{"voornaam":"John"}]',
			'object' => '{"a":1}',
			'padded' => "  [1,2]\n",
			'broken list' => '[1,',
			'broken object' => '{a',
			'plain' => 'text',
			'empty' => '',
			'blank' => '   ',
			'empty list' => '[]',
			'number' => 5,
			'null' => null,
			'array' => ['[1]'],
			'scalar json' => '12',
			'bool json' => 'true',
		];
		return [
			'multipart' => ['multipart/form-data; boundary=x', $values],
			'upper case multipart' => ['MULTIPART/FORM-DATA', $values],
			'json' => ['application/json', $values],
			'no type' => ['', $values],
			'multipart nothing' => ['multipart/form-data', []],
		];
	}

	#[DataProvider('configCases')]
	public function testConfigMatchesTheRecording(array $params): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($params);
		$this->compare(key: 'config ' . $this->dataName(), actual: $this->privateCall(request: $request, method: 'getConfig', args: []));
	}

	#[DataProvider('extendCases')]
	public function testExtendMatchesTheRecording(mixed $extend): void {
		$this->compare(key: 'extend ' . $this->dataName(), actual: (new ObjectRequestParams())->extend(extend: $extend));
	}

	#[DataProvider('multiValueCases')]
	public function testMultiValueMatchesTheRecording(mixed $param, string $default): void {
		$this->compare(key: 'multi ' . $this->dataName(), actual: (new ObjectRequestParams())->multiValue(param: $param, defaultValue: $default));
	}

	#[DataProvider('formCases')]
	public function testFormDataMatchesTheRecording(string $contentType, array $data): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getHeader')->with('Content-Type')->willReturn($contentType);
		$this->compare(key: 'form ' . $this->dataName(), actual: $this->privateCall(request: $request, method: 'normalizeFormDataValues', args: [$data]));
	}

	/**
	 * @param list<mixed> $args
	 */
	private function privateCall(IRequest $request, string $method, array $args): mixed {
		$reflection = new ReflectionClass(ObjectsController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('request')->setValue($controller, $request);
		return $reflection->getMethod($method)->invokeArgs($controller, $args);
	}

	private function compare(string $key, mixed $actual): void {
		$actual = json_decode((string)json_encode(['value' => $actual, 'type' => get_debug_type($actual)]), true);
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
