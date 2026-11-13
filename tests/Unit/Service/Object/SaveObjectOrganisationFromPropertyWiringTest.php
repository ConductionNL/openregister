<?php

/**
 * SaveObject takes the organisation from the property before it stamps one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\SaveObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pins that both save paths apply the declaration, and in the one order that works.
 *
 * A unit test cannot run `prepareObjectForCreation()` without a database, and
 * the thing that breaks silently here is ORDER: applied after the
 * `getOrganisationForNewEntity()` fallback the property would never decide
 * anything, because the fallback only fires on an empty organisation and would
 * already have filled it. So the order is asserted on the source of the two
 * methods, which is where that mistake would live.
 *
 * @coversNothing
 */
class SaveObjectOrganisationFromPropertyWiringTest extends TestCase {

	/**
	 * The source of one method of SaveObject.
	 *
	 * @param string $method The method name.
	 *
	 * @return string The source lines.
	 */
	private function sourceOf(string $method): string {
		$reflected = (new ReflectionClass(SaveObject::class))->getMethod($method);
		$file = (array)file((string)$reflected->getFileName());
		$start = ($reflected->getStartLine() - 1);

		return implode('', array_slice($file, $start, ($reflected->getEndLine() - $start)));
	}//end sourceOf()

	/**
	 * Create applies the declaration before the active-organisation fallback.
	 *
	 * @return void
	 */
	public function testCreateAppliesTheDeclarationBeforeTheFallback(): void {
		$source = $this->sourceOf(method: 'prepareObjectForCreation');
		$applied = strpos($source, 'organisationFromProperty()->apply(');
		$fallback = strpos($source, 'getOrganisationForNewEntity()');

		$this->assertIsInt(actual: $applied, message: 'prepareObjectForCreation() does not apply the declaration.');
		$this->assertIsInt(actual: $fallback, message: 'The active-organisation fallback moved; this test must follow it.');
		$this->assertLessThan(expected: $fallback, actual: $applied);
	}//end testCreateAppliesTheDeclarationBeforeTheFallback()

	/**
	 * Update applies the declaration too, after the incoming data is on the entity.
	 *
	 * @return void
	 */
	public function testUpdateAppliesTheDeclarationAfterTheDataIsSet(): void {
		$source = $this->sourceOf(method: 'prepareObjectForUpdate');
		$applied = strpos($source, 'organisationFromProperty()->apply(');
		$dataSet = strpos($source, '$existingObject->setObject($preparedData);');

		$this->assertIsInt(actual: $applied, message: 'prepareObjectForUpdate() does not apply the declaration.');
		$this->assertIsInt(actual: $dataSet, message: 'The update no longer sets the prepared data; this test must follow it.');
		$this->assertGreaterThan(expected: $dataSet, actual: $applied);
	}//end testUpdateAppliesTheDeclarationAfterTheDataIsSet()

	/**
	 * The resolver is built from the pipeline's own organisation service and session.
	 *
	 * @return void
	 */
	public function testTheResolverIsBuiltFromThePipelinesOwnServices(): void {
		$reflected = (new ReflectionClass(SaveObject::class))->getMethod('organisationFromProperty');
		$source = $this->sourceOf(method: 'organisationFromProperty');

		$this->assertTrue(condition: $reflected->isPrivate());
		$this->assertStringContainsString(needle: 'organisations: $this->organisationService', haystack: $source);
		$this->assertStringContainsString(needle: 'userSession: $this->userSession', haystack: $source);
		$this->assertStringContainsString(needle: 'groupManager: $this->groupManager', haystack: $source);
	}//end testTheResolverIsBuiltFromThePipelinesOwnServices()
}//end class
