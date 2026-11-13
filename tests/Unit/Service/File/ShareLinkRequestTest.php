<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\OpenRegister\Tests\Unit\Service\File
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/openregister
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\File;

use OCA\OpenRegister\Service\File\ShareLinkRequest;
use PHPUnit\Framework\TestCase;

/**
 * The permissions a share link grants: the asked ones, else read for a public
 * link and all for any other share type.
 *
 * @covers \OCA\OpenRegister\Service\File\ShareLinkRequest
 */
class ShareLinkRequestTest extends TestCase {

	public function testAPublicLinkDefaultsToReadOnly(): void {
		$request = new ShareLinkRequest('/admin/files/report.pdf');

		$this->assertSame(3, $request->shareType);
		$this->assertSame(1, $request->permissions());
	}//end testAPublicLinkDefaultsToReadOnly()

	public function testAnyOtherShareTypeDefaultsToAllPermissions(): void {
		$this->assertSame(31, (new ShareLinkRequest('/f', 0))->permissions());
		$this->assertSame(31, (new ShareLinkRequest('/f', 4))->permissions());
		$this->assertSame(31, (new ShareLinkRequest('/f', null))->permissions());
	}//end testAnyOtherShareTypeDefaultsToAllPermissions()

	public function testAskedPermissionsWinOverTheDefault(): void {
		$this->assertSame(17, (new ShareLinkRequest('/f', 3, 17))->permissions());
		$this->assertSame(0, (new ShareLinkRequest('/f', 0, 0))->permissions());
	}//end testAskedPermissionsWinOverTheDefault()
}//end class
