<?php

declare(strict_types=1);

/**
 * SettingsController Unit Test
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

namespace OCA\OpenRegister\Tests\Unit\Controller;

use OCA\OpenRegister\Tests\Unit\Controller\Support\SettingsControllerFactory;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use OCA\OpenRegister\Controller\SettingsController;
use OCA\OpenRegister\Service\SettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for SettingsController
 *
 * These tests focus on controller behavior and API response formatting.
 * They would catch issues like malformed JSON responses or missing error handling.
 *
 * @package OCA\OpenRegister\Tests\Unit\Controller
 * @category Testing
 * @author  OpenRegister Development Team
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version 1.0.0
 * @link    https://github.com/ConductionNL/openregister
 */
class SettingsControllerTest extends TestCase {
	private SettingsController $controller;
	private SettingsService $settingsService;
	private IAppConfig $config;
	private IDBConnection $db;
	private ContainerInterface $container;
	private IAppManager $appManager;
	private IRequest $request;
	private LoggerInterface $logger;
	private IL10N $l10n;

	/**
	 * Set up test dependencies
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->request = $this->createMock(IRequest::class);
		$this->config = $this->createMock(IAppConfig::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->container = $this->createMock(ContainerInterface::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l10n = $this->createMock(IL10N::class);
		$this->l10n->method('t')->willReturnCallback(
			static function (string $text, $parameters = []): string {
				return vsprintf($text, $parameters);
			}
		);

		$this->controller = SettingsControllerFactory::create(
			'openregister',
			$this->request,
			$this->config,
			$this->db,
			$this->container,
			$this->settingsService,
			$this->logger,
			$this->l10n
		);
	}

	/**
	 * Test index (get settings) endpoint returns proper JSON structure
	 *
	 * @return void
	 */
	public function testIndexReturnsValidJson(): void {
		$this->settingsService
			->method('getSettings')
			->willReturn([
				'solr' => ['enabled' => true],
				'rbac' => ['enabled' => false],
			]);

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertSame(['solr' => ['enabled' => true], 'rbac' => ['enabled' => false]], $data);
	}

