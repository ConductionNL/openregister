<?php

/**
 * ArchivalDeclarationChecksListenerTest
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
use OCA\OpenRegister\Listener\ArchivalDeclarationChecksListener;
use OCA\OpenRegister\Service\Retention\ExpiryDeclaration;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Compliance contributes the MDTO mapping and archival checks in their own slots.
 *
 * @covers \OCA\OpenRegister\Listener\ArchivalDeclarationChecksListener
 */
class ArchivalDeclarationChecksListenerTest extends TestCase {

	public function testItContributesTheMdtoMappingArchivalAndRetentionSlots(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new ArchivalDeclarationChecksListener(logger: new NullLogger()))->handle(event: $event);

		$this->assertSame(
			[SchemaDeclarationOrder::MDTO_MAPPING, SchemaDeclarationOrder::ARCHIVAL, SchemaDeclarationOrder::RETENTION],
			array_map(static fn ($check): int => $check->position(), $event->checks())
		);
	}//end testItContributesTheMdtoMappingArchivalAndRetentionSlots()

	public function testTheArchivalSlotRefusesAMalformedBlock(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new ArchivalDeclarationChecksListener(logger: new NullLogger()))->handle(event: $event);
		$schema = new Schema();
		$schema->setSlug('dossiers');
		$schema->setConfiguration(['x-openregister-archival' => ['foo' => 1]]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('x-openregister-archival: ');
		$event->checks()[1]->check(schema: $schema, discarded: new DiscardedAnnotations());
	}//end testTheArchivalSlotRefusesAMalformedBlock()

	public function testTheArchivalSlotRefusesAnUnknownExpiryAction(): void {
		$event = new SchemaDeclarationChecksEvent();
		(new ArchivalDeclarationChecksListener(logger: new NullLogger()))->handle(event: $event);
		$schema = new Schema();
		$schema->setSlug('dossiers');
		$schema->setProperties(['reference' => ['type' => 'string']]);
		$schema->setConfiguration([ExpiryDeclaration::ANNOTATION => [ExpiryDeclaration::KEY => ['action' => 'remove']]]);

		$this->expectException(Exception::class);
		$this->expectExceptionMessage('expiry.action "remove" is not allowed');
		$event->checks()[1]->check(schema: $schema, discarded: new DiscardedAnnotations());
	}//end testTheArchivalSlotRefusesAnUnknownExpiryAction()

	public function testAnyOtherEventIsIgnored(): void {
		(new ArchivalDeclarationChecksListener(logger: new NullLogger()))->handle(event: new Event());

		$this->addToAssertionCount(1);
	}//end testAnyOtherEventIsIgnored()
}//end class
