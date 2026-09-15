<?php

declare(strict_types=1);

/**
 * The refusal that stands between an unmapped element and the e-Depot.
 *
 * Discovering an unmapped mandatory element at the e-Depot means a package has
 * already left and an administrator has a rejection to interpret. These pin the
 * two halves of the answer: which elements a mapping leaves unfilled, and that
 * a schema declaring no mapping keeps today's behaviour rather than having
 * every transfer refused the day this shipped.
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

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Archival\MdtoElementCatalogue;
use OCA\OpenRegister\Service\Archival\MdtoMappingResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for MdtoMappingResolver.
 */
class MdtoMappingResolverTest extends TestCase {

	private SchemaMapper&MockObject $schemaMapper;
	private MdtoMappingResolver $resolver;

	protected function setUp(): void {
		parent::setUp();

		$this->schemaMapper = $this->getMockBuilder(SchemaMapper::class)
			->disableOriginalConstructor()
			->onlyMethods(['find'])
			->getMock();

		$this->resolver = new MdtoMappingResolver(
			$this->schemaMapper,
			new MdtoElementCatalogue(),
			$this->createMock(LoggerInterface::class)
		);
	}

	/**
	 * Point the schema mapper at a configuration.
	 *
	 * @param array<string, mixed> $configuration The schema configuration.
	 *
	 * @return void
	 */
	private function withSchemaConfiguration(array $configuration): void {
		$schema = $this->getMockBuilder(Schema::class)
			->disableOriginalConstructor()
			->onlyMethods(['getConfiguration'])
			->getMock();
		$schema->method('getConfiguration')->willReturn($configuration);
		$this->schemaMapper->method('find')->willReturn($schema);
	}

	/**
	 * A record with some data.
	 *
	 * @param array<string, mixed> $data The object data.
	 *
	 * @return ObjectEntity The record.
	 */
	private function object(array $data): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('7');
		$object->setObject($data);

		return $object;
	}

	/**
	 * A mapping filling every mandatory element from the record.
	 *
	 * @return array<string, mixed> The mapping.
	 */
	private function mapping(): array {
		return [
			'identificatie' => ['property' => 'zaaknummer'],
			'naam' => ['property' => 'titel'],
			'waardering' => ['property' => 'resultaat'],
			'archiefvormer' => ['const' => 'Gemeente Voorbeeld'],
			'beperkingGebruik' => ['const' => 'Geen beperking'],
		];
	}

	public function testASchemaWithNoMappingKeepsTodaysBehaviour(): void {
		$this->withSchemaConfiguration([]);

		$this->assertNull($this->resolver->mappingFor(object: $this->object(['titel' => 'A'])));
		$this->assertSame(
			[],
			$this->resolver->unfilledMandatoryElements(object: $this->object([]))
		);
	}

	public function testAMappingThatEveryRecordFieldSatisfiesLeavesNothingUnfilled(): void {
		$this->withSchemaConfiguration(['x-openregister-mdto-mapping' => $this->mapping()]);

		$this->assertSame(
			[],
			$this->resolver->unfilledMandatoryElements(
				object: $this->object(
					['zaaknummer' => 'Z-1', 'titel' => 'Bezwaar 2019/114', 'resultaat' => 'vernietigen']
				)
			)
		);
	}

	/**
	 * A property the mapping names but the record leaves empty is unfilled. The
	 * mapping being valid at save time does not make the record complete.
	 */
	public function testAnEmptyValueOnAMappedPropertyIsUnfilled(): void {
		$this->withSchemaConfiguration(['x-openregister-mdto-mapping' => $this->mapping()]);

		$this->assertSame(
			['naam'],
			$this->resolver->unfilledMandatoryElements(
				object: $this->object(['zaaknummer' => 'Z-1', 'titel' => '', 'resultaat' => 'vernietigen'])
			)
		);
	}

	public function testAnElementTheMappingOmitsIsUnfilled(): void {
		$mapping = $this->mapping();
		unset($mapping['archiefvormer']);
		$this->withSchemaConfiguration(['x-openregister-mdto-mapping' => $mapping]);

		$this->assertSame(
			['archiefvormer'],
			$this->resolver->unfilledMandatoryElements(
				object: $this->object(['zaaknummer' => 'Z-1', 'titel' => 'A', 'resultaat' => 'vernietigen'])
			)
		);
	}

	public function testAConstantAlwaysFillsEvenOnAnEmptyRecord(): void {
		$this->withSchemaConfiguration(
			[
				'x-openregister-mdto-mapping' => [
					'identificatie' => ['const' => 'onbekend'],
					'naam' => ['const' => 'onbekend'],
					'waardering' => ['const' => 'vernietigen'],
					'archiefvormer' => ['const' => 'Gemeente Voorbeeld'],
					'beperkingGebruik' => ['const' => 'Geen beperking'],
				],
			]
		);

		$this->assertSame([], $this->resolver->unfilledMandatoryElements(object: $this->object([])));
	}

	public function testADottedPathReachesIntoANestedValue(): void {
		$this->withSchemaConfiguration(
			[
				'x-openregister-mdto-mapping' => array_merge(
					$this->mapping(),
					['waardering' => ['property' => 'resultaat.archiefnominatie']]
				),
			]
		);

		$this->assertSame(
			[],
			$this->resolver->unfilledMandatoryElements(
				object: $this->object(
					[
						'zaaknummer' => 'Z-1',
						'titel' => 'A',
						'resultaat' => ['archiefnominatie' => 'vernietigen'],
					]
				)
			)
		);

		$this->assertSame(
			['waardering'],
			$this->resolver->unfilledMandatoryElements(
				object: $this->object(['zaaknummer' => 'Z-1', 'titel' => 'A', 'resultaat' => []])
			)
		);
	}
}
