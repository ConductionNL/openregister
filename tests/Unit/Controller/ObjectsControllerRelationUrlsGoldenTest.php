<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use JsonSerializable;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\LinkedDataServices;
use OCA\OpenRegister\Service\DeepLinkRegistryService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use RuntimeException;

/**
 * Golden test for how ObjectsController stamps a url on relation records.
 *
 * Every case records each resolver, generator and logger call and the envelope
 * it answers, and compares them with fixtures/objects-relation-urls-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @covers \OCA\OpenRegister\Controller\Support\RelationUrlStamper
 * @uses   \OCA\OpenRegister\Controller\Support\LinkedDataServices
 */
class ObjectsControllerRelationUrlsGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-relation-urls-golden.json';

	/** @var list<mixed> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$row = ['title' => 't', '@self' => ['id' => 'u1', 'register' => 1, 'schema' => '2']];
		$flat = ['id' => 'u2', '@self' => ['register' => '3', 'schema' => 4]];
		return [
			'no results key' => [['result' => ['total' => 0]]],
			'results not an array' => [['result' => ['results' => 'x']]],
			'no resolver' => [['resolver' => 'none', 'result' => ['results' => [$row]]]],
			'resolved' => [['resolver' => 'url', 'result' => ['results' => [$row], 'total' => 1]]],
			'id outside @self' => [['resolver' => 'url', 'result' => ['results' => [$flat]]]],
			'row keeps its own keys' => [['resolver' => 'url', 'result' => ['results' => ['k' => $row + ['register' => 'x', 'uuid' => 'y', 'url' => 'old']]]]],
			'no id' => [['resolver' => 'url', 'result' => ['results' => [['@self' => ['register' => 1, 'schema' => 2]]]]]],
			'text register' => [['resolver' => 'url', 'result' => ['results' => [['@self' => ['id' => 'u', 'register' => 'r', 'schema' => 2]]]]]],
			'text schema' => [['resolver' => 'url', 'result' => ['results' => [['@self' => ['id' => 'u', 'register' => 1, 'schema' => 's']]]]]],
			'no @self' => [['resolver' => 'url', 'result' => ['results' => [['id' => 'u']]]]],
			'scalar rows' => [['resolver' => 'url', 'result' => ['results' => ['a', 5, null]]]],
			'route fallback' => [['resolver' => 'null', 'result' => ['results' => [$row]]]],
			'no url at all' => [['resolver' => 'null', 'generator' => 'none', 'result' => ['results' => [$row]]]],
			'resolver throws' => [['resolver' => 'throw', 'result' => ['results' => [$row, $flat]]]],
			'generator throws' => [['resolver' => 'null', 'generator' => 'throw', 'result' => ['results' => [$row]]]],
			'serializable row' => [['resolver' => 'url', 'serializable' => $row, 'result' => ['results' => []]]],
			'row serializing to text stays' => [['resolver' => 'url', 'serializable' => 'text', 'result' => ['results' => []]]],
			'plain object stays' => [['resolver' => 'url', 'plain' => true, 'result' => ['results' => []]]],
		];
	}

	#[DataProvider('cases')]
	public function testStampedEnvelopeMatchesTheRecording(array $case): void {
		$this->calls = [];
		$result = $case['result'];
		if (array_key_exists('serializable', $case) === true) {
			$result['results'][] = new class($case['serializable']) implements JsonSerializable {
				public function __construct(private mixed $value) {
				}

				public function jsonSerialize(): mixed {
					return $this->value;
				}
			};
		}

		if (isset($case['plain']) === true) {
			$result['results'][] = (object)['kept' => true];
		}

		$actual = $this->stamp(
			linkedData: new LinkedDataServices(
				deepLinkRegistry: $this->resolver(mode: $case['resolver'] ?? 'url'),
				relationUrlGenerator: $this->generator(mode: $case['generator'] ?? 'url')
			),
			result: $result
		);

		$this->compare(key: $this->dataName(), actual: ['calls' => $this->calls, 'result' => $actual]);
	}

	private function resolver(string $mode): ?DeepLinkRegistryService {
		if ($mode === 'none') {
			return null;
		}

		$resolver = $this->createMock(DeepLinkRegistryService::class);
		$resolver->method('resolveUrl')->willReturnCallback(function (int $registerId, int $schemaId, array $objectData) use ($mode): ?string {
			$this->calls[] = ['resolveUrl' => [$registerId, $schemaId, $objectData, array_keys($objectData)]];
			if ($mode === 'throw') {
				throw new RuntimeException('no resolver');
			}

			if ($mode === 'null') {
				return null;
			}

			return 'https://app/' . $registerId . '/' . $schemaId;
		});
		return $resolver;
	}

	private function generator(string $mode): ?IURLGenerator {
		if ($mode === 'none') {
			return null;
		}

		$generator = $this->createMock(IURLGenerator::class);
		$generator->method('linkToRoute')->willReturnCallback(function (string $route, array $parameters) use ($mode): string {
			$this->calls[] = ['linkToRoute' => [$route, $parameters]];
			if ($mode === 'throw') {
				throw new RuntimeException('no route');
			}

			return 'https://route/' . $parameters['id'];
		});
		return $generator;
	}

	private function stamp(LinkedDataServices $linkedData, array $result): array {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('debug')->willReturnCallback(function (string|\Stringable $message): void {
			$this->calls[] = ['debug' => (string)$message];
		});

		$reflection = new ReflectionClass(ObjectsController::class);
		$controller = $reflection->newInstanceWithoutConstructor();
		$reflection->getProperty('linkedData')->setValue($controller, $linkedData);
		$reflection->getProperty('logger')->setValue($controller, $logger);
		return $reflection->getMethod('stampObjectUrls')->invoke($controller, $result);
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
