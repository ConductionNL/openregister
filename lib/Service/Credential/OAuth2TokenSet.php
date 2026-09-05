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
	 * @param string $accessToken The bearer token injected on every brokered call.
	 * @param string|null $refreshToken The refresh token, or null when the provider issued none.
	 * @param string $tokenType The token type the provider named (practically always `Bearer`).
	 * @param DateTimeImmutable|null $expiresAt Absolute expiry, or null when the provider declared none.
	 * @param array<int, string> $scopes The scopes the provider actually granted.
	 * @param array<string, string> $account Non-secret account identity (`id`, `handle`, `displayName`).
	 * @param array<string, mixed> $raw The raw token response, kept for provider-specific fields.
	 * @param DateTimeImmutable $obtainedAt When this set was issued or rotated.
	 *
	 * @return void
	 */
	private function __construct(
		private readonly string $accessToken,
		private readonly ?string $refreshToken,
		private readonly string $tokenType,
		private readonly ?DateTimeImmutable $expiresAt,
		private readonly array $scopes,
		private readonly array $account,
		private readonly array $raw,
		private readonly DateTimeImmutable $obtainedAt,
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
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public static function fromStoredJson(string $stored): self {
		try {
			$decoded = json_decode($stored, true, 32, JSON_THROW_ON_ERROR);
		} catch (JsonException) {
			throw new InvalidArgumentException(message: 'stored token set is not valid JSON');
		}

		if (is_array($decoded) === false || is_string(($decoded['accessToken'] ?? null)) === false) {
			throw new InvalidArgumentException(message: 'stored token set carries no access token');
		}

		$expiresAt = self::parseAtom(value: ($decoded['expiresAt'] ?? null));
		$obtainedAt = self::parseAtom(value: ($decoded['obtainedAt'] ?? null));

		$raw = [];
		if (is_array(($decoded['raw'] ?? null)) === true) {
			$raw = $decoded['raw'];
		}

		return new self(
			accessToken: (string)$decoded['accessToken'],
			refreshToken: self::nullableString(value: ($decoded['refreshToken'] ?? null)),
			tokenType: (self::nullableString(value: ($decoded['tokenType'] ?? null)) ?? 'Bearer'),
			expiresAt: $expiresAt,
			scopes: self::stringList(value: ($decoded['scopes'] ?? [])),
			account: self::accountOf(value: ($decoded['account'] ?? [])),
			raw: $raw,
			obtainedAt: ($obtainedAt ?? new DateTimeImmutable())
		);
	}//end fromStoredJson()

	/**
	 * Build a token set from a provider's token or refresh response.
	 *
	 * `expires_in` is resolved against `$now` rather than against the wall clock at
	 * use time, so a slow exchange does not inflate the credential's remaining life.
	 * When the response omits `refresh_token` the previous set's refresh token is
	 * kept: most providers here rotate the access token only, and dropping the
	 * refresh token would turn every such rotation into the last one.
	 *
	 * @param array<string, mixed> $response The decoded token response.
	 * @param array<int, string> $requestedScopes Scopes to record when the response names none.
	 * @param self|null $previous The set being replaced, for the refresh token and account fallbacks.
	 * @param DateTimeImmutable|null $now The clock, injectable for tests.
	 *
	 * @return self The new token set.
	 *
	 * @throws InvalidArgumentException When the response carries no access token.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public static function fromTokenResponse(
		array $response,
		array $requestedScopes = [],
		?self $previous = null,
		?DateTimeImmutable $now = null,
	): self {
		$accessToken = self::nullableString(value: ($response['access_token'] ?? null));
		if ($accessToken === null) {
			throw new InvalidArgumentException(message: 'token response carries no access token');
		}

		$now = ($now ?? new DateTimeImmutable());

		$expiresAt = null;
		$expiresIn = ($response['expires_in'] ?? null);
		if (is_numeric($expiresIn) === true && (int)$expiresIn > 0) {
			$expiresAt = $now->modify('+' . (int)$expiresIn . ' seconds');
		}

		$scopes = self::stringList(value: ($response['scope'] ?? null));
		if ($scopes === [] && $requestedScopes !== []) {
			$scopes = array_values($requestedScopes);
		}

		if ($scopes === [] && $previous !== null) {
			$scopes = $previous->scopes;
		}

		return new self(
			accessToken: $accessToken,
			refreshToken: (self::nullableString(value: ($response['refresh_token'] ?? null)) ?? $previous?->refreshToken),
			tokenType: (self::nullableString(value: ($response['token_type'] ?? null)) ?? 'Bearer'),
			expiresAt: $expiresAt,
			scopes: $scopes,
			account: self::accountOf(value: $previous?->account),
			raw: $response,
			obtainedAt: $now
		);
	}//end fromTokenResponse()

	/**
	 * Return a copy carrying the provider account identity.
	 *
	 * @param string $id The provider's own identifier for the account.
	 * @param string $handle The account handle as the provider spells it.
	 * @param string $displayName The account's display name.
	 *
	 * @return self A new token set; this one is unchanged.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public function withAccount(string $id, string $handle, string $displayName): self {
		return new self(
			accessToken: $this->accessToken,
			refreshToken: $this->refreshToken,
			tokenType: $this->tokenType,
			expiresAt: $this->expiresAt,
			scopes: $this->scopes,
			account: ['id' => $id, 'handle' => $handle, 'displayName' => $displayName],
			raw: $this->raw,
			obtainedAt: $this->obtainedAt
		);
	}//end withAccount()

	/**
	 * Serialise the whole set for the custody leaf.
	 *
	 * The ONE legitimate destination for this string is {@see CredentialStore::put()}.
	 *
	 * @return string The stored document.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-an-oauth2-token-set-is-stored-as-one-opaque-secret-in-the-custody-leaf
	 */
	public function toStoredJson(): string {
		return (string)json_encode(
			[
				'accessToken' => $this->accessToken,
				'refreshToken' => $this->refreshToken,
				'tokenType' => $this->tokenType,
				'expiresAt' => $this->expiresAt?->format(DATE_ATOM),
				'scopes' => $this->scopes,
				'account' => $this->account,
				'raw' => $this->raw,
				'obtainedAt' => $this->obtainedAt->format(DATE_ATOM),
			]
		);
	}//end toStoredJson()

	/**
	 * The access token to inject as a bearer token.
	 *
	 * @return string The access token.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	public function getAccessToken(): string {
		return $this->accessToken;
	}//end getAccessToken()

	/**
	 * The refresh token, or null when the provider issued none.
	 *
	 * @return string|null The refresh token.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-refresh-runs-under-a-per-credential-lock-and-rotates-atomically
	 */
	public function getRefreshToken(): ?string {
		return $this->refreshToken;
	}//end getRefreshToken()

	/**
	 * Absolute expiry, or null when the provider declared none.
	 *
	 * @return DateTimeImmutable|null The expiry.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	public function getExpiresAt(): ?DateTimeImmutable {
		return $this->expiresAt;
	}//end getExpiresAt()

	/**
	 * The scopes the provider granted.
	 *
	 * @return array<int, string> The granted scopes.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	public function getScopes(): array {
		return $this->scopes;
	}//end getScopes()

	/**
	 * The non-secret provider account identity.
	 *
	 * @return array<string, string> The `id`, `handle` and `displayName`.
	 *
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-non-secret-connection-metadata-lives-on-the-credential-object
	 */
	public function getAccount(): array {
		return $this->account;
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
	 * @spec openspec/changes/credential-oauth2-token-set/specs/credential-oauth2-token-set/spec.md#requirement-a-brokered-call-refreshes-an-expiring-token-set-before-it-is-used
	 */
	public function needsRefresh(int $marginSeconds, ?DateTimeImmutable $now = null): bool {
		if ($this->expiresAt === null) {
			return false;
		}

		$now = ($now ?? new DateTimeImmutable());

		return ($this->expiresAt->getTimestamp() - $marginSeconds) <= $now->getTimestamp();
	}//end needsRefresh()

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
		if (is_string($value) === false) {
			return null;
		}

		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		return $trimmed;
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
			$parts = preg_split('/[\s,]+/', trim($value), -1, PREG_SPLIT_NO_EMPTY);
			if ($parts === false) {
				return [];
			}

			return $parts;
		}

		if (is_array($value) === false) {
			return [];
		}

		$out = [];
		foreach ($value as $entry) {
			if (is_string($entry) === true && trim($entry) !== '') {
				$out[] = trim($entry);
			}
		}

		return $out;
	}//end stringList()

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
