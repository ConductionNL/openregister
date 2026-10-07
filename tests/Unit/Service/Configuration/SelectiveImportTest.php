<?php

/**
 * Selective import from a preview, and auto-update, reach the importer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use OCA\OpenRegister\BackgroundJob\ConfigurationCheckJob;
use OCA\OpenRegister\Controller\ConfigurationController;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\CacheHandler;
use OCA\OpenRegister\Service\Configuration\ExportHandler;
use OCA\OpenRegister\Service\Configuration\GitHubHandler;
use OCA\OpenRegister\Service\Configuration\GitLabHandler;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\PreviewHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ConfigurationService;
use OCA\OpenRegister\Service\NotificationService;
use OCA\OpenRegister\Service\ObjectService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Drives the REAL ConfigurationService (with the real FetchHandler over a
 * mocked HTTP transport) from its two callers: the preview's import endpoint
 * and the hourly ConfigurationCheckJob. Only the ImportHandler, which has its
 * own tests, is a test double, so the assertions read what it was handed.
 *
 * Before this change both callers reached a stub that returned `[]`: the
 * endpoint answered 500 and the job counted an update that imported nothing.
 */
class SelectiveImportTest extends TestCase {

	/**
	 * Remote document as an export produces it.
	 *
	 * @var array<string, mixed>
	 */
	private const REMOTE = [
		'openapi' => '3.0.0',
		'info' => ['title' => 'Demo', 'version' => '2.0.0'],
		'x-openregister' => [
			'type' => 'app',
			'seedData' => ['objects' => [['@self' => ['register' => 'demo', 'schema' => 'zaak', 'slug' => 'seed1']]]],
		],
		'components' => [
			'registers' => ['demo' => ['slug' => 'demo', 'title' => 'Demo', 'version' => '2.0.0']],
			'schemas' => [
				'Klant' => ['slug' => 'Klant', 'title' => 'Klant', 'version' => '2.0.0'],
				'zaak' => ['slug' => 'zaak', 'title' => 'Zaak', 'version' => '2.0.0'],
			],
			'objects' => [
				['@self' => ['register' => 'demo', 'schema' => 'Klant', 'slug' => 'k1'], 'name' => 'K1'],
				['@self' => ['register' => 'demo', 'schema' => 'zaak', 'slug' => 'z1'], 'name' => 'Z1'],
			],
		],
	];

	/**
	 * What the ImportHandler received, one entry per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $imported = [];

	/**
	 * The configuration mapper double.
	 *
	 * @var ConfigurationMapper&MockObject
	 */
	private ConfigurationMapper&MockObject $configurationMapper;

	/**
	 * The configuration under test.
	 *
	 * @var Configuration
	 */
	private Configuration $configuration;

	/**
	 * Build the configuration.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->configuration = new Configuration();
		$this->configuration->setId(1);
		$this->configuration->setTitle('Demo');
		$this->configuration->setApp('demo');
		$this->configuration->setSourceType('url');
		$this->configuration->setSourceUrl('https://example.org/demo.json');
		$this->configuration->setLocalVersion('1.0.0');
		$this->configuration->setAutoUpdate(true);
		$this->configuration->setRegisters([]);
		$this->configuration->setSchemas([5]);
		$this->configuration->setObjects([]);

		$this->configurationMapper = $this->createMock(ConfigurationMapper::class);
		$this->configurationMapper->method('find')->willReturn($this->configuration);
		$this->configurationMapper->method('findAll')->willReturn([$this->configuration]);
		$this->configurationMapper->method('update')->willReturnArgument(0);

	}//end setUp()

	/**
	 * Build the real service over an HTTP transport that answers with $bodies.
	 *
	 * @param array<int, Response> $responses Queued HTTP responses.
	 *
	 * @return ConfigurationService
	 */
	private function service(array $responses): ConfigurationService {
		$client = new Client(['handler' => HandlerStack::create(new MockHandler($responses))]);
		$logger = $this->createMock(LoggerInterface::class);

		$importHandler = $this->createMock(ImportHandler::class);
		$importHandler->method('importFromJson')->willReturnCallback(
			function (array $data) {
				$this->imported[] = $data;
				$result = ['registers' => [], 'schemas' => [], 'objects' => [], 'endpoints' => [], 'sources' => [],
					'mappings' => [], 'jobs' => [], 'synchronizations' => [], 'rules' => []];
				$id = 10;
				foreach (array_keys($data['components']['registers'] ?? []) as $slug) {
					$register = new Register();
					$register->setId($id++);
					$register->setSlug((string)$slug);
					$result['registers'][] = $register;
				}

				foreach (array_keys($data['components']['schemas'] ?? []) as $slug) {
					$schema = new Schema();
					$schema->setId($id++);
					$schema->setSlug((string)$slug);
					$result['schemas'][] = $schema;
				}

				foreach ($data['components']['objects'] ?? [] as $row) {
					$object = new ObjectEntity();
					$object->setId($id++);
					$object->setSlug($row['@self']['slug']);
					$result['objects'][] = $object;
				}

				return $result;
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id) => match ($id) {
				Client::class => $client,
				LoggerInterface::class => $logger,
				ImportHandler::class => $importHandler,
				default => throw new \RuntimeException('unexpected service ' . $id),
			}
		);

		return new ConfigurationService(
			$this->createMock(SchemaMapper::class),
			$this->createMock(RegisterMapper::class),
			$this->configurationMapper,
			$this->createMock(IAppManager::class),
			$container,
			$this->createMock(IAppConfig::class),
			$logger,
			$client,
			$this->createMock(ObjectService::class),
			$this->createMock(GitHubHandler::class),
			$this->createMock(GitLabHandler::class),
			$this->createMock(CacheHandler::class),
			$this->createMock(PreviewHandler::class),
			$this->createMock(ExportHandler::class),
			$this->createMock(UploadHandler::class),
			'/tmp'
		);

	}//end service()

