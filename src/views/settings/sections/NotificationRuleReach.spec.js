/**
 * The notification rule reach section tells an administrator which rules
 * resolved to nobody, how often and when last, says so when every rule
 * reached somebody, and says so when the record could not be read.
 *
 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import NotificationRuleReach from './NotificationRuleReach.vue'

jest.mock('@nextcloud/axios', () => ({ get: jest.fn() }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (url) => url }))
jest.mock('../../../components/shared/SettingsSection.vue', () => ({
	__esModule: true,
	default: {
		name: 'SettingsSection',
		props: ['name', 'description'],
		template: '<section><h2>{{ name }}</h2><p class="description">{{ description }}</p><slot /></section>',
	},
}))

/**
 * Mounts the section over this answer of the endpoint.
 *
 * @param {Function} answer What the GET does
 * @return {Promise<object>} The wrapper
 */
async function section(answer) {
	axios.get.mockImplementation(answer)
	const wrapper = mount(NotificationRuleReach, {
		global: {
			mocks: {
				t: (app, text, vars = {}) => Object.entries(vars).reduce((out, [k, v]) => out.replace(`{${k}}`, v), text),
			},
		},
	})
	await flushPromises()
	return wrapper
}

describe('notification rule reach section', () => {
	beforeEach(() => {
		axios.get.mockReset()
	})

	it('reads the reach record from the administrator endpoint', async () => {
		await section(() => Promise.resolve({ data: { rulesReachingNobody: [] } }))

		expect(axios.get).toHaveBeenCalledWith('/apps/openregister/api/notification-rule-reach')
	})

	it('names each rule that reached nobody with how often and when last', async () => {
		const wrapper = await section(() => Promise.resolve({
			data: {
				rulesReachingNobody: [
					{ rule: 'case-assigned', occurrences: 12, lastSeen: '2023-11-14T22:13:20Z' },
					{ rule: 'task-due', occurrences: 1, lastSeen: '2023-11-13T08:00:00Z' },
				],
			},
		}))

		const rules = wrapper.findAll('.rule-reach__rule')
		expect(rules).toHaveLength(2)
		expect(rules[0].find('.rule-reach__name').text()).toBe('case-assigned')
		expect(rules[0].text()).toContain('Times reached nobody: 12.')
		expect(rules[0].text()).toContain(new Date('2023-11-14T22:13:20Z').toLocaleString())
		expect(rules[1].find('.rule-reach__name').text()).toBe('task-due')
		expect(wrapper.text()).not.toContain('Every notification rule reached at least one recipient.')
	})

	it('says every rule reached somebody when the record is empty', async () => {
		const wrapper = await section(() => Promise.resolve({ data: { rulesReachingNobody: [], needsAPerson: false } }))

		expect(wrapper.findAll('.rule-reach__rule')).toHaveLength(0)
		expect(wrapper.text()).toContain('Every notification rule reached at least one recipient.')
	})

	it('says it could not read the record rather than claiming all is well', async () => {
		const wrapper = await section(() => Promise.reject(new Error('403')))

		expect(wrapper.text()).toContain('Could not load which notification rules reached nobody.')
		expect(wrapper.text()).not.toContain('Every notification rule reached at least one recipient.')
	})

	it('shows it is loading until the record arrives', async () => {
		const wrapper = await section(() => new Promise(() => {}))

		expect(wrapper.text()).toContain('Loading…')
	})

	it('treats a malformed answer as an empty record', async () => {
		const wrapper = await section(() => Promise.resolve({ data: { rulesReachingNobody: 'nope' } }))

		expect(wrapper.findAll('.rule-reach__rule')).toHaveLength(0)
		expect(wrapper.text()).toContain('Every notification rule reached at least one recipient.')
	})

	it('shows an unreadable time as it was given', () => {
		expect(NotificationRuleReach.methods.formatDate('not a date')).toBe('not a date')
	})
})
