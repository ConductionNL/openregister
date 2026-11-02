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
