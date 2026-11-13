<?php

/**
 * Unit tests for MdtoBegrip
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Edepot
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

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\MdtoBegrip;
use PHPUnit\Framework\TestCase;

/**
 * Pins how an optional MDTO begrip is built.
 *
 * @covers \OCA\OpenRegister\Service\Edepot\MdtoBegrip
 */
final class MdtoBegripTest extends TestCase {

	/**
	 * No label means no begrip.
	 *
	 * @return void
	 */
	public function testOptionalWithoutLabelIsNull(): void {
		$this->assertNull(actual: MdtoBegrip::optional(label: null, list: 'Archiefwet', code: 'x'));
	}//end testOptionalWithoutLabelIsNull()

	/**
	 * A label builds a begrip carrying the label, list and code.
	 *
	 * @return void
	 */
	public function testOptionalWithLabelBuildsTheBegrip(): void {
		$begrip = MdtoBegrip::optional(label: 'Openbaar', list: 'Openbaarheid', code: 'O');

		$this->assertInstanceOf(expected: MdtoBegrip::class, actual: $begrip);
		$this->assertSame(expected: ['Openbaar', 'Openbaarheid', 'O'], actual: [$begrip->label, $begrip->list, $begrip->code]);
		$this->assertNull(actual: MdtoBegrip::optional(label: 'Openbaar', list: 'Openbaarheid')->code);
	}//end testOptionalWithLabelBuildsTheBegrip()
}//end class
