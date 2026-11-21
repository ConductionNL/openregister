<?php

/**
 * Edges of the substantive-change test behind read state: a new object is
 * always substantive, list keys and non-array bodies, objects without or with
 * an unresolvable schema (logged, then judged by any change), computed
 * properties that do not count, and which declared sub-resources are kept.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Interaction
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Interaction;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\SchemaReading;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Interaction\SubstantiveChangeEvaluator;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * SubstantiveChangeEvaluator edges.
 */
class SubstantiveChangeEvaluatorEdgesBehaviourTest extends TestCase {

	private SchemaMapperInterface&MockObject $schemas;

	private LoggerInterface&MockObject $logger;

	protected function setUp(): void {
		$this->schemas = $this->createMock(SchemaMapperInterface::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	private function evaluator(): SubstantiveChangeEvaluator {
		return new SubstantiveChangeEvaluator(new SchemaReading($this->schemas, $this->logger));
	}//end evaluator()

	private static function object(?string $schema, ?array $body): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		$object->setObject($body);
		return $object;
	}//end object()

	private function schemaWith(array $properties, ?array $annotation = null): void {
		$schema = new Schema();
		$schema->setId(5);
		$schema->setProperties($properties);
		$schema->setConfiguration($annotation === null ? null : [SubstantiveChangeEvaluator::ANNOTATION => $annotation]);
		$this->schemas->method('find')->willReturn($schema);
	}//end schemaWith()

	public function testANewObjectIsAlwaysSubstantive(): void {
		$this->schemas->expects($this->never())->method('find');

		$this->assertTrue($this->evaluator()->isSubstantive(self::object('5', ['a' => 1])));
	}//end testANewObjectIsAlwaysSubstantive()

	public function testWithoutASchemaAnyChangedNamedPropertyIsSubstantive(): void {
		$this->schemas->expects($this->never())->method('find');
		$evaluator = $this->evaluator();

		$this->assertTrue($evaluator->isSubstantive(self::object(null, ['a' => 2]), self::object(null, ['a' => 1])));
		$this->assertTrue($evaluator->isSubstantive(self::object('', ['a' => 1]), self::object('', null)));
		$this->assertFalse($evaluator->isSubstantive(self::object(null, [0 => 'x']), self::object(null, [0 => 'y'])));
		$this->assertFalse($evaluator->isSubstantive(self::object(null, null), self::object(null, null)));
	}//end testWithoutASchemaAnyChangedNamedPropertyIsSubstantive()

	public function testAnUnresolvableSchemaIsLoggedAndTheChangeJudgedWithoutIt(): void {
		$this->schemas->method('find')->willThrowException(new DoesNotExistException('gone'));
		$this->logger->expects($this->once())->method('debug')
			->with('[SubstantiveChangeEvaluator] schema 9 did not resolve: gone');

		$this->assertTrue($this->evaluator()->isSubstantive(self::object('9', ['a' => 2]), self::object('9', ['a' => 1])));
	}//end testAnUnresolvableSchemaIsLoggedAndTheChangeJudgedWithoutIt()

	public function testAChangeToOnlyComputedPropertiesIsNotSubstantive(): void {
		$this->schemaWith(
			[
				'total' => ['type' => 'number', 'computed' => ['expression' => 'a + b']],
				'label' => ['type' => 'string', 'computed' => 'not-a-block'],
				7 => ['type' => 'string', 'computed' => ['x']],
				'raw' => 'not-a-definition',
			]
		);
		$evaluator = $this->evaluator();

		$this->assertFalse($evaluator->isSubstantive(self::object('5', ['total' => 3]), self::object('5', ['total' => 2])));
		$this->assertTrue($evaluator->isSubstantive(self::object('5', ['label' => 'b']), self::object('5', ['label' => 'a'])));
		$this->assertTrue($evaluator->isSubstantive(self::object('5', ['raw' => 'b', 'total' => 3]), self::object('5', ['raw' => 'a', 'total' => 2])));
	}//end testAChangeToOnlyComputedPropertiesIsNotSubstantive()

	public function testFilesAreAlwaysASubResourceAndOnlyCompleteDeclarationsAreAdded(): void {
		$this->schemaWith(
			[],
			[
				'subResources' => [
					'attachments' => ['kind' => 'files'],
					'notes' => ['property' => 'notes', 'dateField' => 'created'],
					'comments' => ['dateField' => 'at'],
					'undated' => ['property' => 'x'],
					'' => ['kind' => 'files'],
					3 => ['kind' => 'files'],
					'bad' => 'files',
				],
			]
		);

		$this->assertSame(
			[
				'files' => ['kind' => 'files'],
				'attachments' => ['kind' => 'files'],
				'notes' => ['kind' => 'property', 'property' => 'notes', 'dateField' => 'created'],
				'comments' => ['kind' => 'property', 'property' => 'comments', 'dateField' => 'at'],
			],
			$this->evaluator()->subResources(self::object('5', []))
		);
	}//end testFilesAreAlwaysASubResourceAndOnlyCompleteDeclarationsAreAdded()

	public function testWithoutAnAnnotationOrSchemaOnlyFilesAreSubResources(): void {
		$this->schemaWith([], ['subResources' => 'all']);

		$this->assertSame(['files' => ['kind' => 'files']], $this->evaluator()->subResources(self::object('5', [])));
		$this->assertSame(['files' => ['kind' => 'files']], $this->evaluator()->subResources(self::object(null, [])));
	}//end testWithoutAnAnnotationOrSchemaOnlyFilesAreSubResources()
}//end class
