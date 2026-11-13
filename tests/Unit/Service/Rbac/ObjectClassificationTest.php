<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ObjectClassification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * An object's label, placed on its scale, with what cannot be placed logged.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ObjectClassification
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 */
final class ObjectClassificationTest extends TestCase {

	public function testAKnownLabelIsRankedSilently(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		$classification = new ObjectClassification($logger);
		$scale          = ClassificationScaleTest::zgw();
		$this->assertSame(2, $classification->rank($scale, 'u1', ['vertrouwelijkheidaanduiding' => 'intern']));
		$this->assertTrue($classification->readable($scale, 'intern', 'u1', ['vertrouwelijkheidaanduiding' => 'intern']));
		$this->assertFalse($classification->readable($scale, 'intern', 'u1', ['vertrouwelijkheidaanduiding' => 'zaakvertrouwelijk']));
	}

	public function testAnUnlabelledObjectIsHiddenBelowTheTopAndNamed(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->exactly(2))->method('warning')->with($this->logicalAnd(
			$this->stringContains('"u2" carries nothing'),
			$this->stringContains('"zeer_geheim"')
		));
		$classification = new ObjectClassification($logger);
		$scale          = ClassificationScaleTest::zgw();
		$this->assertFalse($classification->readable($scale, 'geheim', 'u2', ['vertrouwelijkheidaanduiding' => '']));
		$this->assertTrue($classification->readable($scale, 'zeer_geheim', 'u2', []));
	}

	public function testAnUnknownLabelIsNamedInTheWarning(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with($this->stringContains('carries "topsecret" in "vertrouwelijkheidaanduiding"'));
		$this->assertSame(7, (new ObjectClassification($logger))->rank(ClassificationScaleTest::zgw(), 'u3', ['vertrouwelijkheidaanduiding' => 'topsecret']));
	}
}
