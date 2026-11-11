/**
 * Request and answer handling of the publish-to-GitHub form: reading a JSON
 * answer, picking a default branch, and turning the form's selections into
 * the repository and branch a publish goes to.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 */

/**
 * The JSON body of a successful answer. A failed answer throws its `error`,
 * else the fallback message.
 *
 * @param {Response} response The fetch answer.
 * @param {string} fallback The message when a failed answer names no error.
 * @return {Promise<object>} The body.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 */
export async function jsonOrThrow(response, fallback) {
	if (!response.ok) {
		const errorData = await response.json()
		throw new Error(errorData.error || fallback)
	}
	return response.json()
}

/**
 * The branch a repository opens on: main, else master, else the first one.
 *
 * @param {Array<object>} branches The repository's branches.
 * @return {object|undefined} The branch, or undefined when there are none.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 */
export function defaultBranch(branches) {
	return (
		branches.find((branch) => branch.name === 'main')
		|| branches.find((branch) => branch.name === 'master')
		|| branches[0]
	)
}

/**
 * The value of a select: an option's `value`, else its fallback field, or the
 * selection itself when it is not an option.
 *
 * @param {object|string|null} selection The selection.
 * @param {string} fallbackKey The option field used when `value` is empty.
 * @return {string|undefined} The selected value.
 */
function selectedValue(selection, fallbackKey) {
	if (typeof selection === 'object') {
		return selection.value || selection[fallbackKey]
	}
	return selection
}

/**
 * The repository the form selected, as its full name.
 *
 * @param {object} form The form, with `selectedRepository` and `repositories`.
 * @return {string} The repository's full name.
 */
function selectedRepository(form) {
	const repoValue = selectedValue(form.selectedRepository, 'full_name')
	if (!repoValue) {
		throw new Error('Repository not selected')
	}
	if (!form.repositories.find((repo) => repo.full_name === repoValue)) {
		throw new Error('Repository not found')
	}
	return repoValue
}

/**
 * Where a publish goes: the owner and name of the selected repository and the
 * selected branch. Throws when either is missing or the repository is unknown.
 *
 * @param {object} form The form, with `selectedRepository`, `repositories` and `selectedBranch`.
 * @return {{owner: string, repo: string, branch: string}} The target.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 */
export function publishTarget(form) {
	const [owner, repo] = selectedRepository(form).split('/')
	const branch = selectedValue(form.selectedBranch, 'name')
	if (!branch) {
		throw new Error('Branch not selected')
	}
	return { owner, repo, branch }
}

/**
 * The success message of a publish: the short commit hash, and the indexing
 * note when the answer has one.
 *
 * @param {string} publishedMessage The message that opens it.
 * @param {object} data The publish answer.
 * @return {string} The message.
 *
 * @spec openspec/specs/entity-management-modals/spec.md
 */
export function publishedText(publishedMessage, data) {
	const message = `${publishedMessage} Commit: ${data.commit_sha?.substring(0, 7) || 'N/A'}`
	return data.indexing_note ? `${message}\n\n${data.indexing_note}` : message
}
