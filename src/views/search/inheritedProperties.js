import { schemaStore } from '../../store/store.js'

/**
 * The properties a schema inherits from its `allOf` parents, in parent order;
 * a later parent's property overrides an earlier one's. A parent is found in
 * the schema list by its id, its uuid, or its id written as a string.
 *
 * @param {object|null} schema The schema
 * @return {object} The inherited properties by name
 * @spec exclude UI plumbing — derived schema view state for display
 */
export function inheritedProperties(schema) {
	const inherited = {}
	for (const ref of schema?.allOf || []) {
		const parentSchema = findParentSchema(ref)
		if (parentSchema?.properties) {
			Object.assign(inherited, parentSchema.properties)
		}
	}
	return inherited
}

/**
 * The schema an `allOf` entry names.
 *
 * @param {object|string|number} ref The entry: a reference object or the identifier
 * @return {object|undefined} The schema, when the list holds it
 */
function findParentSchema(ref) {
	const schemaId = typeof ref === 'object' ? ref.id : ref
	return schemaStore.schemaList.find(
		(s) =>
			s.id === schemaId
			|| s.uuid === schemaId
			|| String(s.id) === String(schemaId),
	)
}
