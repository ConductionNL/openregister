<?php

/**
 * A facet is a read of the column, so it obeys the read rule.
 *
 * 🔴 THE LEAK THIS CLOSES WAS QUIET AND PRE-EXISTING. `expandFacetConfig()`
 * offered EVERY property marked `facetable` to EVERY caller who could see the
 * rows, and a facet over a governed column hands back its DISTINCT VALUES with
 * counts. The rows were protected and the value list was not: for a property
 * scoped to one team, everybody else could read the set of answers without ever
 * being allowed to read one of them.
 *
 * Nothing on screen suggested it. The response looked like an ordinary facet,
 * and the property never appeared in any object body, because the render path
 * strips it correctly. Only the facet did not ask.
 *
 * 🔑 IT ASKS THE ONE THING THAT ALREADY ANSWERS THE QUESTION. Reimplementing
 * the read rule inside the facet handler would mean two answers to "may this
 * person see this field", and the two disagree within a week; the wider one is
 * the one that discloses.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Db\MagicMapper
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

namespace Unit\Db\MagicMapper;

use OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\PropertyRbacHandler;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;
use ReflectionMethod;

/**
 * `MagicFacetHandler::callerMayFacet()`.
 *
 * @covers \OCA\OpenRegister\Db\MagicMapper\MagicFacetHandler
 */
class FacetsObeyThePropertyReadRuleTest extends TestCase {

	/**
	 * A handler whose property read rule answers as given.
	 *
	 * @param bool|null $mayRead What the read rule answers, or null for no container at all.
	 *
	 * @return MagicFacetHandler The handler.
	 */
	private function handlerWhereReadIs(?bool $mayRead): MagicFacetHandler {
		$container = null;

		if ($mayRead !== null) {
			$rbac = $this->createMock(PropertyRbacHandler::class);
			$rbac->method('canReadProperty')->willReturn($mayRead);

			$container = $this->createMock(ContainerInterface::class);
			$container->method('get')->willReturn($rbac);
		}

		return new MagicFacetHandler(
			$this->createMock(IDBConnection::class),
			new NullLogger(),
			null,
			null,
			null,
			$container,
			null
		);
	}//end handlerWhereReadIs()

	/**
	 * Ask the handler whether it would offer the facet.
	 *
	 * @param MagicFacetHandler $handler The handler.
	 * @param Schema            $schema  The schema.
	 *
	 * @return bool The answer.
	 */
	private function mayFacet(MagicFacetHandler $handler, Schema $schema): bool {
		$method = new ReflectionMethod(MagicFacetHandler::class, 'callerMayFacet');
		$method->setAccessible(true);

		return (bool)$method->invoke($handler, $schema, 'salary');
	}//end mayFacet()

	/**
	 * A schema whose only control is a scope on `salary`.
	 *
	 * @return Schema The schema.
	 */
	private function scopedSchema(): Schema {
		$schema = new Schema();
		$schema->setProperties(['salary' => ['type' => 'number', 'facetable' => true, 'scope' => 'team-a']]);

		return $schema;
	}//end scopedSchema()

	/**
	 * 🔴 SOMEBODY OUTSIDE THE SCOPE IS NOT OFFERED THE FACET.
	 *
	 * This is the leak. Without it they receive the distinct salaries.
	 *
	 * @return void
	 */
	public function testAPropertyTheCallerMayNotReadIsNotFaceted(): void {
		$this->assertFalse(
			$this->mayFacet($this->handlerWhereReadIs(false), $this->scopedSchema()),
			'A facet over a column the caller may not read hands back its distinct values.'
		);
	}//end testAPropertyTheCallerMayNotReadIsNotFaceted()

	/**
	 * Somebody inside the scope still gets the facet.
	 *
	 * The control. Without it, a method that always refused would pass the test
	 * above while removing the feature.
	 *
	 * @return void
	 */
	public function testAPropertyTheCallerMayReadIsStillFaceted(): void {
		$this->assertTrue($this->mayFacet($this->handlerWhereReadIs(true), $this->scopedSchema()));
	}//end testAPropertyTheCallerMayReadIsStillFaceted()

	/**
	 * An ungoverned schema asks nothing and is unaffected.
	 *
	 * Note the handler here has NO container at all: if an ungoverned schema
	 * reached the lookup it would fail closed and every ordinary facet would
	 * vanish.
	 *
	 * @return void
	 */
	public function testAnUngovernedSchemaIsUnaffected(): void {
		$schema = new Schema();
		$schema->setProperties(['salary' => ['type' => 'number', 'facetable' => true]]);

		$this->assertTrue($this->mayFacet($this->handlerWhereReadIs(null), $schema));
	}//end testAnUngovernedSchemaIsUnaffected()

	/**
	 * With no way to ask, the facet is omitted rather than offered.
	 *
	 * Fails closed: the alternative is offering a facet whose access nobody
	 * checked.
	 *
	 * @return void
	 */
	public function testWithNoWayToAskTheFacetIsOmitted(): void {
		$this->assertFalse(
			$this->mayFacet($this->handlerWhereReadIs(null), $this->scopedSchema()),
			'A governed property with no resolvable read rule must fail closed.'
		);
	}//end testWithNoWayToAskTheFacetIsOmitted()
}//end class
