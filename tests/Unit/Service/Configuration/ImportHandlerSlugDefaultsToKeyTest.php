<?php

/**
 * A schema component without a `slug` is imported under its component key.
 *
 * Every app configuration names its schemas by key, and the register lists
 * reference them by that same key, so a fragment such as
 * `"partyFieldSet": {"title": "Party field set", ...}` has always meant "the
 * schema with slug partyFieldSet". importSchema() nevertheless rejected any
 * fragment whose `slug` was missing, and importFromJson() carried on without
 * it. pipelinq's party, survey and programme schemas (sixteen of them) never
 * reached a single instance that way, while the app's own re-import reported
 * success. These tests pin the Pass-1 default: the key becomes the slug, the
 * schema is created and linked, and a slug that is present but blank is still
 * rejected, because that one is a mistake rather than a convention.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportHandlerSlugDefaultsToKeyTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;

	private RegisterMapper&MockObject $registerMapper;

	private ImportHandler $handler;

	/**
	 * The slugs SchemaMapper::createFromArray() was asked to create, in order.
	 *
	 * @var string[]
	 */
	private array $createdSlugs = [];

	/**
	 * The schema ids the register was created with.
	 *
	 * @var int[]
	 */
	private array $linkedSchemaIds = [];

	private int $nextSchemaId = 100;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->registerMapper = $this->createMock(RegisterMapper::class);
		$objectEntityMapper = $this->createMock(MagicMapper::class);
		$configurationMapper = $this->createMock(ConfigurationMapper::class);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$client = $this->createMock(Client::class);
		$appConfig = $this->createMock(IAppConfig::class);
		$logger = $this->createMock(LoggerInterface::class);
		$uploadHandler = $this->createMock(UploadHandler::class);
		$objectService = $this->createMock(ObjectService::class);

		// No previously-imported version, nothing in the database: a fresh instance.
		$appConfig->method('getValueString')->willReturn('');
		$this->schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('getSlugToIdMap')->willReturn([]);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);
		$this->registerMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->schemaMapper->method('find')->willThrowException(new DoesNotExistException('not found'));
		$this->schemaMapper->method('findBySlugInIds')->willReturn(null);
		$this->schemaMapper->method('findByApplicationAndSlug')->willReturn(null);

		$this->schemaMapper->method('createFromArray')
			->willReturnCallback(function (array $data): Schema {
				$this->createdSlugs[] = (string)$data['slug'];
				return $this->makeSchema((string)$data['slug']);
			});
		$this->schemaMapper->method('updateFromArray')
			->willReturnCallback(fn (int $id, array $data): Schema => $this->makeSchema((string)$data['slug'], $id));
		$this->schemaMapper->method('update')->willReturnArgument(0);

		$this->registerMapper->method('createFromArray')
			->willReturnCallback(function (array $data): Register {
				$this->linkedSchemaIds = ($data['schemas'] ?? []);
				$register = new Register();
				$register->hydrate(['slug' => $data['slug'], 'version' => '1.0.0']);
				$register->setId(1);
				return $register;
			});
		$this->registerMapper->method('update')->willReturnArgument(0);

		$this->handler = new ImportHandler(
			schemaMapper:        $this->schemaMapper,
			registerMapper:      $this->registerMapper,
			objectEntityMapper:  $objectEntityMapper,
			configurationMapper: $configurationMapper,
			mappingMapper:       $mappingMapper,
			client:              $client,
			appConfig:           $appConfig,
			logger:              $logger,
			appDataPath:         '/tmp',
			uploadHandler:       $uploadHandler,
			objectService:       $objectService
		);
	}//end setUp()

	private function makeSchema(string $slug, ?int $id = null): Schema {
		if ($id === null) {
			$id = $this->nextSchemaId;
			$this->nextSchemaId++;
		}

		$schema = new Schema();
		$schema->hydrate(['slug' => $slug, 'title' => $slug, 'version' => '1.0.0', 'properties' => []]);
		$schema->setId($id);
		return $schema;
	}//end makeSchema()

	/**
	 * Two schemas as pipelinq shipped them: `client` with a slug, `partyFieldSet` without one.
	 *
	 * @param array $partyFieldSet The partyFieldSet component, so a test can vary its slug.
	 *
	 * @return array The configuration payload.
	 */
	private function configuration(array $partyFieldSet): array {
		return [
			'appId' => 'pipelinq',
			'version' => '0.5.7',
			'components' => [
				'schemas' => [
					'client' => [
						'slug' => 'client',
						'title' => 'Client',
						'version' => '1.0.0',
						'properties' => ['name' => ['type' => 'string']],
					],
					'partyFieldSet' => $partyFieldSet,
				],
				'registers' => [
					'pipelinq' => ['slug' => 'pipelinq', 'version' => '1.0.0', 'schemas' => ['client', 'partyFieldSet']],
				],
			],
		];
	}//end configuration()

	/**
	 * @return void
	 */
	public function testASchemaWithoutASlugIsImportedUnderItsKey(): void {
		$result = $this->handler->importFromJson(
			data: $this->configuration([
				'title' => 'Party field set',
				'version' => '1.0.0',
				'properties' => ['key' => ['type' => 'string']],
			]),
			configuration: new Configuration(),
			appId: 'pipelinq',
			version: '0.5.7'
		);

		$this->assertSame([], $result['failed']['schemas'], 'nothing is rejected');
		$this->assertSame(0, $result['skipped']['schemas']);
		$this->assertContains('partyFieldSet', $this->createdSlugs, 'the key became the slug');
		$this->assertCount(2, $this->linkedSchemaIds, 'the register links both schemas');
	}//end testASchemaWithoutASlugIsImportedUnderItsKey()

	/**
	 * @return void
	 */
	public function testASchemaWithABlankSlugIsStillRejected(): void {
		$result = $this->handler->importFromJson(
			data: $this->configuration([
				'slug' => '   ',
				'title' => 'Party field set',
				'version' => '1.0.0',
				'properties' => ['key' => ['type' => 'string']],
			]),
			configuration: new Configuration(),
			appId: 'pipelinq',
			version: '0.5.7'
		);

		$this->assertCount(1, $result['failed']['schemas']);
		$this->assertSame('partyFieldSet', $result['failed']['schemas'][0]['key']);
		$this->assertStringContainsString("missing a 'slug'", $result['failed']['schemas'][0]['error']);
		// Pass 2 re-imports `client` against these stateless mocks, so it is created twice; the set is what matters.
		$this->assertSame(['client'], array_values(array_unique($this->createdSlugs)), 'the blank slug is not silently replaced by the key');
		$this->assertCount(1, $this->linkedSchemaIds, 'the register links only the schema that imported');
	}//end testASchemaWithABlankSlugIsStillRejected()

	/**
	 * @return void
	 */
	public function testAnExplicitSlugStillWins(): void {
		$this->handler->importFromJson(
			data: $this->configuration([
				'slug' => 'party_field_set',
				'title' => 'Party field set',
				'version' => '1.0.0',
				'properties' => ['key' => ['type' => 'string']],
			]),
			configuration: new Configuration(),
			appId: 'pipelinq',
			version: '0.5.7'
		);

		$this->assertContains('party_field_set', $this->createdSlugs);
		$this->assertNotContains('partyFieldSet', $this->createdSlugs, 'the key does not override a slug the fragment declares');
	}//end testAnExplicitSlugStillWins()
}//end class
