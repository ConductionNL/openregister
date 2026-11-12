<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\AppHost;

use OCA\OpenRegister\AppHost\Observability\ObservabilityManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for ObservabilityManifest::fromManifest().
 *
 * Each case parses one manifest; the recording holds every public property of the
 * resulting config, including its health check and metric descriptors and diagnostics.
 * Compared with fixtures/observability-manifest-golden.json; set UPDATE_GOLDEN=1 to rewrite it.
 *
 * @covers \OCA\OpenRegister\AppHost\Observability\ObservabilityManifest
 * @uses   \OCA\OpenRegister\AppHost\Observability\HealthCheckDescriptor
 * @uses   \OCA\OpenRegister\AppHost\Observability\MetricDescriptor
 * @uses   \OCA\OpenRegister\AppHost\Observability\ObservabilityValidationException
 */
class ObservabilityManifestGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/observability-manifest-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$check = ['id' => 'cfg', 'type' => 'appConfig', 'app' => 'demo', 'key' => 'k', 'severity' => 'degraded'];
		$metric = ['name' => 'app_items_total', 'type' => 'gauge', 'source' => ['kind' => 'appConfig', 'app' => 'demo', 'key' => 'items']];

		return [
			'no block' => [[]],
			'no block, registers' => [['registers' => ['a']]],
			'block not an array' => [['observability' => 'yes', 'openregister' => ['registers' => ['x']]]],
			'empty block' => [['observability' => []]],
			'empty block, config registers' => [['observability' => [], 'config' => ['registers' => [1]]]],
			'valid policy and cors' => [['observability' => ['health' => ['statusCodePolicy' => 'always200', 'cors' => true, 'checks' => [$check]]]]],
			'policy unknown string' => [['observability' => ['health' => ['statusCodePolicy' => 'teapot']]]],
			'policy int' => [['observability' => ['health' => ['statusCodePolicy' => 418]]]],
			'policy array' => [['observability' => ['health' => ['statusCodePolicy' => ['x']]]]],
			'policy null' => [['observability' => ['health' => ['statusCodePolicy' => null]]]],
			'cors truthy string' => [['observability' => ['health' => ['cors' => 'true', 'checks' => [$check]]]]],
			'health not an array' => [['observability' => ['health' => 'ok']]],
			'checks not an array' => [['observability' => ['health' => ['checks' => 'db']]]],
			'check not an object' => [['observability' => ['health' => ['checks' => [$check, 'db']]]]],
			'check invalid' => [['observability' => ['health' => ['checks' => [['type' => 'nope'], $check]]]]],
			'checks empty' => [['observability' => ['health' => ['checks' => []]], 'registers' => ['r']]],
			'second check without id' => [['observability' => ['health' => ['checks' => [$check, ['type' => 'database']]]]]],
			'checks keyed' => [['observability' => ['health' => ['checks' => ['a' => $check, 'b' => ['type' => 'database']]]]]],
			'metrics valid' => [['observability' => ['metrics' => [$metric]]]],
			'metrics not an array' => [['observability' => ['metrics' => 5]]],
			'metric not an object' => [['observability' => ['metrics' => [$metric, 'x']]]],
			'metric invalid' => [['observability' => ['metrics' => [['name' => 'Bad Name'], $metric]]]],
			'everything wrong' => [['observability' => ['health' => ['statusCodePolicy' => 'x', 'checks' => [1]], 'metrics' => [2, ['name' => '1']]]]],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $manifest The decoded manifest
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testFromManifestMatchesGolden(array $manifest): void {
		$config = ObservabilityManifest::fromManifest('demo', $manifest);
		$actual = json_decode(json_encode(get_object_vars($config)), true);

		$name = $this->dataName();
		$golden = [];
		if (file_exists(self::FIXTURE) === true) {
			$golden = json_decode((string) file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testFromManifestMatchesGolden()
}//end class
