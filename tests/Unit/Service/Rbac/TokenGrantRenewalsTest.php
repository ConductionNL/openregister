<?php

/**
 * Renewing a grant is recorded: a changed end date appends one entry, nothing else does.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use OCA\OpenRegister\Service\Rbac\TokenGrantRenewals;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\TokenGrantRenewals
 */
final class TokenGrantRenewalsTest extends TestCase {

	private const AT = '2026-11-17T10:00:00+00:00';

	public function testAChangedEndDateAppendsOneRenewal(): void {
		$renewals = TokenGrantRenewals::after(
			previousGrant: ['verbs' => ['read'], 'expiresAt' => '2026-12-01T00:00:00+00:00'],
			grant: ['verbs' => ['read'], 'expiresAt' => '2027-03-01T00:00:00+00:00'],
			renewals: [],
			actor: 'admin',
			at: self::AT
		);

		$this->assertSame(
			[['by' => 'admin', 'at' => self::AT, 'from' => '2026-12-01T00:00:00+00:00', 'expiresAt' => '2027-03-01T00:00:00+00:00']],
			$renewals
		);
	}

	public function testAnUnchangedEndDateKeepsTheRenewalsAsTheyWere(): void {
		$earlier = [['by' => 'a', 'at' => 'x', 'from' => null, 'expiresAt' => '2026-12-01T00:00:00+00:00']];

		$renewals = TokenGrantRenewals::after(
			previousGrant: ['verbs' => ['read'], 'expiresAt' => '2026-12-01T00:00:00+00:00'],
			grant: ['verbs' => ['read', 'update'], 'expiresAt' => '2026-12-01T00:00:00+00:00'],
			renewals: $earlier,
			actor: 'admin',
			at: self::AT
		);

		$this->assertSame($earlier, $renewals);
	}

	public function testAFirstGrantIsAnIssueNotARenewal(): void {
		$renewals = TokenGrantRenewals::after(
			previousGrant: null,
			grant: ['verbs' => ['read'], 'expiresAt' => '2027-03-01T00:00:00+00:00'],
			renewals: null,
			actor: 'admin',
			at: self::AT
		);

		$this->assertSame([], $renewals);
	}

	public function testOnlyTheLastTwentyAreKeptAndDamagedEntriesAreDropped(): void {
		$earlier = ['not-an-entry'];
		for ($i = 0; $i < TokenGrantRenewals::MAX; $i++) {
			$earlier[] = ['by' => 'a', 'at' => (string) $i, 'from' => null, 'expiresAt' => null];
		}

		$renewals = TokenGrantRenewals::after(
			previousGrant: ['expiresAt' => '2026-12-01T00:00:00+00:00'],
			grant: ['expiresAt' => '2027-03-01T00:00:00+00:00'],
			renewals: $earlier,
			actor: 'admin',
			at: self::AT
		);

		$this->assertCount(TokenGrantRenewals::MAX, $renewals);
		$this->assertSame('1', $renewals[0]['at']);
		$this->assertSame(self::AT, $renewals[TokenGrantRenewals::MAX - 1]['at']);
	}
}
