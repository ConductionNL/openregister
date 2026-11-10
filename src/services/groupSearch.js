/**
 * The debounced Nextcloud group search behind the group pickers of the
 * application, organisation and view edit modals.
 *
 * @spec exclude Debounced group-autocomplete search; UI search plumbing.
 */

const OCS_HEADERS = { 'OCS-APIRequest': 'true' }

/**
 * The groups to offer: the selected ones first, then the found ones that
 * are not selected yet.
 *
 * @param {Array<object>} selected The selected groups.
 * @param {Array<object>} found The groups the search found.
 * @return {Array<object>} The merged list.
 */
function withSelectedFirst(selected, found) {
	const selectedIds = selected.map((group) => group.id)
	return [...selected, ...found.filter((group) => !selectedIds.includes(group.id))]
}

/**
 * A Nextcloud group id as a picker option with a user count.
 *
 * @param {string} groupId The group id.
 * @return {{id: string, name: string, userCount: number}} The option.
 */
function countedGroup(groupId) {
	return { id: groupId, name: groupId, userCount: 0 }
}

/**
 * Ask Nextcloud for the groups matching a search.
 *
 * @param {string} query The search query.
 * @return {Promise<Response>} The OCS response.
 */
function fetchGroupsMatching(query) {
	return fetch(
		`/ocs/v1.php/cloud/groups?format=json&search=${encodeURIComponent(query)}`,
		{ headers: OCS_HEADERS },
	)
}

/**
 * The groups an OCS answer names, as picker options; undefined when the
 * answer named no groups.
 *
 * @param {object} data The OCS answer.
 * @param {function(string): object} toGroup How a group id becomes a picker option.
 * @return {Array<object>|undefined} The picker options.
 */
function foundGroups(data, toGroup) {
	if (data.ocs?.data?.groups) {
		return data.ocs.data.groups.map(toGroup)
	}
	return undefined
}

/**
 * Offer the found groups on the picker, after the selected ones; nothing
 * changes when the answer named no groups.
 *
 * @param {object} vm The component: `availableGroups` and `selectedGroups`.
 * @param {Array<object>|undefined} groups The found groups as picker options.
 * @return {void}
 */
function offerGroups(vm, groups) {
	if (groups) {
		vm.availableGroups = withSelectedFirst(vm.selectedGroups, groups)
	}
}

/**
 * Query the Nextcloud groups matching a search and offer them on the picker.
 *
 * @param {object} vm The component: `availableGroups`, `selectedGroups` and `loadingGroups`.
 * @param {{query: string, toGroup: Function, warnOnRefusal: boolean}} search The query, how a group id becomes a picker option, and whether a refused answer is logged.
 * @return {Promise<void>}
 */
async function runGroupSearch(vm, search) {
	vm.loadingGroups = true
	try {
		const response = await fetchGroupsMatching(search.query)
		if (response.ok) {
			const data = await response.json()
			offerGroups(vm, foundGroups(data, search.toGroup))
		} else if (search.warnOnRefusal) {
			// eslint-disable-next-line no-console
			console.warn('Failed to search groups:', response.statusText)
		}
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error('Error searching Nextcloud groups:', error)
	} finally {
		vm.loadingGroups = false
	}
}

/**
 * Search the Nextcloud groups 300 ms after the last keystroke.
 *
 * A new query cancels the pending one. An empty query cancels it and runs
 * `onEmpty` instead.
 *
 * @param {object} vm The component: `groupSearchDebounce`, `availableGroups`, `selectedGroups` and `loadingGroups`.
 * @param {{query: string|undefined, onEmpty: Function, toGroup: Function, warnOnRefusal: boolean}} search What to search for and how.
 *
 * @spec exclude Debounced group-autocomplete search; UI search plumbing.
 */
export function searchGroupsDebounced(vm, search) {
	if (vm.groupSearchDebounce) {
		clearTimeout(vm.groupSearchDebounce)
	}
	if (!search.query || search.query.trim() === '') {
		search.onEmpty()
		return
	}
	vm.groupSearchDebounce = setTimeout(() => runGroupSearch(vm, search), 300)
}

/**
 * Load every Nextcloud group onto a store as `{ id, name, userCount }`, for
 * the access-control pickers. A refused or failed load leaves the store's
 * groups as they were; only a failure is logged.
 *
 * @param {object} store The store: `nextcloudGroups`.
 * @return {Promise<void>}
 *
 * @spec exclude Thin passthrough to the Nextcloud OCS groups API; observable contract owned by Nextcloud core, not OpenRegister.
 */
export async function loadNextcloudGroupsInto(store) {
	try {
		// Nextcloud OCS API v1, for compatibility.
		const response = await fetch('/ocs/v1.php/cloud/groups?format=json', {
			headers: OCS_HEADERS,
		})
		if (response.ok) {
			const data = await response.json()
			if (data.ocs?.data?.groups) {
				store.nextcloudGroups = data.ocs.data.groups.map(countedGroup)
			}
		}
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error('Error loading Nextcloud groups:', error)
	}
}
