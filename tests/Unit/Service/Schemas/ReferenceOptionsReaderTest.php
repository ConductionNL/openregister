<?php

/**
 * The read behind a filtered reference picker.
 *
 * 🔴 NO OPTIONS MUST NEVER BECOME EVERY OPTION. When an operand the filter
 * depends on has no value yet, the answer is an EMPTY list naming what it is
 * waiting for. Returning the unfiltered set would show every contact in the
 * register to somebody who had not yet chosen an organisation, and each of
 * those is a value they were never meant to browse. Wider is the direction that
 * discloses.
 *
 * 🔑 IT PLANS WITH THE SAME `resolve()` THE SAVE PATH CALLS. A picker that
 * offers one set while the save path accepts another is two evaluators of one
 * rule.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Schemas
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

namespace OCA\OpenRegister\Tests\Unit\Service\Schemas;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Schemas\ReferenceFilterException;
use OCA\OpenRegister\Service\Schemas\ReferenceOptionsReader;
use PHPUnit\Framework\TestCase;

/**
 * `ReferenceOptionsReader`.
 *
 * @covers \OCA\OpenRegister\Service\Schemas\ReferenceOptionsReader
 */
class ReferenceOptionsReaderTest extends TestCase {

	/**
	 * The reader.
	 *
	 * @var ReferenceOptionsReader
	 */
	private ReferenceOptionsReader $reader;

	/**
	 * Build the reader.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->reader = new ReferenceOptionsReader();
	}//end setUp()

	/**
	 * A schema whose `contact` points at contacts, filtered by organisation.
	 *
	 * @return Schema The schema.
	 */
	private function filteredSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties([
			'organisation' => ['type' => 'string'],
			'contact' => [
				'type' => 'string',
				'$ref' => 'contact',
				'register' => 'crm',
				'x-openregister-reference-filter' => [
					['field' => 'organisation', 'op' => 'eq', 'from' => 'organisation'],
				],
			],
		]);

