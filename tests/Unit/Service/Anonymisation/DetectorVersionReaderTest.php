<?php

/**
 * The pattern backend's version record reads its patterns' version through the Compliance port.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Anonymisation;

use OCA\OpenRegister\Kernel\Contracts\Compliance\DetectionPatternsVersionInterface;
use OCA\OpenRegister\Service\Anonymisation\BackendState;
use OCA\OpenRegister\Service\Anonymisation\DetectorVersionReader;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Anonymisation\DetectorVersionReader
 * @uses   \OCA\OpenRegister\Service\Anonymisation\DetectorVersion
 */
final class DetectorVersionReaderTest extends TestCase {

	/**
	 * A reader over a port that reports version 9.9.9, changed 2030-01-02.
	 *
	 * @return DetectorVersionReader
	 */
	private function reader(): DetectorVersionReader {
		$patterns = $this->createMock(DetectionPatternsVersionInterface::class);
		$patterns->method('patternsVersion')->willReturn('9.9.9');
		$patterns->method('patternsChangedAt')->willReturn('2030-01-02');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->willReturn('1.2.3');

		return new DetectorVersionReader(appManager: $appManager, patterns: $patterns);
	}//end reader()

	/**
	 * The regex backend reports the port's version and change day.
	 *
	 * @return void
	 */
	public function testTheRegexBackendReportsThePortsPatterns(): void {
		$fields = $this->reader()->forMethod(method: BackendState::METHOD_REGEX)->jsonSerialize();

		$this->assertSame('openregister-patterns', $fields['engine']);
		$this->assertSame('1.2.3', $fields['engineVersion']);
		$this->assertSame('9.9.9', $fields['patternSetVersion']);
		$this->assertSame('2030-01-02', $fields['changedAt']);
	}//end testTheRegexBackendReportsThePortsPatterns()

	/**
	 * The hybrid backend reports the port's version.
	 *
	 * @return void
	 */
	public function testTheHybridBackendReportsThePortsVersion(): void {
		$fields = $this->reader()->forMethod(method: BackendState::METHOD_HYBRID)->jsonSerialize();

		$this->assertSame('9.9.9', $fields['patternSetVersion']);
	}//end testTheHybridBackendReportsThePortsVersion()
}//end class
