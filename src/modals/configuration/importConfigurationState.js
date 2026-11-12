/**
 * The initial state of the ImportConfiguration modal, one group per tab.
 * Every call returns fresh objects so no two modals share state.
 *
 * @spec exclude Client-side initial state of the import modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The modal's tab and outcome.
 *
 * @return {object} The dialog state
 */
export function importDialogState() {
	return {
		activeTab: 0,
		loading: false,
		success: false,
		successMessage: '',
		error: null,
	}
}

/**
 * Which repository hosts the user has a token for; none until checked.
 *
 * @return {object} The token availability
 */
export function tokenAvailability() {
	return {
		hasGithubToken: false,
		hasGitlabToken: false,
	}
}

/**
 * The Discover tab: an empty search on GitHub.
 *
 * @return {object} The search state
 */
export function discoverTabState() {
	return {
		searchQuery: '',
		searchSource: 'github',
		searchLoading: false,
		searchResults: [],
		searchError: null,
		hasSearched: false,
	}
}

/**
 * The Repository tab: no repository, branch or file chosen yet.
 *
 * @return {object} The repository state
 */
export function repositoryTabState() {
	return {
		repoSource: 'GitHub',
		repoOwner: '',
		repoNamespace: '',
		repoName: '',
		branches: [],
		selectedBranch: null,
		configFiles: [],
		selectedFile: null,
	}
}

/**
 * The URL and File upload tabs: nothing entered yet.
 *
 * @return {object} The URL and upload state
 */
export function urlAndUploadTabState() {
	return {
		importUrl: '',
		urlError: null,
		selectedUploadFile: null,
		isDragging: false,
		fileError: null,
	}
}

/**
 * The sync settings every import shares: daily sync on.
 *
 * @return {object} The sync settings
 */
export function syncSettings() {
	return {
		syncEnabled: true,
		syncInterval: 24,
	}
}
