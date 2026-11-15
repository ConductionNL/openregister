import { flushPromises, mount } from '@vue/test-utils'
import FlowRunDetail from './FlowRunDetail.vue'

/**
 * Golden record of the run page's header, facts and steps: the texts it shows
 * for a failed run, the version badge read from the version list, and the
 * buttons the e2e journey reads.
 *
 * @spec exclude Golden record of the client-side run page.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(() => Promise.reject(new Error('offline'))) } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => path }))
jest.mock('@nextcloud/l10n', () => ({
	__esModule: true,
	t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	translate: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}`,
	n: (app, one, many, count) => `n:${count}:${many}`,
	translatePlural: (app, one, many, count) => `n:${count}:${many}`,
	getCanonicalLocale: () => 'en-GB',
	getLanguage: () => 'en',
}))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('@conduction/nextcloud-vue', () => {
	const { h } = jest.requireActual('vue')
	return {
		__esModule: true,
		CnStatusBadge: {
			name: 'CnStatusBadge',
			props: ['label', 'variant'],
			render() {
				return h('span', `[${this.variant}] ${this.label}`)
			},
		},
	}
})
jest.mock('vue-material-design-icons/PlayCircleOutline.vue', () => ({ __esModule: true, default: { render: () => null } }))

const RUN = {
	uuid: 'run-1234-0000-4000-8000-000000000000',
	flowId: 'flow-1',
	status: 'failed',
	created: '2026-11-02T10:00:00Z',
	finished: '2026-11-02T10:00:05Z',
	trigger: 'manual',
	flowVersion: 1,
	error: 'Boom',
	subjectUuid: 'e2236ef7-0000-4000-8000-000000000000',
	log: [],
}

/**
 * Mount the run page, let its (failing) load settle, then give it a run.
 *
 * @param {object} state The data to show
 * @return {Promise<object>} The wrapper
 */
async function mounted(state) {
	const wrapper = mount(FlowRunDetail, {
		props: { uuid: RUN.uuid },
		global: {
			stubs: { 'router-link': { props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' } },
			mocks: { $router: { push: jest.fn() } },
			config: { globalProperties: { t: (app, text, vars) => `t:${text}${vars ? JSON.stringify(vars) : ''}` } },
		},
	})
	await flushPromises()
	await wrapper.setData({ loading: false, run: RUN, flow: { id: 'flow-1', name: 'Enquiry' }, ...state })
	return wrapper
}

describe('FlowRunDetail', () => {
	// The golden pins UTC wall-clock times whatever timezone the machine runs in.
	beforeEach(() => {
		const toLocaleString = Date.prototype.toLocaleString
		jest.spyOn(Date.prototype, 'toLocaleString').mockImplementation(function(locale, options) {
			return toLocaleString.call(this, locale, { ...options, timeZone: 'UTC' })
		})
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	it('shows the header with the listed semantic version', async () => {
		const wrapper = await mounted({ versions: [{ version: 1, semver: '1.0.0' }] })
		expect(wrapper.find('[data-testid="run-detail-title"]').text()).toMatchSnapshot()
		expect(wrapper.find('header').text().replace(/\s+/g, ' ')).toMatchSnapshot()
	})

	it('falls back to the version number when the version is not listed', async () => {
		const wrapper = await mounted({ versions: [] })
		expect(wrapper.find('header').text()).toContain('v1')
		expect(wrapper.find('header').text()).not.toContain('1.0.0')
	})

	it('shows no version badge without a version', async () => {
		const wrapper = await mounted({ run: { ...RUN, flowVersion: null }, versions: [] })
		expect(wrapper.find('header').text().replace(/\s+/g, ' ')).toMatchSnapshot()
	})

	it('shows the whole page text', async () => {
		const wrapper = await mounted({ versions: [{ version: 1, semver: '1.0.0' }] })
		expect(wrapper.text().replace(/\s+/g, ' ')).toMatchSnapshot()
	})
})
