<?php

/**
 * The openconnector HITL retirement inventory is COMPLETE: every property
 * the approval_request schema declares names a home or an explicit decision
 * not to carry it. A property with no entry blocks the runner's retirement
 * (flow-approval-consolidation task 7.1).
 *
 * The schema fragment is a checked-in snapshot beside the inventory, so a
 * change to openconnector's declaration lands here as a fixture update that
 * this test then judges — never as silent drift.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Contract
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Contract;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing The subject is the checked-in retirement contract itself.
 */
class HitlRetirementInventoryTest extends TestCase {

	private const FIXTURES = __DIR__ . '/../../fixtures/approval-consolidation';

	/**
	 * @return array<string, mixed> A decoded fixture.
	 */
	private function fixture(string $name): array {
		$decoded = json_decode((string)file_get_contents(self::FIXTURES . '/' . $name), true);
		self::assertIsArray($decoded, $name . ' must decode');

		return $decoded;
	}//end fixture()

	public function testEveryDeclaredPropertyHasAHomeOrARecordedDecision(): void {
		$schema = $this->fixture('hitl-approval-rule-action.json');
		$declared = array_keys($schema['components']['schemas']['approval_request']['properties']);
		$inventory = $this->fixture('hitl-retirement-inventory.json')['properties'];

		$homeless = [];
		foreach ($declared as $property) {
			$entry = ($inventory[$property] ?? null);
			$named = is_array($entry) === true
				&& (trim((string)($entry['home'] ?? '')) !== '' || trim((string)($entry['decision'] ?? '')) !== '')
				&& trim((string)($entry['owner'] ?? '')) !== '';
			if ($named === false) {
				$homeless[] = $property;
			}
		}

		self::assertSame(
			[],
			$homeless,
			'These approval_request properties have no named home and no recorded decision; '
			. 'the openconnector runner cannot be retired while any of them is homeless.'
		);
	}//end testEveryDeclaredPropertyHasAHomeOrARecordedDecision()

	public function testTheInventoryNamesNoPropertyTheSchemaLacks(): void {
		$schema = $this->fixture('hitl-approval-rule-action.json');
		$declared = array_keys($schema['components']['schemas']['approval_request']['properties']);
		$inventory = array_keys($this->fixture('hitl-retirement-inventory.json')['properties']);

		self::assertSame([], array_values(array_diff($inventory, $declared)), 'a stale inventory entry maps nothing');
	}//end testTheInventoryNamesNoPropertyTheSchemaLacks()

	public function testTheCriticalSemanticsAreCoveredByName(): void {
		$inventory = $this->fixture('hitl-retirement-inventory.json')['properties'];

		foreach (['approverGroup', 'requesterUserId', 'comment', 'expiresAt', 'onTimeout', 'onReject', 'consumedAt'] as $semantic) {
			self::assertArrayHasKey($semantic, $inventory, $semantic . ' is one of the six semantics the spec requires at minimum');
			self::assertNotSame('', trim((string)($inventory[$semantic]['home'] ?? '')), $semantic . ' must have a HOME, not a drop decision');
		}
	}//end testTheCriticalSemanticsAreCoveredByName()

	public function testTheRetiredSurfaceNamesRoutesEventsAndShapes(): void {
		$surface = $this->fixture('retired-approval-surface.json');

		self::assertCount(4, $surface['retiredEventClasses']);
		self::assertContains('/api/approval-steps/{id}/approve', $surface['retiredRoutes']);
		self::assertContains('approval-chain-pending', $surface['keptErrorCodes']);
		$rules = array_column($surface['antiPatternShapes'], 'rule');
		foreach (['no-own-step-engine', 'no-stored-overdue', 'no-flow-definition-mirror'] as $rule) {
			self::assertContains($rule, $rules);
		}
	}//end testTheRetiredSurfaceNamesRoutesEventsAndShapes()
}//end class
