<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ClassificationLimits;
use PHPUnit\Framework\TestCase;

/**
 * Ceilings refuse a scope outright; the floor refuses a lowering write.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ClassificationLimits
 * @uses   \OCA\OpenRegister\Service\Rbac\ClassificationScale
 */
final class ClassificationLimitsTest extends TestCase {

	public function testACeilingRefusesItsScopeAtOrAboveTheLevel(): void {
		$ceiling = ['scope' => 'public', 'atOrAbove' => 'vertrouwelijk'];
		$scale   = ClassificationLimits::fromBlock(ClassificationScaleTest::zgw(), ['ceilings' => [$ceiling, 'junk']]);
		$this->assertSame($ceiling, $scale->ceilingRefusing('public', 'geheim'));
		$this->assertSame($ceiling, $scale->ceilingRefusing('public', 'vertrouwelijk'));
		$this->assertSame($ceiling, $scale->ceilingRefusing('public', null), 'unlabelled is most restrictive');
		$this->assertNull($scale->ceilingRefusing('public', 'zaakvertrouwelijk'));
		$this->assertNull($scale->ceilingRefusing('authenticated', 'zeer_geheim'));
		$this->assertNull(ClassificationLimits::fromBlock(ClassificationScaleTest::zgw(), [])->ceilingRefusing('public', 'zeer_geheim'));
	}

	public function testLoweringBelowTheFloorIsRefusedNamingBothLevels(): void {
		$scale = ClassificationLimits::fromBlock(ClassificationScaleTest::zgw(), ['floor' => ['property' => 'standaard', 'sourceRelation' => 'type']]);
		$this->assertSame(['property' => 'standaard', 'sourceRelation' => 'type'], $scale->floor());
		$this->assertSame(
			'Classification "openbaar" is below the floor "zaakvertrouwelijk" for this object',
			$scale->belowFloor('openbaar', 'zaakvertrouwelijk')
		);
		$this->assertNull($scale->belowFloor('geheim', 'zaakvertrouwelijk'));
		$this->assertNull($scale->belowFloor('zaakvertrouwelijk', 'zaakvertrouwelijk'));
		$this->assertNull($scale->belowFloor('openbaar', 'unknown'), 'an unknown floor refuses nothing');
		$this->assertNull($scale->belowFloor('bogus', 'geheim'), 'an unknown request ranks most restrictive');
		$this->assertNull(ClassificationLimits::fromBlock(ClassificationScaleTest::zgw(), [])->floor());
	}
}
