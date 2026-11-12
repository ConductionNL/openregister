/**
 * Golden record of explainError: message, details, code and status for
 * every branch of the slug, status and server-message handling.
 */
import { explainError } from './migrationPacks.js'

jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	translate: jest.fn((app, text) => `[${text}]`),
}))

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))

jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => path),
}))

jest.mock('@nextcloud/auth', () => ({
	__esModule: true,
	getCurrentUser: jest.fn(() => null),
}))

const response = (status, data) => ({ response: { status, data } })

const CASES = [
	['no error', undefined],
	['null error', null],
	['no response', new Error('offline')],
	['403 without body', response(403)],
	['403 with known slug', response(403, { error: 'pack-not-found' })],
	['known slug', response(404, { error: 'pack-not-found', message: 'server' })],
	[
		'known slug with details',
		response(422, {
			error: 'invalid-pack',
			details: { errors: ['a', 2, null] },
		}),
	],
	[
		'details not a list',
		response(422, { error: 'invalid-pack', details: { errors: 'a' } }),
	],
	[
		'unknown slug with message',
		response(409, { error: 'some-other-slug', message: 'Server says' }),
	],
	[
		'unknown slug with empty message',
		response(409, { error: 'some-other-slug', message: '' }),
	],
	[
		'unknown slug with non-string message',
		response(409, { error: 'some-other-slug', message: 5 }),
	],
	[
		'generic slug',
		response(500, { error: 'migration-pack-error', message: 'boom' }),
	],
	['not a slug', response(400, { error: 'Not A Slug', message: 'm' })],
	['slug with trailing hyphen', response(400, { error: 'bad-', message: 'm' })],
	['error not a string', response(400, { error: 5, message: 'm' })],
	['inherited key as slug', response(400, { error: 'constructor', message: 'm' })],
	['status 0 with data', { response: { data: { error: 'pack-slug-exists' } } }],
]

describe('explainError golden', () => {
	it.each(CASES)('%s', (_label, error) => {
		expect({
			plain: explainError(error, 'Fallback'),
			ownTexts: explainError(error, 'Fallback', {
				'pack-not-found': 'Own not found',
				'some-other-slug': 'Own other',
				'admin-required': 'Own admin',
				'migration-pack-error': 'Own generic',
			}),
		}).toMatchSnapshot()
	})
})
