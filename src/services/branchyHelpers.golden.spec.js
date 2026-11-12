/**
 * Golden record of helpers with many branches: the schema explorer's
 * suggested property configuration, the date picker's string parsing, the
 * Mail URL parser, the file metadata update, the map's representative point,
 * the search trail statistics mapping and the object list refresh.
 *
 * @spec exclude Golden record of client-side helpers and API passthroughs.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { createPinia, setActivePinia } from 'pinia'
import { parseMailUrl } from '../mail-sidebar/composables/useMailObserver.js'
import { useObjectStore } from '../store/modules/object.js'
import { useRegisterStore } from '../store/modules/register.js'
import { useSchemaStore } from '../store/modules/schema.js'
import { useSearchTrailStore } from '../store/modules/searchTrail.js'
import { stringToDate } from './dateUtils.js'
import { updateFileMetadata } from './fileMetadata.js'
import { suggestedPropertyConfig } from './schemaExploration.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), put: jest.fn(), post: jest.fn(), delete: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: jest.fn((path) => `/index.php${path}`),
}))

/**
 * A value as snapshot text, with a Date shown by its ISO time.
 *
 * @param {Date|string|null} value The value.
 * @return {string|null} The shown value.
 */
function shown(value) {
	return value instanceof Date ? `Date(${value.toISOString()})` : value
}

/**
 * A parsed date as snapshot text that does not depend on the time zone the
 * test runs in. A value read as an instant (a Date passed in, or a date-time
 * with `Z` or with no time part, which JavaScript reads as UTC) is shown by
 * its ISO time; a local date or time by its local fields.
 *
 * @param {Date|null} parsed The parsed value.
 * @param {{input: (Date|string|null), format: (string|undefined)}} read What was parsed, and as which format.
 * @return {string|null} The shown value.
 */
function shownParsed(parsed, read) {
	if (!(parsed instanceof Date)) {
		return parsed
	}
	const text = String(read.input)
	const instant =
		read.input instanceof Date
		|| (read.format === 'date-time'
			&& (text.endsWith('Z') || /^\d{4}(-\d{2}){0,2}$/.test(text)))
	if (instant) {
		return shown(parsed)
	}
	const two = (n) => String(n).padStart(2, '0')
	return `Local(${parsed.getFullYear()}-${two(parsed.getMonth() + 1)}-${two(parsed.getDate())} ${two(parsed.getHours())}:${two(parsed.getMinutes())}:${two(parsed.getSeconds())})`
}

