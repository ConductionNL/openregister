/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

/**
 * A schema title as it reads inside a sentence: lower case, unless it is an
 * acronym or a name with capitals after its first letter ("GDPR", "OpenProject").
 *
 * @param {string} title The schema title.
 * @return {string}
 */
export function sentenceName(title) {
	const text = String(title || '')
	if (/^.[^A-Z]*$/.test(text) === false) return text
	return text.charAt(0).toLocaleLowerCase() + text.slice(1)
}

/**
 * What one item of a schema is called inside a sentence: the schema's
 * `mailObjectName` ("work queue item") when it sets one, else its title in
 * sentence case ("case").
 *
 * @param {object|null} schema The schema.
 * @return {string}
 */
export function itemName(schema) {
	const name = schema?.configuration?.mailObjectName
	return typeof name === 'string' && name.trim() !== '' ? name.trim() : sentenceName(schema?.title)
}
