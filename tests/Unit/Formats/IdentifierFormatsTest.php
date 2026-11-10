<?php

/**
 * Unit tests for IdentifierFormats.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Formats
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Formats;

use OCA\OpenRegister\Formats\BsnFormat;
use OCA\OpenRegister\Formats\IdentifierFormats;
use PHPUnit\Framework\TestCase;

/**
 * The factory hands out the component's own BSN check.
 *
 * @covers \OCA\OpenRegister\Formats\IdentifierFormats
 */
class IdentifierFormatsTest extends TestCase {

	/**
	 * The BSN check is the elfproef of BsnFormat.
	 *
	 * @return void
	 */
	public function testBsnIsTheElfproef(): void {
		$format = IdentifierFormats::bsn();

		$this->assertInstanceOf(BsnFormat::class, $format);
		$this->assertTrue($format->validate('111222333'));
		$this->assertFalse($format->validate('111222334'));
	}//end testBsnIsTheElfproef()
}//end class
