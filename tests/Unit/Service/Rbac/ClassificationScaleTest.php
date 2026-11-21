<?php

/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ClassificationScale;
use PHPUnit\Framework\TestCase;

/**
 * The comparator over a schema's own ordered vocabulary.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ClassificationScale
 */
final class ClassificationScaleTest extends TestCase {

	public const ZGW = ['openbaar', 'beperkt_openbaar', 'intern', 'zaakvertrouwelijk', 'vertrouwelijk', 'confidentieel', 'geheim', 'zeer_geheim'];

	public const ZGW_BLOCK = ['property' => 'vertrouwelijkheidaanduiding', 'levels' => self::ZGW];

	/**
	 * A scale over the eight ZGW levels.
	 *
	 * @param array<string, mixed> $extra Extra block entries.
	 *
	 * @return ClassificationScale
	 */
	public static function zgw(array $extra=[]): ClassificationScale {
		return ClassificationScale::fromBlock(self::ZGW_BLOCK + $extra);
	}

	public function testEightZgwLevelsOrderAsDeclared(): void {
		$scale = self::zgw();
		$this->assertSame('vertrouwelijkheidaanduiding', $scale->property());
		$this->assertSame(self::ZGW, $scale->levels());
		$this->assertGreaterThan($scale->rankOf('intern'), $scale->rankOf('zaakvertrouwelijk'));
		$this->assertLessThan($scale->rankOf('geheim'), $scale->rankOf('zaakvertrouwelijk'));
		$this->assertSame(3, $scale->rankOf('zaakvertrouwelijk'));
	}

	public function testTheOrderIsTheSchemasNotABuiltIn(): void {
		$scale = ClassificationScale::fromBlock(['property' => 'm', 'levels' => ['laag', 'midden', 'hoog']]);
		$this->assertSame(2, $scale->rankOf('hoog'));
		$this->assertSame('hoog', $scale->highest());
		$this->assertSame(2, $scale->rankOf('geheim'), 'a ZGW label is unknown on another scale');
	}

	public function testAnAbsentEmptyOrUnknownLabelRanksMostRestrictive(): void {
		$scale = self::zgw();
		foreach ([null, '', 'GEHEIM', 'topsecret', 3, ['geheim']] as $label) {
			$this->assertSame(7, $scale->rankOf($label));
			$this->assertFalse($scale->isKnown($label));
		}

		$this->assertTrue($scale->isKnown('openbaar'));
	}

	public function testReadAtOrBelowClearanceIsAdmittedAndAboveIsNot(): void {
		$scale = self::zgw();
		$this->assertTrue($scale->admits('vertrouwelijk', 'vertrouwelijk'));
		$this->assertTrue($scale->admits('vertrouwelijk', 'openbaar'));
		$this->assertFalse($scale->admits('vertrouwelijk', 'confidentieel'));
		$this->assertFalse($scale->admits('geheim', ''), 'an unlabelled object is hidden below the top');
		$this->assertTrue($scale->admits('zeer_geheim', ''));
	}

	public function testAdmittedLabelsAreThePrefixUpToTheClearance(): void {
		$scale = self::zgw();
		$this->assertSame(['openbaar', 'beperkt_openbaar', 'intern'], $scale->admittedLabels('intern'));
		$this->assertSame(['openbaar'], $scale->admittedLabels('openbaar'));
		$this->assertSame(self::ZGW, $scale->admittedLabels('zeer_geheim'));
	}

	public function testAnUnplaceableClearanceIsTheDefault(): void {
		$this->assertSame(['openbaar'], self::zgw()->admittedLabels('nonsense'));
		$withDefault = self::zgw(['default' => 'intern']);
		$this->assertSame('intern', $withDefault->defaultClearance());
		$this->assertSame(['openbaar', 'beperkt_openbaar', 'intern'], $withDefault->admittedLabels('nonsense'));
	}

	public function testAnUndeclaredDefaultFallsBackToTheLowestLevel(): void {
		$this->assertSame('openbaar', self::zgw()->defaultClearance());
		$this->assertSame('openbaar', self::zgw(['default' => 'bogus'])->defaultClearance());
	}

	public function testFromBlockKeepsMappingsAndDropsJunk(): void {
		$scale = self::zgw(['clearances' => [['group' => 'a', 'level' => 'intern'], 'x']]);
		$this->assertSame([['group' => 'a', 'level' => 'intern']], $scale->clearances());
		$this->assertSame([], self::zgw()->clearances());
	}
}
