import type { ZodSafeParseResult } from 'zod'
import type { TObject } from './object.types'

import { z } from 'zod'
import { withFallbacks } from '../withFallbacks.js'

const SELF_FALLBACKS = {
	id: '',
	name: null,
	description: null,
	uuid: '',
	uri: '',
	version: null,
	register: '',
	schema: '',
	schemaVersion: null,
	relations: null,
	files: null,
	folder: null,
	textRepresentation: null,
	locked: null,
	owner: null,
	authorization: null,
	application: null,
	organisation: null,
	groups: null,
	validation: null,
	deleted: null,
	geo: null,
	retention: null,
	size: null,
	updated: '',
	created: '',
}

/**
 * Entity class representing an Object with validation
 */
export class ObjectEntity implements TObject {
	'@self': TObject['@self'];

	[key: string]: unknown

	/**
	 * @param object
	 * @spec exclude Entity model field-copy boilerplate: copies typed @self fields off the input with || defaults plus an extra-key passthrough; no standalone behavioural contract (validate() carries the schema contract).
	 */
	constructor(object: TObject) {
		this['@self'] = withFallbacks(object['@self'], SELF_FALLBACKS)

		// Copy any additional properties
		Object.keys(object).forEach((key) => {
			if (key !== '@self') {
				this[key] = object[key]
			}
		})
	}

	/**
	 * Validates the object against a schema
	 */
	public validate(): ZodSafeParseResult<unknown> {
		const schema = z
			.object({
				'@self': z.object({
					id: z.string().min(1),
					name: z.string().nullable(),
					description: z.string().nullable(),
					uuid: z.string().min(1),
					uri: z.string().min(1),
					version: z.string().nullable(),
					register: z.string().min(1),
					schema: z.string().min(1),
					schemaVersion: z.string().nullable(),
					relations: z
						.union([z.string(), z.array(z.unknown())])
						.nullable(),
					files: z.union([z.string(), z.array(z.unknown())]).nullable(),
					folder: z.string().nullable(),
					textRepresentation: z.string().nullable(),
					locked: z.array(z.unknown()).nullable(),
					owner: z.string().nullable(),
					authorization: z.array(z.unknown()).nullable(),
					application: z.string().nullable(),
					organisation: z.string().nullable(),
					groups: z.array(z.unknown()).nullable(),
					validation: z.array(z.unknown()).nullable(),
					deleted: z.array(z.unknown()).nullable(),
					geo: z.array(z.unknown()).nullable(),
					retention: z.array(z.unknown()).nullable(),
					size: z.string().nullable(),
					updated: z.string().min(1),
					created: z.string().min(1),
				}),
			})
			.passthrough()

		return schema.safeParse(this)
	}
}
