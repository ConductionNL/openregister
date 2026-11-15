<?php

/**
 * The grant as one more layer in the one evaluator (D-2).
 *
 * `PermissionHandlerInterface::resolveAuthorization()` is the step every path takes: the
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
	 * The match value that stands for the token's own subject.
	 *
	 * Resolved when the marker is written, to the token or Consumer id the
	 * grant is bound under, so the evaluators downstream compare a plain
	 * literal and never have to know a token exists. A grant without an id
	 * resolves it to {@see IMPOSSIBLE}, which no row carries.
	 *
	 * @var string
	 */
	public const TOKEN_SUBJECT = '@self.tokenSubject';

	/**
	 * Narrow a resolved block by the grant in force, if any.
	 *
	 * @param array<string, mixed>|null $authorization The resolved block.
	 * @param TokenGrantUse             $use           The grant in force (null for a session user), the schema being
	 *                                                 resolved and its register, and the moment for the expiry.
	 *
	 * @return array<string, mixed>|null The block to evaluate.
	 *
	 * @spec openspec/changes/scoped-api-tokens/specs/auth-system/spec.md
	 */
	public function narrow(array|null $authorization, TokenGrantUse $use): array|null {
		if ($use->grant === null) {
			return $this->withoutMarker(authorization: $authorization);
		}

		$run = new TokenNarrowing(grant: $use->grant, target: $use->target, now: ($use->now ?? new DateTimeImmutable()));

		return $this->narrowed(authorization: $authorization, run: $run);
	}//end narrow()

	/**
	 * Narrow a resolved block by a grant that is in force.
	 *
	 * @param array<string, mixed>|null $authorization The resolved block.
	 * @param TokenNarrowing            $run           The grant, the schema and the moment.
	 *
	 * @return array<string, mixed> The block to evaluate.
	 */
	private function narrowed(array|null $authorization, TokenNarrowing $run): array {
		$permitted = $this->permittedVerbs(run: $run);

		$block = ($authorization ?? []);

		// The marker is written LAST and unconditionally, so a block that
		// declared one of its own cannot claim a wider grant than the token
		// actually holds.
		$block[self::MARKER] = self::markerOf(run: $run, permitted: $permitted);

		$block = $this->refuse(block: $block, verbs: array_diff($this->verbsIn(block: $authorization), $permitted));

		// A block that was empty (default-open) and is now scoped needs at
		// least one rule of its own, or `empty()` would read it as unconfigured
		// and grant everything the grant just refused.
		if ($permitted === []) {
			$block = $this->refuse(block: $block, verbs: $this->catalogueVerbs());
		}

		return $block;
	}//end narrowed()

	/**
	 * The marker a narrowed block carries: the verbs the grant permits and whether it is in force.
	 *
	 * @param TokenNarrowing     $run       The grant, the schema and the moment.
	 * @param array<int, string> $permitted The verbs the grant permits.
	 *
	 * A grant with a row condition also carries it as `match`, with
	 * {@see TOKEN_SUBJECT} resolved, so the PHP ceiling and the SQL ceiling
	 * read the one condition from the one place.
	 *
	 * @return array{verbs: array<int, string>, tokenId: string, expired: bool, inScope: bool, match?: array<string, mixed>} The marker.
	 *
	 * @spec openspec/changes/scoped-api-tokens/tasks.md#1-1d
	 */
	private static function markerOf(TokenNarrowing $run, array $permitted): array {
		$marker = [
			'verbs' => $permitted,
			'tokenId' => $run->grant->tokenId,
			'expired' => $run->grant->isExpired(now: $run->now),
			'inScope' => $run->grant->covers(schemaSlug: $run->target->schemaSlug, registerSlug: $run->target->registerSlug),
		];

		if (is_array($run->grant->match) === true && $run->grant->match !== []) {
			$marker['match'] = self::withSubject(value: $run->grant->match, subject: $run->grant->tokenId);
		}

		return $marker;
	}//end markerOf()

	/**
	 * A match clause with every {@see TOKEN_SUBJECT} replaced by the token's id.
	 *
	 * @param mixed  $value   The clause, or one value inside it.
	 * @param string $subject The token or Consumer id; '' when the grant has none.
	 *
	 * @return mixed The clause with the subject resolved.
	 *
	 * @spec openspec/changes/scoped-api-tokens/tasks.md#1-1d
	 */
	private static function withSubject(mixed $value, string $subject): mixed {
		if (is_array($value) === true) {
			return array_map(static fn (mixed $inner): mixed => self::withSubject(value: $inner, subject: $subject), $value);
		}

		if ($value !== self::TOKEN_SUBJECT) {
			return $value;
		}

		if ($subject === '') {
			return self::IMPOSSIBLE;
		}

		return $subject;
	}//end withSubject()

	/**
	 * The row condition a narrowed block's grant puts on every row it reaches.
	 *
	 * Null when the block carries no marker, or a marker without a condition:
	 * the grant then limits verbs and schemas only. A condition that is present
	 * but not a non-empty object cannot be applied, and is answered with one
	 * that no row meets rather than with none.
	 *
	 * @param array|null $authorization The narrowed block.
	 *
	 * @return array<string, mixed>|null The condition, or null for none.
	 *
	 * @spec openspec/changes/scoped-api-tokens/tasks.md#1-1d
	 */
	public function markerMatch(?array $authorization): ?array {
		$marker = ($authorization[self::MARKER] ?? null);
		if (is_array($marker) === false || array_key_exists('match', $marker) === false) {
			return null;
		}

		$match = $marker['match'];
		if (is_array($match) === false || $match === [] || array_is_list($match) === true) {
			return ['_owner' => self::IMPOSSIBLE];
		}

		return $match;
	}//end markerMatch()

	/**
	 * A block passed through without a token, minus any marker a schema declares.
	 *
	 * A declared marker must not be able to stand in for a real grant in
	 * either direction.
	 *
	 * @param array|null $authorization The resolved block.
	 *
	 * @return array|null The block without a marker.
	 */
	private function withoutMarker(array|null $authorization): array|null {
		if (is_array($authorization) === true && array_key_exists(self::MARKER, $authorization) === true) {
			unset($authorization[self::MARKER]);
		}

		return $authorization;
	}//end withoutMarker()

	/**
	 * Replace the rule of every listed verb with the impossible principal.
	 *
	 * @param array    $block The block being narrowed.
	 * @param string[] $verbs The verbs the grant does not permit, in order.
	 *
	 * @return array The block.
	 */
	private function refuse(array $block, array $verbs): array {
		foreach ($verbs as $verb) {
			$block[$verb] = [self::IMPOSSIBLE];
		}

		return $block;
	}//end refuse()

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
	 * @param TokenNarrowing $run The grant, the schema and its register, and the moment.
	 *
	 * @return array<int, string> The permitted verbs.
	 */
	private function permittedVerbs(TokenNarrowing $run): array {
		if ($run->grant->isExpired(now: $run->now) === true) {
			return [];
		}

		if ($run->grant->covers(schemaSlug: $run->target->schemaSlug, registerSlug: $run->target->registerSlug) === false) {
			return [];
		}

		return $this->grantedVerbs(grant: $run->grant);
	}//end permittedVerbs()

	/**
	 * The grant's verbs that it may actually grant, as strings.
	 *
	 * @param TokenGrant $grant The token's grant.
	 *
	 * @return string[] The verbs.
	 */
	private function grantedVerbs(TokenGrant $grant): array {
		$permitted = [];
		foreach ($grant->verbs as $verb) {
			if ($grant->permits((string)$verb) === true) {
				$permitted[] = (string)$verb;
			}
		}

		return $permitted;
	}//end grantedVerbs()

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

		$keys = array_map(static fn (int|string $key): string => (string)$key, array_keys($block));

		return array_values(array_filter($keys, self::isVerbKey(...)));
	}//end verbsIn()

	/**
	 * Whether one block key is a verb rather than a control key or the grant marker.
	 *
	 * @param string $key The block key.
	 *
	 * @return bool
	 */
	private static function isVerbKey(string $key): bool {
		return in_array($key, PermissionCatalogueInterface::CONTROL_KEYS, true) === false && $key !== self::MARKER;
	}//end isVerbKey()

	/**
	 * The canonical verbs, used to close a block that had no rules at all.
	 *
	 * @return array<int, string> The verbs.
	 */
	private function catalogueVerbs(): array {
		return array_keys(PermissionCatalogueInterface::CANONICAL);
	}//end catalogueVerbs()
}//end class