describe('branchy helpers golden', () => {
	beforeEach(() => {
		jest.useFakeTimers()
		jest.setSystemTime(new Date('2026-03-04T05:06:07Z'))
	})
	afterEach(() => jest.useRealTimers())

	it('suggests property configurations', () => {
		const suggestions = [
			{},
			{
				recommended_type: 'string',
				property_name: 'name',
				detected_format: 'email',
				max_length: 20,
				min_length: 3,
				description: 'A name',
			},
			{ max_length: 0, min_length: null },
			{ max_length: -1, min_length: Number.MAX_SAFE_INTEGER },
			{ max_length: null, min_length: 0 },
			{ min_length: Number.MAX_SAFE_INTEGER - 1, max_length: '5' },
			{ detected_format: null, description: null },
		]
		expect(
			suggestions.map((suggestion) => suggestedPropertyConfig(suggestion)),
		).toMatchSnapshot()
	})

	it('reads date strings', () => {
		const cases = []
		for (const format of [
			'date',
			'time',
			'date-time',
			'other',
			undefined,
			'toString',
			'constructor',
		]) {
			for (const value of [
				null,
				'',
				'2026-02-03',
				'2026-02-03 10:11:12',
				'2026-02-03T10:11:12Z',
				'10:11',
				'10:11:12',
				'1:x',
				'garbage',
				'2026-02',
				new Date('2020-01-01T00:00:00Z'),
			]) {
				cases.push([
					format,
					shown(value),
					shownParsed(stringToDate(value, format), {
						input: value,
						format,
					}),
				])
			}
		}
		expect(cases).toMatchSnapshot()
	})

	it('parses Mail URLs', () => {
		const urls = [
			null,
			'',
			'https://x/apps/mail/box/priority/thread/6',
			'/apps/mail/box/2/thread/42',
			'/apps/mail/box/2',
			'/apps/mail/box/2/',
			'#/accounts/3/folders/INBOX/messages/42',
			'#/accounts/3/folders/INBOX',
			'#/accounts/3/folders/',
			'/apps/mail/box/x',
			'/apps/files',
		]
		expect(urls.map((url) => [url, parseMailUrl(url)])).toMatchSnapshot()
	})

	it('updates file metadata', async () => {
		const calls = []
		const results = []
		const variants = [
			{},
			{ description: 'd' },
			{ description: null, category: 'c', labels: ['a'] },
			{ description: undefined, category: '', labels: [] },
			{ description: 'd', category: 'c', labels: null },
		]
		for (const [index, extra] of variants.entries()) {
			global.fetch = jest.fn(async (url, init) => {
				calls.push([url, init])
				return {
					ok: index !== 4,
					status: 409,
					json: async () => ({ id: index }),
				}
			})
			try {
				results.push({
					returned: await updateFileMetadata({
						registerId: 1,
						schemaId: 2,
						objectId: 'o',
						fileId: 9,
						...extra,
					}),
				})
			} catch (error) {
				results.push({ thrown: error.message })
			}
		}
		expect({ calls, results }).toMatchSnapshot()
	})

	it('maps search trail statistics', () => {
		setActivePinia(createPinia())
		const store = useSearchTrailStore()
		const out = []
		for (const stats of [
			{},
			{
				total_searches: 5,
				total_results: 9,
				avg_results_per_search: 1.5,
				avg_response_time: 20,
				success_rate: 50,
				unique_search_terms: 3,
				unique_users: 2,
				unique_organizations: 1,
				avg_searches_per_session: 4,
				avg_object_views_per_session: 6,
				query_complexity: { simple: 1 },
			},
			{ success_rate: 0, total_searches: null, query_complexity: null },
		]) {
			store.setStatistics(stats)
			out.push(JSON.parse(JSON.stringify(store.statistics)))
		}
		expect(out).toMatchSnapshot()
	})

	it('refreshes the object list', async () => {
		const out = []
		const scopes = [
			[{}, null, null],
			[{}, { id: 1 }, { id: 2 }],
			[
				{ register: 5, schema: 6, limit: 10, page: 2, search: 'q' },
				null,
				null,
			],
			[{ register: 5, limit: 0, page: null, search: '' }, null, { id: 7 }],
			[{ register: 0, schema: 6 }, { id: 1 }, null],
			[{ register: 5 }, null, null],
		]
		for (const [options, register, schema] of scopes) {
			setActivePinia(createPinia())
			useRegisterStore().registerItem = register
			useSchemaStore().schemaItem = schema
			const store = useObjectStore()
			store.filters = { status: 'open' }
			const fetched = []
			store.fetchCollection = jest.fn(async (type, params) => {
				fetched.push([type, params])
				return [{ id: 'r' }]
			})
			store.getPagination = jest.fn(() => ({
				total: 30,
				page: 3,
				pages: 4,
				limit: 10,
			}))
			store.registerObjectType = jest.fn((...args) =>
				fetched.push(['register', ...args]),
			)
			try {
				out.push({
					returned: await store.refreshObjectList(options),
					fetched,
				})
			} catch (error) {
				out.push({ thrown: error.message, fetched })
			}
			out.push({
				secondCall:
					(await store.refreshObjectList(options).catch((e) => e.message))
					&& fetched.length,
			})
		}
		expect(out).toMatchSnapshot()
	})
})
