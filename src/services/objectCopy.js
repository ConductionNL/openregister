/**
 * How a mass copy turns a stored object into the payload of its copy.
 *
 * @spec exclude client-side helper for the mass-copy modal
 */

/**
 * The `@self` fields that belong to the original and are not copied.
 */
const ORIGINAL_ONLY_METADATA = [
	'id',
	'uuid',
	'uri',
	'created',
	'updated',
	'version',
	'files',
	'relations',
	'folder',
	'size',
	'deleted',
]

/**
 * The name of a copy: the pattern with `{name}` and `{id}` filled in.
 *
 * @param {object} object The original object.
 * @param {string} pattern The naming pattern.
 * @return {string}
 * @spec exclude client-side helper for the mass-copy modal
 */
export function copyName(object, pattern) {
	const originalName =
		object['@self']?.name
		|| object.name
		|| object['@self']?.title
		|| `Object ${object['@self']?.id}`

	return pattern
		.replace('{name}', originalName)
		.replace('{id}', object['@self']?.id || object.id || 'ID')
}

/**
 * A shallow copy of an object without the fields that belong to the
 * original. Its `@self` is the original's, so stripping the metadata strips
 * it there too.
 *
 * @param {object} object The original object.
 * @return {object}
 * @spec exclude client-side helper for the copy modals
 */
export function withoutOriginalMetadata(object) {
	const objectToCopy = { ...object }
	delete objectToCopy.id
	if (objectToCopy['@self']) {
		for (const field of ORIGINAL_ONLY_METADATA) {
			delete objectToCopy['@self'][field]
		}
	}
	return objectToCopy
}

/**
 * Give a copy its name: in `@self`, and in a root `name` or `title` it has.
 *
 * @param {object} objectToCopy The copy.
 * @param {string} newName The name.
 * @spec exclude client-side helper for the copy modals
 */
export function renameCopy(objectToCopy, newName) {
	if (objectToCopy['@self']) {
		objectToCopy['@self'].name = newName
	}
	if (objectToCopy.name !== undefined) {
		objectToCopy.name = newName
	}
	if (objectToCopy.title !== undefined) {
		objectToCopy.title = newName
	}
}

/**
 * The payload that saves a copy of an object. The copy is shallow: its
 * `@self` is the original's, so stripping the metadata strips it there too.
 *
 * @param {object} object The original object.
 * @param {string} pattern The naming pattern.
 * @return {object}
 * @spec exclude client-side helper for the mass-copy modal
 */
export function copyPayload(object, pattern) {
	const objectToCopy = withoutOriginalMetadata(object)
	renameCopy(objectToCopy, copyName(object, pattern))
	return objectToCopy
}

/**
 * Save a copy of each row named by `ids`, one after the other. Each copy is
 * made from a deep clone, because stripping the metadata reaches into `@self`.
 *
 * @param {object} request The copy.
 * @param {Array<object>} request.rows The rows on the page.
 * @param {Array<string>} request.ids The ids to copy.
 * @param {Function} request.nameOf The name of a copy of a row.
 * @param {Function} request.save Saves a copy; resolves to a falsy value when it was not saved.
 * @return {Promise<{success: boolean, successfulIds: Array<string>, failedIds: Array<string>}>}
 * @spec exclude client-side helper for the bulk copy on the object list
 */
export async function copyRows(request) {
	const successfulIds = []
	const failedIds = []
	for (const id of request.ids) {
		const row = request.rows.find((candidate) => String(candidate.id) === String(id))
		const saved = await savedCopy(request, row)
		const outcome = saved ? successfulIds : failedIds
		outcome.push(id)
	}
	return { success: failedIds.length === 0, successfulIds, failedIds }
}

/**
 * Save a renamed copy of a row, without the original's metadata.
 *
 * @param {{nameOf: function(object): string, save: function(object): Promise<object>}} job How a copy is named and saved.
 * @param {object|undefined} row The row to copy; nothing is saved without one.
 * @return {Promise<object|null>} What the save answered, or null without a row.
 */
async function savedCopy(job, row) {
	if (!row) {
		return null
	}
	const { nameOf, save } = job
	const copy = withoutOriginalMetadata(JSON.parse(JSON.stringify(row)))
	renameCopy(copy, nameOf(row))
	return save(copy)
}
