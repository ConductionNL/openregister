<?php

/**
 * OAuth2TokenSet — the stored secret of an `oauth2-token-set` credential.
 *
 * An immutable value object holding a whole OAuth2 token set: the access token,
 * the refresh token when one was issued, the absolute expiry, the token type, the
 * granted scopes, the provider account identity, and the raw token response the
 * provider returned. It is serialised to ONE opaque document and handed to
 * {@see CredentialStore} as a single value, which is what makes rotation atomic —
 * a `put` either lands the whole new set or leaves the whole old one, with no
 * window in which an access token and a refresh token disagree.
 *
 * It carries no `__toString()` and no `jsonSerialize()` on purpose: the only way
 * out is {@see toStoredJson()}, whose one legitimate destination is the custody
 * leaf. The token values MUST NEVER be logged, persisted to an OpenRegister
 * object, exported, or returned in any API response (the CredentialStore
 * contract, ADR-064 rule 1).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use DateTimeImmutable;
use InvalidArgumentException;
use JsonException;

/**
 * Immutable OAuth2 token set: the whole secret of one connected account.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) The two entry points are NAMED CONSTRUCTORS.
 * A value object whose constructor is private has no instance to call them on, and
 * turning them into an injected factory would put the one class that must never be
 * mocked away behind a seam a test could replace.
 */
final class OAuth2TokenSet {
	/**
	 * Constructor. Private: instances come from the two named constructors only.
	 *
	 * @param OAuth2Tokens   $tokens   The access and refresh tokens, their type and expiry.
	 * @param OAuth2Issuance $issuance The granted scopes, the account, the raw response and when it was issued.
	 *
	 * @return void
	 */
	private function __construct(
		private readonly OAuth2Tokens $tokens,
		private readonly OAuth2Issuance $issuance,
	) {
	}//end __construct()

