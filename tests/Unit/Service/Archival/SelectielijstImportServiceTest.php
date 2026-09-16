<?php

declare(strict_types=1);

/**
 * Importing a selectielijst as a version, and comparing two of them.
 *
 * 🔴 THE DIFF TEST ASSERTS WHAT A CHANGE WOULD DO, not that there is one. A
 * bewaartermijn moving from P7Y to P10Y is three more years on every record
 * nominated under that category, and a diff that reported only "11.1.2 changed"
 * would leave the archivist to go and look it up, which is what they came here
 * to avoid.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author   Conduction Development Team <dev@conduction.nl>
 * @license  EUPL-1.2
 *
 * @spec openspec/changes/archiving-as-a-process-with-sign-off/specs/retention-management/spec.md
 */

namespace Unit\Service\Archival;

use InvalidArgumentException;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\SelectielijstImportService;
use OCA\OpenRegister\Service\Object\SaveObject;
use OCA\OpenRegister\Service\Settings\ObjectRetentionHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for SelectielijstImportService.
 */
class SelectielijstImportServiceTest extends TestCase {

	private MagicMapper&MockObject $objectMapper;
	private SaveObject&MockObject $saveObject;
	private ObjectRetentionHandler&MockObject $settingsHandler;
	private SelectielijstImportService $service;

	/**
	 * Rows handed to saveObject during a test.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $saved = [];

	protected function setUp(): void {
		parent::setUp();

		$this->saved = [];

		$this->objectMapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll'])
			->getMock();
		$this->objectMapper->method('findAll')->willReturn([]);

		$this->saveObject = $this->getMockBuilder(SaveObject::class)
			->disableOriginalConstructor()
			->onlyMethods(['saveObject'])
			->getMock();
		$this->saveObject->method('saveObject')->willReturnCallback(
			function ($register, $schema, array $data) {
				$this->saved[] = $data;
				return new ObjectEntity();
			}
		);

		$this->settingsHandler = $this->createMock(ObjectRetentionHandler::class);
		$this->settingsHandler->method('getArchivalSettingsOnly')->willReturn(
			['selectielijstRegister' => 1, 'selectielijstSchema' => 2]
		);

		$registerMapper = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$registerMapper->method('find')->willReturn(
			$this->getMockBuilder(Register::class)->disableOriginalConstructor()->getMock()
		);

		$schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemaMapper->method('find')->willReturn(
			$this->getMockBuilder(Schema::class)->disableOriginalConstructor()->getMock()
		);

		$this->service = new SelectielijstImportService(
			$this->settingsHandler,
			$registerMapper,
			$schemaMapper,
			$this->objectMapper,
			$this->saveObject,
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Point the store at a set of rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 *
	 * @return void
	 */
	private function stored(array $rows): void {
		$objects = [];
		foreach ($rows as $row) {
			$object = new ObjectEntity();
			$object->setObject($row);
			$objects[] = $object;
		}

		$mapper = $this->getMockBuilder(MagicMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['findAll'])
			->getMock();
		$mapper->method('findAll')->willReturn($objects);

		$registerMapper = $this->getMockBuilder(RegisterMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$registerMapper->method('find')->willReturn(
			$this->getMockBuilder(Register::class)->disableOriginalConstructor()->getMock()
		);

		$schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();
		$schemaMapper->method('find')->willReturn(
			$this->getMockBuilder(Schema::class)->disableOriginalConstructor()->getMock()
		);

		$this->service = new SelectielijstImportService(
			$this->settingsHandler,
			$registerMapper,
			$schemaMapper,
			$mapper,
			$this->saveObject,
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testACsvWithAHeaderBecomesRows(): void {
		$rows = $this->service->parse(
			contents: "categorie,archiefnominatie,bewaartermijn\n11.1.2,vernietigen,P7Y\n1.1,blijvend_bewaren,\n",
			filename: 'selectielijst-2020.csv'
		);

		$this->assertCount(2, $rows);
		$this->assertSame('11.1.2', $rows[0]['categorie']);
		$this->assertSame('P7Y', $rows[0]['bewaartermijn']);
		$this->assertSame('blijvend_bewaren', $rows[1]['archiefnominatie']);
	}

	public function testJsonIsReadTheSameWay(): void {
		$rows = $this->service->parse(
			contents: '[{"categorie":"11.1.2","bewaartermijn":"P7Y"}]',
			filename: 'lijst.json'
		);

		$this->assertSame('11.1.2', $rows[0]['categorie']);
	}

	/**
	 * A shifted column turns a bewaartermijn into a category, and a padded
	 * import of that is a wrong list nobody can see is wrong.
	 */
	public function testARowThatDoesNotMatchTheHeaderIsRefusedRatherThanPadded(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/refuses rather than pads/');

		$this->service->parse(
			contents: "categorie,archiefnominatie,bewaartermijn\n11.1.2,vernietigen\n",
			filename: 'lijst.csv'
		);
	}

	public function testARowWithNoCategoryIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/has no "categorie"/');

		$this->service->parse(
			contents: "categorie,bewaartermijn\n,P7Y\n",
			filename: 'lijst.csv'
		);
	}

	public function testAFormatTheImportDoesNotReadIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/not a "\.xlsx"/');

		$this->service->parse(contents: 'anything', filename: 'lijst.xlsx');
	}

	public function testEveryImportedRowCarriesTheVersionItCameFrom(): void {
		$result = $this->service->import(
			rows: [['categorie' => '11.1.2', 'bewaartermijn' => 'P7Y']],
			version: '2020',
			source: 'selectielijst-2020.csv'
		);

		$this->assertSame(['version' => '2020', 'imported' => 1, 'failed' => 0], $result);
		$this->assertSame('2020', $this->saved[0][SelectielijstImportService::VERSION_KEY]);
		$this->assertSame('selectielijst-2020.csv', $this->saved[0]['bron']);
	}

	public function testAnImportWithNoVersionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/names the version it is/');

		$this->service->import(rows: [['categorie' => '11.1.2']], version: '  ');
	}

