<?php

/**
 * ReadableAuditCursor - the scoped audit list's cursor, sealed
 *
 * The scoped audit list resumes at an offset into the RAW trail, because which
 * rows a caller may read is decided per row and not in SQL. Handed out as a
 * number, that offset counts the rows the caller could not read: a caller
 * filtering on another organisation's object or user would learn how many
 * entries it has. So the offset leaves sealed with the instance secret, and a
 * sealed cursor is all a client gets to hand back.
 *
 * A plain number is still accepted on the way in, so a client that pages by
 * offset keeps working; reading from an offset the caller chose tells it
 * nothing.
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
	 * The cursor a client is given for an offset into the raw trail.
	 *
	 * @param int $offset The raw-trail offset to resume at.
	 *
	 * @return string The sealed cursor.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function seal(int $offset): string {
		return $this->crypto->encrypt(self::PURPOSE . $offset);
	}//end seal()

	/**
	 * The raw-trail offset a client's cursor resumes at.
	 *
	 * A sealed cursor opens to its offset, a plain number is read as an
	 * offset, and anything else, a tampered cursor included, starts at the
	 * top of the trail.
	 *
	 * @param int|string|null $cursor What the client handed back.
	 *
	 * @return int The offset, never negative.
	 *
	 * @spec openspec/specs/audit-trail-immutable/spec.md#requirement-the-audit-trail-is-readable-within-a-callers-own-scope
	 */
	public function open(int|string|null $cursor): int {
		if ($cursor === null || $cursor === '') {
			return 0;
		}

		if (is_int($cursor) === true || ctype_digit((string)$cursor) === true) {
			return max(0, (int)$cursor);
		}

		try {
			$plain = $this->crypto->decrypt((string)$cursor);
		} catch (Throwable $e) {
			return 0;
		}

		$offset = substr($plain, strlen(self::PURPOSE));
		if (str_starts_with($plain, self::PURPOSE) === false || ctype_digit($offset) === false) {
			return 0;
		}

		return (int)$offset;
	}//end open()
}//end class
