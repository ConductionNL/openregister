<?php

/**
 * Three stored objects of one schema with a materialised calculation, two of them stale,
 * for the recompute tests.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/recompute-and-reindex-on-demand/specs/computed-fields/spec.md#requirement-computed-fields-are-re-derived-on-demand-over-a-selection-req-rrd-001
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Calculation\Support;

use OCA\OpenRegister\Command\Support\RematerialiseEvaluation;
use OCA\OpenRegister\Command\Support\RematerialiseStorage;
use OCA\OpenRegister\Db\MagicMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaCatalog;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Kernel\Contracts\ListQuery;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluator;
use OCA\OpenRegister\Service\Calculation\CalculationRematerialiser;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use OCA\OpenRegister\Service\Object\SchemaTypeConverter;
use OCA\OpenRegister\Service\ObjectService;

/**
 * Cases A and B hold a stale `typeLabel`; case C holds the current one.
 */
trait RecomputeHarness {
	/**
	 * The uuids handed to saveObject(), in order.
	 *
	 * @var list<string>
	 */
	private array $savedUuids = [];

	/**
	 * The listings the reads asked for.
	 *
	 * @var list<ListQuery>
	 */
	private array $listings = [];

	/**
	 * The register.
	 *
	 * @return Register
	 */
	private function register(): Register {
		$register = new Register();
		$register->setId(5);
		$register->setSlug('zaken');
		return $register;
	}//end register()

	/**
	 * The case schema: `typeLabel` is materialised and reads the case type.
	 *
	 * @return Schema
	 */
	private function caseSchema(): Schema {
		$schema = new Schema();
		$schema->setId(7);
		$schema->setSlug('case');
		$schema->setProperties(['title' => ['type' => 'string'], 'typeLabel' => ['type' => 'string', 'readOnly' => true]]);
		$schema->setConfiguration(['x-openregister-calculations' => ['typeLabel' => ['expression' => 'label', 'materialise' => true]]]);
		return $schema;
	}//end caseSchema()

	/**
	 * The three stored cases.
	 *
	 * @return list<ObjectEntity>
	 */
	private function cases(): array {
		$cases = [];
		foreach ([['A', 11, 'Old name'], ['B', 12, 'Old name'], ['C', 13, 'New name']] as [$name, $id, $label]) {
			$case = new ObjectEntity();
			$case->setId($id);
			$case->setUuid('case-' . $name);
			$case->setRegister('5');
			$case->setSchema('7');
			$case->setOwner('owner-' . $name);
			$case->setObject(['title' => 'Case ' . $name, 'typeLabel' => $label]);
			$cases[] = $case;
		}

		return $cases;
	}//end cases()

	/**
	 * The storage and evaluation the command and the service share, over the three cases.
	 *
	 * @return array{RematerialiseStorage, RematerialiseEvaluation, SchemaTypeConverter}
	 */
	private function collaborators(): array {
		$registers = $this->createMock(RegisterMapper::class);
		$registers->method('find')->willReturn($this->register());
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturn($this->caseSchema());

		$cases = $this->cases();
		$byUuid = array_column(array_map(static fn (ObjectEntity $c): array => [$c->getUuid(), $c], $cases), 1, 0);
		$magic = $this->createMock(MagicMapper::class);
		$magic->method('findAllInRegisterSchemaTable')->willReturnCallback(
			function ($table, ListQuery $listing) use ($cases): array {
				$this->listings[] = $listing;
				return $cases;
			}
		);
		$magic->method('find')->willReturnCallback(static fn (string $identifier): ObjectEntity => $byUuid[$identifier]);

		$evaluator = $this->createMock(CalculationEvaluator::class);
		$evaluator->method('expressionUsesSequence')->willReturn(false);
		$evaluator->method('evaluate')->willReturn('New name');

		$objects = $this->createMock(ObjectService::class);
		$objects->method('saveObject')->willReturnCallback(
			function (array|ObjectEntity $object, ObjectSaveOptions $options = new ObjectSaveOptions()): ObjectEntity {
				// The save path materialises the value, as CalculationOnSaveListener does.
				$this->savedUuids[] = (string)$options->uuid;
				$saved = new ObjectEntity();
				$saved->setObject(array_merge($object, ['typeLabel' => 'New name']));
				return $saved;
			}
		);

		return [
			new RematerialiseStorage(new SchemaCatalog($registers, $schemas, $magic), $objects),
			new RematerialiseEvaluation($evaluator, $this->createMock(ReferenceResolver::class), $this->createMock(AggregateReferenceResolver::class)),
			new SchemaTypeConverter(),
		];
	}//end collaborators()

	/**
	 * The service over the three cases.
	 *
	 * @return CalculationRematerialiser
	 */
	private function rematerialiser(): CalculationRematerialiser {
		[$storage, $evaluation, $types] = $this->collaborators();
		return new CalculationRematerialiser(storage: $storage, evaluation: $evaluation, types: $types);
	}//end rematerialiser()
}//end trait
