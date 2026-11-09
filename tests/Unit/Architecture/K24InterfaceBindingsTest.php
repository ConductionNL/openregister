<?php

/**
 * K24: the constant interfaces that keep three concrete classes hidden.
 *
 * SyncRecordStatusInterface, RegisterSlugAliasesInterface and
 * RegisterObjectEntityInterface let code outside the owning component read
 * the status vocabulary, the declared slug map and the Flow entity id
 * without naming the concrete class. They hold constants only, so no
 * container binding exists; this test pins that each concrete class
 * implements its interface and that the constants still say what the
 * concrete class answers.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Architecture
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Architecture;

use OCA\OpenRegister\Listener\FlowEngineRegistrationListener;
use OCA\OpenRegister\Service\Sync\SyncRecordStatus;
use OCA\OpenRegister\Service\Sync\SyncRecordStatusInterface;
use OCA\OpenRegister\Support\RegisterSlugAliases;
use OCA\OpenRegister\Support\RegisterSlugAliasesInterface;
use OCA\OpenRegister\WorkflowEngine\RegisterObjectEntity;
use OCA\OpenRegister\WorkflowEngine\RegisterObjectEntityInterface;
use OCP\WorkflowEngine\Events\RegisterEntitiesEvent;
use OCP\WorkflowEngine\IEntity;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @coversNothing
 */
final class K24InterfaceBindingsTest extends TestCase {

	/**
	 * Each concrete class implements the interface its outside callers name.
	 *
	 * @return void
	 */
	public function testConcreteClassesImplementTheirInterfaces(): void {
		self::assertContains(SyncRecordStatusInterface::class, class_implements(SyncRecordStatus::class));
		self::assertContains(RegisterSlugAliasesInterface::class, class_implements(RegisterSlugAliases::class));
		self::assertContains(RegisterObjectEntityInterface::class, class_implements(RegisterObjectEntity::class));
		self::assertContains(IEntity::class, class_implements(RegisterObjectEntity::class));
	}//end testConcreteClassesImplementTheirInterfaces()

	/**
	 * The transition table answers exactly what canTransition() answers.
	 *
	 * @return void
	 */
	public function testTransitionTableMatchesTheStateMachine(): void {
		$statuses = array_merge(SyncRecordStatus::all(), ['', 'unknown']);
		foreach ($statuses as $from) {
			foreach ($statuses as $to) {
				$fromTable = in_array($to, (SyncRecordStatusInterface::TRANSITIONS[$from] ?? []), true);
				self::assertSame(SyncRecordStatus::canTransition($from, $to), $fromTable, $from.' -> '.$to);
			}
		}
	}//end testTransitionTableMatchesTheStateMachine()

	/**
	 * The alias map answers exactly what candidatesFor() answers for a normalised key.
	 *
	 * @return void
	 */
	public function testAliasMapMatchesCandidatesFor(): void {
		$keys = array_merge(array_keys(RegisterSlugAliasesInterface::ALIASES), ['procest', 'unrelated']);
		foreach ($keys as $key) {
			self::assertSame(RegisterSlugAliases::candidatesFor($key), (RegisterSlugAliasesInterface::ALIASES[$key] ?? [$key]), $key);
		}
	}//end testAliasMapMatchesCandidatesFor()

	/**
	 * The entity id is the concrete entity, and the listener registers it by that id.
	 *
	 * @return void
	 */
	public function testListenerRegistersTheEntityByItsId(): void {
		self::assertSame(RegisterObjectEntity::class, RegisterObjectEntityInterface::ENTITY_ID);

		$entity    = $this->createMock(RegisterObjectEntityInterface::class);
		$container = $this->createMock(ContainerInterface::class);
		$container->expects(self::once())->method('get')->with(RegisterObjectEntity::class)->willReturn($entity);
		$event = $this->createMock(RegisterEntitiesEvent::class);
		$event->expects(self::once())->method('registerEntity')->with($entity);

		(new FlowEngineRegistrationListener($container))->handle($event);
	}//end testListenerRegistersTheEntityByItsId()
}//end class
