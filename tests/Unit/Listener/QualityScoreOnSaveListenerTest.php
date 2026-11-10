<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Listener\QualityScoreOnSaveListener;
use OCA\OpenRegister\Service\Quality\QualityScorer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The quality assessment belongs to `@self`, not to the object's data.
 *
 * It describes the object's data rather than the thing the object describes.
 * Keeping it out of the body is what lets a schema be scored without
 * declaring `qualityScore` as an ordinary property, which used to put a
 * number field the platform overwrites in front of whoever filled the form.
 */
class QualityScoreOnSaveListenerTest extends TestCase {

	private SchemaMapper $schemaMapper;

	private QualityScorer $scorer;

	private QualityScoreOnSaveListener $listener;

	protected function setUp(): void {
		$this->schemaMapper = $this->createMock(SchemaMapper::class);
		$this->scorer = $this->createMock(QualityScorer::class);

		$this->listener = new QualityScoreOnSaveListener(
			$this->schemaMapper,
			$this->scorer,
			$this->createMock(LoggerInterface::class),
		);
	}

	/**
	 * A schema carrying the annotation, declaring the given properties.
	 *
	 * Only methods Schema really declares are stubbed. PHPUnit refuses to
	 * configure one that does not exist, which is the property that makes this
	 * mock able to fail when the real signature moves.
	 *
	 * @param array $properties The schema's declared properties.
	 * @param array $quality    The `x-openregister-quality` annotation.
	 */
	private function schema(array $properties, array $quality = ['rules' => [['field' => 'title']]]): Schema {
		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn(['x-openregister-quality' => $quality]);
		$schema->method('getProperties')->willReturn($properties);

		return $schema;
	}

	private function fire(ObjectEntity $object, Schema $schema): void {
		$this->schemaMapper->method('find')->willReturn($schema);
		$this->scorer->method('score')->willReturn(0.5);
		$this->scorer->method('status')->willReturn('fair');

		$this->listener->handle(new ObjectCreatingEvent($object));
	}

	public function testWritesTheAssessmentIntoSelf(): void {
		$object = new ObjectEntity();
		$object->setSchema(1);
		$object->setObject(['title' => 'A case']);

		$this->fire($object, $this->schema(['title' => ['type' => 'string']]));

		$quality = $object->getQuality();
		$this->assertSame(0.5, $quality['score']);
		$this->assertSame('fair', $quality['status']);
		$this->assertArrayHasKey('scoredAt', $quality);
	}

	public function testLeavesTheObjectBodyAloneWhenTheSchemaDeclaresNoQualityProperty(): void {
		// The whole point of the change. A schema that has dropped its
		// `qualityScore` declaration is still scored, and its objects stay
		// clean.
		$object = new ObjectEntity();
		$object->setSchema(1);
		$object->setObject(['title' => 'A case']);

		$this->fire($object, $this->schema(['title' => ['type' => 'string']]));

		$this->assertSame('A case', $object->getObject()['title']);
		$this->assertArrayNotHasKey('qualityScore', $object->getObject());
		$this->assertArrayNotHasKey('qualityStatus', $object->getObject());
	}

	public function testStillWritesTheBodyPropertyWhileTheSchemaDeclaresIt(): void {
		// Dropping the body write outright would freeze the stored value of
		// every schema that has one, and a score that silently stops updating
		// reads exactly like a score that is simply good.
		$object = new ObjectEntity();
		$object->setSchema(1);
		$object->setObject(['title' => 'A case', 'qualityScore' => 0.1]);

		$this->fire($object, $this->schema(
			[
				'title' => ['type' => 'string'],
				'qualityScore' => ['type' => 'number'],
				'qualityStatus' => ['type' => 'string'],
			],
			['rules' => [['field' => 'title']], 'statusField' => 'qualityStatus'],
		));

		$this->assertSame(0.5, $object->getObject()['qualityScore']);
		$this->assertSame('fair', $object->getObject()['qualityStatus']);
		// And it is in @self as well, so a consumer can move over before the
		// schema drops the declaration.
		$this->assertSame(0.5, $object->getQuality()['score']);
	}

	public function testDoesNotInventAStatusPropertyTheSchemaNeverDeclared(): void {
		$object = new ObjectEntity();
		$object->setSchema(1);
		$object->setObject(['title' => 'A case']);

		$this->fire($object, $this->schema(
			['title' => ['type' => 'string']],
			['rules' => [['field' => 'title']], 'statusField' => 'qualityStatus'],
		));

		$this->assertArrayNotHasKey('qualityStatus', $object->getObject());
		// The status is still assessed; it just lives in the envelope.
		$this->assertSame('fair', $object->getQuality()['status']);
	}

	public function testIgnoresASchemaWithoutTheAnnotation(): void {
		$object = new ObjectEntity();
		$object->setSchema(1);
		$object->setObject(['title' => 'A case']);

		$schema = $this->createMock(Schema::class);
		$schema->method('getConfiguration')->willReturn([]);
		$schema->method('getProperties')->willReturn([]);
		$this->schemaMapper->method('find')->willReturn($schema);

		$this->listener->handle(new ObjectCreatingEvent($object));

		$this->assertEmpty($object->getQuality());
		$this->assertArrayNotHasKey('qualityScore', $object->getObject());
	}
}
