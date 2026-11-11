<?php

/**
 * DataManipulationHandler: key-based property mapping.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Service\Object\DataManipulationHandler;
use PHPUnit\Framework\TestCase;

/**
 * What the mapping keeps and drops.
 *
 * @covers \OCA\OpenRegister\Service\Object\DataManipulationHandler
 */
class DataManipulationHandlerTest extends TestCase {

	/**
	 * Mapped keys are renamed; a source key that is absent is left out, a present null is kept.
	 *
	 * @return void
	 */
	public function testPresentSourceKeysAreMappedAndAbsentOnesDropped(): void {
		$mapped = (new DataManipulationHandler())->mapObjectProperties(
			['firstName' => 'John', 'lastName' => 'Doe', 'middle' => null, 'unused' => 1],
			['name' => 'firstName', 'surname' => 'lastName', 'initial' => 'middle', 'age' => 'birthYear']
		);

		$this->assertSame(['name' => 'John', 'surname' => 'Doe', 'initial' => null], $mapped);
	}//end testPresentSourceKeysAreMappedAndAbsentOnesDropped()

	/**
	 * An empty mapping maps nothing.
	 *
	 * @return void
	 */
	public function testAnEmptyMappingMapsNothing(): void {
		$this->assertSame([], (new DataManipulationHandler())->mapObjectProperties(['a' => 1], []));
	}//end testAnEmptyMappingMapsNothing()
}//end class
