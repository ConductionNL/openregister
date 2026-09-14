<?php

/**
 * Unit tests for what counts as a substantive change.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Interaction;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The declared case, the undeclared default, and the computed-field exemption.
 *
 * @coversDefaultClass \OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator
 */
class SubstantiveChangeEvaluatorTest extends TestCase {

	/**
	 * The schema resolver, mocked.
	 *
	 * @var SchemaMapper
	 */
	private SchemaMapper $schemaMapper;

	/**
	 * Build the shared mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(originalClassName: SchemaMapper::class);

	}//end setUp()

	/**
	 * An evaluator whose schema carries the given annotation and properties.
	 *
	 * @param array<string, mixed> $annotation The `x-openregister-read-state` block.
	 * @param array<string, mixed> $properties The schema's properties.
	 *
	 * @return SubstantiveChangeEvaluator
	 */
	private function evaluatorFor(array $annotation = [], array $properties = []): SubstantiveChangeEvaluator {
		// A REAL Schema, not a mock: `getId()` is final on Nextcloud's Entity
		// base class, so a mock cannot answer it and the memo key would be
		// empty for every schema, which would make one test's annotation leak
		// into the next.
		$schema = new Schema();
		$schema->setId(777);
		$schema->setProperties($properties);

		$configuration = [];
		if ($annotation !== []) {
			$configuration[SubstantiveChangeEvaluator::ANNOTATION] = $annotation;
		}

		$schema->setConfiguration($configuration);
		$this->schemaMapper->method('find')->willReturn($schema);

		return new SubstantiveChangeEvaluator(
			schemaMapper: $this->schemaMapper,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);

	}//end evaluatorFor()

	/**
	 * An object carrying a body.
	 *
	 * @param array<string, mixed> $body The object body.
	 *
	 * @return ObjectEntity
	 */
	private function objectWith(array $body): ObjectEntity {
		$object = $this->createMock(originalClassName: ObjectEntity::class);
		$object->method('getUuid')->willReturn('uuid-case-1');
		$object->method('getSchema')->willReturn('777');
		$object->method('getObject')->willReturn($body);

		return $object;

	}//end objectWith()

	/**
	 * A declared property changing is news.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testADeclaredPropertyChangeIsSubstantive(): void {
		$evaluator = $this->evaluatorFor(annotation: ['properties' => ['status']]);

		$this->assertTrue(
			$evaluator->isSubstantive(
				new: $this->objectWith(['status' => 'closed', 'title' => 'A']),
				old: $this->objectWith(['status' => 'open', 'title' => 'A'])
			)
		);

	}//end testADeclaredPropertyChangeIsSubstantive()

	/**
	 * A property the schema did not declare is a technical touch.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testAnUndeclaredPropertyChangeIsNotSubstantive(): void {
		$evaluator = $this->evaluatorFor(annotation: ['properties' => ['status']]);

		$this->assertFalse(
			$evaluator->isSubstantive(
				new: $this->objectWith(['status' => 'open', 'internalRef' => 'B']),
				old: $this->objectWith(['status' => 'open', 'internalRef' => 'A'])
			)
		);

	}//end testAnUndeclaredPropertyChangeIsNotSubstantive()

	/**
	 * A recalculated computed field changes nothing for anybody.
	 *
	 * This is the scenario the whole evaluator exists for: without it, the
	 * nightly recomputation marks every object unread for every reader
	 * overnight, and the badge stops meaning anything.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testARecalculatedComputedFieldIsNotSubstantive(): void {
		$evaluator = $this->evaluatorFor(
			properties: [
				'title' => ['type' => 'string'],
				'daysOpen' => ['type' => 'integer', 'computed' => ['expression' => 'now - created']],
			]
		);

		$this->assertFalse(
			$evaluator->isSubstantive(
				new: $this->objectWith(['title' => 'A', 'daysOpen' => 12]),
				old: $this->objectWith(['title' => 'A', 'daysOpen' => 11])
			)
		);

	}//end testARecalculatedComputedFieldIsNotSubstantive()

	/**
	 * With nothing declared, an ordinary property change is still news.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testWithNothingDeclaredAnOrdinaryChangeIsSubstantive(): void {
		$evaluator = $this->evaluatorFor(
			properties: ['title' => ['type' => 'string'], 'daysOpen' => ['computed' => ['expression' => 'x']]]
		);

		$this->assertTrue(
			$evaluator->isSubstantive(
				new: $this->objectWith(['title' => 'B', 'daysOpen' => 11]),
				old: $this->objectWith(['title' => 'A', 'daysOpen' => 11])
			)
		);

	}//end testWithNothingDeclaredAnOrdinaryChangeIsSubstantive()

	/**
	 * A write that changed nothing at all is not news either.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-an-object-carries-a-read-state-per-user-req-ors-001
	 */
	public function testAWriteThatChangedNothingIsNotSubstantive(): void {
		$evaluator = $this->evaluatorFor();

		$this->assertFalse(
			$evaluator->isSubstantive(
				new: $this->objectWith(['title' => 'A']),
				old: $this->objectWith(['title' => 'A'])
			)
		);

	}//end testAWriteThatChangedNothingIsNotSubstantive()

	/**
	 * Files are a sub-resource whether or not a schema says so.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function testFilesAreAlwaysASubResource(): void {
		$this->assertSame(
			['files' => ['kind' => 'files']],
			$this->evaluatorFor()->subResources(object: $this->objectWith([]))
		);

	}//end testFilesAreAlwaysASubResource()

	/**
	 * A declared sub-resource without a date field cannot be counted, so it is
	 * absent rather than badged as nought.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/object-read-state/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	public function testASubResourceWithoutADateFieldIsDropped(): void {
		$resources = $this->evaluatorFor(
			annotation: [
				'subResources' => [
					'messages' => ['kind' => 'property', 'property' => 'messages', 'dateField' => 'created'],
					'notes' => ['kind' => 'property', 'property' => 'notes'],
				],
			]
		)->subResources(object: $this->objectWith([]));

		$this->assertArrayHasKey('messages', $resources);
		$this->assertArrayNotHasKey('notes', $resources);

	}//end testASubResourceWithoutADateFieldIsDropped()
}//end class
