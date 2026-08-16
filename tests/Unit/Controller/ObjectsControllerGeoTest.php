<?php

/**
 * RBAC-scoped geo read tests for ObjectsController geo endpoints.
 *
 * Closes geo-metadata-kaart REQ-GEO-005 / REQ-GEO-008. The geo endpoints
 * (geoJson, wfs, geocode) MUST NOT widen data access: the GeoJSON / WFS
 * outputs are built strictly from the rows the standard listing path
 * (`index()`) returns, and `index()` already enforces per-object RBAC.
 *
 * These tests stub `index()` with a partial mock so the assertion is
 * exactly "the geo output contains only the RBAC-scoped rows index()
 * exposed — never more". A second register/schema with a different
 * scoped result confirms the geo endpoints don't leak across scopes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Controller;

use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\ExportService;
use OCA\OpenRegister\Service\Geo\GeoFeatureCollectionBuilder;
use OCA\OpenRegister\Service\Geo\GeoFilterApplier;
use OCA\OpenRegister\Service\Geo\GeoFilterParser;
use OCA\OpenRegister\Service\Geo\PdokGeocoder;
use OCA\OpenRegister\Service\ImportService;
use OCA\OpenRegister\Service\ObjectService;
use OCA\OpenRegister\Service\WebhookService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ObjectsControllerGeoTest extends TestCase {

	private IRequest $request;

	/**
	 * Build an ObjectsController whose index() is stubbed to a known
	 * RBAC-scoped result, with the real GeoFeatureCollectionBuilder wired.
	 *
	 * @param array $scopedResults The rows index() would return (already RBAC-scoped).
	 *
	 * @return ObjectsController
	 */
	private function controllerWithScopedIndex(array $scopedResults): ObjectsController {
		$this->request = $this->createMock(IRequest::class);

		$controller = $this->getMockBuilder(ObjectsController::class)
			->setConstructorArgs([
				'openregister',
				$this->request,
				$this->createMock(IAppConfig::class),
				$this->createMock(IAppManager::class),
				$this->createMock(ContainerInterface::class),
				$this->createMock(RegisterMapper::class),
				$this->createMock(SchemaMapper::class),
				$this->createMock(AuditTrailMapper::class),
				$this->createMock(ObjectService::class),
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(ExportService::class),
				$this->createMock(ImportService::class),
				$this->createMock(WebhookService::class),
				$this->createMock(LoggerInterface::class),
				null,
				null,
				null,
				null,
				new GeoFeatureCollectionBuilder(),
				null,
			])
			->onlyMethods(['index'])
			->getMock();

		$controller->method('index')->willReturn(
			new JSONResponse(['results' => $scopedResults, 'total' => count($scopedResults)])
		);

		return $controller;
	}//end controllerWithScopedIndex()

	private function scopedRows(): array {
		// Pretend index() (RBAC) only returned objects 1 and 2 — object 3
		// is filtered out for this caller and MUST never appear in geo output.
		return [
			['id' => 1, 'title' => 'Visible A', 'locatie' => ['type' => 'Point', 'coordinates' => [5.1, 52.0]]],
			['id' => 2, 'title' => 'Visible B', 'locatie' => ['type' => 'Point', 'coordinates' => [4.9, 52.3]]],
		];
	}//end scopedRows()

	public function testGeoJsonOnlyExposesRbacScopedRows(): void {
		$controller = $this->controllerWithScopedIndex($this->scopedRows());
		$this->request->method('getParams')->willReturn([]);

		$response = $controller->geoJson('reg', 'sch', $this->createMock(ObjectService::class));
		$this->assertInstanceOf(JSONResponse::class, $response);

		$data = $response->getData();
		$this->assertSame('FeatureCollection', $data['type']);
		$this->assertCount(2, $data['features']);

		$ids = array_map(static fn ($f) => $f['id'], $data['features']);
		$this->assertSame([1, 2], $ids);
		$this->assertNotContains(3, $ids, 'RBAC-filtered object must never appear in GeoJSON output');
	}//end testGeoJsonOnlyExposesRbacScopedRows()

	public function testWfsOnlyExposesRbacScopedRows(): void {
		$controller = $this->controllerWithScopedIndex($this->scopedRows());
		$this->request->method('getParams')->willReturn([]);

		$response = $controller->wfs('reg', 'sch', $this->createMock(ObjectService::class));
		$data = $response->getData();
		$this->assertSame(2, $data['numberReturned']);
	}//end testWfsOnlyExposesRbacScopedRows()

	public function testEmptyRbacScopeYieldsEmptyGeoJson(): void {
		// A caller with no read access -> index() returns no rows ->
		// geo output is empty (no IDOR leakage).
		$controller = $this->controllerWithScopedIndex([]);
		$this->request->method('getParams')->willReturn([]);

		$response = $controller->geoJson('reg', 'sch', $this->createMock(ObjectService::class));
		$data = $response->getData();
		$this->assertSame([], $data['features']);
	}//end testEmptyRbacScopeYieldsEmptyGeoJson()

	public function testGeoJsonBodyIsValidGeoJson(): void {
		$controller = $this->controllerWithScopedIndex($this->scopedRows());
		$this->request->method('getParams')->willReturn([]);

		$response = $controller->geoJson('reg', 'sch', $this->createMock(ObjectService::class));
		$data = $response->getData();
		$this->assertArrayHasKey('type', $data);
		$this->assertArrayHasKey('features', $data);
		$this->assertSame('Feature', $data['features'][0]['type']);
	}//end testGeoJsonBodyIsValidGeoJson()

	public function testGeoJsonNotConfiguredReturns501(): void {
		$this->request = $this->createMock(IRequest::class);
		$controller = new ObjectsController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class)
			// No geo feature builder wired -> 501.
		);

		$response = $controller->geoJson('reg', 'sch', $this->createMock(ObjectService::class));
		$this->assertSame(501, $response->getStatus());
	}//end testGeoJsonNotConfiguredReturns501()

	/**
	 * Build an ObjectsController with the geo-filter primitives wired and
	 * index() stubbed to a known RBAC-scoped result.
	 *
	 * @param array $scopedResults The rows index() would return.
	 * @param GeoFilterParser|null $parser Geo filter parser (null → not configured).
	 * @param GeoFilterApplier|null $applier Geo filter applier (null → not configured).
	 *
	 * @return ObjectsController
	 */
	private function controllerWithGeoSearch(
		array $scopedResults,
		?GeoFilterParser $parser,
		?GeoFilterApplier $applier,
	): ObjectsController {
		$this->request = $this->createMock(IRequest::class);

		$controller = $this->getMockBuilder(ObjectsController::class)
			->setConstructorArgs([
				'openregister',
				$this->request,
				$this->createMock(IAppConfig::class),
				$this->createMock(IAppManager::class),
				$this->createMock(ContainerInterface::class),
				$this->createMock(RegisterMapper::class),
				$this->createMock(SchemaMapper::class),
				$this->createMock(AuditTrailMapper::class),
				$this->createMock(ObjectService::class),
				$this->createMock(IUserSession::class),
				$this->createMock(IGroupManager::class),
				$this->createMock(ExportService::class),
				$this->createMock(ImportService::class),
				$this->createMock(WebhookService::class),
				$this->createMock(LoggerInterface::class),
				$parser,
				$applier,
			])
			->onlyMethods(['index'])
			->getMock();

		$controller->method('index')->willReturn(
			new JSONResponse(['results' => $scopedResults, 'total' => count($scopedResults)])
		);

		return $controller;
	}//end controllerWithGeoSearch()

	/**
	 * Build an ObjectsController with only the PDOK geocoder wired.
	 *
	 * @param PdokGeocoder|null $geocoder The geocoder (null → not configured).
	 *
	 * @return ObjectsController
	 */
	private function controllerWithGeocoder(?PdokGeocoder $geocoder): ObjectsController {
		$this->request = $this->createMock(IRequest::class);

		return new ObjectsController(
			'openregister',
			$this->request,
			$this->createMock(IAppConfig::class),
			$this->createMock(IAppManager::class),
			$this->createMock(ContainerInterface::class),
			$this->createMock(RegisterMapper::class),
			$this->createMock(SchemaMapper::class),
			$this->createMock(AuditTrailMapper::class),
			$this->createMock(ObjectService::class),
			$this->createMock(IUserSession::class),
			$this->createMock(IGroupManager::class),
			$this->createMock(ExportService::class),
			$this->createMock(ImportService::class),
			$this->createMock(WebhookService::class),
			$this->createMock(LoggerInterface::class),
			null,
			null,
			null,
			null,
			null,
			$geocoder
		);
	}//end controllerWithGeocoder()

	public function testGeoSearchPostFiltersOnlyTheRbacScopedRows(): void {
		$parser = $this->createMock(GeoFilterParser::class);
		$parser->method('fromGeoSearchBody')->willReturn([['op' => 'within', 'geometry' => []]]);

		$applier = $this->createMock(GeoFilterApplier::class);
		// The applier is handed the rows index() returned — never a wider set.
		$applier->expects($this->once())
			->method('applyAll')
			->with($this->scopedRows(), [['op' => 'within', 'geometry' => []]])
			->willReturn([$this->scopedRows()[0]]);

		$controller = $this->controllerWithGeoSearch($this->scopedRows(), $parser, $applier);
		$this->request->method('getParams')->willReturn(['geometry' => ['within' => []]]);

		$response = $controller->geoSearch('reg', 'sch', $this->createMock(ObjectService::class));

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$data = $response->getData();
		$this->assertCount(1, $data['results']);
		// The total is recomputed from the filtered set, not left at the listing's.
		$this->assertSame(1, $data['total']);
	}//end testGeoSearchPostFiltersOnlyTheRbacScopedRows()

	public function testGeoSearchReturns422ForAnUnparseableGeometry(): void {
		$parser = $this->createMock(GeoFilterParser::class);
		$parser->method('fromGeoSearchBody')
			->willThrowException(new \InvalidArgumentException('geometry.within must be a Polygon'));

		$applier = $this->createMock(GeoFilterApplier::class);
		$applier->expects($this->never())->method('applyAll');

		$controller = $this->controllerWithGeoSearch([], $parser, $applier);
		$this->request->method('getParams')->willReturn(['geometry' => ['within' => 'nonsense']]);

		$response = $controller->geoSearch('reg', 'sch', $this->createMock(ObjectService::class));

		$this->assertSame(422, $response->getStatus());
		$this->assertSame('geometry.within must be a Polygon', $response->getData()['error']);
	}//end testGeoSearchReturns422ForAnUnparseableGeometry()

	public function testGeoSearchReturns501WhenTheFilterPrimitivesAreNotConfigured(): void {
		$controller = $this->controllerWithGeoSearch([], null, null);

		$response = $controller->geoSearch('reg', 'sch', $this->createMock(ObjectService::class));

		$this->assertSame(501, $response->getStatus());
		$this->assertSame('Geo filtering primitives not configured', $response->getData()['error']);
	}//end testGeoSearchReturns501WhenTheFilterPrimitivesAreNotConfigured()

	public function testGeocodeForwardsAFreeTextQueryToPdok(): void {
		$geocoder = $this->createMock(PdokGeocoder::class);
		$geocoder->method('isAvailable')->willReturn(true);
		$geocoder->expects($this->once())
			->method('geocodeFree')
			->with('Nieuwe Gracht 1 Utrecht', 5, true)
			->willReturn([['weergavenaam' => 'Nieuwe Gracht 1, Utrecht']]);

		$controller = $this->controllerWithGeocoder($geocoder);
		$this->request->method('getParams')->willReturn(
			['q' => 'Nieuwe Gracht 1 Utrecht', 'bagOnly' => 'true']
		);

		$response = $controller->geocode();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(200, $response->getStatus());
		$this->assertTrue($response->getData()['available']);
		$this->assertCount(1, $response->getData()['suggestions']);
	}//end testGeocodeForwardsAFreeTextQueryToPdok()

	public function testGeocodeReverseGeocodesALonLatPair(): void {
		$geocoder = $this->createMock(PdokGeocoder::class);
		$geocoder->method('isAvailable')->willReturn(true);
		$geocoder->expects($this->once())
			->method('reverseGeocode')
			->with(5.12, 52.09)
			->willReturn(['weergavenaam' => 'Domplein, Utrecht']);
		$geocoder->expects($this->never())->method('geocodeFree');

		$controller = $this->controllerWithGeocoder($geocoder);
		$this->request->method('getParams')->willReturn(['lon' => '5.12', 'lat' => '52.09']);

		$response = $controller->geocode();

		$this->assertSame(200, $response->getStatus());
		$this->assertSame('Domplein, Utrecht', $response->getData()['address']['weergavenaam']);
	}//end testGeocodeReverseGeocodesALonLatPair()

	public function testGeocodeReturns422WithoutAQueryOrACoordinatePair(): void {
		$geocoder = $this->createMock(PdokGeocoder::class);
		$geocoder->method('isAvailable')->willReturn(true);

		$controller = $this->controllerWithGeocoder($geocoder);
		$this->request->method('getParams')->willReturn([]);

		$response = $controller->geocode();

		$this->assertSame(422, $response->getStatus());
		$this->assertStringContainsString('requires either', $response->getData()['error']);
	}//end testGeocodeReturns422WithoutAQueryOrACoordinatePair()

	public function testGeocodeDegradesToAnEmptySuggestionListWhenPdokIsNotWired(): void {
		$controller = $this->controllerWithGeocoder(null);

		$response = $controller->geocode();

		// Geocoding is non-blocking (REQ-GEO-005): never an error status.
		$this->assertSame(200, $response->getStatus());
		$this->assertFalse($response->getData()['available']);
		$this->assertSame([], $response->getData()['suggestions']);
	}//end testGeocodeDegradesToAnEmptySuggestionListWhenPdokIsNotWired()
}//end class
