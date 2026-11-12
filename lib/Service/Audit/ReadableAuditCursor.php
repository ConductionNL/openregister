<?php

/**
 * ReadableAuditCursor - the scoped audit list's cursor, sealed to its scope
 *
 * The scoped audit list resumes at an offset into the RAW trail, because which
 * rows a caller may read is decided per row and not in SQL. That offset
 * counts rows the caller could not read, so it is only ever handed out sealed
 * with the instance secret, together with the scope it was walked under: the
 * caller, and a hash of the filters and search. A cursor opens only under the
 * same scope. Replayed under another filter, handed back by another caller,
 * forged, or a plain number, it starts at the top of the trail, so no client
 * can choose where in the raw trail a walk resumes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Audit;

use JsonException;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Seals and opens the raw-trail offset of the scoped audit list.
 */
class ReadableAuditCursor {

	/**
	 * Prefix of the sealed plaintext, so a token sealed for something else is not read as an offset.
	 *
	 * @var string
	 */
	private const PURPOSE = 'audit-readable-cursor:';

	/**
	 * Constructor.
	 *
	 * @param ICrypto $crypto Authenticated encryption with the instance secret.
	 */
	public function __construct(
		private readonly ICrypto $crypto,
	) {
	}//end __construct()

	/**
	 * The scope a cursor is sealed to: the caller and what they filtered and searched on.
	 *
	 * A walk whose caller, filters or search cannot be encoded (a value that
	 * is not valid UTF-8) has no scope: its cursors never open, so it always
	 * starts at the top, and it is handed no cursor.
	 *
	 * @param string $userId The caller.
	 * @param array  $query  The filters and the search term of the walk.
	 *
	 * @return string|null The scope, a hash, or null when it cannot be encoded.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function scope(string $userId, array $query): ?string {
		try {
			return hash('sha256', json_encode([$userId, $query], JSON_THROW_ON_ERROR));
		} catch (JsonException $e) {
			return null;
		}
	}//end scope()

	/**
	 * The cursor a client is given for an offset into the raw trail, sealed to its scope.
	 *
	 * @param int    $offset The raw-trail offset to resume at.
	 * @param string $scope  The scope of the walk, from scope(); never null.
	 *
	 * @return string The sealed cursor.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function seal(int $offset, string $scope): string {
		return $this->crypto->encrypt(self::PURPOSE . $scope . ':' . $offset);
	}//end seal()

	/**
	 * The raw-trail offset a client's cursor resumes at, under the given scope.
	 *
	 * Only a cursor sealed under the same scope opens to its offset. Anything
	 * else starts at the top of the trail: no cursor, a plain number, a
	 * tampered or forged token, or one sealed for another caller, filter or
	 * search.
	 *
	 * @param int|string|null $cursor What the client handed back.
	 * @param string|null     $scope  The scope of this walk, from scope(); null opens nothing.
	 *
	 * @return int The offset, never negative.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function open(int|string|null $cursor, ?string $scope): int {
		if ($scope === null || is_string($cursor) === false || $cursor === '' || ctype_digit($cursor) === true) {
			return 0;
		}

		$plain = $this->decrypted(cursor: $cursor);
		if ($plain === null) {
			return 0;
		}

		$prefix = self::PURPOSE . $scope . ':';
		$offset = substr($plain, strlen($prefix));
		if (str_starts_with($plain, $prefix) === false || ctype_digit($offset) === false) {
			return 0;
		}

		return (int)$offset;
	}//end open()

	/**
	 * A cursor's plain text, or null when it does not decrypt.
	 *
	 * @param string $cursor The opaque cursor.
	 *
	 * @return string|null The plain text.
	 */
	private function decrypted(string $cursor): ?string {
		try {
			return $this->crypto->decrypt($cursor);
		} catch (Throwable $e) {
			return null;
		}
	}//end decrypted()
}//end class
