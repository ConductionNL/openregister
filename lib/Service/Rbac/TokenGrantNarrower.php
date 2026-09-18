<?php

/**
 * The grant as one more layer in the one evaluator (D-2).
 *
 * `PermissionHandler::resolveAuthorization()` is the step every path takes: the
 * object read, the relation check and both list emitters resolve through it,
 * and `MagicRbacHandler` delegates to it. Adding the grant there is what makes
 * the PHP verdict and the SQL verdict identical BY CONSTRUCTION rather than by
 * two implementations agreeing — the same argument the department matrix is
 * compiled there for.
 *
 * 🔴 AN EMPTY AUTHORIZATION BLOCK IS DEFAULT-OPEN, SO THE NARROWED BLOCK IS
 * NEVER EMPTY. `hasGroupPermission()` reads `empty($authorization)` as "no
 * rules configured" and grants. Narrowing by DELETING the verbs a token may not
 * use would therefore hand a token with no verbs left the whole schema. Every
 * refusal here is written as a rule that cannot match, never as an absence.
 *
 * 🔴 AND THE BLOCK ALONE CANNOT BIND AN ADMIN OR AN OWNER. `hasGroupPermission()`
 * returns true for the `admin` group and for the object's owner BEFORE it looks
 * at the block at all. A grant that only rewrote the block would narrow a
 * supplier and not narrow the administrator who issued them the token, which is
 * the wrong way round. So the narrowed block carries a MARKER, and the handler
 * consults it ahead of those two bypasses: intersection, never substitution,
 * applies to everybody.
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
 * Intersects a token grant with a resolved authorization block.
 *
 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
 */
class TokenGrantNarrower {

	/**
	 * The control key the effective grant is carried under inside a block.
	 *
	 * 🔴 IT MUST BE IN `PermissionCatalogue::CONTROL_KEYS`. A key in an
	 * authorization block that is not listed there is read as a VERB, and the
	 * block is then refused at save as declaring an unknown permission. That
	 * defect shipped once already, with `matrix`, and made an entire change
	 * unreachable while every test passed.
	 *
	 * @var string
	 */
	public const MARKER = 'x-openregister-token-grant';

	/**
	 * The group name that can never match, used to write a closed rule.
	 *
	 * A rule listing one impossible group is a rule that denies; an ABSENT rule
	 * on an empty block is a rule that grants. The difference is this constant.
	 *
	 * @var string
	 */
	public const IMPOSSIBLE = '__openregister_token_scope_denied__';

	/**
	 * Narrow a resolved block by the grant in force, if any.
	 *
	 * @param array<string, mixed>|null $authorization The resolved block.
	 * @param TokenGrant|null           $grant         The grant in force, or null for a session user.
	 * @param string|null               $schemaSlug    The schema being resolved.
	 * @param string|null               $registerSlug  Its register.
	 * @param DateTimeInterface|null    $now           The moment, for the expiry.
	 *
	 * @return array<string, mixed>|null The block to evaluate.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function narrow(
		?array $authorization,
		?TokenGrant $grant,
		?string $schemaSlug,
		?string $registerSlug,
		?DateTimeInterface $now = null
	): ?array {
		if ($grant === null) {
			// No token: the block is whatever it was, MINUS any marker a schema
			// happens to declare. A declared marker must not be able to stand in
			// for a real grant in either direction.
			if (is_array($authorization) === true && array_key_exists(self::MARKER, $authorization) === true) {
				unset($authorization[self::MARKER]);
			}

			return $authorization;
		}

		$now = ($now ?? new DateTimeImmutable());

		$permitted = $this->permittedVerbs(
			grant: $grant,
			schemaSlug: $schemaSlug,
			registerSlug: $registerSlug,
			now: $now
		);

		$block = [];
		if (is_array($authorization) === true) {
			$block = $authorization;
		}

		// The marker is written LAST and unconditionally, so a block that
		// declared one of its own cannot claim a wider grant than the token
		// actually holds.
		$block[self::MARKER] = [
			'verbs' => $permitted,
			'tokenId' => $grant->tokenId,
			'expired' => $grant->isExpired(now: $now),
			'inScope' => $grant->covers(schemaSlug: $schemaSlug, registerSlug: $registerSlug),
		];

		foreach ($this->verbsIn(block: $authorization) as $verb) {
			if (in_array($verb, $permitted, true) === false) {
				$block[$verb] = [self::IMPOSSIBLE];
			}
		}

		// A block that was empty (default-open) and is now scoped needs at
		// least one rule of its own, or `empty()` would read it as unconfigured
		// and grant everything the grant just refused.
		if ($permitted === []) {
			foreach ($this->catalogueVerbs() as $verb) {
				$block[$verb] = [self::IMPOSSIBLE];
			}
		}

		return $block;
	}//end narrow()

	/**
	 * Whether the marker in a block permits one action.
	 *
	 * Called by the permission handler AHEAD of the admin and owner bypasses.
	 * A block with no marker is not scoped by a token and answers true, which
	 * is every session request.
	 *
	 * @param array<string, mixed>|null $authorization The block.
	 * @param string                    $action        The action.
	 *
	 * @return bool True when no token narrows this, or the token permits it.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function markerPermits(?array $authorization, string $action): bool {
		if (is_array($authorization) === false) {
			return true;
		}

		$marker = ($authorization[self::MARKER] ?? null);
		if (is_array($marker) === false) {
			return true;
		}

		$verbs = ($marker['verbs'] ?? null);
		if (is_array($verbs) === false) {
			// A marker that cannot be read is a narrowing that cannot be
			// applied, and the safe reading of that is "refused", never "open".
			return false;
		}

		return in_array($action, $verbs, true);
	}//end markerPermits()

	/**
	 * The verbs the grant leaves, for this schema, at this moment.
	 *
	 * @param TokenGrant        $grant        The grant.
	 * @param string|null       $schemaSlug   The schema.
	 * @param string|null       $registerSlug Its register.
	 * @param DateTimeInterface $now          The moment.
	 *
	 * @return array<int, string> The permitted verbs.
	 */
	private function permittedVerbs(
		TokenGrant $grant,
		?string $schemaSlug,
		?string $registerSlug,
		DateTimeInterface $now
	): array {
		if ($grant->isExpired(now: $now) === true) {
			return [];
		}

		if ($grant->covers(schemaSlug: $schemaSlug, registerSlug: $registerSlug) === false) {
			return [];
		}

		$permitted = [];
		foreach ($grant->verbs as $verb) {
			if ($grant->permits((string)$verb) === true) {
				$permitted[] = (string)$verb;
			}
		}

		return $permitted;
	}//end permittedVerbs()

	/**
	 * The verb keys a block declares, ignoring its control keys.
	 *
	 * @param array<string, mixed>|null $block The block.
	 *
	 * @return array<int, string> The verbs.
	 */
	private function verbsIn(?array $block): array {
		if (is_array($block) === false) {
			return [];
		}

		$verbs = [];
		foreach (array_keys($block) as $key) {
			$key = (string)$key;
			if (in_array($key, PermissionCatalogue::CONTROL_KEYS, true) === true || $key === self::MARKER) {
				continue;
			}

			$verbs[] = $key;
		}

		return $verbs;
	}//end verbsIn()

	/**
	 * The canonical verbs, used to close a block that had no rules at all.
	 *
	 * @return array<int, string> The verbs.
	 */
	private function catalogueVerbs(): array {
		return array_keys(PermissionCatalogue::CANONICAL);
	}//end catalogueVerbs()
}//end class
