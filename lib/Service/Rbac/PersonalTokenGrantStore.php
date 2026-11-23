<?php

/**
 * Where a personal API token's grant is kept.
 *
 * A Nextcloud app password is not an OpenRegister row, so its grant cannot
 * live beside it the way a Consumer's lives in `authorizationConfiguration`.
 * It is kept as a user value of the token's owner, keyed by the token id: no
 * migration, no table of our own, and a grant is only ever read from the
 * namespace of the user the token belongs to, so writing a grant for an id
 * that is not yours narrows nothing anybody uses.
 *
 * The stored record carries the grant, who issued it and when, and every
 * renewal (a changed end date) with its actor and new end date, so renewing
 * is a recorded act and not a silent overwrite (REQ-SAT-003).
 *
 * 🔴 UNREADABLE IS NOT ABSENT. A value that is present but cannot be decoded
 * is returned as an unreadable record, which binds as a grant that permits
 * nothing. Only a value that is not there at all means "no grant".
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

use DateTimeInterface;
use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\IConfig;

/**
 * Reads and writes the grant of a user's personal API token.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class PersonalTokenGrantStore {

	/**
	 * The user-value key prefix; the token id follows it.
	 *
	 * @var string
	 */
	public const KEY_PREFIX = 'token_grant_';

	/**
	 * The prefix a personal token's id carries as `actorVia`, telling it apart from a Consumer uuid.
	 *
	 * @var string
	 */
	public const ACTOR_PREFIX = 'app-password:';

	/**
	 * How many renewals a record keeps; the oldest drop off first.
	 *
	 * @var int
	 */
	public const MAX_RENEWALS = 20;

	/**
	 * The app value listing every token that carries a grant, so a sweep can find them.
	 *
	 * @var string
	 */
	public const INDEX_KEY = 'personal_token_grants';

	/**
	 * Constructor.
	 *
	 * @param IConfig $config Holds the user values.
	 */
	public function __construct(
		private readonly IConfig $config,
	) {
	}//end __construct()

	/**
	 * The stored record of a token's grant.
	 *
	 * @param string $uid     The token's owner.
	 * @param string $tokenId The token id.
	 *
	 * @return array<string, mixed>|null The record (`grant`, `issuedBy`, `issuedAt`, `renewals`),
	 *                                   `['grant' => false]` when present but unreadable, or null when absent.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function read(string $uid, string $tokenId): ?array {
		$raw = (string) $this->config->getUserValue($uid, AppIdentity::APP_ID, self::key(tokenId: $tokenId), '');
		if ($raw === '') {
			return null;
		}

		$record = json_decode($raw, true);
		if (is_array($record) === false || is_array(($record['grant'] ?? null)) === false) {
			return ['grant' => false];
		}

		return $record;
	}//end read()

	/**
	 * The grant a token carries, as `TokenGrant::fromStored()` reads it.
	 *
	 * @param string $uid     The token's owner.
	 * @param string $tokenId The token id.
	 *
	 * @return TokenGrant|null The grant, an empty grant when unreadable, or null when there is none.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function grantFor(string $uid, string $tokenId): ?TokenGrant {
		$record = $this->read(uid: $uid, tokenId: $tokenId);
		if ($record === null) {
			return null;
		}

		return TokenGrant::fromStored(stored: $record['grant'], tokenId: self::actorId(tokenId: $tokenId));
	}//end grantFor()

	/**
	 * Store a token's grant, recording a renewal when the end date changes.
	 *
	 * @param string            $uid     The token's owner.
	 * @param string            $tokenId The token id.
	 * @param array             $grant   The validated grant.
	 * @param string            $actor   Who issues or renews it.
	 * @param DateTimeInterface $now     The moment of the act.
	 * @param string|null       $label   The token's name as its owner knows it, or null to keep the stored one.
	 *
	 * @return array<string, mixed> The stored record.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public function write(string $uid, string $tokenId, array $grant, string $actor, DateTimeInterface $now, ?string $label = null): array {
		$at       = $now->format(DateTimeInterface::ATOM);
		$previous = $this->read(uid: $uid, tokenId: $tokenId);
		$record   = ['grant' => $grant, 'issuedBy' => $actor, 'issuedAt' => $at, 'renewals' => []];

		if ($previous !== null && is_array($previous['grant']) === true) {
			$record['issuedBy'] = (string) ($previous['issuedBy'] ?? $actor);
			$record['issuedAt'] = (string) ($previous['issuedAt'] ?? $at);
			$record['renewals'] = self::renewalsOf(record: $previous);

			$oldEnd = ($previous['grant']['expiresAt'] ?? null);
			$newEnd = ($grant['expiresAt'] ?? null);
			if ($oldEnd !== $newEnd) {
				$record['renewals'][] = ['by' => $actor, 'at' => $at, 'from' => $oldEnd, 'expiresAt' => $newEnd];
				$record['renewals']   = array_slice($record['renewals'], -self::MAX_RENEWALS);
			}
		}

		if ($label === null && is_string(($previous['label'] ?? null)) === true) {
			$label = $previous['label'];
		}

		if ($label !== null && $label !== '') {
			$record['label'] = $label;
		}

		$this->config->setUserValue($uid, AppIdentity::APP_ID, self::key(tokenId: $tokenId), (string) json_encode($record));
		$this->index(uid: $uid, tokenId: $tokenId, present: true);

		return $record;
	}//end write()

	/**
	 * Remove a token's grant, which gives the token its owner's full rights again.
	 *
	 * @param string $uid     The token's owner.
	 * @param string $tokenId The token id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function remove(string $uid, string $tokenId): void {
		$this->config->deleteUserValue($uid, AppIdentity::APP_ID, self::key(tokenId: $tokenId));
		$this->index(uid: $uid, tokenId: $tokenId, present: false);
	}//end remove()

	/**
	 * Every token that carries a grant, as its owner and id.
	 *
	 * @return array<int, array{uid: string, tokenId: string}> The tokens.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-a-token-carries-a-required-end-date-and-is-warned-before-it-lapses-req-sat-003
	 */
	public function all(): array {
		$entries = json_decode((string) $this->config->getAppValue(AppIdentity::APP_ID, self::INDEX_KEY, '[]'), true);
		if (is_array($entries) === false) {
			return [];
		}

		$tokens = [];
		foreach ($entries as $entry) {
			if (is_array($entry) === true && is_string(($entry['uid'] ?? null)) === true && is_string(($entry['tokenId'] ?? null)) === true) {
				$tokens[] = ['uid' => $entry['uid'], 'tokenId' => $entry['tokenId']];
			}
		}

		return $tokens;
	}//end all()

	/**
	 * Add a token to, or drop it from, the index.
	 *
	 * @param string $uid     The token's owner.
	 * @param string $tokenId The token id.
	 * @param bool   $present Whether the token now carries a grant.
	 *
	 * @return void
	 */
	private function index(string $uid, string $tokenId, bool $present): void {
		$tokens = array_values(
			array_filter(
				$this->all(),
				static fn (array $entry): bool => ($entry['uid'] !== $uid || $entry['tokenId'] !== $tokenId)
			)
		);
		if ($present === true) {
			$tokens[] = ['uid' => $uid, 'tokenId' => $tokenId];
		}

		$this->config->setAppValue(AppIdentity::APP_ID, self::INDEX_KEY, (string) json_encode($tokens));
	}//end index()

	/**
	 * The id a personal token is named by in `actorVia`, whoami and the rate limit.
	 *
	 * @param string $tokenId The Nextcloud token id.
	 *
	 * @return string The prefixed id.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md#requirement-the-effective-grant-is-visible-and-writes-name-the-token
	 */
	public static function actorId(string $tokenId): string {
		return self::ACTOR_PREFIX.$tokenId;
	}//end actorId()

	/**
	 * The user-value key for a token.
	 *
	 * @param string $tokenId The token id.
	 *
	 * @return string The key.
	 */
	private static function key(string $tokenId): string {
		return self::KEY_PREFIX.$tokenId;
	}//end key()

	/**
	 * The renewals a stored record already carries.
	 *
	 * @param array<string, mixed> $record The stored record.
	 *
	 * @return array<int, array<string, mixed>> The renewals.
	 */
	private static function renewalsOf(array $record): array {
		$renewals = ($record['renewals'] ?? []);
		if (is_array($renewals) === false) {
			return [];
		}

		return array_values(array_filter($renewals, 'is_array'));
	}//end renewalsOf()
}//end class
