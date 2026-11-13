<?php

/**
 * SearchLenses: the lenses that narrow a search to what the calling user follows, has not read or opened.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Service\Interaction\ReadHistoryService;
use OCP\IUserSession;

/**
 * Resolves `_watching`, `_favourite`, `_unread` and `_recent` into the query.
 *
 * Identity is resolved here, at the edge (ADR-005), never in the query builder,
 * so the mapper can be tested with a uid rather than a session.
 *
 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
 */
final class SearchLenses {
	/**
	 * The id a `_watching=true` query falls back to when there is no caller.
	 * It is deliberately not a uuid, so no object can ever carry it and the
	 * lens cannot accidentally widen to the whole register.
	 *
	 * @var string
	 */
	private const NO_WATCHED_OBJECTS = '__no-watched-objects__';

	/**
	 * The id an `_unread=true` query falls back to when there is no caller.
	 *
	 * An anonymous reader has no read state, so a naive `NOT EXISTS` would be
	 * true for every row and the lens would answer the WHOLE register instead of
	 * nothing. That is the silent-widening failure the watchers lens guards
	 * against too, and it is guarded the same way: a literal no object can
	 * carry.
	 *
	 * @var string
	 */
	private const NO_UNREAD_READER = '__no-unread-reader__';

	/**
	 * The id a `_favourite=true` or `_recent=true` query falls back to when
	 * there is no caller.
	 *
	 * Anonymous has starred nothing and opened nothing, so an unguarded lens
	 * would drop its own restriction and answer the WHOLE register. Guarded the
	 * same way as the two above: a literal no object can carry.
	 *
	 * @var string
	 */
	private const NO_PERSONAL_LENS_USER = '__no-personal-lens-user__';

	/**
	 * The id an owner filter falls back to when there is no caller.
	 *
	 * Without a session the owner is withheld from every answer, so filtering
	 * or sorting on it would still tell which records a user owns through the
	 * count and the order. An owner filter answers nothing and an owner sort
	 * is dropped.
	 *
	 * @var string
	 */
	private const NO_OWNER_FOR_ANONYMOUS = '__no-owner-for-anonymous__';

	/**
	 * The id each caller lens falls back to without a caller.
	 *
	 * @var array<string, string>
	 */
	private const NOBODY_IDS = [
		'_watchingFor' => self::NO_WATCHED_OBJECTS,
		'_unreadFor' => self::NO_UNREAD_READER,
	];

	/**
	 * Constructor.
	 *
	 * @param IUserSession|null       $userSession The session naming the caller.
	 * @param ReadHistoryService|null $readHistory The read history behind `_recent`; absent, that lens
	 *                                             answers an empty page that says the history could not be read.
	 */
	public function __construct(
		private readonly ?IUserSession $userSession,
		private readonly ?ReadHistoryService $readHistory,
	) {
	}//end __construct()

	/**
	 * Apply the lenses, in the order that lets them narrow each other.
	 *
	 * The watching lens comes after `_ids` is normalised to an array, so the
	 * two intersect. The unread lens comes next, so "unread among what I follow"
	 * narrows twice. The personal lenses come last, so they compose with every
	 * earlier narrowing of `_ids` rather than replacing it.
	 *
	 * @param array $query The query.
	 *
	 * @return array The query.
	 */
	public function apply(array $query): array {
		$query = $this->applyRecentLens(query: $this->applyUnreadLens(query: $this->applyWatchingLens(query: $query)));

		return $this->withoutOwnerLensForAnonymous(query: $query);
	}//end apply()

	/**
	 * Take the owner out of an anonymous caller's filter and sort.
	 *
	 * @param array $query The query.
	 *
	 * @return array The query.
	 *
	 * @spec openspec/specs/row-field-level-security/spec.md
	 */
	private function withoutOwnerLensForAnonymous(array $query): array {
		$uid = $this->userSession?->getUser()?->getUID();
		if ($uid !== null && $uid !== '') {
			return $query;
		}

		if (is_array($query['@self'] ?? null) === true && array_key_exists('owner', $query['@self']) === true) {
			unset($query['@self']['owner']);
			$query['_ids'] = [self::NO_OWNER_FOR_ANONYMOUS];
		}

		if (is_array($query['_order'] ?? null) === true) {
			$query['_order'] = array_filter(
				$query['_order'],
				static fn ($field): bool => in_array((string)$field, ['owner', '@self.owner', '_owner'], true) === false,
				ARRAY_FILTER_USE_KEY
			);
		}

		return $query;
	}//end withoutOwnerLensForAnonymous()

	/**
	 * Narrow a query to the objects the calling user follows.
	 *
	 * `_watching=true` is a LENS, not a filter: it does not ask the object a
	 * question, it restricts the result to this user's follows and lets every
	 * other filter, the RBAC gate and the paging run over what is left.
	 *
	 * It is resolved INSIDE the query (`merge-follow-and-favourites` D-4): the
	 * caller's uid lands on `_watchingFor`, which the mapper turns into a
	 * correlated `EXISTS` against `openregister_watchers`, like the unread
	 * lens. It used to load every followed uuid onto `_ids`, a list that grew
	 * with every follow and, once favourites moved in, with every star too.
	 * An explicit `_ids` therefore composes by AND, so "these five cases, of
	 * which I follow two" still answers two.
	 *
	 * `_favourite=true` is the deprecated alias of this lens for one release:
	 * a favourite IS a follow now, so both flags ask the same question and
	 * either one turns the lens on.
	 *
	 * Anonymous callers get a literal id no object carries, so the answer is
	 * an honest empty page rather than the whole register.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 *
	 * @return array<string, mixed> The query, narrowed when the lens was asked for.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-watchers-are-a-lens-and-a-list
	 */
	private function applyWatchingLens(array $query): array {
		$asked = ($this->asks(query: $query, flag: '_watching') || $this->asks(query: $query, flag: '_favourite'));

		// Never trust a resolved key from the outside: a caller must not hand
		// in somebody else's uid.
		unset($query['_watchingFor'], $query['_watching'], $query['_favourite']);

		if ($asked === false) {
			return $query;
		}

		return $this->forCaller(query: $query, lens: '_watchingFor');
	}//end applyWatchingLens()