	/**
	 * Decode a token set previously written to the custody leaf.
	 *
	 * @param string $stored The document {@see toStoredJson()} produced.
	 *
	 * @return self The decoded token set.
	 *
	 * @throws InvalidArgumentException When the document is not decodable into a token set. The message names the
	 *                                  failure and NEVER quotes any part of the stored value.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public static function fromStoredJson(string $stored): self {
		$decoded = self::decodeStored(stored: $stored);
		$obtainedAt = self::parseAtom(value: ($decoded['obtainedAt'] ?? null));

		return new self(
			tokens: new OAuth2Tokens(
				accessToken: (string)$decoded['accessToken'],
				refreshToken: self::nullableString(value: ($decoded['refreshToken'] ?? null)),
				tokenType: (self::nullableString(value: ($decoded['tokenType'] ?? null)) ?? 'Bearer'),
				expiresAt: self::parseAtom(value: ($decoded['expiresAt'] ?? null))
			),
			issuance: new OAuth2Issuance(
				scopes: self::stringList(value: ($decoded['scopes'] ?? [])),
				account: self::accountOf(value: ($decoded['account'] ?? [])),
				raw: self::arrayOf(value: ($decoded['raw'] ?? null)),
				obtainedAt: ($obtainedAt ?? new DateTimeImmutable())
			)
		);
	}//end fromStoredJson()

	/**
	 * Build a token set from a provider's token or refresh response.
	 *
	 * `expires_in` is resolved against the options' clock rather than against the wall clock at
	 * use time, so a slow exchange does not inflate the credential's remaining life.
	 * When the response omits `refresh_token` the previous set's refresh token is
	 * kept: most providers here rotate the access token only, and dropping the
	 * refresh token would turn every such rotation into the last one.
	 *
	 * @param array<string, mixed> $response The decoded token response.
	 * @param TokenResponseOptions $options  The scopes to record when the response names none, the set being replaced
	 *                                       (for the refresh token and account fallbacks), and the clock.
	 *
	 * @return self The new token set.
	 *
	 * @throws InvalidArgumentException When the response carries no access token.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public static function fromResponse(array $response, TokenResponseOptions $options = new TokenResponseOptions()): self {
		$accessToken = self::requiredAccessToken(response: $response);
		$now = ($options->now ?? new DateTimeImmutable());
		$previous = $options->previous;

		return new self(
			tokens: new OAuth2Tokens(
				accessToken: $accessToken,
				refreshToken: (self::nullableString(value: ($response['refresh_token'] ?? null)) ?? $previous?->tokens->refreshToken),
				tokenType: (self::nullableString(value: ($response['token_type'] ?? null)) ?? 'Bearer'),
				expiresAt: self::expiryOf(expiresIn: ($response['expires_in'] ?? null), now: $now)
			),
			issuance: new OAuth2Issuance(
				scopes: self::grantedScopes(response: $response, requestedScopes: $options->requestedScopes, previous: $previous),
				account: self::accountOf(value: $previous?->issuance->account),
				raw: $response,
				obtainedAt: $now
			)
		);
	}//end fromResponse()

	/**
	 * Return a copy carrying the provider account identity.
	 *
	 * @param string $id The provider's own identifier for the account.
	 * @param string $handle The account handle as the provider spells it.
	 * @param string $displayName The account's display name.
	 *
	 * @return self A new token set; this one is unchanged.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public function withAccount(string $id, string $handle, string $displayName): self {
		return $this->withAccountOf(account: ['id' => $id, 'handle' => $handle, 'displayName' => $displayName]);
	}//end withAccount()

	/**
	 * Return a copy carrying the provider account identity.
	 *
	 * @param array{id: string, handle: string, displayName: string} $account The provider's identifier for the account,
	 *                                                                       its handle and its display name.
	 *
	 * @return self A new token set; this one is unchanged.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public function withAccountOf(array $account): self {
		return new self(
			tokens: $this->tokens,
			issuance: new OAuth2Issuance(
				scopes: $this->issuance->scopes,
				account: self::accountOf(value: $account),
				raw: $this->issuance->raw,
				obtainedAt: $this->issuance->obtainedAt
			)
		);
	}//end withAccountOf()

	/**
	 * Serialise the whole set for the custody leaf.
	 *
	 * The ONE legitimate destination for this string is {@see CredentialStore::put()}.
	 *
	 * @return string The stored document.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public function toStoredJson(): string {
		return (string)json_encode(
			[
				'accessToken' => $this->tokens->accessToken,
				'refreshToken' => $this->tokens->refreshToken,
				'tokenType' => $this->tokens->tokenType,
				'expiresAt' => $this->tokens->expiresAt?->format(DATE_ATOM),
				'scopes' => $this->issuance->scopes,
				'account' => $this->issuance->account,
				'raw' => $this->issuance->raw,
				'obtainedAt' => $this->issuance->obtainedAt->format(DATE_ATOM),
			]
		);
	}//end toStoredJson()

	/**
	 * The access token to inject as a bearer token.
	 *
	 * @return string The access token.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	public function getAccessToken(): string {
		return $this->tokens->accessToken;
	}//end getAccessToken()

	/**
	 * The refresh token, or null when the provider issued none.
	 *
	 * @return string|null The refresh token.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public function getRefreshToken(): ?string {
		return $this->tokens->refreshToken;
	}//end getRefreshToken()

	/**
	 * Absolute expiry, or null when the provider declared none.
	 *
	 * @return DateTimeImmutable|null The expiry.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	public function getExpiresAt(): ?DateTimeImmutable {
		return $this->tokens->expiresAt;
	}//end getExpiresAt()

	/**
	 * The scopes the provider granted.
	 *
	 * @return array<int, string> The granted scopes.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	public function getScopes(): array {
		return $this->issuance->scopes;
	}//end getScopes()

	/**
	 * The non-secret provider account identity.
	 *
	 * @return array<string, string> The `id`, `handle` and `displayName`.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	public function getAccount(): array {
		return $this->issuance->account;
	}//end getAccount()

	/**
	 * Whether this set must be refreshed before it is used.
	 *
	 * A set with no declared expiry never needs refreshing on the clock; a provider
	 * that issues no expiry is telling us it will fail the call instead, and guessing
	 * an expiry would refresh perfectly good tokens forever.
	 *
	 * @param integer $marginSeconds How far ahead of the expiry a refresh is due.
	 * @param DateTimeImmutable|null $now The clock, injectable for tests.
	 *
	 * @return boolean True when the margin has been crossed.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	public function needsRefresh(int $marginSeconds, ?DateTimeImmutable $now = null): bool {
		if ($this->tokens->expiresAt === null) {
			return false;
		}

		$now = ($now ?? new DateTimeImmutable());

		return ($this->tokens->expiresAt->getTimestamp() - $marginSeconds) <= $now->getTimestamp();
	}//end needsRefresh()

	/**
	 * Decode a stored document, refusing one that is not a token set.
	 *
	 * @param string $stored The stored document.
	 *
	 * @return array<string, mixed> The decoded document, carrying a string `accessToken`.
	 *
	 * @throws InvalidArgumentException When the document is not decodable into a token set. The message names the
	 *                                  failure and NEVER quotes any part of the stored value.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	private static function decodeStored(#[\SensitiveParameter] string $stored): array {
		try {
			$decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new InvalidArgumentException(message: 'stored token set is not valid JSON');
		}

		if (is_array($decoded) === false || is_string(($decoded['accessToken'] ?? null)) === false) {
			throw new InvalidArgumentException(message: 'stored token set carries no access token');
		}

		return $decoded;
	}//end decodeStored()

	/**
	 * The access token a token response must carry.
	 *
	 * @param array<string, mixed> $response The decoded token response.
	 *
	 * @return string The trimmed access token.
	 *
	 * @throws InvalidArgumentException When the response carries no access token.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	private static function requiredAccessToken(#[\SensitiveParameter] array $response): string {
		$accessToken = self::nullableString(value: ($response['access_token'] ?? null));
		if ($accessToken === null) {
			throw new InvalidArgumentException(message: 'token response carries no access token');
		}

		return $accessToken;
	}//end requiredAccessToken()

	/**
	 * Resolve `expires_in` against the exchange's own clock.
	 *
	 * @param mixed $expiresIn The declared lifetime in seconds.
	 * @param DateTimeImmutable $now When the set was issued.
	 *
	 * @return DateTimeImmutable|null The absolute expiry, or null when none was declared.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	private static function expiryOf(mixed $expiresIn, DateTimeImmutable $now): ?DateTimeImmutable {
		if (is_numeric($expiresIn) === false || (int)$expiresIn <= 0) {
			return null;
		}

		return $now->modify('+' . (int)$expiresIn . ' seconds');
	}//end expiryOf()

	/**
	 * The scopes to record: granted, else requested, else the previous set's.
	 *
	 * @param array<string, mixed> $response The decoded token response.
	 * @param array<int, string> $requestedScopes Scopes to record when the response names none.
	 * @param self|null $previous The set being replaced.
	 *
	 * @return array<int, string> The scopes.
	 *
	 * @spec openspec/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	private static function grantedScopes(array $response, array $requestedScopes, ?self $previous): array {
		$scopes = self::stringList(value: ($response['scope'] ?? null));
		if ($scopes === [] && $requestedScopes !== []) {
			$scopes = array_values($requestedScopes);
		}

		if ($scopes === [] && $previous !== null) {
			$scopes = $previous->issuance->scopes;
		}

		return $scopes;
	}//end grantedScopes()

	/**
	 * Coerce a value to an array, or an empty one.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return array<mixed> The array.
	 *
	 * @spec exclude private coercion helper with no behaviour of its own
	 */
	private static function arrayOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end arrayOf()

