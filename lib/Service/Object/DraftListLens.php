<?php

/**
 * The draft lens on a list: drafts are hidden unless the caller asks for them.
 *
 * Decision 201 (Ruben, 11 October 2026): staff work lists hide drafts by
 * default, with a filter to show them. A draft is unsent work of one person,
 * so a list or search leaves it out whoever asks and by whichever path: an
 * administrator and an app's own service (which lists with RBAC off) get the
 * same work list a colleague gets. The caller's own drafts stay in view.
 *
 * The lens is a default on what a list shows, not an authorisation. Asking
 * for drafts shows the drafts the caller may already reach; the read RBAC
 * (DraftStatusPolicy, decision 180) still keeps a draft to its owner unless
 * the schema lets others see drafts.
 *
 * The parameter follows `_archived`: absent or false is the work list,
 * `_drafts=true` is drafts alone, `_drafts=any` is both. It knows nothing of
 * any app or procedure (decision 182).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Object;

use OCA\OpenRegister\Db\ObjectEntity;

/**
 * Which drafts a list shows, and the SQL clause that says so.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
 */
class DraftListLens {

	/**
	 * The query parameter a caller sends.
	 *
	 * @var string
	 */
	public const PARAMETER = '_drafts';

	/**
	 * The work list: no drafts but the caller's own. The default.
	 *
	 * @var string
	 */
	public const EXCLUDE = 'exclude';

	/**
	 * Drafts and nothing else.
	 *
	 * @var string
	 */
	public const ONLY = 'only';

	/**
	 * Drafts beside the rest. The literal is the value callers send (`_drafts=any`).
	 *
	 * @var string
	 */
	public const ANY = 'any';

	/**
	 * No drafts at all, the caller's own included. The literal is the value callers send (`_drafts=none`).
	 *
	 * For a work list whose drafts live somewhere else on the screen, such as
	 * a quick entry that keeps its own half-typed draft (decision 216).
	 *
	 * @var string
	 */
	public const NONE = 'none';

	/**
	 * The lens a query asks for.
	 *
	 * An unrecognised value reads as the work list, because a typo must never
	 * widen what a list shows. Without the parameter, a filter on the stored
	 * status (`@self[status]=draft`) is the caller saying which statuses it
	 * wants, so the default steps aside and that filter decides.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return string One of EXCLUDE, ONLY, ANY and NONE.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
	 */
	public function modeFor(array $query): string {
		if (array_key_exists(self::PARAMETER, $query) === false) {
			if ($this->filtersOnStatus(query: $query) === true) {
				return self::ANY;
			}

			return self::EXCLUDE;
		}

		$raw = $query[self::PARAMETER];
		if (is_string($raw) === true && strtolower(trim($raw)) === self::ANY) {
			return self::ANY;
		}

		if (is_string($raw) === true && strtolower(trim($raw)) === self::NONE) {
			return self::NONE;
		}

		if (is_scalar($raw) === true && filter_var($raw, FILTER_VALIDATE_BOOLEAN) === true) {
			return self::ONLY;
		}

		return self::EXCLUDE;
	}//end modeFor()

	/**
	 * Whether the query carries a filter on the stored status.
	 *
	 * @param array<string, mixed> $query The search query.
	 *
	 * @return bool True for a non-empty `@self.status` filter.
	 */
	private function filtersOnStatus(array $query): bool {
		$self = ($query['@self'] ?? null);
		if (is_array($self) === false) {
			return false;
		}

		$status = ($self['status'] ?? null);

		return $status !== null && $status !== '' && $status !== [];
	}//end filtersOnStatus()

	/**
	 * The clause for a lens, or null when it puts no condition on the list.
	 *
	 * @param string      $mode         One of EXCLUDE, ONLY, ANY and NONE.
	 * @param string      $columnPrefix The table alias with its dot, or empty for an unaliased UNION member.
	 * @param string|null $owner        The caller, already quoted or bound by the emitter; null for nobody.
	 *
	 * @return string|null The predicate, without a leading AND.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-a-list-must-hide-drafts-unless-asked
	 */
	public function sql(string $mode, string $columnPrefix, ?string $owner): ?string {
		if ($mode === self::ANY) {
			return null;
		}

		if ($mode === self::ONLY) {
			return $columnPrefix . "_status = '" . ObjectEntity::STATUS_DRAFT . "'";
		}

		$notADraft = (new DraftStatusPolicy())->notADraftSql(columnPrefix: $columnPrefix);
		if ($mode === self::NONE || $owner === null || $owner === '') {
			return $notADraft;
		}

		return '(' . $notADraft . ' OR ' . $columnPrefix . '_owner = ' . $owner . ')';
	}//end sql()
}//end class
