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
 * Query the Nextcloud groups matching a search and offer them on the picker.
 *
 * @param {object} vm The component: `availableGroups`, `selectedGroups` and `loadingGroups`.
 * @param {{query: string, toGroup: Function, warnOnRefusal: boolean}} search The query, how a group id becomes a picker option, and whether a refused answer is logged.
 * @return {Promise<void>}
 */
async function runGroupSearch(vm, search) {
	vm.loadingGroups = true
	try {
		const response = await fetch(
			`/ocs/v1.php/cloud/groups?format=json&search=${encodeURIComponent(search.query)}`,
			{ headers: OCS_HEADERS },
		)
		if (response.ok) {
			const data = await response.json()
			if (data.ocs?.data?.groups) {
				vm.availableGroups = withSelectedFirst(
					vm.selectedGroups,
					data.ocs.data.groups.map(search.toGroup),
				)
			}
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
				store.nextcloudGroups = data.ocs.data.groups.map((groupId) => ({
					id: groupId,
					name: groupId,
					userCount: 0,
				}))
			}
		}
	} catch (error) {
		// eslint-disable-next-line no-console
		console.error('Error loading Nextcloud groups:', error)
	}
}
