<?php

/**
 * A change to a referenced object refreshes the materialised values that read
 * the changed field: a case type that starts listing flows re-saves its cases,
 * so their `hasStartableFlows` follows.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/rematerialise-on-referenced-change/specs/computed-fields/spec.md#requirement-a-referenced-change-refreshes-the-materialised-values-that-read-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation;

use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher;
use OCA\OpenRegister\Service\ObjectService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\OpenRegister\Service\Calculation\ReferencedCalculationRefresher
 */
class ReferencedCalculationRefresherTest extends TestCase {

	/**
	 * The case type schema.
	 *
	 * @return Schema
	 */
	private static function caseTypeSchema(): Schema {
		$schema = new Schema();
		$schema->setId(1);
		$schema->setSlug('caseType');
		return $schema;
	}//end caseTypeSchema()

	/**
	 * The case schema, reading `@ref.caseType.startableFlows` in a materialised calculation.
	 *
	 * @return Schema
	 */
	private static function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setId(2);
		$schema->setSlug('case');
		$schema->setProperties(['title' => ['type' => 'string'], 'caseType' => ['type' => 'string'], 'statusHistory' => ['type' => 'string']]);
		$schema->setConfiguration([
			'x-openregister-references'   => [
				'caseType' => ['schema' => 'caseType', 'mode' => 'relatedObject', 'field' => 'caseType'],
			],
			'x-openregister-calculations' => [
				'hasStartableFlows' => [
					'materialise' => true,
					'expression'  => ['ne' => [['prop' => '@ref.caseType.startableFlows'], null]],
				],
			],
		]);
		return $schema;
	}//end caseSchema()

	/**
	 * A refresher over a register with the two schemas and one case of the type.
	 *
	 * @param ObjectService    $service The object service the re-saves go through.
	 * @param MagicMapper|null $rows    The referrer lookup, a fresh mock when null.
	 *
	 * @return ReferencedCalculationRefresher
	 */
	private function refresher(ObjectService $service, ?MagicMapper $rows=null): ReferencedCalculationRefresher {
		$register = new Register();
		$register->setId(7);
		$register->setSchemas([1, 2]);

		$registers = $this->createMock(originalClassName: RegisterMapper::class);
		$registers->method('find')->willReturn(value: $register);

		$schemas = $this->createMock(originalClassName: SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			callback: static function (string|int $id): Schema {
				if ((string) $id === '2') {
					return self::caseSchema();
				}

				return self::caseTypeSchema();
			}
		);

		$case = new ObjectEntity();
		$case->setUuid('case-1');
		$case->setRegister('7');
		$case->setSchema('2');
		// As the read hands it back: the JSON the string-typed statusHistory holds, decoded.
		$case->setObject(['title' => 'Zaak', 'caseType' => 'type-1', 'statusHistory' => [['status' => 'ontvangen']]]);

		$rows ??= $this->createMock(originalClassName: MagicMapper::class);
		$rows->method('findAllInRegisterSchemaTable')->willReturnCallback(
			callback: static function (mixed ...$args) use ($case): array {
				if (in_array(['caseType' => 'type-1'], $args, true) === true) {
					return [$case];
				}

				return [];
			}
		);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn(value: $service);

		return new ReferencedCalculationRefresher(registers: $registers, schemas: $schemas, rows: $rows, container: $container, logger: new NullLogger());
	}//end refresher()

	/**
	 * A case type version.
	 *
	 * @param array<string, mixed> $data Its data.
	 *
	 * @return ObjectEntity
	 */
	private static function caseType(array $data): ObjectEntity {
		$type = new ObjectEntity();
		$type->setUuid('type-1');
		$type->setRegister('7');
		$type->setSchema('1');
		$type->setObject($data);
		return $type;
	}//end caseType()

	/**
	 * The case type starts listing flows: its case is re-saved.
	 *
	 * @return void
	 */
	public function testAChangedReadFieldReSavesTheReferringObjects(): void {
		$service = $this->createMock(originalClassName: ObjectService::class);
		$service->expects($this->once())->method('saveObject')->with(
			$this->callback(callback: static fn (array $object): bool => ($object['caseType'] ?? null) === 'type-1')
		);

		$saved = $this->refresher(service: $service)->refresh(
			updated: self::caseType(data: ['title' => 'Woo', 'startableFlows' => ['flow-1']]),
			old: self::caseType(data: ['title' => 'Woo'])
		);

		$this->assertSame(expected: 1, actual: $saved);
	}//end testAChangedReadFieldReSavesTheReferringObjects()

	/**
	 * The inline guard names the changed fields a dependent reads, without reading a referrer.
	 *
	 * @return void
	 */
	public function testTheGuardNamesTheChangedReadFieldsWithoutReadingReferrers(): void {
		$service = $this->createMock(originalClassName: ObjectService::class);
		$service->expects($this->never())->method('saveObject');
		$rows = $this->createMock(originalClassName: MagicMapper::class);
		$rows->expects($this->never())->method('findAllInRegisterSchemaTable');

		$changed = $this->refresher(service: $service, rows: $rows)->changedReadFields(
			updated: self::caseType(data: ['title' => 'Other', 'startableFlows' => ['flow-1']]),
			old: self::caseType(data: ['title' => 'Woo'])
		);

		$this->assertSame(expected: ['startableFlows'], actual: $changed);
	}//end testTheGuardNamesTheChangedReadFieldsWithoutReadingReferrers()

	/**
	 * A refresh queued at the cascade bound re-saves nothing, so a deferred cascade stops.
	 *
	 * @return void
	 */
	public function testARefreshAtTheCascadeBoundReSavesNothing(): void {
		$service = $this->createMock(originalClassName: ObjectService::class);
		$service->expects($this->never())->method('saveObject');

		$refresher = $this->refresher(service: $service);
		$saved     = $refresher->refreshChanged(updated: self::caseType(data: ['startableFlows' => ['flow-1']]), changed: ['startableFlows'], depth: 2);

		$this->assertSame(expected: 0, actual: $saved);
		$this->assertSame(expected: 0, actual: $refresher->depth());
	}//end testARefreshAtTheCascadeBoundReSavesNothing()

	/**
	 * While the re-saves run the depth is one deeper, so a refresh they queue carries it.
	 *
	 * @return void
	 */
	public function testTheReSavesRunOneLevelDeeper(): void {
		$service   = $this->createMock(originalClassName: ObjectService::class);
		$seen      = [];
		$refresher = null;
		$service->method('saveObject')->willReturnCallback(
			function () use (&$seen, &$refresher) {
				$seen[] = $refresher->depth();
				return new ObjectEntity();
			}
		);
		$refresher = $this->refresher(service: $service);

		$refresher->refreshChanged(updated: self::caseType(data: ['startableFlows' => ['flow-1']]), changed: ['startableFlows'], depth: 1);

		$this->assertSame(expected: [2], actual: $seen);
		$this->assertSame(expected: 0, actual: $refresher->depth());
	}//end testTheReSavesRunOneLevelDeeper()

	/**
	 * The re-save hands a decoded string-typed value back as the stored JSON string.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/resave-restores-string-typed-values/specs/computed-fields/spec.md
	 */
	public function testTheReSaveRestoresDecodedStringTypedValues(): void {
		$service = $this->createMock(originalClassName: ObjectService::class);
		$service->expects($this->once())->method('saveObject')->with(
			$this->callback(callback: static fn (array $object): bool => ($object['statusHistory'] ?? null) === '[{"status":"ontvangen"}]')
		);

		$this->refresher(service: $service)->refresh(
			updated: self::caseType(data: ['title' => 'Woo', 'startableFlows' => ['flow-1']]),
			old: self::caseType(data: ['title' => 'Woo'])
		);
	}//end testTheReSaveRestoresDecodedStringTypedValues()

	/**
	 * A change to a field no calculation reads re-saves nothing.
	 *
	 * @return void
	 */
	public function testAChangeNoCalculationReadsReSavesNothing(): void {
		$service = $this->createMock(originalClassName: ObjectService::class);
		$service->expects($this->never())->method('saveObject');

		$saved = $this->refresher(service: $service)->refresh(
			updated: self::caseType(data: ['title' => 'Woo-verzoek', 'startableFlows' => ['flow-1']]),
			old: self::caseType(data: ['title' => 'Woo', 'startableFlows' => ['flow-1']])
		);

		$this->assertSame(expected: 0, actual: $saved);
	}//end testAChangeNoCalculationReadsReSavesNothing()

	/**
	 * The guard: when no materialised `@ref` reads the changed field, no
	 * referring object is even looked up, let alone saved.
	 *
	 * @return void
	 */
	public function testAnUnreadChangeLooksNothingUp(): void {
		$service = $this->createMock(originalClassName: ObjectService::class);
		$service->expects($this->never())->method('saveObject');
		$rows = $this->createMock(originalClassName: MagicMapper::class);
		$rows->expects($this->never())->method('findAllInRegisterSchemaTable');

		$saved = $this->refresher(service: $service, rows: $rows)->refresh(
			updated: self::caseType(data: ['title' => 'Woo-verzoek']),
			old: self::caseType(data: ['title' => 'Woo'])
		);

		$this->assertSame(expected: 0, actual: $saved);
	}//end testAnUnreadChangeLooksNothingUp()

	/**
	 * The dependency names the pointing field and the fields read through it.
	 *
	 * @return void
	 */
	public function testTheDependencyNamesThePointingFieldAndTheFieldsRead(): void {
		$refresher = $this->refresher(service: $this->createMock(originalClassName: ObjectService::class));

		$this->assertSame(
			expected: ['caseType' => ['startableFlows']],
			actual: $refresher->dependencies(schema: self::caseSchema(), target: self::caseTypeSchema())
		);
		$this->assertSame(expected: [], actual: $refresher->dependencies(schema: self::caseTypeSchema(), target: self::caseSchema()));
	}//end testTheDependencyNamesThePointingFieldAndTheFieldsRead()
}//end class
