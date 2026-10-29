<?php

/**
 * The app id has one value under both names.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use PHPUnit\Framework\TestCase;

class AppIdentityTest extends TestCase {

	/**
	 * AppIdentity::APP_ID and Application::APP_ID are the id in appinfo/info.xml.
	 *
	 * Other apps read Application::APP_ID, so it must keep giving the same id.
	 *
	 * @return void
	 */
	public function testBothNamesGiveTheInfoXmlId(): void {
		$infoXml = (string)file_get_contents(__DIR__ . '/../../../appinfo/info.xml');
		preg_match('#<id>([^<]+)</id>#', $infoXml, $match);

		$this->assertSame(expected: $match[1], actual: AppIdentity::APP_ID);
		$this->assertSame(expected: AppIdentity::APP_ID, actual: Application::APP_ID);
	}//end testBothNamesGiveTheInfoXmlId()
}//end class
