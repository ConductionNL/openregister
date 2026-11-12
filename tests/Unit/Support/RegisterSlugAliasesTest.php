<?php

/**
 * The declared slug history of renamed fleet registers: the candidates a
 * canonical slug answers to, and the superseded slugs mapped to their
 * replacement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Support;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.

use OCA\OpenRegister\Support\RegisterSlugAliases;
use PHPUnit\Framework\TestCase;

/**
 * RegisterSlugAliases.
 *
 * @covers \OCA\OpenRegister\Support\RegisterSlugAliases
 */
class RegisterSlugAliasesTest extends TestCase {

	public function testARenamedRegisterAnswersToItsHistoryNewestFirst(): void {
		$this->assertSame(['stackiq', 'voorzieningen'], RegisterSlugAliases::candidatesFor('stackiq'));
		$this->assertSame(['dossiq-default', 'procest-default'], RegisterSlugAliases::candidatesFor('dossiq-default'));
	}//end testARenamedRegisterAnswersToItsHistoryNewestFirst()

	public function testTheCanonicalSlugIsNormalised(): void {
		$this->assertSame(['integriq', 'openconnector'], RegisterSlugAliases::candidatesFor('  IntegrIQ '));
	}//end testTheCanonicalSlugIsNormalised()

	public function testASlugWithoutARenameAnswersToItselfOnly(): void {
		$this->assertSame(['publications'], RegisterSlugAliases::candidatesFor(' Publications'));
	}//end testASlugWithoutARenameAnswersToItselfOnly()

	public function testABlankSlugHasNoCandidates(): void {
		$this->assertSame([], RegisterSlugAliases::candidatesFor('   '));
		$this->assertSame([], RegisterSlugAliases::candidatesFor(''));
	}//end testABlankSlugHasNoCandidates()

	public function testAnOldSlugIsNotItselfACanonicalSlug(): void {
		$this->assertSame(['voorzieningen'], RegisterSlugAliases::candidatesFor('voorzieningen'));
	}//end testAnOldSlugIsNotItselfACanonicalSlug()

	public function testEverySupersededSlugMapsToItsReplacement(): void {
		$this->assertSame(
			[
				'openconnector' => 'integriq',
				'openbuild' => 'buildiq',
				'decidesk' => 'decidiq',
				'hrmq' => 'humaniq',
				'larpingapp' => 'larpinq',
				'planix' => 'planninq',
				'voorzieningen' => 'stackiq',
				'procest' => 'dossiq',
				'procest-default' => 'dossiq-default',
				'scholiq' => 'learniq',
			],
			RegisterSlugAliases::supersededSlugs()
		);
	}//end testEverySupersededSlugMapsToItsReplacement()

	public function testNoCanonicalSlugIsReportedAsSuperseded(): void {
		$superseded = RegisterSlugAliases::supersededSlugs();
		foreach (array_unique(array_values($superseded)) as $canonical) {
			$this->assertArrayNotHasKey($canonical, $superseded);
		}
	}//end testNoCanonicalSlugIsReportedAsSuperseded()
}//end class
