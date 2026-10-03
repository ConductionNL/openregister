<?php

/**
 * An app's register import hands `components.selectionLists` to the seeder.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Configuration
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Configuration;

use GuzzleHttp\Client;
use OCA\OpenRegister\Db\Configuration;
use OCA\OpenRegister\Db\ConfigurationMapper;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\MappingMapper;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\SelectionListSeeder;
use OCA\OpenRegister\Service\Configuration\ImportHandler;
use OCA\OpenRegister\Service\Configuration\UploadHandler;
use OCA\OpenRegister\Service\ObjectService;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportHandlerSelectionListsTest extends TestCase {

	private SelectionListSeeder&MockObject $seeder;

	private ImportHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getValueString')->willReturn('');
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('getSlugToIdMap')->willReturn([]);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('getSlugToIdMap')->willReturn([]);
		$mappingMapper = $this->createMock(MappingMapper::class);
		$mappingMapper->method('getSlugToIdMap')->willReturn([]);

		$this->seeder = $this->createMock(SelectionListSeeder::class);

		$this->handler = new ImportHandler(
			schemaMapper: $schemaMapper,
			registerMapper: $registerMapper,
			objectEntityMapper: $this->createMock(MagicMapper::class),
			configurationMapper: $this->createMock(ConfigurationMapper::class),
			mappingMapper: $mappingMapper,
			client: $this->createMock(Client::class),
			appConfig: $appConfig,
			logger: $this->createMock(LoggerInterface::class),
			appDataPath: '/tmp',
			uploadHandler: $this->createMock(UploadHandler::class),
			objectService: $this->createMock(ObjectService::class)
		);
		$this->handler->setSelectionListSeeder($this->seeder);
	}//end setUp()

	public function testTheShippedCategoriesReachTheSeederAndTheirCountsTheResult(): void {
		$entries = [
			['category' => '2.1', 'retentionYears' => 10, 'action' => 'vernietigen', 'description' => 'Raadsvoorstellen'],
			['category' => '19.1', 'action' => 'bewaren'],
		];
		$counts = ['created' => 2, 'updated' => 0, 'unchanged' => 0, 'kept' => 0, 'failed' => []];
		$this->seeder->expects($this->once())->method('seed')->with($entries, 'decidiq')->willReturn($counts);

		$result = $this->handler->importFromJson(
			data: ['components' => ['selectionLists' => $entries]],
			configuration: new Configuration(),
			appId: 'decidiq',
			version: '1.0.0'
		);

		$this->assertSame($counts, $result['selectionLists']);
	}//end testTheShippedCategoriesReachTheSeederAndTheirCountsTheResult()

	public function testAnImportWithoutSelectionListsDoesNotCallTheSeeder(): void {
		$this->seeder->expects($this->never())->method('seed');

		$result = $this->handler->importFromJson(
			data: ['components' => []],
			configuration: new Configuration(),
			appId: 'decidiq',
			version: '1.0.0'
		);

		$this->assertArrayNotHasKey('selectionLists', $result);
	}//end testAnImportWithoutSelectionListsDoesNotCallTheSeeder()
}//end class
