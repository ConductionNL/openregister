<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service;

use Adbar\Dot;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Service\MappingService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Golden test for every mapping cast, applied through MappingService::handleCast().
 *
 * Each cast runs against a fixed set of values; the recording holds the mapped
 * document, whether the key survived, any PHP warning and any thrown error.
 * Compared with fixtures/mapping-cast-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 */
class MappingServiceCastGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/mapping-cast-golden.json';

	private const CASTS = [
		'string', 'bool', 'boolean', '?bool', '?boolean', 'int', 'integer', 'float', 'array',
		'date', 'url', 'urlDecode', 'rawurl', 'rawurlDecode', 'html', 'htmlDecode', 'base64',
		'base64Decode', 'json', 'jsonToArray', 'utf8', 'nullStringToNull', 'coordinateStringToArray',
		'keyCantBeValue', 'unsetIfValue==', 'unsetIfValue==hello', 'setNullIfValue==',
		'setNullIfValue==hello', 'countValue:other', 'countValue:missing', 'countValue:', 'countValue:scalar',
		'moneyStringToInt', 'intToMoneyString', 'unknownCast', 'unsetIfValue', 'setNullIfValue', 'countValue',
	];

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function cases(): array {
		$cases = [];
		foreach (self::CASTS as $cast) {
			$cases[$cast] = [$cast];
		}

		return $cases;
	}//end cases()

	/**
	 * @return array<string, mixed>
	 */
	private static function values(): array {
		return [
			'null' => null,
			'empty' => '',
			'true string' => 'true',
			'yes upper' => 'YES',
			'one string' => '1',
			'one int' => 1,
			'zero int' => 0,
			'no' => 'no',
			'text' => 'hello',
			'markup' => 'a & <b>"c"</b>',
			'encoded markup' => '&lt;b&gt;&amp;',
			'base64' => 'aGVsbG8=',
			'url encoded' => 'a%20b+c',
			'json text' => '{"a":1,"b":[1,2]}',
			'json entity text' => '{&quot;a&quot;:1}',
			'list' => [1, 2],
			'null map' => ['a' => null, 'b' => ['c' => null]],
			'filled map' => ['a' => null, 'b' => 'x'],
			'empty list' => [],
			'null string' => 'null',
			'coordinates' => '52.1,4.3',
			'money string' => '1.234,56',
			'cents' => 123456,
			'float' => 3.75,
			'accented' => 'Ünïcödé',
			'key name' => 'f',
			'date format' => '\\Y\\m',
		];
	}//end values()

	#[DataProvider('cases')]
	public function testCastMatchesGolden(string $cast): void {
		$service = $this->service();
		$handleCast = new \ReflectionMethod(MappingService::class, 'handleCast');

		$actual = [];
		foreach (self::values() as $label => $value) {
			// `date` formats the current time; only values without format characters are stable.
			if ($cast === 'date' && in_array($label, ['null', 'empty', 'one string', 'one int', 'zero int', 'cents', 'date format'], true) === false) {
				continue;
			}

			$dot = new Dot(['f' => $value, 'other' => [1, 2, 3], 'scalar' => 5]);
			$warnings = [];
			set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
				$warnings[] = $message;
				return true;
			});
			try {
				$handleCast->invoke($service, $dot, 'f', $cast);
				$outcome = ['document' => $dot->all(), 'hasKey' => $dot->has('f')];
			} catch (\Throwable $e) {
				$outcome = ['threw' => get_class($e), 'message' => $e->getMessage()];
			} finally {
				restore_error_handler();
			}

			if ($warnings !== []) {
				$outcome['warnings'] = $warnings;
			}

			$actual[$label] = $outcome;
		}//end foreach

		$actual = json_decode(json_encode($actual, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), true);

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$cast] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($cast, $golden, 'No golden recording for this cast.');
		$this->assertSame($golden[$cast], $actual);
	}//end testCastMatchesGolden()

	private function service(): MappingService {
		$cacheFactory = $this->createMock(ICacheFactory::class);
		$cacheFactory->method('createDistributed')->willReturn($this->createMock(ICache::class));
		$cacheFactory->method('createLocal')->willReturn($this->createMock(ICache::class));

		return new MappingService(
			$this->createMock(MappingMapper::class),
			$cacheFactory,
			$this->createMock(LoggerInterface::class)
		);
	}//end service()
}//end class
