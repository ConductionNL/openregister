<?php

/**
 * OAuth2TokenSetTest — the stored-secret value object of an OAuth2 connection.
 *
 * Pins the four properties the rest of the refresh machinery relies on:
 *   - a stored document round-trips without losing a field;
 *   - a refresh response that omits `refresh_token` KEEPS the previous one, which is
 *     what stops the first rotation being the last for most providers here;
 *   - `expires_in` is resolved against the exchange clock, not read later;
 *   - a document that is not a token set is refused with a message that quotes none
 *     of it.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
 */

declare(strict_types=1);

namespace Unit\Service\Credential;

use DateTimeImmutable;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Credential\OAuth2TokenSet;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2TokenSet
 */
class OAuth2TokenSetTest extends TestCase {
	public function testRoundTripsThroughTheStoredDocument(): void {
		$set = OAuth2TokenSet::fromTokenResponse(
			response: [
				'access_token' => 'ACCESS_TOKEN_HERE',
				'refresh_token' => 'REFRESH_TOKEN_HERE',
				'expires_in' => 3600,
				'token_type' => 'Bearer',
				'scope' => 'read:accounts write:statuses',
			],
			now: new DateTimeImmutable('2026-09-04T12:00:00+00:00')
		)->withAccount(id: '42', handle: '@example@mastodon.example', displayName: 'Example');

		$decoded = OAuth2TokenSet::fromStoredJson(stored: $set->toStoredJson());

		$this->assertSame('ACCESS_TOKEN_HERE', $decoded->getAccessToken());
		$this->assertSame('REFRESH_TOKEN_HERE', $decoded->getRefreshToken());
		$this->assertSame(['read:accounts', 'write:statuses'], $decoded->getScopes());
		$this->assertSame('@example@mastodon.example', $decoded->getAccount()['handle']);
		$this->assertSame('2026-09-04T13:00:00+00:00', $decoded->getExpiresAt()?->format(DATE_ATOM));
	}

	public function testARefreshResponseWithoutARefreshTokenKeepsThePreviousOne(): void {
		$previous = OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => 'OLD_ACCESS', 'refresh_token' => 'KEEP_THIS_REFRESH', 'expires_in' => 60]
		);

		$rotated = OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => 'NEW_ACCESS', 'expires_in' => 3600],
			previous: $previous
		);

		$this->assertSame('NEW_ACCESS', $rotated->getAccessToken());
		$this->assertSame('KEEP_THIS_REFRESH', $rotated->getRefreshToken());
	}

	public function testARotationThatIssuesANewRefreshTokenUsesIt(): void {
		$previous = OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => 'OLD_ACCESS', 'refresh_token' => 'OLD_REFRESH', 'expires_in' => 60]
		);

		$rotated = OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 3600],
			previous: $previous
		);

		$this->assertSame('NEW_REFRESH', $rotated->getRefreshToken());
	}

	public function testTheMarginDecidesWhetherARefreshIsDue(): void {
		$now = new DateTimeImmutable('2026-09-04T12:00:00+00:00');
		$set = OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => 'ACCESS_TOKEN_HERE', 'expires_in' => 300],
			now: $now
		);

		$this->assertFalse($set->needsRefresh(marginSeconds: 120, now: $now));
		$this->assertTrue($set->needsRefresh(marginSeconds: 400, now: $now));
	}

	public function testASetWithNoDeclaredExpiryIsNeverRefreshedOnTheClock(): void {
		$set = OAuth2TokenSet::fromTokenResponse(response: ['access_token' => 'ACCESS_TOKEN_HERE']);

		$this->assertNull($set->getExpiresAt());
		$this->assertFalse($set->needsRefresh(marginSeconds: 86400));
	}

	public function testAStoredDocumentThatIsNotATokenSetIsRefusedWithoutQuotingIt(): void {
		$this->expectException(InvalidArgumentException::class);

		try {
			OAuth2TokenSet::fromStoredJson(stored: '{"nope": "SOME_SECRET_LOOKING_VALUE"}');
		} catch (InvalidArgumentException $refusal) {
			$this->assertStringNotContainsString('SOME_SECRET_LOOKING_VALUE', $refusal->getMessage());
			throw $refusal;
		}
	}

	public function testANonJsonDocumentIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		OAuth2TokenSet::fromStoredJson(stored: 'not json at all');
	}

	public function testATokenResponseWithoutAnAccessTokenIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		OAuth2TokenSet::fromTokenResponse(response: ['refresh_token' => 'REFRESH_TOKEN_HERE']);
	}

	public function testCommaSeparatedScopesAreAcceptedBecauseMetaSpellsThemThatWay(): void {
		$set = OAuth2TokenSet::fromTokenResponse(
			response: ['access_token' => 'ACCESS_TOKEN_HERE', 'scope' => 'pages_manage_posts,pages_read_engagement']
		);

		$this->assertSame(['pages_manage_posts', 'pages_read_engagement'], $set->getScopes());
	}
}
