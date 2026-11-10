<?php

/**
 * Tests for AggregateVisibility's some-row and explicit-reveal decisions.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandlerInterface;
use OCA\OpenRegister\Service\Rbac\AggregateVisibility;
use OCA\OpenRegister\Service\Rbac\ExplicitReveal;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * mayReadOnSomeRow() and the explicit-reveal masking of value summaries.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 */
class AggregateVisibilityRevealTest extends TestCase {

	/**
	 * A schema with one governed property `bsn`, one explicit-reveal `iban` and an ungoverned `name`.
	 *
	 * @return Schema
	 */
	private function schema(): Schema {
		$schema = new Schema();
		$schema->setProperties(
			[
				'bsn'  => ['type' => 'string', 'authorization' => ['read' => [['group' => 'hr']]]],
				'iban' => ['type' => 'string', 'authorization' => ['reveal' => 'explicit']],
				'name' => ['type' => 'string'],
			]
		);
		return $schema;
	}//end schema()

	/**
	 * An ungoverned property is readable on some row without asking the rule.
	 *
	 * @return void
	 */
	public function testUngovernedPropertyIsReadableOnSomeRow(): void {
		$rbac = $this->createMock(PropertyRbacHandlerInterface::class);
		$rbac->expects($this->never())->method('couldReadProperty');

		$this->assertTrue((new AggregateVisibility(rbac: $rbac))->mayReadOnSomeRow(schema: $this->schema(), property: 'name'));
	}//end testUngovernedPropertyIsReadableOnSomeRow()

	/**
	 * A governed property follows the rule's some-row answer.
	 *
	 * @return void
	 */
	public function testGovernedPropertyFollowsTheRule(): void {
		$schema = $this->schema();
		$rbac   = $this->createMock(PropertyRbacHandlerInterface::class);
		$rbac->method('couldReadProperty')->willReturnMap([[$schema, 'bsn', false]]);
		$this->assertFalse((new AggregateVisibility(rbac: $rbac))->mayReadOnSomeRow(schema: $schema, property: 'bsn'));

		$yes = $this->createMock(PropertyRbacHandlerInterface::class);
		$yes->method('couldReadProperty')->willReturn(true);
		$this->assertTrue((new AggregateVisibility(rbac: $yes))->mayReadOnSomeRow(schema: $schema, property: 'bsn'));
	}//end testGovernedPropertyFollowsTheRule()

	/**
	 * A missing or throwing rule fails closed, and the throw is logged.
	 *
	 * @return void
	 */
	public function testMissingOrThrowingRuleFailsClosed(): void {
		$this->assertFalse((new AggregateVisibility())->mayReadOnSomeRow(schema: $this->schema(), property: 'bsn'));

		$rbac = $this->createMock(PropertyRbacHandlerInterface::class);
		$rbac->method('couldReadProperty')->willThrowException(new RuntimeException('resolver down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[AggregateVisibility] Withholding a summary: resolver down', $this->callback(static fn (array $c): bool => $c['property'] === 'bsn'));

		$this->assertFalse((new AggregateVisibility(rbac: $rbac, logger: $logger))->mayReadOnSomeRow(schema: $this->schema(), property: 'bsn'));
	}//end testMissingOrThrowingRuleFailsClosed()

	/**
	 * An explicit-reveal property is masked unless the read is trusted; other properties
	 * and a missing schema never are.
	 *
	 * @return void
	 */
	public function testExplicitRevealIsMaskedUnlessTrusted(): void {
		$reveal     = new ExplicitReveal();
		$visibility = new AggregateVisibility(reveal: $reveal);
		$schema     = $this->schema();

		$this->assertTrue($visibility->isMaskedExplicit(schema: $schema, property: 'iban'));
		$this->assertFalse($visibility->isMaskedExplicit(schema: $schema, property: 'name'));
		$this->assertFalse($visibility->isMaskedExplicit(schema: $schema, property: 'missing'));
		$this->assertFalse($visibility->isMaskedExplicit(schema: null, property: 'iban'));
		$this->assertFalse($reveal->trustedDuring(static fn (): bool => $visibility->isMaskedExplicit(schema: $schema, property: 'iban')));
	}//end testExplicitRevealIsMaskedUnlessTrusted()

	/**
	 * Without a reveal service the system-operation context decides; outside it the value is masked.
	 *
	 * @return void
	 */
	public function testWithoutRevealServiceOrdinaryReadsAreMasked(): void {
		$this->assertTrue((new AggregateVisibility())->isMaskedExplicit(schema: $this->schema(), property: 'iban'));
	}//end testWithoutRevealServiceOrdinaryReadsAreMasked()

	/**
	 * Value summaries of an explicit-reveal property are withheld with a reason; others
	 * fall through to the read rule.
	 *
	 * @return void
	 */
	public function testValueSummariesOfExplicitRevealAreWithheld(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')
			->with('[AggregateVisibility] Withholding a summary: the property is shown only on an explicit reveal');
		$visibility = new AggregateVisibility(logger: $logger, reveal: new ExplicitReveal());

		$this->assertSame(
			['allowed' => ['name'], 'withheld' => ['iban']],
			$visibility->partition(schema: $this->schema(), fields: ['name', 'iban'])
		);
	}//end testValueSummariesOfExplicitRevealAreWithheld()
}//end class
