<?php

/**
 * Unit tests for ReadRuleTenancy.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/rbac-cross-organisation-is-explicit/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\ReadRuleTenancy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Tests the tenancy reading of read rules.
 *
 * @covers \OCA\OpenRegister\Service\Rbac\ReadRuleTenancy
 */
class ReadRuleTenancyTest extends TestCase {

	/**
	 * Rules and whether they carry the marker.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function markerCases(): array {
		return [
			'boolean true'        => [['group' => 'g', 'crossOrganisation' => true], true],
			'boolean false'       => [['group' => 'g', 'crossOrganisation' => false], false],
			'string true'         => [['group' => 'g', 'crossOrganisation' => 'true'], false],
			'integer one'         => [['group' => 'g', 'crossOrganisation' => 1], false],
			'nested in the match' => [['group' => 'g', 'match' => ['crossOrganisation' => true]], false],
			'absent'              => [['group' => 'g'], false],
			'a plain string rule' => ['crossOrganisation', false],
		];

	}//end markerCases()

	/**
	 * Only the boolean `true` is a marker.
	 *
	 * @param mixed $rule     The rule.
	 * @param bool  $expected Whether it is marked.
	 *
	 * @return void
	 */
	#[DataProvider('markerCases')]
	public function testOnlyBooleanTrueIsAMarker(mixed $rule, bool $expected): void {
		$this->assertSame(expected: $expected, actual: (new ReadRuleTenancy())->isMarked(rule: $rule));

	}//end testOnlyBooleanTrueIsAMarker()

	/**
	 * Read rule lists and whether `public` reads every row.
	 *
	 * @return array<string, array{0: mixed, 1: bool}>
	 */
	public static function publicCases(): array {
		return [
			'plain public'                 => [['hr', 'public'], true],
			'object public without match'  => [[['group' => 'public']], true],
			'marked public without match'  => [[['group' => 'public', 'crossOrganisation' => true]], true],
			'public with an empty match'   => [[['group' => 'public', 'match' => []]], true],
			'public with a null match'     => [[['group' => 'public', 'match' => null]], true],
			'public with a match'          => [[['group' => 'public', 'match' => ['status' => 'open']]], false],
			'another group'                => [['hr', ['group' => 'authenticated']], false],
			'no rules'                     => [[], false],
			'not a list'                   => ['public', false],
		];

	}//end publicCases()

	/**
	 * A plain or matchless `public` rule reads every row; a conditional one does not.
	 *
	 * @param mixed $rules    The read rules.
	 * @param bool  $expected Whether `public` reads unconditionally.
	 *
	 * @return void
	 */
	#[DataProvider('publicCases')]
	public function testReadsPublicUnconditionally(mixed $rules, bool $expected): void {
		$this->assertSame(expected: $expected, actual: (new ReadRuleTenancy())->readsPublicUnconditionally(readRules: $rules));

	}//end testReadsPublicUnconditionally()

	/**
	 * Matches and whether they bind a row field to the caller's tenancy.
	 *
	 * @return array<string, array{0: array, 1: bool}>
	 */
	public static function bindCases(): array {
		return [
			'provider is the organisation'      => [['match' => ['provider' => '$organisation']], true],
			'active organisation uuid'          => [['match' => ['provider' => '$activeOrganisation.uuid']], true],
			'$eq the organisation'              => [['match' => ['provider' => ['$eq' => '$organisation']]], true],
			'membership set'                    => [['match' => ['team' => ['$memberOf' => ['schema' => 'm', 'field' => 'a']]]], true],
			'_organisation is the organisation' => [['match' => ['_organisation' => '$organisation']], false],
			'_organisation membership set'      => [['match' => ['_organisation' => ['$memberOf' => ['schema' => 'm']]]], false],
			'$eq beside another operator'       => [['match' => ['provider' => ['$eq' => '$organisation', '$ne' => 'x']]], false],
			'$in with the token'                => [['match' => ['provider' => ['$in' => ['$organisation']]]], false],
			'a literal organisation'            => [['match' => ['provider' => 'org-1']], false],
			'the caller uid'                    => [['match' => ['assignee' => '$userId']], false],
			'no match'                          => [['group' => 'g'], false],
			'a match that is not a list'        => [['match' => 'open'], false],
		];

	}//end bindCases()

	/**
	 * Only equality with the caller's organisation or membership on a field
	 * other than `_organisation` binds.
	 *
	 * @param array $rule     The rule.
	 * @param bool  $expected Whether it binds.
	 *
	 * @return void
	 */
	#[DataProvider('bindCases')]
	public function testBindsFieldToCallerTenancy(array $rule, bool $expected): void {
		$this->assertSame(expected: $expected, actual: (new ReadRuleTenancy())->bindsFieldToCallerTenancy(rule: $rule));

	}//end testBindsFieldToCallerTenancy()
}//end class
