<?php

/**
 * An API principal narrower than the person who issued it (row Q13.20).
 *
 * `AuthorizationService::authorizeJwt()` ends with
 * `$this->userSession->setUser($this->userManager->get($issuer->getUserId()))`.
 * That one line is the row: a Consumer resolves to a Nextcloud user and
 * inherits everything that user may do, so a supplier given a token today gets
 * the handler's whole desk.
 *
 * 🔑 INTERSECTION, NEVER SUBSTITUTION (D-1). A grant carries no rights of its
 * own; it is a filter over the rights the user already has. So a token can only
 * narrow, an issuer cannot mint what they lack, and a user whose rights shrink
 * takes every token issued in their name with them, without a sweep.
 *
 * 🔴 AN EMPTY VERB LIST IS NOT "EVERY VERB". It is the shape that turns a
 * filter into an unconditional grant, and it is the same trap as an empty `$in`
 * in a conditional scope: the list is empty, nothing is excluded, everything
 * passes. A grant with no verbs permits NOTHING, and the validator refuses to
 * issue one at all so nobody has to rely on that.
 *
 * 🔴 AND AN ABSENT LIST IS NOT AN EMPTY ONE. `registers` and `schemas` absent
 * means "not scoped by that axis", which is the documented default in the
 * change ("absent grant means the user's full rights"). `registers: []`
 * present-but-empty would mean "no register", and reading those two the same
 * way is how a scope silently becomes universal.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * What one token or Consumer may do, as a filter over its holder's rights.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class TokenGrant {

	/**
	 * The key a grant is carried under in a Consumer's authorization
	 * configuration.
	 *
	 * Stored inside the existing `authorizationConfiguration` JSON column, so
	 * this needs no migration and no second place for a Consumer's settings.
	 *
	 * @var string
	 */
	public const KEY = 'grant';

	/**
	 * The verb that may never be granted to a token (D-3).
	 *
	 * `manage` changes the access rules themselves. A machine principal that
	 * can widen its own audience is precisely the failure iTop's scoping exists
	 * to prevent, so it is not grantable at all rather than grantable-with-care.
	 *
	 * @var string
	 */
	public const UNGRANTABLE = 'manage';

	/**
	 * Constructor.
	 *
	 * @param array<int, string>        $verbs     The verbs this token may use.
	 * @param array<int, string>|null   $registers Register slugs, or null for every one the holder may reach.
	 * @param array<int, string>|null   $schemas   Schema slugs, or null for every one.
	 * @param array<string, mixed>|null $match     A row condition, in the conditional-scope grammar.
	 * @param DateTimeInterface|null    $expiresAt When it lapses; required at issue.
	 * @param int|null                  $rateLimit Calls per minute, or null for none.
	 * @param string                    $tokenId   Which token this is, for `actorVia`.
	 */
	public function __construct(
		public readonly array $verbs,
		public readonly ?array $registers = null,
		public readonly ?array $schemas = null,
		public readonly ?array $match = null,
		public readonly ?DateTimeInterface $expiresAt = null,
		public readonly ?int $rateLimit = null,
		public readonly string $tokenId = '',
	) {
	}//end __construct()

	/**
	 * A grant read from stored configuration, or null when there is none.
	 *
	 * 🔴 A MALFORMED GRANT IS NOT AN ABSENT ONE. Absent means the token carries
	 * the holder's full rights, which is the documented behaviour for a token
	 * issued before this existed. Malformed means somebody meant to narrow and
	 * the narrowing cannot be read, and answering that with "full rights" would
	 * turn a typo into an escalation. So this returns a grant that permits
	 * NOTHING, and the caller can tell the two apart by asking
	 * {@see self::isEmpty()}.
	 *
	 * @param mixed  $stored  The stored grant.
	 * @param string $tokenId The token the grant belongs to.
	 *
	 * @return self|null The grant, or null when none is declared.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public static function fromStored(mixed $stored, string $tokenId = ''): ?self {
		if ($stored === null) {
			return null;
		}

		if (is_array($stored) === false) {
			return new self(verbs: [], tokenId: $tokenId);
		}

		$verbs = ($stored['verbs'] ?? null);
		if (is_array($verbs) === false) {
			return new self(verbs: [], tokenId: $tokenId);
		}

		$match = null;
		if (is_array(($stored['match'] ?? null)) === true) {
			$match = $stored['match'];
		}

		$rateLimit = null;
		if (is_numeric(($stored['rateLimit'] ?? null)) === true) {
			$rateLimit = (int)$stored['rateLimit'];
		}

		return new self(
			verbs: array_values(array_map(static fn (mixed $v): string => (string)$v, $verbs)),
			registers: self::listOrNull(value: ($stored['registers'] ?? null)),
			schemas: self::listOrNull(value: ($stored['schemas'] ?? null)),
			match: $match,
			expiresAt: self::dateOrNull(value: ($stored['expiresAt'] ?? null)),
			rateLimit: $rateLimit,
			tokenId: $tokenId
		);
	}//end fromStored()

	/**
	 * Whether this grant permits nothing at all.
	 *
	 * @return bool True when it permits nothing.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function isEmpty(): bool {
		return ($this->verbs === []);
	}//end isEmpty()

	/**
	 * Whether the grant permits one verb.
	 *
	 * @param string $verb The verb.
	 *
	 * @return bool True when it does.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function permits(string $verb): bool {
		if ($verb === self::UNGRANTABLE) {
			return false;
		}

		return in_array($verb, $this->verbs, true);
	}//end permits()

	/**
	 * Whether the grant reaches one schema in one register.
	 *
	 * An absent axis does not narrow; a present one is a closed list. A slug
	 * this grant does not name is out of scope whatever the holder may do.
	 *
	 * @param string|null $schemaSlug   The schema.
	 * @param string|null $registerSlug The register.
	 *
	 * @return bool True when the grant reaches it.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function covers(?string $schemaSlug, ?string $registerSlug): bool {
		if ($this->schemas !== null) {
			if ($schemaSlug === null || in_array($schemaSlug, $this->schemas, true) === false) {
				return false;
			}
		}

		if ($this->registers !== null) {
			if ($registerSlug === null || in_array($registerSlug, $this->registers, true) === false) {
				return false;
			}
		}

		return true;
	}//end covers()

	/**
	 * Whether the grant has lapsed.
	 *
	 * 🔴 A GRANT WITH NO END DATE READS AS EXPIRED HERE (C40.1). The validator
	 * refuses to issue one, so a grant without an end date can only be a row
	 * that predates the rule or one somebody edited by hand. "No end date" and
	 * "never expires" are the same string to a reader and opposite facts to a
	 * supplier holding a migration token six years on.
	 *
	 * @param DateTimeInterface $now The moment to judge against.
	 *
	 * @return bool True when it has lapsed.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function isExpired(DateTimeInterface $now): bool {
		if ($this->expiresAt === null) {
			return true;
		}

		return ($this->expiresAt->getTimestamp() <= $now->getTimestamp());
	}//end isExpired()

	/**
	 * How long until it lapses, in whole days, or null when it has none.
	 *
	 * @param DateTimeInterface $now The moment to measure from.
	 *
	 * @return int|null The days left, negative when it has already lapsed.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function daysLeft(DateTimeInterface $now): ?int {
		if ($this->expiresAt === null) {
			return null;
		}

		return (int)floor((($this->expiresAt->getTimestamp() - $now->getTimestamp()) / 86400));
	}//end daysLeft()

	/**
	 * The grant as a caller can read it back (`whoami`).
	 *
	 * @return array<string, mixed> The grant.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function toArray(): array {
		return [
			'verbs' => $this->verbs,
			'registers' => $this->registers,
			'schemas' => $this->schemas,
			'match' => $this->match,
			'expiresAt' => $this->expiresAt?->format(DATE_ATOM),
			'rateLimit' => $this->rateLimit,
			'tokenId' => $this->tokenId,
		];
	}//end toArray()

	/**
	 * A list of strings, or null when the axis is absent.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, string>|null The list.
	 */
	private static function listOrNull(mixed $value): ?array {
		if (is_array($value) === false) {
			return null;
		}

		return array_values(array_map(static fn (mixed $v): string => (string)$v, $value));
	}//end listOrNull()

	/**
	 * A date, or null when it cannot be read.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return DateTimeImmutable|null The date.
	 */
	private static function dateOrNull(mixed $value): ?DateTimeImmutable {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (\Exception $e) {
			return null;
		}
	}//end dateOrNull()
}//end class
