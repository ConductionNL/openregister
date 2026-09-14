<?php

/**
 * Unit tests for ConflictPolicy — the decision an import takes on a conflict.
 *
 * Covers the four policies against the three match counts that exist (none,
 * one, more than one), the rule that survives every policy (a row matching
 * two objects is refused naming both), and the promise to callers that
 * predate policies: declaring none keeps the upsert behaviour the import path
 * already had.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Import
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/import-preview-and-conflict-policy/specs/data-import-export/spec.md
 */

declare(strict_types=1);

namespace Unit\Service\Import;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use InvalidArgumentException;
use OCA\OpenRegister\Db\ImportPreviewRow;
use OCA\OpenRegister\Service\Import\ConflictPolicy;
use PHPUnit\Framework\TestCase;

final class ConflictPolicyTest extends TestCase {

	public function testAnImportDeclaringNoPolicyKeepsUpsert(): void {
		$this->assertSame(ConflictPolicy::UPSERT, ConflictPolicy::resolve(null));
		$this->assertSame(ConflictPolicy::UPSERT, ConflictPolicy::resolve(''));
		$this->assertSame(ConflictPolicy::UPSERT, ConflictPolicy::DEFAULT_POLICY);
	}

	public function testAnUnknownPolicyIsRefusedRatherThanIgnored(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessageMatches('/merge-please/');

		ConflictPolicy::resolve('merge-please');
	}

	public function testCreateOnlyRefusesAnUnexpectedMatch(): void {
		$decision = ConflictPolicy::decide(ConflictPolicy::CREATE_ONLY, ['object-a']);

		$this->assertSame(ImportPreviewRow::DECISION_REFUSE, $decision['decision']);
		$this->assertStringContainsString('object-a', (string)$decision['reason']);
		$this->assertNull($decision['targetUuid']);
	}

	public function testCreateOnlyCreatesWhenNothingMatches(): void {
		$decision = ConflictPolicy::decide(ConflictPolicy::CREATE_ONLY, []);

		$this->assertSame(ImportPreviewRow::DECISION_CREATE, $decision['decision']);
	}

	public function testUpdateOnlySkipsARowThatMatchesNothing(): void {
		$decision = ConflictPolicy::decide(ConflictPolicy::UPDATE_ONLY, []);

		$this->assertSame(ImportPreviewRow::DECISION_SKIP, $decision['decision']);
		$this->assertStringContainsString('update-only', (string)$decision['reason']);
	}

	public function testUpdateOnlyUpdatesTheObjectItMatched(): void {
		$decision = ConflictPolicy::decide(ConflictPolicy::UPDATE_ONLY, ['object-b']);

		$this->assertSame(ImportPreviewRow::DECISION_UPDATE, $decision['decision']);
		$this->assertSame('object-b', $decision['targetUuid']);
	}

	public function testUpsertCreatesWhenUnmatchedAndUpdatesWhenMatched(): void {
		$this->assertSame(
			ImportPreviewRow::DECISION_CREATE,
			ConflictPolicy::decide(ConflictPolicy::UPSERT, [])['decision']
		);
		$this->assertSame(
			ImportPreviewRow::DECISION_UPDATE,
			ConflictPolicy::decide(ConflictPolicy::UPSERT, ['object-c'])['decision']
		);
	}

	public function testRefuseOnConflictRefusesAMatchAndCreatesOtherwise(): void {
		$refused = ConflictPolicy::decide(ConflictPolicy::REFUSE_ON_CONFLICT, ['object-d']);

		$this->assertSame(ImportPreviewRow::DECISION_REFUSE, $refused['decision']);
		$this->assertStringContainsString('object-d', (string)$refused['reason']);

		$this->assertSame(
			ImportPreviewRow::DECISION_CREATE,
			ConflictPolicy::decide(ConflictPolicy::REFUSE_ON_CONFLICT, [])['decision']
		);
	}

	/**
	 * The rule that outranks the policy: two matches is never one match.
	 * Asserted for every policy, because "under upsert" would leave the other
	 * three free to pick a candidate.
	 */
	public function testARowMatchingTwoObjectsIsRefusedUnderEveryPolicy(): void {
		foreach (ConflictPolicy::POLICIES as $policy) {
			$decision = ConflictPolicy::decide($policy, ['object-e', 'object-f']);

			$this->assertSame(
				ImportPreviewRow::DECISION_REFUSE,
				$decision['decision'],
				'Policy '.$policy.' resolved an ambiguous row instead of refusing it'
			);
			$this->assertStringContainsString('object-e', (string)$decision['reason']);
			$this->assertStringContainsString('object-f', (string)$decision['reason']);
			$this->assertNull($decision['targetUuid']);
		}
	}
}
