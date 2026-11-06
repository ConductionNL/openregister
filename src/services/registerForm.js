/**
 * What every form that edits a register shares: its field definitions, its
 * schema options and the payload it saves.
 *
 * @spec exclude Client-side form plumbing; the register contract lives in the register store.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { translate as t } from '@nextcloud/l10n'

/**
 * The form definition of a register.
 *
 * @param {object} extraProperties Properties to list after the shared ones
 * @return {object} JSON-schema-like definition for the form
 */
export function registerFormSchema(extraProperties = {}) {
	const required = (title, order) => ({
		type: 'string',
		title,
		required: true,
		minLength: 1,
		order,
	})
	return {
		title: t('openregister', 'Register'),
		properties: {
			title: required(t('openregister', 'Title'), 1),
			slug: required(t('openregister', 'Slug'), 2),
			description: { type: 'string', title: t('openregister', 'Description'), order: 3 },
			schemas: { type: 'array', title: t('openregister', 'Schemas'), order: 4 },
			...extraProperties,
		},
		required: ['title', 'slug'],
	}
}

/**
 * The select options for a list of schemas.
 *
 * @param {Array} schemas The schemas
 * @return {Array} The options
 */
export function schemaOptionsOf(schemas) {
	return schemas.map((schema) => ({ id: schema.id, label: schema.title }))
}

/**
 * Form data as the register store saves it: schemas as ids.
 *
 * @param {object} formData The edited fields
 * @return {object} The register to save
 */
export function registerPayload(formData) {
	return {
		...formData,
		schemas: (formData.schemas || []).map((schema) =>
			typeof schema === 'object' ? schema.id : schema,
		),
	}
}
