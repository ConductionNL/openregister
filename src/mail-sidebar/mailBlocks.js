/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Mail panel's blocks for a schema. One block per schema by default; a
 * schema that declares `configuration.mailBlocks` gets one block per entry,
 * each with its own title, item name, mail template and list filter, so one
 * schema can offer, for example, "Case" and "Work queue" over different
 * slices of the same objects.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

/**
 * The blocks a schema offers in the panel.
 *
 * A block is the schema itself with `blockKey` (what the panel keys its state
 * by), `blockFilter` (query parameters every list and search of the block
 * adds) and `appDialog` (whether an app's own create dialog may replace the
 * generic one). An entry's `title`, `name` and `template` replace the
 * schema's title, `mailObjectName` and `mailObjectTemplate` for that block.
 *
 * @param {object} schema The schema, as the API returns it.
 * @return {Array<object>} The blocks, in declared order.
 */
export function blocksOf(schema) {
	const declared = schema?.configuration?.mailBlocks
	if (!Array.isArray(declared) || declared.length === 0) {
		return [{ ...schema, blockKey: String(schema.id), blockFilter: {}, appDialog: true }]
	}

	return declared
		.map((entry, index) => [entry, index])
		.filter(([entry]) => entry && typeof entry === 'object')
		.map(([entry, index]) => ({
			...schema,
			title: entry.title || schema.title,
			configuration: {
				...schema.configuration,
				mailObjectName: entry.name ?? schema.configuration?.mailObjectName,
				mailObjectTemplate: entry.template ?? schema.configuration?.mailObjectTemplate,
			},
			blockKey: schema.id + ':' + (entry.key || index),
			blockFilter: entry.filter && typeof entry.filter === 'object' ? entry.filter : {},
			appDialog: entry.appDialog !== false,
		}))
}
