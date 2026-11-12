<?php

/**
 * Behaviour tests for the collecting events: identity claims, form dependents and handoff ids.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Event;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCA\OpenRegister\Event\HandoffExecutedEvent;
use OCA\OpenRegister\Event\HandoffExecutedSource;
use OCA\OpenRegister\Event\HandoffExecutedTarget;
use OCA\OpenRegister\Event\IdentityClaimsCollectingEvent;
use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCA\OpenRegister\Service\Form\PublishedForm;
use OCA\OpenRegister\Service\Handoff\HandoffRequest;
use OCA\OpenRegister\Service\Handoff\HandoffSourceRef;
use PHPUnit\Framework\TestCase;

/**
 * Pins what the collecting events keep, skip and convert.
 *
 * @covers \OCA\OpenRegister\Event\IdentityClaimsCollectingEvent
 * @covers \OCA\OpenRegister\Event\FormDestinationDependentsEvent
 * @covers \OCA\OpenRegister\Event\HandoffExecutedEvent
 * @uses   \OCA\OpenRegister\Event\AbstractSchemaEvent
 * @uses   \OCA\OpenRegister\Event\HandoffExecutedSource
 * @uses   \OCA\OpenRegister\Event\HandoffExecutedTarget
 * @uses   \OCA\OpenRegister\Kernel\Contracts\ObjectRef
 * @uses   \OCA\OpenRegister\Service\Form\FormDependent
 * @uses   \OCA\OpenRegister\Service\Form\PublishedForm
 * @uses   \OCA\OpenRegister\Service\Handoff\HandoffRequest
 * @uses   \OCA\OpenRegister\Service\Handoff\HandoffSourceRef
 */
class EventCollectorBehaviourTest extends TestCase {

	/**
	 * Claims with a non-string or empty name are dropped; the first app to assert a name wins.
	 *
	 * @return void
	 */
	public function testIdentityClaimsSkipBadNamesAndKeepTheFirstAnswer(): void {
		$event = new IdentityClaimsCollectingEvent('alice');

		$event->assert([0 => 'indexed', '' => 'empty', 'bsn' => '123456782', 'email' => null]);
		$event->assert(['bsn' => '999999990', 'email' => 'late@example.org', 'kvk' => '12345678']);

		$this->assertSame('alice', $event->getUserId());
		$this->assertSame(
			['bsn' => '123456782', 'email' => null, 'kvk' => '12345678'],
			$event->getClaims()
		);
	}//end testIdentityClaimsSkipBadNamesAndKeepTheFirstAnswer()

	/**
	 * A fresh claims event carries no claims, and an empty assertion adds none.
	 *
	 * @return void
	 */
	public function testIdentityClaimsStartEmpty(): void {
		$event = new IdentityClaimsCollectingEvent('bob');
		$event->assert([]);

		$this->assertSame([], $event->getClaims());
	}//end testIdentityClaimsStartEmpty()

	/**
	 * Forms are collected in the order apps add them, without de-duplication.
	 *
	 * @return void
	 */
	public function testFormDependentsAreCollectedInOrder(): void {
		$schema = $this->createMock(Schema::class);
		$event  = new FormDestinationDependentsEvent($schema);

		$this->assertSame([], $event->getForms());

		$first  = new FormDependent(new PublishedForm('f-1', 'appA', 'Intake'), 'alice', ['name' => 'naam']);
		$second = new FormDependent(new PublishedForm('f-2', 'appB', 'Survey', 'public'), null, []);
		$event->addForm($first);
		$event->addForm($second);
		$event->addForm($first);

		$this->assertSame([$first, $second, $first], $event->getForms());
		$this->assertSame($schema, $event->getSchema());
	}//end testFormDependentsAreCollectedInOrder()

	/**
	 * String register and schema references are reported as integers on both sides.
	 *
	 * @return void
	 */
	public function testHandoffReportsIntegerIdsAndRequestFields(): void {
		$event = new HandoffExecutedEvent(
			new HandoffExecutedSource('appA', new ObjectRef('12', '34', 'src-uuid'), 'Case 1'),
			new HandoffExecutedTarget('schema:Task', new ObjectRef(56, '78', 'tgt-uuid')),
			new HandoffRequest(new HandoffSourceRef('12', '34', 'src-uuid'), 'h-1', true, 'corr-9')
		);

		$this->assertSame('appA', $event->getSourceApp());
		$this->assertSame(12, $event->getSourceRegister());
		$this->assertSame(34, $event->getSourceSchema());
		$this->assertSame('src-uuid', $event->getSourceObjectUuid());
		$this->assertSame('Case 1', $event->getSubjectLabel());
		$this->assertSame('schema:Task', $event->getTargetSemanticType());
		$this->assertSame(56, $event->getTargetRegister());
		$this->assertSame(78, $event->getTargetSchema());
		$this->assertSame('tgt-uuid', $event->getTargetObjectUuid());
		$this->assertSame('h-1', $event->getHandoffId());
		$this->assertSame('corr-9', $event->getCorrelationId());
		$this->assertTrue($event->isDeferred());
	}//end testHandoffReportsIntegerIdsAndRequestFields()

	/**
	 * A slug reference that is not numeric casts to zero and a missing label stays null.
	 *
	 * @return void
	 */
	public function testHandoffNonNumericReferenceCastsToZero(): void {
		$event = new HandoffExecutedEvent(
			new HandoffExecutedSource('appB', new ObjectRef('cases', 'case', 's'), null),
			new HandoffExecutedTarget('schema:Note', new ObjectRef('notes', 'note', 't')),
			new HandoffRequest(new HandoffSourceRef('cases', 'case', 's'), 'h-2', false, 'corr-1')
		);

		$this->assertSame(0, $event->getSourceRegister());
		$this->assertSame(0, $event->getTargetSchema());
		$this->assertNull($event->getSubjectLabel());
		$this->assertFalse($event->isDeferred());
	}//end testHandoffNonNumericReferenceCastsToZero()
}//end class
