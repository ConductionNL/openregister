<?php

/**
 * Tests for SipLayout.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\Edepot
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\SipLayout;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Edepot\SipLayout
 */
class SipLayoutTest extends TestCase {

	public function testAFileGoesUnderOriginalOrRendition(): void {
		$original  = ['name' => 'a.pdf', 'isRendition' => false];
		$rendition = ['name' => 'a.pdf', 'isRendition' => true];

		$this->assertSame('objects/u/content/original/a.pdf', SipLayout::contentPath(uuid: 'u', file: $original));
		$this->assertSame('objects/u/content/rendition/a.pdf', SipLayout::contentPath(uuid: 'u', file: $rendition));
		$this->assertSame('original', SipLayout::subDir(file: ['isRendition' => 'yes']));
	}
}//end class
