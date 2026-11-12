/**
 * The register and schema pickers that list sidebars share: NcSelect option
 * bags and the selected value, built from the stores' lists and items.
 *
 * @spec exclude Client-side picker options; no backend contract.
 */

/**
 * A register or schema as an NcSelect option, or null when none is selected.
 *
 * @param {object|null} item The register or schema
 * @param {string} key 'register' or 'schema', the key that carries the item
 * @return {object|null} The option
 */
export function scopeOption(item, key) {
	if (!item) {
		return null
	}
	return { value: item.id, label: item.title, title: item.title, [key]: item }
}

/**
 * The label an option shows: its title, its item's title, or its label.
 *
 * @param {object} option The option
 * @param {string} key 'register' or 'schema'
 * @return {string} The label
 */
function optionLabel(option, key) {
	return option.title || (option[key] && option[key].title) || option.label || ''
}

/**
 * An NcSelect bag over options that carry their item under `key`.
 *
 * @param {Array<object>} options The options
 * @param {string} key 'register' or 'schema'
 * @return {object} The bag
 */
function scopeSelect(options, key) {
	return {
		options,
		reduce: (option) => option[key],
		label: 'title',
		getOptionLabel: (option) => optionLabel(option, key),
	}
}

/**
 * The register picker.
 *
 * @param {Array<object>} registers The registers
 * @return {object} The NcSelect bag
 */
export function registerSelect(registers) {
	return scopeSelect(
		registers.map((register) => scopeOption(register, 'register')),
		'register',
	)
}

/**
 * The schema picker, limited to the schemas of the selected register; empty
 * while no register is selected.
 *
 * @param {object|null} register The selected register
 * @param {Array<object>} schemas The schemas
 * @return {object} The NcSelect bag
 */
export function schemaSelect(register, schemas) {
	if (!register) {
		return { options: [] }
	}
	const inRegister = schemas.filter((schema) =>
		register.schemas.some((registerSchema) => registerSchema.id === schema.id),
	)
	return schemaPicker(inRegister)
}

/**
 * A schema picker over the given schemas.
 *
 * @param {Array<object>} schemas The schemas to offer
 * @return {object} The NcSelect bag
 */
export function schemaPicker(schemas) {
	return scopeSelect(
		schemas.map((schema) => scopeOption(schema, 'schema')),
		'schema',
	)
}