	public function testAVersionAlreadyHereIsNotLoadedTwice(): void {
		$this->stored([['categorie' => '11.1.2', 'selectielijstVersie' => '2020']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/already imported/');

		$this->service->import(rows: [['categorie' => '11.1.2']], version: '2020');
	}

	public function testVersionsAreCountedAndAnUnnamedListIsSaidToBeUnversioned(): void {
		$this->stored(
			[
				['categorie' => '11.1.2', 'selectielijstVersie' => '2020'],
				['categorie' => '1.1', 'selectielijstVersie' => '2020'],
				['categorie' => '11.1.2', 'selectielijstVersie' => '2026'],
				['categorie' => '9.9'],
			]
		);

		$this->assertSame(
			['2020' => 2, '2026' => 1, 'unversioned' => 1],
			$this->service->versions()
		);
	}

	public function testTheDiffSaysWhatEachChangeWouldDo(): void {
		$this->stored(
			[
				['categorie' => '11.1.2', 'archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P7Y', 'selectielijstVersie' => '2020'],
				['categorie' => '2.3', 'archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P5Y', 'selectielijstVersie' => '2020'],
				['categorie' => '11.1.2', 'archiefnominatie' => 'vernietigen', 'bewaartermijn' => 'P10Y', 'selectielijstVersie' => '2026'],
				['categorie' => '4.4', 'archiefnominatie' => 'blijvend_bewaren', 'selectielijstVersie' => '2026'],
			]
		);

		$diff = $this->service->diff(from: '2020', to: '2026');

		$this->assertSame(['4.4'], $diff['added']);
		$this->assertSame(['2.3'], $diff['removed']);
		$this->assertCount(1, $diff['changed']);
		$this->assertSame('11.1.2', $diff['changed'][0]['category']);
		$this->assertSame(
			['from' => 'P7Y', 'to' => 'P10Y'],
			$diff['changed'][0]['fields']['bewaartermijn']
		);
		$this->assertArrayNotHasKey('archiefnominatie', $diff['changed'][0]['fields']);
	}

	public function testDiffingAgainstAVersionThatIsNotHereSaysSo(): void {
		$this->stored([['categorie' => '11.1.2', 'selectielijstVersie' => '2020']]);

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/No selectielijst rows are stored under version "2030"/');

		$this->service->diff(from: '2020', to: '2030');
	}
}
