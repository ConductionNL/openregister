<?php

/**
 * Tests for SearchDeclarationChecksListener.
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

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Search\Listener\SearchDeclarationChecksListener;
use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator;
use OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidatorInterface;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Search contributes the widgets check in the WIDGETS slot, and the check
 * refuses a malformed block exactly as the mapper's own check did.
 *
 * @covers \OCA\OpenRegister\Search\Listener\SearchDeclarationChecksListener
 * @uses   \OCA\OpenRegister\Service\Aggregation\WidgetAnnotationValidator
 */
final class SearchDeclarationChecksListenerTest extends TestCase {

	/**
	 * The listener adds exactly one check, in the WIDGETS slot.
	 *
	 * @return void
	 */
	public function testItContributesOneCheckInTheWidgetsSlot(): void {
		$event = new SchemaDeclarationChecksEvent();
		$this->listener()->handle(event: $event);

		$this->assertCount(1, $event->checks());
		$this->assertSame(SchemaDeclarationOrder::WIDGETS, $event->checks()[0]->position());
	}//end testItContributesOneCheckInTheWidgetsSlot()

	/**
	 * A malformed block refuses the save with the validator's messages.
	 *
	 * @return void
	 */
	public function testAMalformedBlockRefusesTheSave(): void {
		$schema = $this->schemaWith(widgets: [
			['type' => 'pie-galaxy', 'title' => 't', 'dataSource' => ['mode' => 'aggregation', 'register' => 'r', 'schema' => 's', 'aggregation' => 'a']],
		]);
		$expected = (new WidgetAnnotationValidator())->validate(['x-openregister-widgets' => $schema->getConfiguration()['x-openregister-widgets']]);
		$this->assertNotSame([], $expected);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage($expected[0]['message']);
		$this->runCheck(schema: $schema);
	}//end testAMalformedBlockRefusesTheSave()

	/**
	 * An empty block is a block too, and the validator refuses it.
	 *
	 * @return void
	 */
	public function testAnEmptyBlockRefusesTheSave(): void {
		$this->expectException(Exception::class);
		$this->runCheck(schema: $this->schemaWith(widgets: []));
	}//end testAnEmptyBlockRefusesTheSave()

	/**
	 * A valid block passes.
	 *
	 * @return void
	 */
	public function testAValidBlockPasses(): void {
		$this->runCheck(schema: $this->schemaWith(widgets: [
			['type' => 'kpi', 'title' => 'Open', 'dataSource' => ['mode' => 'aggregation', 'register' => 'decidesk', 'schema' => 'action-item', 'aggregation' => 'totalOpen']],
		]));
		$this->addToAssertionCount(1);
	}//end testAValidBlockPasses()

	/**
	 * A schema without the annotation, or with a non-array value, is never validated.
	 *
	 * @return void
	 */
	public function testANonBlockIsNotValidated(): void {
		$validator = $this->createMock(WidgetAnnotationValidatorInterface::class);
		$validator->expects($this->never())->method('validate');
		$listener = new SearchDeclarationChecksListener(widgets: $validator);

		$plain = new Schema();
		$listener->checkWidgets(schema: $plain);
		$scalar = new Schema();
		$scalar->setConfiguration(['x-openregister-widgets' => 'x']);
		$listener->checkWidgets(schema: $scalar);
	}//end testANonBlockIsNotValidated()

	/**
	 * Another event is ignored.
	 *
	 * @return void
	 */
	public function testAnotherEventIsIgnored(): void {
		$this->listener()->handle(event: new Event());
		$this->addToAssertionCount(1);
	}//end testAnotherEventIsIgnored()

	/**
	 * The listener with the real validator.
	 *
	 * @return SearchDeclarationChecksListener
	 */
	private function listener(): SearchDeclarationChecksListener {
		return new SearchDeclarationChecksListener(widgets: new WidgetAnnotationValidator());
	}//end listener()

	/**
	 * A schema declaring the given widgets.
	 *
	 * @param array<int, mixed> $widgets The widget block.
	 *
	 * @return Schema
	 */
	private function schemaWith(array $widgets): Schema {
		$schema = new Schema();
		$schema->setSlug('dashboard');
		$schema->setConfiguration(['x-openregister-widgets' => $widgets]);
		return $schema;
	}//end schemaWith()

	/**
	 * Run the contributed check on the schema.
	 *
	 * @param Schema $schema The schema being saved.
	 *
	 * @return void
	 */
	private function runCheck(Schema $schema): void {
		$event = new SchemaDeclarationChecksEvent();
		$this->listener()->handle(event: $event);
		$event->checks()[0]->check(schema: $schema, discarded: new DiscardedAnnotations());
	}//end runCheck()
}//end class