	/**
	 * Test index handles service exceptions gracefully
	 *
	 * @return void
	 */
	public function testIndexHandlesServiceExceptions(): void {
		$this->settingsService
			->method('getSettings')
			->willThrowException(new \Exception('Config error'));

		$response = $this->controller->index();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test update endpoint returns proper JSON structure
	 *
	 * @return void
	 */
	public function testUpdateReturnsValidJson(): void {
		$this->settingsService
			->method('updateSettings')
			->willReturn(['success' => true]);

		$response = $this->controller->update();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('success', $data);
		$this->assertTrue($data['success']);
	}

	/**
	 * Test load endpoint returns proper JSON structure
	 *
	 * @return void
	 */
	public function testLoadReturnsValidJson(): void {
		$this->settingsService
			->method('getSettings')
			->willReturn(['solr' => ['enabled' => true]]);

		$response = $this->controller->load();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertSame(['solr' => ['enabled' => true]], $data);
	}

	/**
	 * Test rebase endpoint returns proper JSON structure
	 *
	 * @return void
	 */
	public function testRebaseReturnsValidJson(): void {
		$this->settingsService
			->method('rebase')
			->willReturn(['success' => true, 'objects_updated' => 10]);

		$response = $this->controller->rebase();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('success', $data);
		$this->assertTrue($data['success']);
	}

	/**
	 * Test statistics endpoint returns proper structure
	 *
	 * @return void
	 */
	public function testStatisticsReturnsValidStructure(): void {
		$mockStats = [
			'registers' => 5,
			'schemas' => 12,
			'objects' => 1500,
		];

		$this->settingsService
			->method('getStats')
			->willReturn($mockStats);

		$response = $this->controller->stats();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertSame(['registers' => 5, 'schemas' => 12, 'objects' => 1500], $data);
	}

	/**
	 * Test getStatistics is an alias for stats
	 *
	 * @return void
	 */
	public function testGetStatisticsReturnsValidStructure(): void {
		$mockStats = [
			'registers' => 5,
			'schemas' => 12,
			'objects' => 1500,
		];

		$this->settingsService
			->method('getStats')
			->willReturn($mockStats);

		$response = $this->controller->getStatistics();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertSame(['registers' => 5, 'schemas' => 12, 'objects' => 1500], $data);
	}

	/**
	 * Test version info endpoint
	 *
	 * @return void
	 */
	public function testGetVersionInfoReturnsValidStructure(): void {
		$mockVersionInfo = [
			'version' => '2.1.0',
			'build' => 'abc123',
			'environment' => 'production',
			'php_version' => '8.1.0',
			'nextcloud_version' => '30.0.4'
		];

		$this->settingsService
			->method('getVersionInfoOnly')
			->willReturn($mockVersionInfo);

		$response = $this->controller->getVersionInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertArrayHasKey('version', $data);
		$this->assertArrayHasKey('environment', $data);
	}

	/**
	 * Test getSearchBackend endpoint returns proper JSON
	 *
	 * @return void
	 */
	public function testGetSearchBackendReturnsValidJson(): void {
		$this->settingsService
			->method('getSearchBackendConfig')
			->willReturn([
				'active' => 'solr',
				'available' => ['solr', 'elasticsearch'],
			]);

		$response = $this->controller->getSearchBackend();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('active', $data);
	}

	/**
	 * Test rebase handles service exceptions gracefully
	 *
	 * @return void
	 */
	public function testRebaseHandlesServiceExceptions(): void {
		$this->settingsService
			->method('rebase')
			->willThrowException(new \Exception('Rebase failed'));

		$response = $this->controller->rebase();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test getStatistics handles service exceptions gracefully
	 *
	 * @return void
	 */
	public function testGetStatisticsHandlesServiceExceptions(): void {
		$this->settingsService
			->method('getStats')
			->willThrowException(new \Exception('Stats error'));

		$response = $this->controller->getStatistics();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test stats handles service exceptions gracefully
	 *
	 * @return void
	 */
	public function testStatsHandlesServiceExceptions(): void {
		$this->settingsService
			->method('getStats')
			->willThrowException(new \Exception('Stats error'));

		$response = $this->controller->stats();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test getVersionInfo handles service exceptions gracefully
	 *
	 * @return void
	 */
	public function testGetVersionInfoHandlesServiceExceptions(): void {
		$this->settingsService
			->method('getVersionInfoOnly')
			->willThrowException(new \Exception('Version error'));

		$response = $this->controller->getVersionInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test getSearchBackend handles service exceptions gracefully
	 *
	 * @return void
	 */
	public function testGetSearchBackendHandlesServiceExceptions(): void {
		$this->settingsService
			->method('getSearchBackendConfig')
			->willThrowException(new \Exception('Config error'));

		$response = $this->controller->getSearchBackend();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test update handles service exceptions gracefully
	 *
	 * @return void
	 */
	public function testUpdateHandlesServiceExceptions(): void {
		$this->settingsService
			->method('updateSettings')
			->willThrowException(new \Exception('Update failed'));

		$response = $this->controller->update();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test load handles service exceptions gracefully
	 *
	 * @return void
	 */
	public function testLoadHandlesServiceExceptions(): void {
		$this->settingsService
			->method('getSettings')
			->willThrowException(new \Exception('Load failed'));

		$response = $this->controller->load();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertIsArray($data);
		$this->assertArrayHasKey('error', $data);
	}

	/**
	 * Test getDatabaseInfo returns cached data
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoReturnsCached(): void {
		$cachedJson = json_encode([
			'database' => ['type' => 'MySQL', 'version' => '8.0'],
			'success' => true,
		]);

		$this->request->method('getParam')
			->willReturn(false);
		$this->config->method('getValueString')
			->with('openregister', 'databaseInfo', '')
			->willReturn($cachedJson);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['fromCache']);
		$this->assertSame('MySQL', $data['database']['type']);
	}

	/**
	 * Test getDatabaseInfo returns 500 on exception
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoReturns500OnException(): void {
		$this->request->method('getParam')
			->willReturn('true');
		$this->db->method('getDatabasePlatform')
			->willThrowException(new \Exception('DB connect failed'));

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(500, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
	}

	/**
	 * Test refreshDatabaseInfo clears cache and delegates
	 *
	 * @return void
	 */
	public function testRefreshDatabaseInfoClearsCache(): void {
		$this->config->expects($this->once())
			->method('deleteKey')
			->with('openregister', 'databaseInfo');

		// After clearing cache, getDatabaseInfo will be called.
		// Make it return cached data (empty) then hit the db path.
		$this->request->method('getParam')
			->willReturn(false);
		$this->config->method('getValueString')
			->willReturn('');
		$this->db->method('getDatabasePlatform')
			->willThrowException(new \Exception('DB error'));

		$response = $this->controller->refreshDatabaseInfo();

		// Will get 500 because db call fails after cache miss.
		$this->assertInstanceOf(JSONResponse::class, $response);
	}

	/**
	 * Test that all existing controller methods return JSONResponse objects
	 *
	 * This comprehensive test ensures API consistency across ALL endpoints
	 * and prevents raw PHP output that could break frontend JSON parsing.
	 *
	 * @return void
	 */
	public function testAllEndpointsReturnJsonResponse(): void {
		// Mock all service methods to return valid data.
		$this->settingsService->method('getSettings')->willReturn(['settings' => []]);
		$this->settingsService->method('updateSettings')->willReturn(['success' => true]);
		$this->settingsService->method('rebase')->willReturn(['success' => true]);
		$this->settingsService->method('getStats')->willReturn(['total' => 0]);
		$this->settingsService->method('getVersionInfoOnly')->willReturn(['version' => '1.0.0']);
		$this->settingsService->method('getSearchBackendConfig')->willReturn(['active' => 'solr']);

		// Test all major existing endpoints on the controller.
		$endpoints = [
			'index',
			'load',
			'update',
			'rebase',
			'stats',
			'getStatistics',
			'getVersionInfo',
			'getSearchBackend',
		];

		foreach ($endpoints as $method) {
			if (method_exists($this->controller, $method)) {
				try {
					$response = $this->controller->$method();

					$this->assertInstanceOf(
						JSONResponse::class,
						$response,
						"Method {$method} should return JSONResponse"
					);

					// Verify response data is serializable (no objects, resources, etc.).
					$data = $response->getData();
					$this->assertIsArray($data, "Method {$method} should return array data");

					// Verify JSON encoding works (would catch circular references, etc.).
					$json = json_encode($data);
					$this->assertNotFalse($json, "Method {$method} data should be JSON encodable");

				} catch (\Exception $e) {
					$this->fail("Method {$method} threw exception: " . $e->getMessage());
				}
			}
		}
	}

	// ── getDatabaseInfo when cache is empty (forces refresh) ─────────

	public function testGetDatabaseInfoWhenCacheEmpty(): void {
		$this->request->method('getParam')
			->willReturn(false);
		$this->config->method('getValueString')
			->willReturn('');
		$this->db->method('getDatabasePlatform')
			->willThrowException(new \Exception('Platform error'));

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(500, $response->getStatus());
	}

	/**
	 * Test getDatabaseInfo with invalid cached JSON (non-parseable)
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithInvalidCachedJson(): void {
		$this->request->method('getParam')
			->willReturn(false);
		$this->config->method('getValueString')
			->willReturn('not-valid-json{{{');
		// After cache miss (invalid JSON), it queries the DB
		$this->db->method('getDatabasePlatform')
			->willThrowException(new \Exception('DB error'));

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(500, $response->getStatus());
	}

	/**
	 * Test getDatabaseInfo with cached JSON missing 'database' key
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithCachedJsonMissingDatabaseKey(): void {
		$this->request->method('getParam')
			->willReturn(false);
		$this->config->method('getValueString')
			->willReturn(json_encode(['success' => true]));
		// Missing 'database' key means cache is invalid, falls through to DB query
		$this->db->method('getDatabasePlatform')
			->willThrowException(new \Exception('DB error'));

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertSame(500, $response->getStatus());
	}

	/**
	 * Test getDatabaseInfo with MySQL platform (live query path)
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithMysqlPlatform(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('mysql');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		// Mock the db->prepare call for VERSION query
		$mockResult = $this->createMock(\OCP\DB\IResult::class);
		$mockResult->method('fetchOne')->willReturn('8.0.32');

		$mockStatement = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$mockStatement->method('execute')->willReturn($mockResult);

		$this->db->method('prepare')->willReturn($mockStatement);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('MySQL', $data['database']['type']);
		$this->assertSame('8.0.32', $data['database']['version']);
		$this->assertFalse($data['database']['vectorSupport']);
		$this->assertFalse($data['fromCache']);
	}

	/**
	 * Test getDatabaseInfo with MariaDB version string
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithMariadbPlatform(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('mysql');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$mockResult = $this->createMock(\OCP\DB\IResult::class);
		$mockResult->method('fetchOne')->willReturn('10.6.12-MariaDB-1:10.6.12+maria~ubu2204');

		$mockStatement = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$mockStatement->method('execute')->willReturn($mockResult);

		$this->db->method('prepare')->willReturn($mockStatement);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('MariaDB', $data['database']['type']);
		$this->assertSame('10.6.12', $data['database']['version']);
		$this->assertFalse($data['database']['vectorSupport']);
	}

	/**
	 * Test getDatabaseInfo with MySQL when VERSION query throws
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoMysqlVersionQueryFails(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('mysql');

		$this->db->method('getDatabasePlatform')->willReturn($platform);
		$this->db->method('prepare')
			->willThrowException(new \Exception('Query failed'));

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('MySQL/MariaDB', $data['database']['type']);
		$this->assertSame('Unknown', $data['database']['version']);
	}

	/**
	 * Test getDatabaseInfo with PostgreSQL platform without pgvector
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithPostgresPlatformNoPgvector(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('postgresql');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		// First prepare call = SELECT VERSION(), second = pg_extension query
		$versionResult = $this->createMock(\OCP\DB\IResult::class);
		$versionResult->method('fetchOne')->willReturn('PostgreSQL 15.4 on x86_64');

		$extResult = $this->createMock(\OCP\DB\IResult::class);
		$extResult->method('fetch')->willReturnOnConsecutiveCalls(
			['extname' => 'plpgsql', 'extversion' => '1.0'],
			false
		);

		$stmt1 = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$stmt1->method('execute')->willReturn($versionResult);

		$stmt2 = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$stmt2->method('execute')->willReturn($extResult);

		$this->db->method('prepare')
			->willReturnOnConsecutiveCalls($stmt1, $stmt2);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('PostgreSQL', $data['database']['type']);
		$this->assertSame('15.4', $data['database']['version']);
		$this->assertFalse($data['database']['vectorSupport']);
		$this->assertStringContainsString('not installed', $data['database']['recommendedPlugin']);
	}

	/**
	 * Test getDatabaseInfo with PostgreSQL platform with pgvector installed
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithPostgresPlatformWithPgvector(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('postgresql');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$versionResult = $this->createMock(\OCP\DB\IResult::class);
		$versionResult->method('fetchOne')->willReturn('PostgreSQL 15.4 on x86_64');

		$extResult = $this->createMock(\OCP\DB\IResult::class);
		$extResult->method('fetch')->willReturnOnConsecutiveCalls(
			['extname' => 'plpgsql', 'extversion' => '1.0'],
			['extname' => 'vector', 'extversion' => '0.5.1'],
			false
		);

		$stmt1 = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$stmt1->method('execute')->willReturn($versionResult);

		$stmt2 = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$stmt2->method('execute')->willReturn($extResult);

		$this->db->method('prepare')
			->willReturnOnConsecutiveCalls($stmt1, $stmt2);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('PostgreSQL', $data['database']['type']);
		$this->assertTrue($data['database']['vectorSupport']);
		$this->assertStringContainsString('installed', $data['database']['recommendedPlugin']);
		$this->assertStringContainsString('Optimal', $data['database']['performanceNote']);
	}

	/**
	 * Test getDatabaseInfo with PostgreSQL when version query throws
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoPostgresVersionQueryFails(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('postgresql');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		// All prepare calls throw to test the catch paths
		$this->db->method('prepare')
			->willThrowException(new \Exception('Query failed'));

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('PostgreSQL', $data['database']['type']);
		$this->assertSame('Unknown', $data['database']['version']);
	}

	/**
	 * Test getDatabaseInfo with SQLite platform
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithSqlitePlatform(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('sqlite');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('SQLite', $data['database']['type']);
		$this->assertFalse($data['database']['vectorSupport']);
		$this->assertStringContainsString('sqlite-vss', $data['database']['recommendedPlugin']);
	}

	/**
	 * Test getDatabaseInfo with unknown platform (no getName method)
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithUnknownPlatform(): void {
		$this->request->method('getParam')
			->willReturn('true');

		// Create a platform mock without getName
		$platform = new \stdClass();

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('unknown', $data['database']['platform']);
		$this->assertSame('Unknown', $data['database']['type']);
	}

	/**
	 * Test getDatabaseInfo caches the result in app config
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoStoresInCache(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('sqlite');
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$this->config->expects($this->once())
			->method('setValueString')
			->with(
				'openregister',
				'databaseInfo',
				$this->isType('string')
			);

		$this->controller->getDatabaseInfo();
	}

	/**
	 * Test getDatabaseInfo with PostgreSQL where extension query throws but version succeeds
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoPostgresExtensionQueryFails(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('postgresql');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		// Version query succeeds, extension query fails
		$versionResult = $this->createMock(\OCP\DB\IResult::class);
		$versionResult->method('fetchOne')->willReturn('PostgreSQL 14.1 on x86_64');

		$stmt1 = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$stmt1->method('execute')->willReturn($versionResult);

		$stmt2 = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$stmt2->method('execute')->willThrowException(new \Exception('Permission denied'));

		$this->db->method('prepare')
			->willReturnOnConsecutiveCalls($stmt1, $stmt2);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('PostgreSQL', $data['database']['type']);
		$this->assertSame('14.1', $data['database']['version']);
		// Without extension info, vectorSupport defaults to false
		$this->assertFalse($data['database']['vectorSupport']);
		$this->assertEmpty($data['database']['extensions']);
	}

	/**
	 * Test getDatabaseInfo with mariadb in platform name
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoWithMariadbPlatformName(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('mariadb');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$mockResult = $this->createMock(\OCP\DB\IResult::class);
		$mockResult->method('fetchOne')->willReturn('10.11.4-MariaDB');

		$mockStatement = $this->createMock(\OCP\DB\IPreparedStatement::class);
		$mockStatement->method('execute')->willReturn($mockResult);

		$this->db->method('prepare')->willReturn($mockStatement);

		$response = $this->controller->getDatabaseInfo();

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertTrue($data['success']);
		$this->assertSame('MariaDB', $data['database']['type']);
		$this->assertFalse($data['database']['vectorSupport']);
		$this->assertStringContainsString('pgvector', $data['database']['recommendedPlugin']);
	}

	/**
	 * Test getDatabaseInfo response includes lastUpdated timestamp
	 *
	 * @return void
	 */
	public function testGetDatabaseInfoIncludesLastUpdated(): void {
		$this->request->method('getParam')
			->willReturn('true');

		$platform = $this->createMock(AbstractPlatform::class);
		$platform->method('getName')->willReturn('sqlite');

		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$response = $this->controller->getDatabaseInfo();

		$data = $response->getData();
		$this->assertArrayHasKey('lastUpdated', $data['database']);
		// Verify it's a valid ISO 8601 date
		$dateTime = \DateTime::createFromFormat(\DateTime::ATOM, $data['database']['lastUpdated']);
		$this->assertNotFalse($dateTime);
	}

	/**
	 * Test stats returns 422 status on exception (not 500)
	 *
	 * @return void
	 */
	public function testStatsReturns422StatusOnException(): void {
		$this->settingsService
			->method('getStats')
			->willThrowException(new \Exception('Stats error'));

		$response = $this->controller->stats();

		$this->assertSame(422, $response->getStatus());
	}

	/**
	 * Test index returns 500 status on exception
	 *
	 * @return void
	 */
	public function testIndexReturns500StatusOnException(): void {
		$this->settingsService
			->method('getSettings')
			->willThrowException(new \Exception('Settings error'));

		$response = $this->controller->index();

		$this->assertSame(500, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Settings error', $data['error']);
	}

	/**
	 * Test update returns 500 status on exception
	 *
	 * @return void
	 */
	public function testUpdateReturns500StatusOnException(): void {
		$this->settingsService
			->method('updateSettings')
			->willThrowException(new \Exception('Update error'));

		$response = $this->controller->update();

		$this->assertSame(500, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Update error', $data['error']);
	}

	/**
	 * Test load returns 500 status on exception
	 *
	 * @return void
	 */
	public function testLoadReturns500StatusOnException(): void {
		$this->settingsService
			->method('getSettings')
			->willThrowException(new \Exception('Load error'));

		$response = $this->controller->load();

		$this->assertSame(500, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Load error', $data['error']);
	}

	/**
	 * Test rebase returns 500 status on exception
	 *
	 * @return void
	 */
	public function testRebaseReturns500StatusOnException(): void {
		$this->settingsService
			->method('rebase')
			->willThrowException(new \Exception('Rebase error'));

		$response = $this->controller->rebase();

		$this->assertSame(500, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('Rebase error', $data['error']);
	}
}
