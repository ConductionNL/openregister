<?php

/**
 * Golden test for LinkExposure::refusalFor().
 *
 * Pins which declared exposures are accepted and the exact refusal of each
 * one that is not, ahead of a unit-size refactor: both what is allowed and
 * what is refused.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Relation
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Relation;

use OCA\OpenRegister\Service\Relation\LinkExposure;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden pins for the save-time exposure refusal.
 */
class LinkExposureRefusalGoldenTest extends TestCase {

	/**
	 * Declarations with their pinned refusal, or null when accepted.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string|null}>
	 */
	public static function declarationProvider(): array {
		$missing = 'relation type "t" exposes "%s", which the linked schema does not declare';
		return [
			'no exposure key'        => [['label' => 'x'], null],
			'exposure not a list'    => [['exposes' => 'name'], null],
			'exposure null'          => [['exposes' => null], null],
			'empty exposure'         => [['exposes' => []], null],
			'all declared'           => [['exposes' => ['name', 'code']], null],
			'declared in other order' => [['exposes' => ['code', 'name']], null],
			'one undeclared'         => [['exposes' => ['name', 'secret']], sprintf($missing, 'secret')],
			'first undeclared wins'  => [['exposes' => ['nope', 'secret']], sprintf($missing, 'nope')],
			'unnamed'                => [['exposes' => ['']], 'relation type "t" exposes an unnamed property'],
			'unnamed after missing'  => [['exposes' => ['x', '']], sprintf($missing, 'x')],
			'missing after unnamed'  => [['exposes' => ['', 'x']], 'relation type "t" exposes an unnamed property'],
			'numeric name declared'  => [['exposes' => [7]], null],
			'numeric name missing'   => [['exposes' => [8]], sprintf($missing, '8')],
			'null entry'             => [['exposes' => [null]], 'relation type "t" exposes an unnamed property'],
			'case differs'           => [['exposes' => ['Name']], sprintf($missing, 'Name')],
		];
	}//end declarationProvider()

	/**
	 * Every declaration is accepted or refused exactly as pinned.
	 *
	 * @param array<string, mixed> $relationType The relation type descriptor.
	 * @param string|null          $expected     The pinned refusal.
	 *
	 * @return void
	 */
	#[DataProvider('declarationProvider')]
	public function testRefusalMatchesThePin(array $relationType, ?string $expected): void {
		$refusal = (new LinkExposure())->refusalFor(relationType: $relationType, farProperties: ['name', 'code', '7'], typeName: 't');

		$this->assertSame($expected, $refusal);
	}//end testRefusalMatchesThePin()
}//end class
