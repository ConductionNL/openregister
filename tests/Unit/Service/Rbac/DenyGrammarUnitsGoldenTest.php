<?php

/**
 * Golden test for the deny-grammar and token-narrowing units split for size.
 *
 * Pins what AuthorizationDenyValidator refuses and admits for user-named
 * entries, and which keys TokenGrantNarrower closes for a token: verbs it does
 * not permit are closed, control keys and its own marker never are.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Rbac;

use DateTimeImmutable;
use OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator;
use OCA\OpenRegister\Service\Rbac\DenyEntryMatcher;
use OCA\OpenRegister\Service\Rbac\DenyResolver;
use OCA\OpenRegister\Service\Rbac\TokenGrant;
use OCA\OpenRegister\Service\Rbac\TokenGrantTerms;
use OCA\OpenRegister\Service\Rbac\TokenGrantNarrower;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Rbac\AuthorizationDenyValidator
 * @covers \OCA\OpenRegister\Service\Rbac\DenyEntryMatcher
 * @covers \OCA\OpenRegister\Service\Rbac\TokenGrantNarrower
 */
final class DenyGrammarUnitsGoldenTest extends TestCase {

	/**
	 * A user granted and denied the same verb is refused, however the user is spelled.
	 *
	 * @return void
	 */
	public function testAUserGrantedAndDeniedTheSameVerbIsRefused(): void {
		$validator = new AuthorizationDenyValidator(denyResolver: new DenyResolver(matcher: new DenyEntryMatcher()));

		$refused = $validator->findings(
			authorization: ['update' => [['user' => 'bob'], 'g1'], 'deny' => ['update' => ['user:bob']]],
			subject: 'the schema "zaak"'
		);
		$this->assertCount(1, $refused);
		$this->assertStringContainsString('"user:bob"', $refused[0]);

		$this->assertSame(
			[],
			$validator->findings(authorization: ['update' => [['user' => 'bob']], 'deny' => ['update' => ['user:ann', ['user' => '']]]], subject: 'the schema "zaak"')
		);
	}

	/**
	 * Denying manage to the only user holding it is refused; leaving another holder is admitted.
	 *
	 * @return void
	 */
	public function testDenyingManageToTheLastUserIsRefused(): void {
		$validator = new AuthorizationDenyValidator(denyResolver: new DenyResolver(matcher: new DenyEntryMatcher()));

		$refused = $validator->findings(authorization: ['manage' => [['user' => 'bob']], 'deny' => ['manage' => [['user' => 'bob']]]], subject: 'the register "r"');
		$this->assertCount(2, $refused, 'the collision and the orphaned manage right');
		$this->assertStringContainsString('leave no principal holding it', $refused[1]);
		$this->assertSame(
			[],
			$validator->findings(authorization: ['manage' => ['beheer', ['user' => 'ann']], 'deny' => ['manage' => [['user' => 'bob']]]], subject: 'the register "r"')
		);
	}

	/**
	 * A token closes the verbs it does not permit and leaves control keys and its marker alone.
	 *
	 * @return void
	 */
	public function testATokenClosesOnlyTheVerbsItDoesNotPermit(): void {
		$narrowed = (new TokenGrantNarrower())->narrow(
			authorization: [
				'read' => ['g1'],
				'update' => ['g1'],
				'roles' => ['editor' => ['g1']],
				'deny' => ['update' => ['g2']],
				TokenGrantNarrower::MARKER => ['verbs' => ['update']],
				7 => ['x'],
			],
			grant: new TokenGrant(verbs: ['read'], terms: new TokenGrantTerms(expiresAt: new DateTimeImmutable('2026-06-01T00:00:00+00:00')), tokenId: 't1'),
			schemaSlug: 'zaak',
			registerSlug: 'r',
			now: new DateTimeImmutable('2026-01-01T00:00:00+00:00')
		);

		$closed = [TokenGrantNarrower::IMPOSSIBLE];
		$this->assertSame(
			[
				'read' => ['g1'],
				'update' => $closed,
				'roles' => ['editor' => ['g1']],
				'deny' => ['update' => ['g2']],
				TokenGrantNarrower::MARKER => ['verbs' => ['read'], 'tokenId' => 't1', 'expired' => false, 'inScope' => true],
				7 => $closed,
			],
			$narrowed
		);
	}
}
