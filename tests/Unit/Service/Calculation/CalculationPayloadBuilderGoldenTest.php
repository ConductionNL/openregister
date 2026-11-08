<?php

/**
 * Golden (characterisation) tests for the calculation payload builder.
 *
 * Pins the `@self` / `@ref` / `@aggregate` payload shape
 * {@see \OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder}
 * builds, and which unresolved references an expression reads.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Calculation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Calculation;

use DateTime;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Calculation\AggregateReferenceResolver;
use OCA\OpenRegister\Service\Calculation\CalculationPayloadBuilder;
use OCA\OpenRegister\Service\Calculation\ReferenceResolver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * CalculationPayloadBuilderGoldenTest.
 */
class CalculationPayloadBuilderGoldenTest extends TestCase {

	/**
	 * The references under test or mocked.
	 *
	 * @var ReferenceResolver&MockObject
	 */
	private ReferenceResolver&MockObject $references;

	/**
	 * The aggregates under test or mocked.
	 *
	 * @var AggregateReferenceResolver&MockObject
	 */
	private AggregateReferenceResolver&MockObject $aggregates;

	/**
	 * The builder under test or mocked.
	 *
	 * @var CalculationPayloadBuilder
	 */
	private CalculationPayloadBuilder $builder;

	/**
	 * Wire the builder with mocked resolvers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->references = $this->createMock(ReferenceResolver::class);
		$this->aggregates = $this->createMock(AggregateReferenceResolver::class);
		$this->builder = new CalculationPayloadBuilder(references: $this->references, aggregates: $this->aggregates);

	}//end setUp()

	/**
	 * Without reference blocks the payload is the data plus `@self`, null timestamps kept.
	 *
	 * @return void
	 */
	public function testPayloadWithoutReferenceBlocks(): void {
		$this->references->expects($this->never())->method('resolveAllWithOutcome');
		$this->aggregates->expects($this->never())->method('resolveAll');
		$schema = $this->schema(configuration: ['x-openregister-references' => [], 'x-openregister-aggregate-refs' => 'x']);

		$this->assertSame(
			[
				'id' => 'u-1',
				'a' => 1,
				'@self' => [
					'id' => 'u-1',
					'uuid' => 'u-1',
					'register' => '7',
					'schema' => '12',
					'owner' => 'alice',
					'created' => null,
					'updated' => null,
				],
			],
			$this->builder->build(object: $this->object(timestamps: false), schema: $schema)
		);

	}//end testPayloadWithoutReferenceBlocks()

	/**
	 * Declared references and aggregates resolve into the payload, with the unresolved list.
	 *
	 * @return void
	 */
	public function testPayloadWithReferenceBlocks(): void {
		$refs = ['owner' => ['schema' => 's', 'mode' => 'lookup']];
		$aggs = ['total' => ['schema' => 's', 'metric' => 'count']];
		$this->references->expects($this->once())->method('resolveAllWithOutcome')->willReturnCallback(
			function (array $payload, array $references, mixed $register, ?string $organisation) use ($refs): array {
				$this->assertSame('2026-01-02T03:04:05+00:00', $payload['@self']['created']);
				$this->assertSame([$refs, '7', 'org-1'], [$references, $register, $organisation]);
				return ['refs' => ['owner' => ['n' => 1]], 'unresolved' => ['gone']];
			}
		);
		$this->aggregates->expects($this->once())->method('resolveAll')->willReturnCallback(
			function (array $payload, array $aggregates, mixed $registerRef) use ($aggs): array {
				$this->assertSame(['owner' => ['n' => 1]], $payload['@ref']);
				$this->assertSame([$aggs, '7'], [$aggregates, $registerRef]);
				return ['total' => 3];
			}
		);
		$schema = $this->schema(configuration: ['x-openregister-references' => $refs, 'x-openregister-aggregate-refs' => $aggs]);

		$payload = $this->builder->build(object: $this->object(timestamps: true), schema: $schema);

		$this->assertSame('2026-01-02T03:04:05+00:00', $payload['@self']['created']);
		$this->assertSame('2026-02-03T04:05:06+00:00', $payload['@self']['updated']);
		$this->assertSame(['owner' => ['n' => 1]], $payload['@ref']);
		$this->assertSame(['gone'], $payload[CalculationPayloadBuilder::UNRESOLVED_KEY]);
		$this->assertSame(['total' => 3], $payload['@aggregate']);
		$this->assertSame(['id' => 'u-1', 'a' => 1], $this->builder->stripSyntheticKeys(data: $payload));

	}//end testPayloadWithReferenceBlocks()

	/**
	 * The unresolved references an expression reads, in first-read order.
	 *
	 * @return void
	 */
	public function testUnresolvedReferencesUsedBy(): void {
		$payload = [CalculationPayloadBuilder::UNRESOLVED_KEY => ['b', 'a', 'c']];
		$expression = ['+' => ['@ref.a.x', ['prop' => '@ref.b'], '@ref.a.y', '@ref.', '@self.c', 3, ['if' => ['@ref.c.z']]]];

		$this->assertSame(['a', 'b', 'c'], $this->builder->unresolvedReferencesUsedBy(payload: $payload, expression: $expression));
		$this->assertSame([], $this->builder->unresolvedReferencesUsedBy(payload: [], expression: $expression));
		$notAList = [CalculationPayloadBuilder::UNRESOLVED_KEY => 'a'];
		$this->assertSame([], $this->builder->unresolvedReferencesUsedBy(payload: $notAList, expression: '@ref.a'));
		$this->assertSame(['a'], $this->builder->unresolvedReferencesUsedBy(payload: $payload, expression: '@ref.a'));
		$this->assertSame([], $this->builder->unresolvedReferencesUsedBy(payload: $payload, expression: 5));

	}//end testUnresolvedReferencesUsedBy()

	/**
	 * A schema with the given configuration.
	 *
	 * @param array<string, mixed> $configuration The configuration.
	 *
	 * @return Schema The schema.
	 */
	private function schema(array $configuration): Schema {
		$schema = new Schema();
		$schema->setConfiguration($configuration);

		return $schema;

	}//end schema()

	/**
	 * An object with or without timestamps.
	 *
	 * @param bool $timestamps Whether created/updated are set.
	 *
	 * @return ObjectEntity The object.
	 */
	private function object(bool $timestamps): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setRegister('7');
		$object->setSchema('12');
		$object->setOwner('alice');
		$object->setOrganisation('org-1');
		$object->setObject(['a' => 1]);
		if ($timestamps === true) {
			$object->setCreated(new DateTime('2026-01-02T03:04:05+00:00'));
			$object->setUpdated(new DateTime('2026-02-03T04:05:06+00:00'));
		}

		return $object;

	}//end object()
}//end class
