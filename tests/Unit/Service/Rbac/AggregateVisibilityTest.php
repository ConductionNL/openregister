<?php

/**
 * An aggregate is a read of the column for everybody it is shown to.
 *
 * 🔴 THE LEAK THIS CLASS ANSWERS WAS INVISIBLE FROM EVERY SCREEN. A facet
 * returns distinct values with counts; a SUM over a salary nobody may read IS
 * the salary total; a kanban column heading is a value. The render path strips
 * a governed property from every object body correctly, so the field was
 * invisible where people looked for it and legible where nobody did.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCA\OpenRegister\Service\Rbac\AggregateVisibility;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * `AggregateVisibility`.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\AggregateVisibility
 */
class AggregateVisibilityTest extends TestCase {

	/**
	 * Visibility whose read rule answers as given.
	 *
	 * @param bool|null $mayRead What the read rule answers, or null for no rule available.
	 *
	 * @return AggregateVisibility The service.
	 */
	private function visibilityWhereReadIs(?bool $mayRead): AggregateVisibility {
		$rbac = null;

		if ($mayRead !== null) {
			$rbac = $this->createMock(PropertyRbacHandler::class);
			$rbac->method('canReadProperty')->willReturn($mayRead);
		}

		return new AggregateVisibility($rbac, new NullLogger());
	}//end visibilityWhereReadIs()

	/**
	 * A schema with one governed property.
	 *
	 * @return Schema The schema.
	 */
	private function governedSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties([
			'salary' => ['type' => 'number', 'scope' => 'team-a'],
			'name' => ['type' => 'string'],
		]);

		return $schema;
	}//end governedSchema()

	/**
	 * 🔴 A PROPERTY THE CALLER MAY NOT READ IS NOT SUMMARISED.
	 *
	 * @return void
	 */
	public function testAPropertyTheCallerMayNotReadIsNotSummarised(): void {
		$this->assertFalse(
			$this->visibilityWhereReadIs(false)->maySummarise($this->governedSchema(), 'salary')
		);
	}//end testAPropertyTheCallerMayNotReadIsNotSummarised()

	/**
	 * A property the caller may read is summarised.
	 *
	 * The control. Without it, a method that always refused would pass the test
	 * above while removing every aggregate in the product.
	 *
	 * @return void
	 */
	public function testAPropertyTheCallerMayReadIsSummarised(): void {
		$this->assertTrue(
			$this->visibilityWhereReadIs(true)->maySummarise($this->governedSchema(), 'salary')
		);
	}//end testAPropertyTheCallerMayReadIsSummarised()

	/**
	 * An ungoverned schema asks nothing and is unaffected.
	 *
	 * Note there is NO read rule wired here: if an ungoverned schema reached the
	 * lookup it would fail closed and every ordinary aggregate would vanish.
	 *
	 * @return void
	 */
	public function testAnUngovernedSchemaIsUnaffected(): void {
		$schema = new Schema();
		$schema->setProperties(['name' => ['type' => 'string']]);

		$this->assertTrue($this->visibilityWhereReadIs(null)->maySummarise($schema, 'name'));
	}//end testAnUngovernedSchemaIsUnaffected()

	/**
	 * A metadata aggregate with no schema is unaffected.
	 *
	 * `@self.created` and friends are governed by row access alone, and there is
	 * no schema property to look up for them.
	 *
	 * @return void
	 */
	public function testAMetadataAggregateWithNoSchemaIsUnaffected(): void {
		$this->assertTrue($this->visibilityWhereReadIs(null)->maySummarise(null, 'created'));
	}//end testAMetadataAggregateWithNoSchemaIsUnaffected()

	/**
	 * With no rule to ask, the summary is withheld.
	 *
	 * @return void
	 */
	public function testWithNoRuleToAskTheSummaryIsWithheld(): void {
		$this->assertFalse(
			$this->visibilityWhereReadIs(null)->maySummarise($this->governedSchema(), 'salary'),
			'A governed property with no resolvable read rule must fail closed.'
		);
	}//end testWithNoRuleToAskTheSummaryIsWithheld()

	/**
	 * A read rule that throws withholds rather than admits.
	 *
	 * @return void
	 */
	public function testAReadRuleThatThrowsWithholds(): void {
		$rbac = $this->createMock(PropertyRbacHandler::class);
		$rbac->method('canReadProperty')->willThrowException(new \RuntimeException('boom'));

		$visibility = new AggregateVisibility($rbac, new NullLogger());

		$this->assertFalse($visibility->maySummarise($this->governedSchema(), 'salary'));
	}//end testAReadRuleThatThrowsWithholds()

	/**
	 * 🔑 THE WITHHELD NAMES COME BACK, SO ABSENT CAN BE TOLD FROM NONE.
	 *
	 * Dropping them silently leaves the caller unable to tell "this field has no
	 * values" from "this field is not yours", and the first is a claim about the
	 * data the system has no business making on the second's behalf.
	 *
	 * @return void
	 */
	public function testPartitionNamesWhatItWithheld(): void {
		$split = $this->visibilityWhereReadIs(false)->partition(
			$this->governedSchema(),
			['salary', 'bonus']
		);

		$this->assertSame([], $split['allowed']);
		$this->assertSame(['salary', 'bonus'], $split['withheld']);
	}//end testPartitionNamesWhatItWithheld()

	/**
	 * Partition keeps what is allowed.
	 *
	 * @return void
	 */
	public function testPartitionKeepsWhatIsAllowed(): void {
		$split = $this->visibilityWhereReadIs(true)->partition($this->governedSchema(), ['salary']);

		$this->assertSame(['salary'], $split['allowed']);
		$this->assertSame([], $split['withheld']);
	}//end testPartitionKeepsWhatIsAllowed()
}//end class
