<?php

/**
 * HandoffDeclarationChecksListenerTest
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
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Listener;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Listener\HandoffDeclarationChecksListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Integrations contributes the hand-off checks in their own slots.
 *
 * @covers \OCA\OpenRegister\Listener\HandoffDeclarationChecksListener
 */
class HandoffDeclarationChecksListenerTest extends TestCase {

	public function testItContributesTheHandoffSlots(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new HandoffDeclarationChecksListener())->handle(event: $event);

		$this->assertSame(
			[SchemaDeclarationOrder::HANDOFF, SchemaDeclarationOrder::HANDOFF_CONTRACT],
			array_map(static fn ($check): int => $check->position(), $event->checks())
		);
	}//end testItContributesTheHandoffSlots()

	public function testTheContractSlotRefusesAnIncompleteBinding(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new HandoffDeclarationChecksListener())->handle(event: $event);
		$schema = new Schema();
		$schema->setSlug('meldingen');
		$schema->setConfiguration(['implements' => ['https://openregister.app/ns#Case'], 'handoffContract' => ['https://openregister.app/ns#Case' => []]]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('handoffContract: handoff-contract-incomplete: ');
		$event->checks()[1]->check(schema: $schema, discarded: new DiscardedAnnotations());
	}//end testTheContractSlotRefusesAnIncompleteBinding()

	public function testAnyOtherEventIsIgnored(): void {
		(new HandoffDeclarationChecksListener())->handle(event: new Event());

		$this->addToAssertionCount(1);
	}//end testAnyOtherEventIsIgnored()
}//end class
