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
 * The payload that saves a copy of an object. The copy is shallow: its
 * `@self` is the original's, so stripping the metadata strips it there too.
 *
 * @param {object} object The original object.
 * @param {string} pattern The naming pattern.
 * @return {object}
 * @spec exclude client-side helper for the mass-copy modal
 */
export function copyPayload(object, pattern) {
	const objectToCopy = { ...object }
	delete objectToCopy.id
	if (objectToCopy['@self']) {
		for (const field of ORIGINAL_ONLY_METADATA) {
			delete objectToCopy['@self'][field]
		}
	}

	const newName = copyName(object, pattern)
	if (objectToCopy['@self']) {
		objectToCopy['@self'].name = newName
	}
	if (objectToCopy.name !== undefined) {
		objectToCopy.name = newName
	}
	if (objectToCopy.title !== undefined) {
		objectToCopy.title = newName
	}
	return objectToCopy
}
