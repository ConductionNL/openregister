<?php

/**
 * Unit tests for SeamStatusSet
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Gdpr\Seam
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Gdpr\Seam;

use InvalidArgumentException;
use OCA\OpenRegister\Service\Gdpr\Seam\SeamStatusSet;
use PHPUnit\Framework\TestCase;

/**
 * Pins which statuses a seam result accepts and the refusal for the rest.
 *
 * @covers \OCA\OpenRegister\Service\Gdpr\Seam\SeamStatusSet
 */
final class SeamStatusSetTest extends TestCase {

	/**
	 * An allowed status passes.
	 *
	 * @return void
	 */
	public function testAnAllowedStatusPasses(): void {
		$set = new SeamStatusSet(resultName: 'Erasure', allowed: ['done', 'held']);

		$set->assert(status: 'held');

		$this->assertSame(expected: ['done', 'held'], actual: $set->allowed);
	}//end testAnAllowedStatusPasses()

	/**
	 * Any other status is refused, naming the result and the allowed set; the match is exact.
	 *
	 * @return void
	 */
	public function testAnotherStatusIsRefused(): void {
		$set = new SeamStatusSet(resultName: 'Erasure', allowed: ['done', 'held']);

		$this->expectException(exception: InvalidArgumentException::class);
		$this->expectExceptionMessage(message: 'Erasure status "Done" is invalid; expected one of: done, held');

		$set->assert(status: 'Done');
	}//end testAnotherStatusIsRefused()
}//end class
