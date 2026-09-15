import type { Locator, Page } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Finding a row in a CnIndexPage list, the way a person would.
 *
 * WHY A HELPER RATHER THAN `page.getByText(title)`
 * ------------------------------------------------
 * Two defects bit the crud specs, both measured on a live instance, and a bare
 * `page.getByText(title).first()` walks into each of them.
 *
 * 1. THE LIST IS PAGED, AND THE NEWEST REGISTER IS LAST.
 *    `RegistersIndex.paginatedRegisters` slices client-side at 20 rows
 *    (`registerStore.pagination.limit`) and `filteredRegisters` applies NO
 *    sort, so a register created just now sits at the END of the list. A fresh
 *    instance already ships 16 registers, `tests/e2e/ci/object-sharing.spec.ts`
 *    and `ci/object-shares-tab.spec.ts` each leak one that is never deleted,
 *    and `archival-retention.spec.ts` leaves one behind that no HTTP caller can
 *    remove. Past 20 the newest row is on page two and the assertion reports
 *    "element(s) not found" — which reads like a broken list, not a full one.
 *    (`SchemasIndex.orderedSchemas` sorts by id DESCENDING, so the sibling list
 *    puts the newest FIRST and never showed this. The two lists disagree.)
 *
 * 2. OPENREGISTER'S OWN NOTIFICATION SATISFIES THE LOCATOR.
 *    Updating a register publishes a Nextcloud notification whose subject is
 *    `Register "<title>" was updated`. Its markup sits in the page header,
 *    hidden until the bell is opened, and BEFORE the app content in DOM order.
 *    So an unscoped `getByText(title).first()` resolves to that hidden span and
 *    `toBeVisible()` fails on a row that rendered perfectly:
 *
 *      locator resolved to <span class="subject">Register "…" was updated</span>
 *        - unexpected value "hidden"
 *
 *    and an unscoped `toHaveCount(0)` after a delete counts the notification
 *    and reports 1. The spec's own action creates the thing that breaks it.
 *
 * Scoping every list assertion to `[data-testid="cn-index-page"]` kills the
 * second, and paging forward kills the first.
 *
 * ⚠️ THE `.catch(() => false)` BELOW IS NOT A CONDITIONAL ASSERTION. It steers
 * the paging loop only; neither helper asserts anything about the row. The
 * caller makes an unconditional `expect(...)` on what comes back, so a row that
 * is on no page at all still reddens the test, naming the row.
 */
import { expect } from '@playwright/test'

/** The CnIndexPage shell that wraps the list, its rows and its pagination. */
const INDEX_PAGE = '[data-testid="cn-index-page"]'

/**
 * Upper bound on paging, so a pagination control that never disables its Next
 * button cannot spin forever. 25 pages is 500 rows at the default page size.
 */
const MAX_PAGES = 25

/**
 * Wait for the index page shell and return it.
 *
 * @param  {Page} page The page under test.
 * @return {Promise<Locator>} The visible CnIndexPage root.
 */
async function indexPage(page: Page): Promise<Locator> {
	const list = page.locator(INDEX_PAGE)
	await expect(list, 'the index page must render').toBeVisible({
		timeout: 30_000,
	})
	return list
}

/**
 * Click through to the next page of the list, if there is one.
 *
 * CnPagination renders its nav only when `pages > 1`, and disables Next on the
 * last page, so both "one page only" and "last page" answer false.
 *
 * @param  {Locator} list The CnIndexPage root.
 * @return {Promise<boolean>} Whether a further page was opened.
 */
async function openNextPage(list: Locator): Promise<boolean> {
	const next = list
		.locator('[data-testid="cn-pagination"]')
		.getByRole('button', { name: 'Next', exact: true })
	if ((await next.count()) === 0) {
		return false
	}
	if (await next.isDisabled()) {
		return false
	}
	await next.click()
	return true
}

/**
 * Resolve a row in the list by its visible text, paging forward until it shows.
 *
 * Returns a locator either way: when the text is on no page, the returned
 * locator resolves to nothing and the caller's `expect(...).toBeVisible()`
 * fails naming it, which is the honest outcome.
 *
 * @param  {Page}   page The page under test, already on the list route.
 * @param  {string} text The row text to look for (substring match).
 * @return {Promise<Locator>} The row, scoped to the index page.
 */
export async function listRowByText(page: Page, text: string): Promise<Locator> {
	const list = await indexPage(page)
	const row = (): Locator => list.getByText(text, { exact: false }).first()

	for (let visited = 0; visited < MAX_PAGES; visited++) {
		const onThisPage = await row()
			.isVisible({ timeout: 2_000 })
			.catch(() => false)
		if (onThisPage === true) {
			break
		}
		if ((await openNextPage(list)) === false) {
			break
		}
	}

	return row()
}

/**
 * Count how many rows across EVERY page carry the given text.
 *
 * "Gone from the list" is a claim about the whole list, not about page one, so
 * a delete assertion has to walk the pages the way a search would.
 *
 * @param  {Page}   page The page under test, already on the list route.
 * @param  {string} text The row text to count (substring match).
 * @return {Promise<number>} The total across all pages.
 */
export async function countListRowsByText(
	page: Page,
	text: string,
): Promise<number> {
	const list = await indexPage(page)
	let total = 0

	for (let visited = 0; visited < MAX_PAGES; visited++) {
		total += await list.getByText(text, { exact: false }).count()
		if ((await openNextPage(list)) === false) {
			break
		}
	}

	return total
}