	/**
	 * Parse a stored ATOM timestamp, or null when there is none to parse.
	 *
	 * A value that does not parse yields null rather than an error. A stored set with
	 * an unreadable timestamp is still a usable token set: null expiry means "do not
	 * refresh on the clock", which is the same conservative answer the code gives a
	 * provider that declared no expiry at all.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The parsed timestamp, or null.
	 *
	 * @spec exclude private parsing helper with no behaviour of its own
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) DateTimeImmutable::createFromFormat is the
	 * language's own named constructor; there is no instance to call it on.
	 */
	private static function parseAtom(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $value);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}//end parseAtom()

	/**
	 * Coerce a value to a non-empty string, or null.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return string|null The trimmed string, or null when absent or empty.
	 *
	 * @spec exclude private coercion helper with no behaviour of its own
	 */
	private static function nullableString(mixed $value): ?string {
		if (is_string($value) === false || trim($value) === '') {
			return null;
		}

		return trim($value);
	}//end nullableString()

	/**
	 * Coerce a scope value to a list of strings.
	 *
	 * Accepts both shapes providers use: a space-separated string (`scope`) and a
	 * JSON array. A comma-separated string is split too, because Meta spells its
	 * granted scopes that way.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return array<int, string> The scope list.
	 *
	 * @spec exclude private coercion helper with no behaviour of its own
	 */
	private static function stringList(mixed $value): array {
		if (is_string($value) === true) {
			return self::splitScopes(value: $value);
		}

		$out = [];
		foreach (self::arrayOf(value: $value) as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$out[] = trim($entry);
			}
		}

		return $out;
	}//end stringList()

	/**
	 * Split a space- or comma-separated scope string.
	 *
	 * @param string $value The scope string.
	 *
	 * @return array<int, string> The scope list.
	 *
	 * @spec exclude private coercion helper with no behaviour of its own
	 */
	private static function splitScopes(string $value): array {
		$parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
		if ($parts === false) {
			return [];
		}

		return $parts;
	}//end splitScopes()

	/**
	 * Coerce an account value to the three non-secret identity fields.
	 *
	 * @param mixed $value The candidate.
	 *
	 * @return array<string, string> The `id`, `handle` and `displayName`.
	 *
	 * @spec exclude private coercion helper with no behaviour of its own
	 */
	private static function accountOf(mixed $value): array {
		$source = [];
		if (is_array($value) === true) {
			$source = $value;
		}

		return [
			'id' => (string)($source['id'] ?? ''),
			'handle' => (string)($source['handle'] ?? ''),
			'displayName' => (string)($source['displayName'] ?? ''),
		];
	}//end accountOf()
}//end class
