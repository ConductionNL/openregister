<?php

/**
 * OAuth2NoTokenLeakTest — the token stays in custody, asserted rather than assumed.
 *
 * ADR-064's whole claim is that an app holds a credentialRef and never a secret. That
 * claim is only worth something if something fails when it stops being true, and the
 * ways it could stop being true are all quiet ones: a property added to the schema
 * because it was convenient, an event payload that carried the token so a listener
 * would not have to look it up, a catalogue entry marked inject-only by somebody who
 * did not know what that meant for this kind.
 *
 * None of those would break a test that only exercised the happy path, which is why
 * these are separate assertions about the SHAPE of things rather than about their
 * behaviour.
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

use OCA\OpenRegister\Event\CredentialRelinkRequiredEvent;
use OCA\OpenRegister\Service\Credential\OAuth2TokenSet;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \OCA\OpenRegister\Service\Credential\OAuth2TokenSet
 * @covers \OCA\OpenRegister\Event\CredentialRelinkRequiredEvent
 */
class OAuth2NoTokenLeakTest extends TestCase {
	/**
	 * The properties `brokeredcredential` declares, all of them non-secret.
	 *
	 * Written out rather than derived, so ADDING a property to the schema fails this
	 * test and makes somebody say out loud what the new property holds. A test that
	 * read the schema and checked the names against a pattern would pass for
	 * `accessToken` spelled `at`.
	 *
	 * @var array<int, string>
	 */
	private const DECLARED_PROPERTIES = [
		'account',
		'allowedApps',
		'clientCredentialRef',
		'clientId',
		'createdAt',
		'expiresAt',
		'instanceBaseUrl',
		'kind',
		'lastError',
		'lastRefreshedAt',
		'name',
		'organisation',
		'owner',
		'provider',
		'scope',
		'scopes',
		'sharedGroups',
		'sharedUsers',
		'sharedWith',
		'status',
	];

	public function testTheCredentialSchemaDeclaresNoPlaceATokenCouldBeStored(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../lib/Settings/credential_broker_register.json'),
			true
		);

		$properties = array_keys($register['components']['schemas']['brokeredcredential']['properties']);
		sort($properties);

		$this->assertSame(
			self::DECLARED_PROPERTIES,
			$properties,
			'a new property on brokeredcredential must be reviewed for whether it can hold a secret'
		);
	}

	public function testTheRelinkEventCarriesNoTokenAndNoClientSecret(): void {
		$event = new CredentialRelinkRequiredEvent(
			credentialId: 'credential-uuid',
			provider: 'mastodon',
			owner: 'alice',
			reason: 'invalid_grant'
		);

		$serialised = (string)json_encode(
			[
				'credentialId' => $event->getCredentialId(),
				'provider' => $event->getProvider(),
				'owner' => $event->getOwner(),
				'reason' => $event->getReason(),
			]
		);

		// Four accessors, and the class declares no fifth: a listener that wanted the
		// token would have to go and fetch it through the broker, which is the point.
		$accessors = array_filter(
			(new ReflectionClass(CredentialRelinkRequiredEvent::class))->getMethods(),
			static fn ($method): bool => str_starts_with($method->getName(), 'get')
		);

		$this->assertCount(4, $accessors);
		$this->assertStringNotContainsStringIgnoringCase('token', $serialised);
		$this->assertStringNotContainsStringIgnoringCase('secret', $serialised);
	}

	public function testATokenSetCannotBeStringifiedByAccident(): void {
		// A `__toString` is how a token ends up in a log line nobody wrote: an
		// interpolation into a message, a var dumped during an incident. There is no
		// safe implementation of it here, so there is none at all.
		$this->assertFalse(
			method_exists(OAuth2TokenSet::class, '__toString'),
			'a token set must not be stringifiable; the only way out is toStoredJson()'
		);
		$this->assertFalse(
			is_a(OAuth2TokenSet::class, \JsonSerializable::class, true),
			'a token set must not serialise itself into a response by being handed to json_encode'
		);
	}

	public function testTheOnlyWayOutOfATokenSetIsNamedForCustody(): void {
		$set = OAuth2TokenSet::fromTokenResponse(
			response: [
				'access_token' => 'ACCESS_TOKEN_HERE',
				'refresh_token' => 'REFRESH_TOKEN_HERE',
				'expires_in' => 3600,
			],
			requestedScopes: ['read:accounts']
		);

		// The method a caller reaches for when it wants the whole document is called
		// toStoredJson, not toArray or jsonSerialize, because its one destination is
		// the custody leaf. A name that read like an ordinary serialiser would invite
		// exactly the call this test exists to prevent.
		$this->assertStringContainsString('ACCESS_TOKEN_HERE', $set->toStoredJson());
		$this->assertSame(['read:accounts'], $set->getScopes());

		// A set that has not been told whose account it is says so with empty
		// strings rather than by omitting the keys, so a consumer never has to
		// distinguish "no account" from "malformed".
		$this->assertSame(['id' => '', 'handle' => '', 'displayName' => ''], $set->getAccount());
	}
}
