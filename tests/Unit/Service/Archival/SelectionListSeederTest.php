<?php

/**
 * An app ships selectielijst categories with its register import.
 *
 * The rows go where retention reads them: the REAL SelectielijstResolver
 * reads back what the seeder wrote, over one shared store.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/openregister
 *
 * @spec openspec/specs/archival-destruction-workflow/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Archival;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\SelectielijstResolver;
use OCA\OpenRegister\Service\Archival\SelectionListSeeder;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SelectionListSeederTest extends TestCase {

	/** @var array<string, ObjectEntity> The selectielijst register, by uuid. */
	private array $rows = [];

	/** @var array<string, mixed> Archival settings. */
	private array $settings = ['selectielijstRegister' => 4, 'selectielijstSchema' => 6];

	private int $writes = 0;

	private SelectionListSeeder $seeder;

	private SelectielijstResolver $resolver;

	protected function setUp(): void {
		parent::setUp();

		$objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll'])
			->getMock();
		$objectMapper->method('findAll')->willReturnCallback(
			function (?int $limit = null, ?int $offset = null, ?array $filters = null): array {
				$category = ($filters['object->categorie'] ?? null);
				return array_values(
					array_filter(
						$this->rows,
						fn (ObjectEntity $row): bool => $category === null || ($row->getObject()['categorie'] ?? null) === $category
					)
				);
			}
		);

		$saveObject = $this->createMock(SaveObject::class);
		$saveObject->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data, ?string $uuid = null): ObjectEntity {
				$this->writes++;
				$uuid = ($uuid ?? 'row-' . (count($this->rows) + 1));
				$row = new ObjectEntity();
				$row->setUuid($uuid);
				$row->setObject($data);
				$this->rows[$uuid] = $row;
				return $row;
			}
		);

		$settings = $this->createMock(ObjectRetentionHandler::class);
		$settings->method('getArchivalSettingsOnly')->willReturnCallback(fn (): array => $this->settings);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn(new Register());
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturn(new Schema());

		$this->seeder = new SelectionListSeeder(
			settingsHandler: $settings,
			registerMapper: $registerMapper,
			schemaMapper: $schemaMapper,
			objectMapper: $objectMapper,
			saveObject: $saveObject,
			logger: $this->createMock(LoggerInterface::class)
		);

		$this->resolver = new SelectielijstResolver(
			$objectMapper,
			$schemaMapper,
			$registerMapper,
			$settings,
			$this->createMock(LoggerInterface::class)
		);
	}//end setUp()

	/**
	 * decidiq's four Selectielijst 2020 categories, as its register would ship them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function decidiqEntries(): array {
		return [
			['category' => '2.1', 'retentionYears' => 10, 'action' => 'vernietigen', 'description' => 'Raadsvoorstellen'],
			['category' => '3.1', 'retentionYears' => 20, 'action' => 'vernietigen', 'description' => 'Moties'],
			['category' => '19.1', 'action' => 'bewaren', 'description' => 'Raadsbesluiten'],
			['category' => '11.1', 'retentionYears' => 5, 'action' => 'destroy', 'description' => 'Vergaderstukken', 'organisation' => 'gemeente-x'],
		];
	}//end decidiqEntries()

	public function testASecondImportChangesNothing(): void {
		$first = $this->seeder->seed(entries: $this->decidiqEntries(), appId: 'decidiq');
		$second = $this->seeder->seed(entries: $this->decidiqEntries(), appId: 'decidiq');

		$this->assertSame(4, $first['created']);
		$this->assertSame([], $first['failed']);
		$this->assertSame(0, $second['created']);
		$this->assertSame(0, $second['updated']);
		$this->assertSame(4, $second['unchanged']);
		$this->assertCount(4, $this->rows);
		$this->assertSame(4, $this->writes, 'the second import writes nothing');
	}//end testASecondImportChangesNothing()

	public function testAShippedCategoryDrivesRetention(): void {
		$this->seeder->seed(entries: $this->decidiqEntries(), appId: 'decidiq');

		$entry = $this->resolver->lookupSelectielijstEntry(category: '2.1');

		$this->assertNotNull($entry, 'retention finds the category the app shipped');
		$this->assertSame('vernietigen', $entry['archiefnominatie']);
		$this->assertSame('P10Y', $entry['bewaartermijn']);
		$this->assertSame('app:decidiq', $entry['bron']);
		$this->assertSame('Raadsvoorstellen', $entry['omschrijving']);

		$kept = $this->resolver->lookupSelectielijstEntry(category: '19.1');
		$this->assertSame('bewaren', $kept['archiefnominatie']);
		$this->assertNull($kept['bewaartermijn']);
	}//end testAShippedCategoryDrivesRetention()

	public function testAChangedPeriodUpdatesTheSameRowAndAnotherOrganisationGetsItsOwn(): void {
		$this->seeder->seed(entries: $this->decidiqEntries(), appId: 'decidiq');

		$changed = $this->seeder->seed(
			entries: [
				['category' => '2.1', 'retentionYears' => 12, 'action' => 'vernietigen', 'description' => 'Raadsvoorstellen'],
				['category' => '2.1', 'retentionYears' => 7, 'action' => 'vernietigen', 'organisation' => 'gemeente-y'],
			],
			appId: 'decidiq'
		);

		$this->assertSame(1, $changed['updated']);
		$this->assertSame(1, $changed['created']);
		$this->assertCount(5, $this->rows);
		$periods = [];
		foreach ($this->rows as $row) {
			$data = $row->getObject();
			if ($data['categorie'] === '2.1') {
				$periods[(string)($data['organisatie'] ?? '')] = $data['bewaartermijn'];
			}
		}

		$this->assertSame(['' => 'P12Y', 'gemeente-y' => 'P7Y'], $periods);
	}//end testAChangedPeriodUpdatesTheSameRowAndAnotherOrganisationGetsItsOwn()

	public function testAnArchivistsOwnRowForTheCategoryIsLeftAlone(): void {
		$own = new ObjectEntity();
		$own->setUuid('archivist-row');
		$own->setObject(['categorie' => '2.1', 'archiefnominatie' => 'bewaren', 'bewaartermijn' => null, 'bron' => 'Selectielijst gemeenten 2020']);
		$this->rows['archivist-row'] = $own;

		$result = $this->seeder->seed(entries: [$this->decidiqEntries()[0]], appId: 'decidiq');

		$this->assertSame(1, $result['kept']);
		$this->assertSame(0, $this->writes);
		$this->assertSame('bewaren', $this->resolver->lookupSelectielijstEntry(category: '2.1')['archiefnominatie']);
	}//end testAnArchivistsOwnRowForTheCategoryIsLeftAlone()

	public function testAnInvalidEntryIsNamedAndTheOthersStillLand(): void {
		$result = $this->seeder->seed(
			entries: [
				['category' => '', 'retentionYears' => 1, 'action' => 'vernietigen'],
				['category' => '8.1', 'retentionYears' => 1, 'action' => 'verbranden'],
				['category' => '8.2', 'action' => 'vernietigen'],
				['category' => '8.3', 'retentionYears' => -1, 'action' => 'vernietigen'],
				['category' => '8.4', 'retentionYears' => 3, 'action' => 'vernietigen'],
			],
			appId: 'decidiq'
		);

		$this->assertSame(1, $result['created']);
		$this->assertSame(['', '8.1', '8.2', '8.3'], array_column($result['failed'], 'category'));
		$this->assertCount(1, $this->rows);
	}//end testAnInvalidEntryIsNamedAndTheOthersStillLand()

	public function testWithoutASelectielijstRegisterEveryEntryFailsWithThatReason(): void {
		$this->settings = [];

		$result = $this->seeder->seed(entries: $this->decidiqEntries(), appId: 'decidiq');

		$this->assertSame(0, $result['created']);
		$this->assertCount(4, $result['failed']);
		$this->assertStringContainsString('selectielijst register', $result['failed'][0]['reason']);
	}//end testWithoutASelectielijstRegisterEveryEntryFailsWithThatReason()
}//end class
