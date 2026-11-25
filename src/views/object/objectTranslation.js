/**
 * The object page's Translate action (ai-translation-with-a-glossary task
 * 3.2): whether an object can be translated, and how a bulk-translate result
 * lands on the object before it is saved.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */

/** The reason prefix BulkTranslationService gives a field it stored as a draft. */
export const GLOSSARY_MISS = 'glossary-terms-missing:'

/**
 * The names of a schema's translatable properties, in schema order.
 *
 * @param {object|null} schema The schema, as `GET /api/schemas/{id}` answers it.
 * @return {string[]} The property names.
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */
export function translatableProperties(schema) {
	const properties = schema?.properties
	if (!properties || typeof properties !== 'object' || Array.isArray(properties)) {
		return []
	}
	return Object.keys(properties).filter((name) => properties[name]?.translatable === true)
}

/**
 * The languages a register lists, without blanks or repeats.
 *
 * @param {object|null} register The register, as `GET /api/registers/{id}` answers it.
 * @return {string[]} The language codes, the default first.
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */
export function registerLanguages(register) {
	const languages = Array.isArray(register?.languages) ? register.languages : []
	return [...new Set(languages.filter((code) => typeof code === 'string' && code !== ''))]
}

/**
 * Whether the Translate action is offered: an administrator, a register with
 * more than one language and a schema with a translatable property.
 *
 * @param {object} facts The facts.
 * @param {boolean} facts.isAdmin Whether the viewer is an administrator.
 * @param {string[]} facts.languages The register's languages.
 * @param {string[]} facts.translatable The schema's translatable properties.
 * @return {boolean} True when the action is shown.
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */
export function canTranslate({ isAdmin, languages, translatable }) {
	return isAdmin === true && languages.length > 1 && translatable.length > 0
}

/**
 * The object with the translated values put in their target language. A
 * value that is still a plain string is the legacy Dutch value, so it becomes
 * `{ nl: value }` first, as BulkTranslationService reads it.
 *
 * @param {object} object The object as the page holds it.
 * @param {{to: string, translated: Object<string, string>}} result The bulk-translate answer.
 * @return {object|null} The object to save, or null when nothing was translated.
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */
export function withTranslations(object, result) {
	const translated = result?.translated
	const to = result?.to
	if (!object || !to || !translated || typeof translated !== 'object' || Object.keys(translated).length === 0) {
		return null
	}

	const updated = { ...object }
	for (const [property, text] of Object.entries(translated)) {
		const existing = object[property]
		let slots = {}
		if (existing && typeof existing === 'object' && !Array.isArray(existing)) {
			slots = { ...existing }
		} else if (typeof existing === 'string' && existing !== '') {
			slots = { nl: existing }
		}
		updated[property] = { ...slots, [to]: text }
	}
	return updated
}

/**
 * Split a bulk-translate answer's `skipped` map into the fields stored as a
 * draft because a glossary term is missing, with those terms, and the fields
 * that were not translated at all.
 *
 * @param {{translated?: object, skipped?: Object<string, string>}} result The answer.
 * @return {{drafted: Array<{property: string, terms: string}>, skipped: Array<{property: string, reason: string}>}} The two lists.
 * @spec openspec/changes/ai-translation-with-a-glossary/tasks.md
 */
export function draftedAndSkipped(result) {
	const drafted = []
	const skipped = []
	for (const [property, reason] of Object.entries(result?.skipped || {})) {
		const text = String(reason ?? '')
		if (text.startsWith(GLOSSARY_MISS)) {
			drafted.push({ property, terms: text.slice(GLOSSARY_MISS.length).trim() })
		} else {
			skipped.push({ property, reason: text })
		}
	}
	return { drafted, skipped }
}