	/**
	 * A JSON response carrying the remote document.
	 *
	 * @return Response
	 */
	private static function remote(): Response {
		return new Response(200, ['Content-Type' => 'application/json'], (string)json_encode(self::REMOTE));

	}//end remote()

	/**
	 * The real controller over the given service, called by an admin.
	 *
	 * @param ConfigurationService $service   The service.
	 * @param array<string, mixed> $selection Posted selection.
	 *
	 * @return ConfigurationController
	 */
	private function controller(ConfigurationService $service, array $selection): ConfigurationController {
		$request = $this->createMock(IRequest::class);
		$request->method('getParams')->willReturn(['selection' => $selection]);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('admin');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);

		return new ConfigurationController(
			'openregister',
			$request,
			$this->configurationMapper,
			$service,
			$this->createMock(NotificationService::class),
			$this->createMock(GitHubHandler::class),
			$this->createMock(GitLabHandler::class),
			$this->createMock(IAppManager::class),
			$this->createMock(LoggerInterface::class),
			$session,
			$groups
		);

	}//end controller()

	/**
	 * The preview's import imports exactly what was selected.
	 *
	 * @return void
	 */
	public function testPreviewImportImportsOnlyTheSelection(): void {
		$controller = $this->controller(
			$this->service([self::remote()]),
			['registers' => [], 'schemas' => ['klant'], 'objects' => ['demo:Klant:k1']]
		);

		$response = $controller->import(1);

		$this->assertSame(200, $response->getStatus(), json_encode($response->getData()));
		$this->assertSame(1, $response->getData()['schemasCount']);
		$this->assertSame(1, $response->getData()['objectsCount']);
		$this->assertCount(1, $this->imported);

		$data = $this->imported[0];
		$this->assertSame(['Klant'], array_keys($data['components']['schemas']), 'schema slugs match case-insensitively');
		$this->assertSame([], $data['components']['registers']);
		$this->assertSame(['k1'], array_map(static fn (array $o): string => $o['@self']['slug'], $data['components']['objects']));
		$this->assertArrayNotHasKey('seedData', $data['x-openregister'] ?? [], 'a selective import does not bring unselected seed objects');
		$this->assertSame('2.0.0', $data['info']['version']);

		// The configuration tracks what it brought in, keeping what it had.
		$this->assertSame([5, 10], $this->configuration->getSchemas());
		$this->assertSame([11], $this->configuration->getObjects());
		// A partial import leaves the rest of the update on offer.
		$this->assertSame('1.0.0', $this->configuration->getLocalVersion());

	}//end testPreviewImportImportsOnlyTheSelection()

	/**
	 * A selection that names nothing present imports nothing and still answers.
	 *
	 * @return void
	 */
	public function testSelectionOfAbsentItemsImportsNothing(): void {
		$controller = $this->controller($this->service([self::remote()]), ['schemas' => ['nope']]);

		$response = $controller->import(1);

		$this->assertSame(200, $response->getStatus());
		$this->assertSame(0, $response->getData()['schemasCount']);
		$this->assertSame([], $this->imported, 'nothing selected that exists, so the importer is not called');

	}//end testSelectionOfAbsentItemsImportsNothing()

	/**
	 * A remote that cannot be fetched is an error, not an empty success.
	 *
	 * @return void
	 */
	public function testUnreachableRemoteIsAnError(): void {
		$controller = $this->controller(
			$this->service([new Response(200, ['Content-Type' => 'application/json'], 'not json {')]),
			['schemas' => ['zaak']]
		);

		$response = $controller->import(1);

		$this->assertNotSame(200, $response->getStatus());
		$this->assertSame([], $this->imported);

	}//end testUnreachableRemoteIsAnError()

	/**
	 * Auto-update imports the whole remote document and moves localVersion.
	 *
	 * @return void
	 */
	public function testAutoUpdateImportsEverythingAndMovesLocalVersion(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('3600');

		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notifyConfigurationUpdate');

		$job = new ConfigurationCheckJob(
			$this->createMock(ITimeFactory::class),
			$this->configurationMapper,
			// One response for the version check, one for the import's fetch.
			$this->service([self::remote(), self::remote()]),
			$notifications,
			$appConfig,
			$this->createMock(LoggerInterface::class)
		);

		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, null);

		$this->assertCount(1, $this->imported);
		$this->assertSame(self::REMOTE, $this->imported[0], 'auto-update hands the whole document over, seed data included');
		$this->assertSame('2.0.0', $this->configuration->getLocalVersion());
		$this->assertSame([10], $this->configuration->getRegisters());
		$this->assertSame([5, 11, 12], $this->configuration->getSchemas());
		$this->assertFalse($this->configuration->hasUpdateAvailable(), 'the next hourly run finds nothing to do');

	}//end testAutoUpdateImportsEverythingAndMovesLocalVersion()
}//end class
