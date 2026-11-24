<?php

/**
 * The pattern backend reports the version of the pattern set it recognises with.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/anonymisation-discloses-itself/specs/anonymisation-disclosure/spec.md#requirement-every-detection-backend-reports-what-it-is-built-from-req-adi-001
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Files\Service\TextExtraction\PatternSet;

use OCA\OpenRegister\Files\FilesRegistrar;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\NlPatternSet;
use OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\PatternSetVersion;
use OCA\OpenRegister\Kernel\Contracts\Compliance\DetectionPatternsVersionInterface;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Files\Service\TextExtraction\PatternSet\PatternSetVersion
 */
final class PatternSetVersionTest extends TestCase {

	/**
	 * The version and change day are the Dutch pattern set's.
	 *
	 * @return void
	 */
	public function testItReportsTheDutchPatternSet(): void {
		$version = new PatternSetVersion();

		$this->assertSame(NlPatternSet::VERSION, $version->patternsVersion());
		$this->assertSame(NlPatternSet::CHANGED_AT, $version->patternsChangedAt());
	}//end testItReportsTheDutchPatternSet()

	/**
	 * The Files registrar binds the Compliance port to it.
	 *
	 * @return void
	 */
	public function testTheFilesRegistrarBindsThePort(): void {
		$this->assertSame(PatternSetVersion::class, FilesRegistrar::BINDINGS[DetectionPatternsVersionInterface::class]);
	}//end testTheFilesRegistrarBindsThePort()
}//end class