		return $schema;
	}//end filteredSchema()

	/**
	 * 🔴 AN UNRESOLVED OPERAND YIELDS NO OPTIONS AND NAMES WHAT IT NEEDS.
	 *
	 * @return void
	 */
	public function testAnUnresolvedOperandYieldsNoOptionsAndNamesIt(): void {
		$plan = $this->reader->plan(
			schema: $this->filteredSchema(),
			property: 'contact',
			record: []
		);

		$this->assertFalse($this->reader->isAnswerable($plan));
		$this->assertSame(['organisation'], $plan['needs']);
	}//end testAnUnresolvedOperandYieldsNoOptionsAndNamesIt()

	/**
	 * A resolved operand yields the filter.
	 *
	 * The control: without it, a reader that always reported `needs` would pass
	 * the test above while offering nothing ever.
	 *
	 * @return void
	 */
	public function testAResolvedOperandYieldsTheFilter(): void {
		$plan = $this->reader->plan(
			schema: $this->filteredSchema(),
			property: 'contact',
			record: ['organisation' => 'org-1']
		);

		$this->assertTrue($this->reader->isAnswerable($plan));
		$this->assertSame([], $plan['needs']);
		$this->assertSame(['organisation' => 'org-1'], $plan['filter']);
	}//end testAResolvedOperandYieldsTheFilter()

	/**
	 * The plan names the schema and register the options come from.
	 *
	 * Reading the record's own schema instead would answer a confidently wrong
	 * list rather than an error.
	 *
	 * @return void
	 */
	public function testThePlanNamesTheReferencedSchemaAndRegister(): void {
		$plan = $this->reader->plan(
			schema: $this->filteredSchema(),
			property: 'contact',
			record: ['organisation' => 'org-1']
		);

		$this->assertSame('contact', $plan['target']['schema']);
		$this->assertSame('crm', $plan['target']['register']);
	}//end testThePlanNamesTheReferencedSchemaAndRegister()

	/**
	 * A property with no filter offers everything the caller may read.
	 *
	 * That is what an unfiltered reference has always meant, and the endpoint
	 * must not start narrowing one.
	 *
	 * @return void
	 */
	public function testAnUnfilteredReferenceIsAnswerableWithNoFilter(): void {
		$schema = new Schema();
		$schema->setProperties(['contact' => ['type' => 'string', '$ref' => 'contact']]);

		$plan = $this->reader->plan(schema: $schema, property: 'contact', record: []);

		$this->assertFalse($plan['filtered']);
		$this->assertTrue($this->reader->isAnswerable($plan));
		$this->assertSame([], $plan['filter']);
	}//end testAnUnfilteredReferenceIsAnswerableWithNoFilter()

	/**
	 * An array of references takes its target from the item shape.
	 *
	 * @return void
	 */
	public function testAnArrayOfReferencesTakesItsTargetFromItsItems(): void {
		$schema = new Schema();
		$schema->setProperties([
			'contacts' => ['type' => 'array', 'items' => ['$ref' => 'contact']],
		]);

		$plan = $this->reader->plan(schema: $schema, property: 'contacts', record: []);

		$this->assertSame('contact', $plan['target']['schema']);
	}//end testAnArrayOfReferencesTakesItsTargetFromItsItems()

	/**
	 * A property that is not on the schema is refused, not treated as empty.
	 *
	 * Treating it as empty would answer "no options" for a typo, which reads
	 * exactly like a filter waiting on an operand.
	 *
	 * @return void
	 */
	public function testAnUnknownPropertyIsRefused(): void {
		$this->expectException(ReferenceFilterException::class);

		$this->reader->plan(schema: $this->filteredSchema(), property: 'nope', record: []);
	}//end testAnUnknownPropertyIsRefused()

	/**
	 * 🔑 A LIMIT OF ZERO IS THE DEFAULT, NOT UNLIMITED AND NOT `LIMIT 0`.
	 *
	 * `_limit=0` reaching a query builder produces an empty page with an HTTP
	 * 200 and no explanation, which is the failure `QueryLimit::normalise()`
	 * exists for.
	 *
	 * @return void
	 */
	public function testALimitOfZeroBecomesTheDefault(): void {
		$this->assertSame(ReferenceOptionsReader::DEFAULT_LIMIT, $this->reader->limitFor(0));
		$this->assertSame(ReferenceOptionsReader::DEFAULT_LIMIT, $this->reader->limitFor(null));
		$this->assertSame(ReferenceOptionsReader::DEFAULT_LIMIT, $this->reader->limitFor('nonsense'));
		$this->assertSame(ReferenceOptionsReader::DEFAULT_LIMIT, $this->reader->limitFor(-5));
	}//end testALimitOfZeroBecomesTheDefault()

	/**
	 * A page is capped, so a picker cannot become a bulk export.
	 *
	 * @return void
	 */
	public function testAPageIsCapped(): void {
		$this->assertSame(ReferenceOptionsReader::MAX_LIMIT, $this->reader->limitFor(100000));
		$this->assertSame(10, $this->reader->limitFor(10));
	}//end testAPageIsCapped()

	/**
	 * The query carries the filter and the paging, and nothing else.
	 *
	 * @return void
	 */
	public function testTheQueryCarriesTheFilterAndThePaging(): void {
		$plan = $this->reader->plan(
			schema: $this->filteredSchema(),
			property: 'contact',
			record: ['organisation' => 'org-1']
		);

		$query = $this->reader->queryFor(plan: $plan, limit: 25, offset: 50);

		$this->assertSame('org-1', $query['organisation']);
		$this->assertSame(25, $query['_limit']);
		$this->assertSame(50, $query['_offset']);
	}//end testTheQueryCarriesTheFilterAndThePaging()

	/**
	 * A negative offset is clamped rather than passed through.
	 *
	 * @return void
	 */
	public function testANegativeOffsetIsClamped(): void {
		$plan = $this->reader->plan(
			schema: $this->filteredSchema(),
			property: 'contact',
			record: ['organisation' => 'org-1']
		);

		$this->assertSame(0, $this->reader->queryFor(plan: $plan, limit: 10, offset: -20)['_offset']);
	}//end testANegativeOffsetIsClamped()
}//end class
