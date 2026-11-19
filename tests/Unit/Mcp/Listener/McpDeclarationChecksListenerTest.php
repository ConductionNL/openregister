<?php

/**
 * McpDeclarationChecksListenerTest
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Mcp\Listener
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Mcp\Listener;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.
// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use Exception;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent;
use OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationOrder;
use OCA\OpenRegister\Mcp\Listener\McpDeclarationChecksListener;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;

/**
 * Integrations contributes the MCP declaration check in the MCP slot, and only there.
 *
 * @covers \OCA\OpenRegister\Mcp\Listener\McpDeclarationChecksListener
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\AnnotationFaults
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\DiscardedAnnotations
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\McpDeclarationCheck
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\PositionedDeclarationCheck
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaAnnotation
 * @uses   \OCA\OpenRegister\Db\SchemaMapper\SchemaDeclarationChecksEvent
 * @uses   \OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidators
 * @uses   \OCA\OpenRegister\Mcp\Service\Mcp\McpAnnotationValidator
 */
class McpDeclarationChecksListenerTest extends TestCase {

	public function testItContributesOneCheckInTheMcpSlot(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new McpDeclarationChecksListener())->handle($event);

		$this->assertCount(1, $event->checks());
		$this->assertSame(SchemaDeclarationOrder::MCP, $event->checks()[0]->position());
	}//end testItContributesOneCheckInTheMcpSlot()

	public function testTheContributedCheckRefusesAMalformedBlock(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new McpDeclarationChecksListener())->handle($event);
		$schema = new Schema();
		$schema->setSlug('meldingen');
		$schema->setConfiguration(['x-openregister-mcp' => ['enabled' => 'yes']]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('mcp-bad-enabled');
		$event->checks()[0]->check($schema, new DiscardedAnnotations());
	}//end testTheContributedCheckRefusesAMalformedBlock()

	public function testAnotherEventIsIgnored(): void {
		$event = new Event();
		(new McpDeclarationChecksListener())->handle($event);

		$this->assertInstanceOf(Event::class, $event);
	}//end testAnotherEventIsIgnored()
}//end class
