<?php

/**
 * Unit tests for mapping expressions a handoff cannot evaluate.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Handoff
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Handoff;

use OCA\OpenRegister\Service\Handoff\HandoffMappingEvaluator;
use OCA\OpenRegister\Service\Handoff\HandoffMappingSource;
use PHPUnit\Framework\TestCase;

/**
 * A scalar expression, or an object with no known operator, yields no field; known ones still map.
 *
 * @covers \OCA\OpenRegister\Service\Handoff\HandoffMappingEvaluator
 * @uses \OCA\OpenRegister\Service\Handoff\HandoffMappingSource
 */
class HandoffMappingEvaluatorShapesTest extends TestCase {

	/**
	 * Unknown shapes are left out of the result; the rest is evaluated.
	 *
	 * @return void
	 */
	public function testUnknownExpressionsYieldNoField(): void {
		$result = (new HandoffMappingEvaluator())->evaluate(
			[
				'title'   => 'naam',
				'kind'    => ['operator' => 'concat'],
				'status'  => ['const' => 'new'],
				'subject' => ['from' => 'naam'],
				'note'    => ['from' => 'opmerking', 'default' => '-'],
			],
			new HandoffMappingSource(['naam' => 'Jansen'], ['source' => 'zaak-1'])
		);

		$this->assertSame(['status' => 'new', 'subject' => 'Jansen', 'note' => '-'], $result);

	}//end testUnknownExpressionsYieldNoField()
}//end class