	/**
	 * Resolve `_unread=true` into the uid whose read state the query reads.
	 *
	 * The lens is resolved INSIDE the query, not applied to a fetched page: the
	 * mapper turns `_unreadFor` into a correlated `NOT EXISTS` against the
	 * read-state table, so the page, the total and the facets all see the same
	 * restriction. A post-filter would give a first page of 25 with a total of
	 * 120 and a second page that skipped rows, which reads as a paging bug and
	 * is not one.
	 *
	 * Identity is resolved HERE, at the edge, and never in the query builder:
	 * ADR-005 wants the principal named where the request arrives, and it also
	 * means the mapper can be tested with a uid rather than a session.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 *
	 * @return array<string, mixed> The query, carrying the resolved reader.
	 *
	 * @spec openspec/specs/object-read-state/spec.md#requirement-unread-is-a-filter-and-a-badge-resolved-in-the-query-req-ors-002
	 */
	private function applyUnreadLens(array $query): array {
		$asked = $this->asks(query: $query, flag: '_unread');

		// Never trust a resolved key from the outside: a caller must not hand
		// in somebody else's uid.
		unset($query['_unreadFor'], $query['_unread']);

		if ($asked === false) {
			return $query;
		}

		return $this->forCaller(query: $query, lens: '_unreadFor');
	}//end applyUnreadLens()

	/**
	 * Whether a query turns a lens flag on.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 * @param string               $flag  The flag.
	 *
	 * @return bool True when it does.
	 */
	private function asks(array $query, string $flag): bool {
		return filter_var(($query[$flag] ?? false), FILTER_VALIDATE_BOOLEAN);
	}//end asks()

	/**
	 * Put the caller's uid on a lens key, or answer an honest empty page.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 * @param string               $lens  The resolved key, `_watchingFor` or `_unreadFor`.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function forCaller(array $query, string $lens): array {
		$uid = $this->userSession?->getUser()?->getUID();
		if ($uid === null || $uid === '') {
			$query['_ids'] = [self::NOBODY_IDS[$lens]];
			return $query;
		}

		$query[$lens] = $uid;

		return $query;
	}//end forCaller()

	/**
	 * Resolve `_recent=true` from the caller's read history on the audit trail.
	 *
	 * The history lands on `_ids` (intersected, history order kept), so every
	 * search path honours it and RBAC still decides. `_recentViews` (uuid =>
	 * ISO 8601) drives the order and `@self.viewedAt`; `_recentLens` is the
	 * response report. Audit trail off: empty, with a reason, no shadow log.
	 * Caller-supplied copies of the internal keys are stripped first.
	 *
	 * @param array<string, mixed> $query The query built so far.
	 *
	 * @return array<string, mixed> The query, narrowed when the lens was asked for.
	 *
	 * @spec openspec/specs/object-interactions/spec.md#requirement-the-recent-lens-says-why-it-is-empty
	 */
	private function applyRecentLens(array $query): array {
		$asked = $this->asks(query: $query, flag: '_recent');
		unset($query['_recentFor'], $query['_recentViews'], $query['_recentLens'], $query['_recent']);

		if ($asked === false) {
			return $query;
		}

		$lens = $this->recentHistory();
		$query['_recentLens'] = ['available' => $lens['available'], 'reason' => $lens['reason']];

		return $this->narrowToViews(query: $query, views: $this->viewsWithinIds(query: $query, views: $lens['views']));
	}//end applyRecentLens()

	/**
	 * The caller's read history, or the answer that it could not be read.
	 *
	 * @return array{available: bool, reason: string|null, views: array<string, string>} The history.
	 */
	private function recentHistory(): array {
		if ($this->readHistory === null) {
			return ['available' => false, 'reason' => ReadHistoryService::REASON_UNAVAILABLE, 'views' => []];
		}

		return $this->readHistory->resolveRecentLens(userId: $this->userSession?->getUser()?->getUID());
	}//end recentHistory()

	/**
	 * Intersect the history with an explicit `_ids`, keeping the history's order.
	 *
	 * @param array<string, mixed>  $query The query built so far.
	 * @param array<string, string> $views Uuid => when it was opened.
	 *
	 * @return array<string, string> The views left.
	 */
	private function viewsWithinIds(array $query, array $views): array {
		$existing = ($query['_ids'] ?? null);
		if (is_array($existing) === true && $existing !== []) {
			return array_intersect_key($views, array_flip(array_map('strval', $existing)));
		}

		return $views;
	}//end viewsWithinIds()

	/**
	 * Narrow a query to the views, or answer an honest empty page.
	 *
	 * @param array<string, mixed>  $query The query built so far.
	 * @param array<string, string> $views Uuid => when it was opened.
	 *
	 * @return array<string, mixed> The query.
	 */
	private function narrowToViews(array $query, array $views): array {
		if ($views === []) {
			$query['_ids'] = [self::NO_PERSONAL_LENS_USER];
			return $query;
		}

		$query['_ids'] = array_keys($views);
		$query['_recentViews'] = $views;

		return $query;
	}//end narrowToViews()
}//end class
