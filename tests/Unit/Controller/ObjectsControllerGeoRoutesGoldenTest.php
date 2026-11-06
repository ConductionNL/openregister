<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Controller;

use InvalidArgumentException;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Controller\Support\GeoServices;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Search\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterApplier;
use OCA\OpenRegister\Search\Service\Geo\GeoFilterParser;
use OCA\OpenRegister\Service\Geo\PdokGeocoder;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Golden test for the geo routes of ObjectsController and the spatial post-filter of a listing.
 *
 * Every case records each collaborator call in order and the answer, and
 * compares them with fixtures/objects-geo-routes-golden.json.
 * Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Controller\ObjectsController
 * @uses \OCA\OpenRegister\Search\Service\Geo\GeoResponseCrs
 * @uses   \OCA\OpenRegister\Controller\Support\GeoServices
 */
class ObjectsControllerGeoRoutesGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/objects-geo-routes-golden.json';

	/** @var list<mixed> */
	private array $calls = [];

	/** @var array<string, mixed>|null */
	private static ?array $recorded = null;

	/** @var array<string, mixed> */
	private static array $golden = [];

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function geoSearchCases(): array {
		$rows = ['results' => [['id' => 1], ['id' => 2], ['id' => 3]], 'total' => 3, 'page' => 1];
		return [
			'no parser' => [['services' => ['applier', 'builder'], 'params' => [], 'listing' => $rows]],
			'no applier' => [['services' => ['parser', 'builder'], 'params' => [], 'listing' => $rows]],
			'bad body' => [['parse' => 'throw', 'params' => ['x' => 1], 'listing' => $rows]],
			'filtered' => [['params' => ['polygon' => 'p'], 'listing' => $rows]],
			'no total' => [['params' => [], 'listing' => ['results' => [['id' => 1], ['id' => 2]]]]],
			'no results' => [['params' => [], 'listing' => ['total' => 9]]],
			'results not an array' => [['params' => [], 'listing' => ['results' => 'x', 'total' => 1]]],
			'null total' => [['params' => [], 'listing' => ['results' => [['id' => 1]], 'total' => null]]],
		];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function featureCases(): array {
		$rows = ['results' => [['id' => 1]]];
		return [
			'no builder' => [['services' => ['parser', 'applier'], 'params' => [], 'listing' => $rows]],
			'plain' => [['params' => [], 'listing' => $rows]],
			'no results' => [['params' => [], 'listing' => ['total' => 1]]],
			'results not an array' => [['params' => [], 'listing' => ['results' => 'x']]],
			'property' => [['params' => ['geo.property' => 'location'], 'listing' => $rows]],
			'number property' => [['params' => ['geo.property' => 5], 'listing' => $rows]],
			'empty property' => [['params' => ['geo.property' => ''], 'listing' => $rows]],
			'zero property' => [['params' => ['geo.property' => '0'], 'listing' => $rows]],
			'fields string' => [['params' => ['_fields' => 'a, b,,c '], 'listing' => $rows]],
			'fields array' => [['params' => ['_fields' => ['a', 2]], 'listing' => $rows]],
			'empty fields' => [['params' => ['_fields' => ''], 'listing' => $rows]],
			'count' => [['params' => ['count' => '7'], 'listing' => $rows]],
			'max features' => [['params' => ['maxFeatures' => 4], 'listing' => $rows]],
			'count wins' => [['params' => ['count' => 7, 'maxFeatures' => 4], 'listing' => $rows]],
			'null count falls through' => [['params' => ['count' => null, 'maxFeatures' => 4], 'listing' => $rows]],
			'text count' => [['params' => ['count' => 'many', 'maxFeatures' => 4], 'listing' => $rows]],
			'float count' => [['params' => ['count' => '2.9'], 'listing' => $rows]],
		];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function geocodeCases(): array {
		return [
			'no geocoder' => [['services' => ['parser'], 'params' => ['q' => 'x']]],
			'nothing' => [['params' => []]],
			'free' => [['params' => ['q' => 'Dam 1']]],
			'free unavailable' => [['available' => false, 'params' => ['q' => 'Dam 1']]],
			'free number' => [['params' => ['q' => 12]]],
			'free bag only' => [['params' => ['q' => 'Dam', 'bagOnly' => 'true']]],
			'free bag text' => [['params' => ['q' => 'Dam', 'bagOnly' => 'nope']]],
			'blank query falls through' => [['params' => ['q' => '  ', 'lon' => '4.9', 'lat' => '52.3']]],
			'free wins over coordinates' => [['params' => ['q' => 'Dam', 'lon' => 4, 'lat' => 52]]],
			'reverse' => [['params' => ['lon' => 4.9, 'lat' => '52.3']]],
			'only lon' => [['params' => ['lon' => 4.9]]],
			'only lat' => [['params' => ['lat' => 52]]],
			'text lon' => [['params' => ['lon' => 'x', 'lat' => 52]]],
			'text lat' => [['params' => ['lon' => 4, 'lat' => 'y']]],
		];
	}

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function postFilterCases(): array {
		$result = ['results' => [['id' => 1], ['id' => 2]], 'total' => 2];
		return [
			'no parser' => [['services' => ['applier'], 'params' => ['geo.bbox' => 'b'], 'result' => $result]],
			'no applier' => [['services' => ['parser'], 'params' => ['geo.bbox' => 'b'], 'result' => $result]],
			'no geo params' => [['params' => ['x' => 1], 'result' => $result]],
			'other geo key' => [['params' => ['geo.other' => 1], 'result' => $result]],
			'null bbox' => [['params' => ['geo.bbox' => null], 'result' => $result]],
			'bbox' => [['params' => ['geo.bbox' => 'b'], 'result' => $result]],
			'near' => [['params' => ['geo.near' => 'n'], 'result' => $result]],
			'radius' => [['params' => ['geo.radius' => 5], 'result' => $result]],
			'property' => [['params' => ['geo.property' => 'p'], 'result' => $result]],
			'nested' => [['params' => ['geo' => ['bbox' => 'b', 'x' => 1]], 'result' => $result]],
			'flat wins over nested' => [['params' => ['geo' => ['bbox' => 'nested'], 'geo.bbox' => 'flat'], 'result' => $result]],
			'nested not an array' => [['params' => ['geo' => 'x'], 'result' => $result]],
			'parse error' => [['parse' => 'throw', 'params' => ['geo.bbox' => 'b'], 'result' => $result]],
			'no filters' => [['parse' => 'empty', 'params' => ['geo.bbox' => 'b'], 'result' => $result]],
			'no results' => [['params' => ['geo.bbox' => 'b'], 'result' => ['total' => 2]]],
			'results not an array' => [['params' => ['geo.bbox' => 'b'], 'result' => ['results' => 'x', 'total' => 2]]],
			'no total' => [['params' => ['geo.bbox' => 'b'], 'result' => ['results' => [['id' => 1], ['id' => 2]]]]],
		];
	}

	#[DataProvider('geoSearchCases')]
	public function testGeoSearchMatchesTheRecording(array $case): void {
		$service = $this->createMock(ObjectOperationsInterface::class);
		$this->compareResponse(key: 'search ' . $this->dataName(), response: $this->controller(case: $case)->geoSearch('r', 's', $service));
	}

	#[DataProvider('featureCases')]
	public function testGeoJsonMatchesTheRecording(array $case): void {
		$service = $this->createMock(ObjectOperationsInterface::class);
		$this->compareResponse(key: 'geojson ' . $this->dataName(), response: $this->controller(case: $case)->geoJson('r', 's', $service));
	}

	#[DataProvider('featureCases')]
	public function testWfsMatchesTheRecording(array $case): void {
		$service = $this->createMock(ObjectOperationsInterface::class);
		$this->compareResponse(key: 'wfs ' . $this->dataName(), response: $this->controller(case: $case)->wfs('r', 's', $service));
	}

	#[DataProvider('geocodeCases')]
	public function testGeocodeMatchesTheRecording(array $case): void {
		$this->compareResponse(key: 'geocode ' . $this->dataName(), response: $this->controller(case: $case)->geocode());
	}

	#[DataProvider('postFilterCases')]
	public function testPostFilterMatchesTheRecording(array $case): void {
		$controller = $this->controller(case: $case);
		$method = (new ReflectionClass(ObjectsController::class))->getMethod('applyGeoQueryFilters');
		$answer = $method->invoke($controller, $case['params'], $case['result']);
		$this->compare(key: 'filter ' . $this->dataName(), actual: ['calls' => $this->calls, 'answer' => $answer]);
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function controller(array $case): ObjectsController {
		$this->calls = [];
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn($case['params']);

		$controller = $this->getMockBuilder(ObjectsController::class)
			->disableOriginalConstructor()
			->onlyMethods(['index'])
			->getMock();
		$controller->method('index')->willReturnCallback(function (string $register, string $schema) use ($case): JSONResponse {
			$this->calls[] = ['index' => [$register, $schema]];
			return new JSONResponse(data: $case['listing'] ?? []);
		});

		$reflection = new ReflectionClass(ObjectsController::class);
		$reflection->getProperty('request')->setValue($controller, $request);
		$reflection->getProperty('geo')->setValue($controller, $this->services(case: $case));
		return $controller;
	}

	/**
	 * @param array<string, mixed> $case
	 */
	private function services(array $case): GeoServices {
		$wanted = ($case['services'] ?? ['parser', 'applier', 'builder', 'geocoder']);
		return new GeoServices(
			geoFilterParser: in_array('parser', $wanted, true) ? $this->parser(mode: $case['parse'] ?? 'filters') : null,
			geoFilterApplier: in_array('applier', $wanted, true) ? $this->applier() : null,
			geoFeatureBuilder: in_array('builder', $wanted, true) ? $this->builder() : null,
			pdokGeocoder: in_array('geocoder', $wanted, true) ? $this->geocoder(available: $case['available'] ?? true) : null,
		);
	}

	private function parser(string $mode): GeoFilterParser {
		$answer = function (string $name, array $input) use ($mode): array {
			$this->calls[] = [$name => $input];
			if ($mode === 'throw') {
				throw new InvalidArgumentException('bad geo');
			}

			return $mode === 'empty' ? [] : ['filter'];
		};
		$parser = $this->createMock(GeoFilterParser::class);
		$parser->method('fromGeoSearchBody')->willReturnCallback(fn (array $body) => $answer('fromGeoSearchBody', $body));
		$parser->method('fromQueryParams')->willReturnCallback(fn (array $params) => $answer('fromQueryParams', $params));
		return $parser;
	}

	private function applier(): GeoFilterApplier {
		$applier = $this->createMock(GeoFilterApplier::class);
		$applier->method('applyAll')->willReturnCallback(function (array $rows, array $filters): array {
			$this->calls[] = ['applyAll' => [$rows, $filters]];
			return array_slice($rows, 0, 1);
		});
		return $applier;
	}

	private function builder(): GeoFeatureCollectionBuilder {
		$builder = $this->createMock(GeoFeatureCollectionBuilder::class);
		$builder->method('buildFeatureCollection')->willReturnCallback(function (...$args): array {
			$this->calls[] = ['buildFeatureCollection' => $args];
			return ['type' => 'FeatureCollection'];
		});
		$builder->method('buildWfsResponse')->willReturnCallback(function (...$args): array {
			$this->calls[] = ['buildWfsResponse' => $args];
			return ['type' => 'wfs'];
		});
		return $builder;
	}

	private function geocoder(bool $available): PdokGeocoder {
		$geocoder = $this->createMock(PdokGeocoder::class);
		$geocoder->method('isAvailable')->willReturnCallback(function () use ($available): bool {
			$this->calls[] = 'isAvailable';
			return $available;
		});
		$geocoder->method('geocodeFree')->willReturnCallback(function (string $query, int $maxItems, bool $bagOnly): array {
			$this->calls[] = ['geocodeFree' => [$query, $maxItems, $bagOnly]];
			return [['label' => $query]];
		});
		$geocoder->method('reverseGeocode')->willReturnCallback(function (float $longitude, float $latitude): array {
			$this->calls[] = ['reverseGeocode' => [$longitude, $latitude]];
			return ['label' => 'address'];
		});
		return $geocoder;
	}

	private function compareResponse(string $key, JSONResponse $response): void {
		$this->compare(key: $key, actual: ['calls' => $this->calls, 'status' => $response->getStatus(), 'data' => $response->getData()]);
	}

	private function compare(string $key, mixed $actual): void {
		$actual = json_decode((string)json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			self::$recorded ??= [];
			self::$recorded[$key] = $actual;
			ksort(self::$recorded);
			file_put_contents(self::FIXTURE, json_encode(self::$recorded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n");
			self::$golden = self::$recorded;
		}

		if (self::$golden === []) {
			self::$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$this->assertArrayHasKey($key, self::$golden);
		$this->assertSame(self::$golden[$key], $actual);
	}
}
