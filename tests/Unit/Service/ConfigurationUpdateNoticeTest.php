<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Service\ConfigurationUpdateNotice;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\ConfigurationUpdateNotice
 */
final class ConfigurationUpdateNoticeTest extends TestCase {

	public function testSubjectParametersCarryTheVersions(): void {
		$notice = new ConfigurationUpdateNotice('Zaken', 8, '1.0.0', '1.1.0');

		$this->assertSame(
			['configurationTitle' => 'Zaken', 'configurationId' => 8, 'currentVersion' => '1.0.0', 'newVersion' => '1.1.0'],
			$notice->subjectParameters()
		);
	}

	public function testUnknownVersionsReadUnknown(): void {
		$notice = new ConfigurationUpdateNotice('Zaken', 8, null, null);

		$this->assertSame('unknown', $notice->subjectParameters()['currentVersion']);
		$this->assertSame('unknown', $notice->subjectParameters()['newVersion']);
	}
}
