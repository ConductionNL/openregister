/**
 * The debounced option searches of the configuration edit modal.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec exclude Debounced autocomplete fetch populating select options; UI search plumbing.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/** How long a search waits for the typing to stop, in milliseconds. */
export const SEARCH_DEBOUNCE_MS = 300

/**
 * The search URL of one entity collection: the query and a limit of 10.
 *
 * @param {string} collection The API collection, such as `registers`
 * @param {string} query What was typed
 * @return {string} The URL
 */
export function searchUrl(collection, query) {
	return `/index.php/apps/openregister/api/${collection}?_search=${encodeURIComponent(query)}&_limit=10`
}

/**
 * Search the options of one select on a component, once the typing stops.
 * The answer replaces the options, with every selected item that it misses
 * put in front; a failed search empties the options. The loading flag is
 * set while the request runs.
 *
 * @param {object} vm The component
 * @param {object} fields The component fields and the request
 * @param {string} fields.timer The field holding the debounce timer
 * @param {string} fields.loading The loading flag
 * @param {string} fields.options The options of the select
 * @param {string} fields.selected The selected items
 * @param {string} fields.label The entities, as named in the error log
 * @param {function(): string} fields.url The URL, built when the search runs
 * @return {void}
 */
export function debouncedOptionSearch(vm, { timer, loading, options, selected, label, url }) {
	clearTimeout(vm[timer])
	vm[timer] = setTimeout(async () => {
		vm[loading] = true
		try {
			const response = await fetch(url())
			const data = await response.json()
			vm[options] = data.results || data || []
			// Include already selected items
			vm[selected].forEach((item) => {
				if (!vm[options].find((option) => option.id === item.id)) {
					vm[options].unshift(item)
				}
			})
		} catch (error) {
			// eslint-disable-next-line no-console
			console.error(`Error searching ${label}:`, error)
			vm[options] = []
		} finally {
			vm[loading] = false
		}
	}, SEARCH_DEBOUNCE_MS)
}

/**
 * The search methods of a component, one per entry: each runs
 * debouncedOptionSearch with its fields, and builds its URL from the query
 * and the component when the search runs.
 *
 * @param {object} searches The fields of each search by method name; `url`
 *     is a `function(string, object): string` of the query and component.
 * @return {object} The methods
 */
export function optionSearchMethods(searches) {
	return Object.fromEntries(
		Object.entries(searches).map(([name, { url, ...fields }]) => [
			name,
			/**
			 * Search the select's options for what was typed.
			 *
			 * @param {string} query What was typed
			 * @return {void}
			 */
			function (query) {
				debouncedOptionSearch(this, { ...fields, url: () => url(query, this) })
			},
		]),
	)
}
