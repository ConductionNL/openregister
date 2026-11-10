<?php

/**
 * Unit tests for naming the record a correction applies to.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Object;

use OCA\OpenRegister\Kernel\Contracts\ObjectRef;
use OCA\OpenRegister\Service\Object\CorrectionTargets;
use PHPUnit\Framework\TestCase;

/**
 * The register, schema and id of a correction keep their places.
 *
 * @covers \OCA\OpenRegister\Service\Object\CorrectionTargets
 * @covers \OCA\OpenRegister\Service\Object\CorrectionTarget
 */
class CorrectionTargetsTest extends TestCase {

	/**
	 * Each identifier lands in its own slot, never swapped.
	 *
	 * @return void
	 */
	public function testEachIdentifierKeepsItsSlot(): void {
		$target = (new CorrectionTargets())->forRecord(new ObjectRef(register: 'zaken', schema: 'zaak', id: 'uuid-42'));

		$this->assertSame(
			['register' => 'zaken', 'schema' => 'zaak', 'id' => 'uuid-42'],
			['register' => $target->register, 'schema' => $target->schema, 'id' => $target->id]
		);

	}//end testEachIdentifierKeepsItsSlot()
}//end class
