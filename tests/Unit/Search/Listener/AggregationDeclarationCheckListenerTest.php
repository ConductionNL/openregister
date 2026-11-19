<?php

/**
 * Tests for AggregationDeclarationCheckListener.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Search\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Search\Listener;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Search\Listener\AggregationDeclarationCheckListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Search contributes the aggregations check in its own slot, and the check
 * only warns and records.
 */
final class AggregationDeclarationCheckListenerTest extends TestCase {

	/**
	 * The listener adds exactly one check, in the AGGREGATIONS slot.
	 *
	 * @return void
	 */
	public function testItContributesOneCheckInTheAggregationsSlot(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new AggregationDeclarationCheckListener(logger: $this->createMock(LoggerInterface::class)))->handle(event: $event);

		$this->assertCount(1, $event->checks());
		$this->assertSame(SchemaDeclarationOrder::AGGREGATIONS, $event->checks()[0]->position());
	}//end testItContributesOneCheckInTheAggregationsSlot()

	/**
	 * An invalid block is logged and recorded, and the save goes on.
	 *
	 * @return void
	 */
	public function testAnInvalidBlockIsWarnedAboutAndRecorded(): void {
		$logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$logged): void {
				$logged[] = $message;
			}
		);
		$schema = new Schema();
		$schema->setSlug('zaak');
		$schema->setProperties(['title' => ['type' => 'string']]);
		$schema->setConfiguration(['x-openregister-aggregations' => ['bogus' => ['nested' => true]]]);
		$event = new SchemaDeclarationChecksEvent();
		(new AggregationDeclarationCheckListener(logger: $logger))->handle(event: $event);
		$discarded = new DiscardedAnnotations();

		$event->checks()[0]->check(schema: $schema, discarded: $discarded);

		$this->assertCount(1, $logged);
		$this->assertStringStartsWith(
			'x-openregister-aggregations annotation on schema "zaak" is invalid and was ignored (aggregation not registered): ',
			$logged[0]
		);
		$this->assertSame('aggregations', $discarded->all()[0]['family']);
	}//end testAnInvalidBlockIsWarnedAboutAndRecorded()

	/**
	 * Another event is left alone.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');

		(new AggregationDeclarationCheckListener(logger: $logger))->handle(event: new Event());
		$this->addToAssertionCount(1);
	}//end testAnotherEventIsIgnored()
}//end class
