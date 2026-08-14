<?php

/**
 * Tests for CaseTypeAuthorizationService — the configuration + ZGW mapping layer
 * for zaaktype-scoped authorization (rbac-zaaktype).
 *
 * Verifies vertrouwelijkheidaanduiding ordinal ordering, clearance comparison,
 * `$in` match-clause construction, ZGW Autorisaties → authorization-block
 * mapping, and permission-matrix extraction. These are pure functions: no
 * Nextcloud dependencies are required.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

namespace Unit\Service;

use OCA\OpenRegister\Service\CaseTypeAuthorizationService;
use PHPUnit\Framework\TestCase;

/**
 * VNG-compliance unit coverage for the zaaktype authorization config layer.
 */
class CaseTypeAuthorizationServiceTest extends TestCase {
	private CaseTypeAuthorizationService $service;

	protected function setUp(): void {
		$this->service = new CaseTypeAuthorizationService();
	}

	// === Vertrouwelijkheidaanduiding ordinal ordering ===

	public function testConfidentialityOrdinalsAreInZgwOrder(): void {
		$this->assertSame(0, $this->service->confidentialityOrdinal('openbaar'));
		$this->assertSame(2, $this->service->confidentialityOrdinal('intern'));
		$this->assertSame(3, $this->service->confidentialityOrdinal('zaakvertrouwelijk'));
		$this->assertSame(7, $this->service->confidentialityOrdinal('zeer_geheim'));
	}

	public function testUnknownConfidentialityLevelHasNoOrdinal(): void {
		$this->assertNull($this->service->confidentialityOrdinal('nonsense'));
	}

	public function testLevelsAtOrBelowIsInclusiveAndOrdered(): void {
		$this->assertSame(
			['openbaar', 'beperkt_openbaar', 'intern'],
			$this->service->levelsAtOrBelow('intern')
		);
	}

	public function testLevelsAtOrBelowUnknownFailsClosedToEmpty(): void {
		// An unknown clearance grants nothing.
		$this->assertSame([], $this->service->levelsAtOrBelow('nonsense'));
	}

	// === Clearance comparison (the audit-denial decision) ===

	public function testAccessibleAtClearanceAllowsEqualAndBelow(): void {
		$this->assertTrue($this->service->isAccessibleAtClearance('intern', 'openbaar'));
		$this->assertTrue($this->service->isAccessibleAtClearance('intern', 'intern'));
	}

	public function testAccessibleAtClearanceDeniesAbove(): void {
		// kcc cleared to 'intern' must NOT see 'vertrouwelijk'.
		$this->assertFalse($this->service->isAccessibleAtClearance('intern', 'vertrouwelijk'));
		$this->assertFalse($this->service->isAccessibleAtClearance('intern', 'zeer_geheim'));
	}

	public function testAccessibleAtClearanceFailsClosedOnUnknownLevels(): void {
		$this->assertFalse($this->service->isAccessibleAtClearance('nonsense', 'openbaar'));
		$this->assertFalse($this->service->isAccessibleAtClearance('intern', 'nonsense'));
	}

	// === $in match-clause construction ===

	public function testBuildConfidentialityMatchUsesInOperator(): void {
		$match = $this->service->buildConfidentialityMatch('intern');
		$this->assertSame(
			['vertrouwelijkheidaanduiding' => ['$in' => ['openbaar', 'beperkt_openbaar', 'intern']]],
			$match
		);
	}

	public function testBuildConfidentialityMatchHonoursCustomProperty(): void {
		$match = $this->service->buildConfidentialityMatch('openbaar', 'classification');
		$this->assertSame(['classification' => ['$in' => ['openbaar']]], $match);
	}

	// === ZGW scope → action mapping ===

	public function testScopeToActionMapsAllFourVerbs(): void {
		$this->assertSame('read', $this->service->scopeToAction('zaken.lezen'));
		$this->assertSame('create', $this->service->scopeToAction('zaken.aanmaken'));
		$this->assertSame('update', $this->service->scopeToAction('zaken.bijwerken'));
		$this->assertSame('delete', $this->service->scopeToAction('zaken.verwijderen'));
	}

	public function testScopeToActionFailsClosedOnUnknownSuffix(): void {
		$this->assertNull($this->service->scopeToAction('zaken.toveren'));
	}

	// === ZGW Autorisatie → authorization block ===

	public function testMapZgwAutorisatieWithoutClearanceProducesBareGroupRules(): void {
		$block = $this->service->mapZgwAutorisatie(
			['zaken.lezen', 'zaken.aanmaken'],
			'zaaksysteem-1'
		);

		$this->assertSame([
			'read' => ['zaaksysteem-1'],
			'create' => ['zaaksysteem-1'],
		], $block);
	}

	public function testMapZgwAutorisatieWithClearanceProducesConditionalRules(): void {
		$block = $this->service->mapZgwAutorisatie(
			['zaken.lezen'],
			'zaaksysteem-1-lezen',
			'zaakvertrouwelijk'
		);

		$this->assertSame([
			'read' => [
				[
					'group' => 'zaaksysteem-1-lezen',
					'match' => [
						'vertrouwelijkheidaanduiding' => [
							'$in' => ['openbaar', 'beperkt_openbaar', 'intern', 'zaakvertrouwelijk'],
						],
					],
				],
			],
		], $block);
	}

	public function testMapZgwAutorisatieSkipsUnknownScopes(): void {
		$block = $this->service->mapZgwAutorisatie(
			['zaken.lezen', 'zaken.toveren', ''],
			'g'
		);

		$this->assertSame(['read' => ['g']], $block);
	}

	// === Permission-matrix extraction ===

	public function testExtractPermissionMatrixClassifiesEntries(): void {
		$rows = $this->service->extractPermissionMatrix([
			'read' => ['kcc-team', 'user:extern-adviseur'],
			'update' => [['group' => 'behandelaars', 'match' => ['_organisation' => '$organisation']]],
			// Non-action keys must be ignored.
			'inheritFromPublic' => false,
		]);

		$this->assertCount(3, $rows);

		$this->assertContains(
			['action' => 'read', 'principal' => 'kcc-team', 'kind' => 'group', 'conditional' => false],
			$rows
		);
		$this->assertContains(
			['action' => 'read', 'principal' => 'user:extern-adviseur', 'kind' => 'user', 'conditional' => false],
			$rows
		);
		$this->assertContains(
			['action' => 'update', 'principal' => 'behandelaars', 'kind' => 'group', 'conditional' => true],
			$rows
		);
	}

	public function testExtractPermissionMatrixEmptyAuthorization(): void {
		$this->assertSame([], $this->service->extractPermissionMatrix(null));
		$this->assertSame([], $this->service->extractPermissionMatrix([]));
	}
}
