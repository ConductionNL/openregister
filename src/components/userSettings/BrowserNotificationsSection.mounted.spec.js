/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The browser notifications section mounted: what it shows for each browser
 * state and what the toggle does, never prompting on its own.
 *
 * @spec exclude Unit test of the client-side browser notifications section.
 */
import { flushPromises, mount } from '@vue/test-utils'
import BrowserNotificationsSection from './BrowserNotificationsSection.vue'

jest.mock('@nextcloud/vue', () => ({
	NcAppSettingsSection: { name: 'NcAppSettingsSection', template: '<section><slot /></section>' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note" :data-type="type"><slot /></div>' },
	NcCheckboxRadioSwitch: {
		name: 'NcCheckboxRadioSwitch',
		props: ['modelValue', 'disabled'],
		emits: ['update:modelValue'],
		template: '<input type="checkbox" :checked="modelValue" :disabled="disabled" @change="$emit(\'update:modelValue\', $event.target.checked)">',
	},
}))

/**
 * Give the browser the Push API with a permission state.
 *
 * @param {string} permission The Notification permission
 * @return {void}
 */
function pushCapable(permission) {
	Object.defineProperty(window.navigator, 'serviceWorker', { value: {}, configurable: true })
	window.PushManager = function PushManager() {}
	window.Notification = { permission, requestPermission: jest.fn() }
}

/**
 * Mount the section.
 *
 * @return {Promise<object>} The wrapper
 */
async function mountSection() {
	const wrapper = mount(BrowserNotificationsSection, {
		global: { mocks: { t: (app, text) => text } },
	})
	await flushPromises()
	return wrapper
}

/**
 * Flip the switch the way the user does.
 *
 * @param {object} wrapper The wrapper
 * @param {boolean} on The requested state
 * @return {Promise<void>}
 */
async function toggle(wrapper, on) {
	wrapper.findComponent({ name: 'NcCheckboxRadioSwitch' }).vm.$emit('update:modelValue', on)
	await flushPromises()
}

const byTestId = (wrapper, id) => wrapper.find(`[data-testid="browser-notifications-${id}"]`)

afterEach(() => {
	delete window.navigator.serviceWorker
	delete window.PushManager
	delete window.Notification
	delete window.OCA
})

describe('BrowserNotificationsSection mounted', () => {
	it('says the browser cannot do web push', async () => {
		const wrapper = await mountSection()
		expect(byTestId(wrapper, 'unsupported').exists()).toBe(true)
		expect(byTestId(wrapper, 'toggle').exists()).toBe(false)
	})

	it.each([
		['default', false, 'not yet requested'],
		['granted', true, 'granted'],
		['denied', false, 'denied. Re-enable'],
	])('shows permission %s with the toggle %s, without prompting', async (permission, on, text) => {
		pushCapable(permission)
		const wrapper = await mountSection()
		expect(byTestId(wrapper, 'toggle').element.checked).toBe(on)
		expect(byTestId(wrapper, 'permission').text()).toContain(text)
		expect(window.Notification.requestPermission).not.toHaveBeenCalled()
	})

	it('subscribes on enable and unsubscribes on disable', async () => {
		pushCapable('default')
		const client = {
			enablePush: jest.fn().mockResolvedValue(),
			disablePush: jest.fn().mockResolvedValue(),
			permission: jest.fn(() => 'granted'),
		}
		window.OCA = { OpenRegister: { WebPush: client } }
		const wrapper = await mountSection()
		await toggle(wrapper, true)
		await flushPromises()
		expect(client.enablePush).toHaveBeenCalledTimes(1)
		expect(byTestId(wrapper, 'toggle').element.checked).toBe(true)
		expect(byTestId(wrapper, 'permission').text()).toContain('granted')
		await toggle(wrapper, false)
		await flushPromises()
		expect(client.disablePush).toHaveBeenCalledTimes(1)
		expect(byTestId(wrapper, 'toggle').attributes('disabled')).toBeUndefined()
	})

	it('asks to try again while the client is still loading', async () => {
		pushCapable('default')
		const wrapper = await mountSection()
		await toggle(wrapper, true)
		expect(byTestId(wrapper, 'error').text()).toContain('still loading')
	})

	it('shows a failed subscription', async () => {
		pushCapable('default')
		window.OCA = {
			OpenRegister: {
				WebPush: {
					enablePush: jest.fn().mockRejectedValue(new Error('Blocked')),
					permission: () => 'denied',
				},
			},
		}
		const wrapper = await mountSection()
		await toggle(wrapper, true)
		await flushPromises()
		expect(byTestId(wrapper, 'error').text()).toBe('Blocked')
		expect(byTestId(wrapper, 'toggle').element.checked).toBe(false)
		expect(byTestId(wrapper, 'permission').text()).toContain('denied')
	})
})
