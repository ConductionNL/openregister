import type { ZodSafeParseResult } from 'zod'
import type { TConfiguration } from './configuration.types'

import { z } from 'zod'

const CONFIGURATION_SCHEMA = z.object({
	id: z.string().min(1),
	title: z.string().min(1),
	description: z.string().nullable(),
	type: z.string(),
	application: z.string(),
	owner: z.string(),
	organisation: z.number().nullable(),
	registers: z.array(z.number()).optional(),
	schemas: z.array(z.number()).optional(),
	created: z.string(),
	updated: z.string(),
})

// The configuration fields, how each is copied: `||` gives the fallback for
// any falsy value, `??` only for null and undefined, and COPY copies as is.
const OR = '||'
const NULLISH = '??'
const COPY = 'copy'
const FIELDS = [
	['id', OR, ''],
	['title', OR, ''],
	['description', OR, null],
	['version', NULLISH, null],
	['type', OR, ''],
	['application', OR, ''],
	['owner', OR, ''],
	['organisation', OR, null],
	['registers', OR, []],
	['schemas', OR, []],
	['created', OR, ''],
	['updated', OR, ''],
	['isLocal', COPY],
	['sourceType', COPY],
	['sourceUrl', NULLISH, null],
	['localVersion', NULLISH, null],
	['remoteVersion', NULLISH, null],
	['syncEnabled', COPY],
	['syncInterval', COPY],
	['syncStatus', COPY],
	['lastSyncDate', NULLISH, null],
	['githubRepo', NULLISH, null],
	['githubBranch', NULLISH, null],
	['githubPath', NULLISH, null],
	['app', COPY],
] as const

/**
 * One configuration field, copied the way FIELDS says.
 *
 * @param configuration The input.
 * @param field The field, its copy rule and its fallback.
 * @return The value.
 */
function fieldValue(
	configuration: TConfiguration,
	field: readonly unknown[],
): unknown {
	const [key, rule, fallback] = field as [keyof TConfiguration, string, unknown]
	const value = configuration[key]
	if (rule === OR) {
		return value || (Array.isArray(fallback) ? [] : fallback)
	}
	return rule === NULLISH ? (value ?? fallback) : value
}

// The instance fields come from TConfiguration, so they are listed once.
const ConfigurationFields = class {} as new () => TConfiguration

/**
 * Entity class representing a Configuration with validation
 */
export class ConfigurationEntity extends ConfigurationFields {
	/**
	 * @param configuration The configuration data to copy.
	 * @spec exclude Entity model field-copy boilerplate: copies typed fields off the input with || defaults; no standalone behavioural contract.
	 */
	constructor(configuration: TConfiguration) {
		super()
		Object.assign(
			this,
			Object.fromEntries(
				FIELDS.map((field) => [field[0], fieldValue(configuration, field)]),
			),
		)
	}

	/**
	 * Validates the configuration against a schema
	 */
	public validate(): ZodSafeParseResult<unknown> {
		return CONFIGURATION_SCHEMA.safeParse(this)
	}
}
