import { z } from 'zod'

/**
 * The resources a quota limits and a usage counts, in this order.
 */
const RESOURCES = ['storage', 'bandwidth', 'requests', 'users', 'groups']

/**
 * An optional object with one optional field per resource.
 *
 * @param {z.ZodType} field The schema of each resource field.
 * @return {z.ZodOptional<z.ZodObject>} The object schema.
 *
 * @spec exclude Entity validation schema builder: no standalone behavioural contract.
 */
function perResource(field) {
	return z
		.object(Object.fromEntries(RESOURCES.map((key) => [key, field.optional()])))
		.optional()
}

/**
 * Validates a quota block: numbers or null, every field optional.
 */
export const QUOTA_SCHEMA = perResource(z.number().nullable())

/**
 * Validates a usage block: numbers, every field optional.
 */
export const USAGE_SCHEMA = perResource(z.number())
