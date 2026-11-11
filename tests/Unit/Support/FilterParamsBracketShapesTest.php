<?php

/**
 * Which `filter` values FilterParams::liftBracketFilter leaves exactly as they came.
 *
 * Only a non-empty map is lifted; a scalar, an empty array, a list or an
 * object leaves the parameters untouched, key order included.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

use OCA\OpenRegister\Support\FilterParams;
use PHPUnit\Framework\TestCase;

/**
 * Bracket shapes that are not lifted, golden.
 *
 * @covers \OCA\OpenRegister\Support\FilterParams
 */
final class FilterParamsBracketShapesTest extends TestCase {

	/**
	 * A scalar, empty, list or object `filter` leaves the parameters as they came.
	 *
	 * @return void
	 */
	public function testOnlyANonEmptyMapIsLifted(): void {
		$object = new \stdClass();
		$object->status = 'open';
		foreach (['scalar' => 'status', 'empty' => [], 'list' => ['status', 'open'], 'object' => $object] as $name => $bracket) {
			$params = ['filter' => $bracket, '_limit' => 5];
			$this->assertSame($params, FilterParams::liftBracketFilter($params), $name);
		}

		$this->assertSame(['_limit' => 5, 'status' => 'open'], FilterParams::liftBracketFilter(['filter' => ['status' => 'open'], '_limit' => 5]));
	}//end testOnlyANonEmptyMapIsLifted()
}//end class
