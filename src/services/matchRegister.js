/**
 * Match a register in a loaded list against whatever a caller wrote down.
 *
 * `RegisterMapper::find()` has always accepted three spellings of the same
 * register: the numeric id, the uuid, and the slug. The frontend accepted one.
 * `/registers/:id` compared the route param against `r.id` alone, so a link
 * naming the slug found nothing, `loadSchemas()` read `register?.schemas` as
 * undefined, and the page rendered an empty schema list with no error — which
 * reads as "this register has no schemas".
 *
 * The slug is the only spelling another app can write. A numeric id is per
 * instance and a uuid is per install, so a cross-app link into OpenRegister
 * ("browse the data model", "manage the object types") has nothing else to
 * name. dossiq's Data model entry is the first one.
 *
 * Case-insensitive on uuid and slug, matching the mapper: it compares
 * `LOWER(slug)` against the lower-cased identifier.
 *
 * @spec exclude Pure identifier-matching helper; register resolution contract owned by RegisterMapper.
 */

/**
 * Whether this register answers to `identifier`.
 *
 * @param {object} register A register as the API serialises it.
 * @param {string|number} identifier An id, uuid or slug.
 * @return {boolean} True when the register answers to that name.
 */
export function registerAnswersTo(register, identifier) {
	if (!register || identifier === null || identifier === undefined) {
		return false
	}
	const wanted = String(identifier)
	if (wanted === '') {
		return false
	}
	const lowered = wanted.toLowerCase()
	// The id is compared as a string because route params are strings and the
	// API returns numbers. The other two are lower-cased, as the mapper does.
	return (
		String(register.id ?? '') === wanted
		|| (register.uuid ? String(register.uuid).toLowerCase() === lowered : false)
		|| (register.slug ? String(register.slug).toLowerCase() === lowered : false)
	)
}

/**
 * The register in `registers` that answers to `identifier`.
 *
 * @param {Array<object>} registers The loaded registers.
 * @param {string|number} identifier An id, uuid or slug.
 * @return {object|undefined} The matching register, or undefined.
 */
export function findRegisterByIdentifier(registers, identifier) {
	if (!Array.isArray(registers)) {
		return undefined
	}
	return registers.find((register) => registerAnswersTo(register, identifier))
}
