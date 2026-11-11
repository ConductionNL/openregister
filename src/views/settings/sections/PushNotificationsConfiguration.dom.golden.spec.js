import axios from '@nextcloud/axios'
/**
 * Golden record of the push notifications section: each notify_push status
 * and each browser web push (VAPID) state.
 *
 * @spec exclude Golden record of client-side settings section markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import PushNotificationsConfiguration from './PushNotificationsConfiguration.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
jest.mock('../../../components/shared/SettingsSection.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('SettingsSection'),
)
jest.mock('vue-material-design-icons/AlertCircle.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('AlertCircle'),
)
jest.mock('vue-material-design-icons/AlertOutline.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('AlertOutline'),
)
jest.mock('vue-material-design-icons/CheckCircle.vue', () =>
	require('../../__mocks__/goldenDom.js').stubModule('CheckCircle'),
)

const { normalise } = require('../../__mocks__/goldenDom.js')

/**
 * Mounts the section with this push status and VAPID answer.
 *
 * @param {string} pushStatus The notify_push status
 * @param {Function} vapid What the VAPID request does
 * @return {Promise<object>} The wrapper
 */
async function section(pushStatus, vapid) {
	axios.get.mockImplementation(vapid)
	const wrapper = mount(PushNotificationsConfiguration, {
		props: { pushStatus },
		global: {
			config: { warnHandler: () => {} },
			mocks: { t: (app, text) => `t:${text}` },
		},
	})
	await flushPromises()
	return wrapper
}

function CONFIGURED () {
  return Promise.resolve({ data: { configured: true, publicKey: 'BPubKey' } })
}
const CONFIGURED_NO_KEY = () => Promise.resolve({ data: { configured: true } })
const NOT_CONFIGURED = () => Promise.resolve({ data: { configured: false } })
const PENDING = () => new Promise(() => {})
const FAILING = () => Promise.reject(new Error('down'))

describe('push notifications section', () => {
	beforeEach(() => {
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	it.each([
		['not installed, configured', 'not_installed', CONFIGURED],
		['unreachable, not configured', 'unreachable', NOT_CONFIGURED],
		['active, configured without a key', 'active', CONFIGURED_NO_KEY],
		['active, still checking', 'active', PENDING],
		['active, check failed', 'active', FAILING],
	])('renders %s', async (name, status, vapid) => {
		const html = normalise(await section(status, vapid))
		expect(html).toContain('x-settingssection')
		expect(html).toMatchSnapshot()
	})
})
