<?php

/**
 * The lifecycle edit reader finds the declaration behind an edit, or none.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Lifecycle\StateMove;
use OCA\OpenRegister\Service\Lifecycle\SchemaReading;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCA\OpenRegister\Listener\LifecycleEditReader;
use OCA\OpenRegister\Service\Lifecycle\LifecycleTransitionResolverInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Each early return of editOf / declarationOf and the schema-load failure path.
 */
class LifecycleEditReaderBehaviourTest extends TestCase {
	private function object(?string $schema): ObjectEntity {
		$object = new ObjectEntity();
		$object->setSchema($schema);
		return $object;
	}

	private function schema(?array $configuration): Schema {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
		return $schema;
	}

	private function reader(SchemaMapperInterface $mapper, ?LoggerInterface $logger=null): LifecycleEditReader {
		return new LifecycleEditReader(new SchemaReading($mapper, $logger ?? $this->createMock(LoggerInterface::class)), $this->createMock(LifecycleTransitionResolverInterface::class));
	}

	public function testAnEditWithoutAStoredObjectHasNoLifecycleEdit(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->never())->method('find');

		$this->assertNull($this->reader($mapper)->editOf(new ObjectUpdatingEvent($this->object('7'))));
	}

	public function testAnEditOnADeclaringSchemaCarriesStoredObjectAndAnnotation(): void {
		$annotation = ['field' => 'status', 'transitions' => []];
		$mapper     = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->once())->method('find')->with('7', [], true, false)
			->willReturn($this->schema(['x-openregister-lifecycle' => $annotation]));
		$stored = $this->object('7');

		$edit = $this->reader($mapper)->editOf(new ObjectUpdatingEvent($this->object('7'), $stored));

		$this->assertNotNull($edit);
		$this->assertSame($stored, $edit->stored);
		$this->assertSame($annotation, $edit->declaration->annotation);
	}

	public function testAnEditOnASchemaWithoutDeclarationHasNoLifecycleEdit(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturn($this->schema(['other' => true]));

		$this->assertNull($this->reader($mapper)->editOf(new ObjectUpdatingEvent($this->object('7'), $this->object('7'))));
	}

	public function testANonArrayAnnotationOrNullConfigurationDeclaresNothing(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturnOnConsecutiveCalls(
			$this->schema(['x-openregister-lifecycle' => 'status']),
			$this->schema(null)
		);
		$reader = $this->reader($mapper);

		$this->assertNull($reader->declarationOf($this->object('7')));
		$this->assertNull($reader->declarationOf($this->object('7')));
	}

	public function testAnObjectWithoutSchemaIsNotLookedUp(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->never())->method('find');
		$reader = $this->reader($mapper);

		$this->assertNull($reader->declarationOf($this->object(null)));
		$this->assertNull($reader->declarationOf($this->object('')));
	}

	public function testAFailedSchemaLoadIsLoggedUnderTheCallerLabel(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willThrowException(new RuntimeException('gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with('[Gate] could not load schema "7": gone');

		$this->assertNull($this->reader($mapper, $logger)->declarationOf($this->object('7'), '[Gate]'));
	}

	public function testAFailedSchemaLoadWithoutLabelIsSilent(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willThrowException(new RuntimeException('gone'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		$this->assertNull($this->reader($mapper, $logger)->declarationOf($this->object('7')));
	}

	/**
	 * A reader with the given resolver and a lifecycle edit from "draft" to "final" on object u-1.
	 *
	 * @param LifecycleTransitionResolverInterface $resolver The resolver double.
	 *
	 * @return array{0: LifecycleEditReader, 1: \OCA\OpenRegister\Listener\LifecycleEdit} Reader and edit.
	 */
	private function editWith(LifecycleTransitionResolverInterface $resolver): array {
		$transitions = ['publish' => ['from' => 'draft', 'to' => 'final']];
		$mapper      = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturn($this->schema(['x-openregister-lifecycle' => ['field' => 'status', 'transitions' => $transitions]]));
		$reader = new LifecycleEditReader(new SchemaReading($mapper, $this->createMock(LoggerInterface::class)), $resolver);
		$stored = $this->object('7');
		$stored->setUuid('u-1');
		$stored->setObject(['status' => 'draft']);
		$next = $this->object('7');
		$next->setUuid('u-1');
		$next->setObject(['status' => 'final']);
		return [$reader, $reader->editOf(new ObjectUpdatingEvent($next, $stored))];
	}

	public function testAMatchedTransitionBecomesAnAttemptWithItsActionAndSpec(): void {
		$resolver = $this->createMock(LifecycleTransitionResolverInterface::class);
		$resolver->expects($this->once())->method('resolve')
			->with(['publish' => ['from' => 'draft', 'to' => 'final']], new StateMove('u-1', 'draft', 'final'))
			->willReturn(['publish', ['from' => 'draft', 'to' => 'final']]);
		[$reader, $edit] = $this->editWith($resolver);

		$attempt = $reader->attemptOf($edit);

		$this->assertNotNull($attempt);
		$this->assertSame('publish', $attempt->action);
		$this->assertSame(['from' => 'draft', 'to' => 'final'], $attempt->spec);
		$this->assertSame($edit, $attempt->edit);
	}

	public function testAnUnmatchedTransitionIsNoAttempt(): void {
		$resolver = $this->createMock(LifecycleTransitionResolverInterface::class);
		$resolver->method('resolve')->willReturn(null);
		[$reader, $edit] = $this->editWith($resolver);

		$this->assertNull($reader->attemptOf($edit));
	}

	public function testWritesDeclaredStateAsksTheResolverForTheEditedUuid(): void {
		$resolver = $this->createMock(LifecycleTransitionResolverInterface::class);
		$resolver->expects($this->exactly(2))->method('writesDeclaredState')->with('u-1')->willReturnOnConsecutiveCalls(true, false);
		[$reader, $edit] = $this->editWith($resolver);

		$this->assertTrue($reader->writesDeclaredState($edit));
		$this->assertFalse($reader->writesDeclaredState($edit));
	}
}
