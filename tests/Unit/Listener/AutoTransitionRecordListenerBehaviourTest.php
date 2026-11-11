<?php

/**
 * Writes on schemas that declare an automatic transition are recorded for the pass.
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

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Listener\AutoTransitionRecordListener;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionPassInterface;
use OCA\OpenRegister\Service\Lifecycle\AutoTransitionSelectorInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Created and updated writes are recorded with their previous data; everything else is not.
 */
class AutoTransitionRecordListenerBehaviourTest extends TestCase {
	private function object(?string $schema, array $data=[]): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid('u-1');
		$object->setRegister('3');
		$object->setSchema($schema);
		$object->setObject($data);
		return $object;
	}

	private function mapperReturning(?array $configuration): SchemaMapperInterface {
		$schema = new Schema();
		$schema->setConfiguration($configuration);
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->method('find')->willReturn($schema);
		return $mapper;
	}

	private function selector(bool $declares): AutoTransitionSelectorInterface {
		$selector = $this->createMock(AutoTransitionSelectorInterface::class);
		$selector->method('declaresAutoWhen')->willReturn($declares);
		return $selector;
	}

	private function lifecycle(): array {
		return ['x-openregister-lifecycle' => ['autoWhen' => ['status' => 'x']]];
	}

	public function testAnUpdateIsRecordedWithThePreviousData(): void {
		$pass = $this->createMock(AutoTransitionPassInterface::class);
		$pass->expects($this->once())->method('record')->with(new ObjectRef(register: '3', schema: '9', id: 'u-1'), $this->callback(fn (array $previous): bool => ($previous['status'] ?? null) === 'old'));

		(new AutoTransitionRecordListener($this->mapperReturning($this->lifecycle()), $this->selector(true), $pass))
			->handle(new ObjectUpdatedEvent($this->object('9', ['status' => 'new']), $this->object('9', ['status' => 'old'])));
	}

	public function testACreateIsRecordedWithNoPreviousData(): void {
		$pass = $this->createMock(AutoTransitionPassInterface::class);
		$pass->expects($this->once())->method('record')->with(new ObjectRef(register: '3', schema: '9', id: 'u-1'), []);

		(new AutoTransitionRecordListener($this->mapperReturning($this->lifecycle()), $this->selector(true), $pass))
			->handle(new ObjectCreatedEvent($this->object('9', ['status' => 'new'])));
	}

	public function testASchemaThatDeclaresNoAutoTransitionIsNotRecorded(): void {
		$pass = $this->createMock(AutoTransitionPassInterface::class);
		$pass->expects($this->never())->method('record');

		(new AutoTransitionRecordListener($this->mapperReturning($this->lifecycle()), $this->selector(false), $pass))
			->handle(new ObjectCreatedEvent($this->object('9')));
	}

	public function testASchemaWithoutLifecycleAnnotationIsNotAskedAboutAutoWhen(): void {
		$selector = $this->createMock(AutoTransitionSelectorInterface::class);
		$selector->expects($this->never())->method('declaresAutoWhen');
		$pass = $this->createMock(AutoTransitionPassInterface::class);
		$pass->expects($this->never())->method('record');

		(new AutoTransitionRecordListener($this->mapperReturning(null), $selector, $pass))
			->handle(new ObjectCreatedEvent($this->object('9')));
	}

	public function testAnObjectWithoutSchemaOrAFailedLookupIsNotRecorded(): void {
		$mapper = $this->createMock(SchemaMapperInterface::class);
		$mapper->expects($this->once())->method('find')->willThrowException(new RuntimeException('gone'));
		$pass = $this->createMock(AutoTransitionPassInterface::class);
		$pass->expects($this->never())->method('record');
		$listener = new AutoTransitionRecordListener($mapper, $this->selector(true), $pass);

		$listener->handle(new ObjectCreatedEvent($this->object(null)));
		$listener->handle(new ObjectCreatedEvent($this->object('9')));
	}

	public function testADeleteIsNotRecorded(): void {
		$pass = $this->createMock(AutoTransitionPassInterface::class);
		$pass->expects($this->never())->method('record');

		(new AutoTransitionRecordListener($this->mapperReturning($this->lifecycle()), $this->selector(true), $pass))
			->handle(new ObjectDeletedEvent($this->object('9')));
	}
}
