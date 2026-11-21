/**
 * The Tables page's personal lenses (record-star-follow-and-unread-on-screen,
 * task 3.1): Following, Recent and Unread as quick filters on CnIndexPage,
 * turned into the list query the page's search store sends.
 *
 * CnIndexPage draws the tabs from `personalLenses` (an "All" tab first, then
 * each lens in the order given) and, for a page that loads its own rows,
 * reports only the index of the chosen tab. This maps that index back to the
 * query keys OpenRegister reads.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/tasks.md
 */

/** The lenses the Tables page offers, in tab order after "All". */
export const TABLE_LENSES = ['watching', 'recent', 'unread']

/** Lens id to the list query key OpenRegister reads. */
const LENS_KEYS = { watching: '_watching', recent: '_recent', unread: '_unread' }

/**
 * The search params a quick-filter tab sets: the chosen lens's key true and
 * every other lens key cleared, back to the first page. Recent also clears
 * the column sort, because that lens owns the order (design D-5). Index 0
 * ("All") and an index the tabs do not have clear every lens.
 *
 * @param {number|null} index The tab CnIndexPage reports
 * @param {Array<string>} lenses The lenses the page passes, in order
 * @return {object} The params to merge into the search
 * @spec openspec/changes/record-star-follow-and-unread-on-screen/specs/object-interactions/spec.md
 */
export function lensSearchParams(index, lenses = TABLE_LENSES) {
	const params = { page: 1 }
	for (const key of Object.values(LENS_KEYS)) {
		params[key] = null
	}

	const lens = Number.isInteger(index) && index > 0 ? lenses[index - 1] : undefined
	if (lens === undefined || !Object.hasOwn(LENS_KEYS, lens)) {
		return params
	}

	params[LENS_KEYS[lens]] = true
	if (lens === 'recent') {
		params.sortKey = null
		params.sortOrder = null
	}

	return params
}
