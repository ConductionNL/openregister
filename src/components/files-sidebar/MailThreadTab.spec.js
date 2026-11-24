/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Files sidebar conversation tab: the thread a collected mail belongs to.
 *
 * @spec openspec/changes/reply-threading-by-headers/specs/integration-email/spec.md#requirement-a-message-is-read-with-the-messages-around-it-req-rth-011
 */
import axios from '@nextcloud/axios'
import { flushPromises, mount } from '@vue/test-utils'
import MailThreadTab, { depthsOf } from './MailThreadTab.vue'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (path, params = {}) =>
		'/index.php' + path.replace(/{(\w+)}/g, (m, key) => params[key]),
}))
jest.mock('@nextcloud/vue', () => ({
	NcLoadingIcon: { name: 'NcLoadingIcon', render: () => null },
	NcEmptyContent: {
		name: 'NcEmptyContent',
		props: ['name'],
		template: '<div class="empty">{{ name }}</div>',
	},
}))
jest.mock('vue-material-design-icons/EmailOutline.vue', () => ({
	name: 'EmailOutline',
	render: () => null,
}))
jest.mock('../shared/TabErrorState.vue', () => ({
	name: 'TabErrorState',
	props: ['name', 'description'],
	template: '<div class="error">{{ name }}|{{ description }}</div>',
}))

/**
 * One message of a thread.
 *
 * @param {number} fileId The file
 * @param {string} messageId The Message-ID
 * @param {string|null} parent The Message-ID it answers
 * @return {object} The thread node
 */
function node(fileId, messageId, parent) {
	return {
		fileId,
		messageId,
		parent,
		sentAt: `2026-11-0${fileId}T10:00:00+00:00`,
		from: '',
		subject: `Mail ${fileId}`,
		gap: false,
	}
}

// Five mails: 2 and 3 answer 1, 4 answers 3, 5 answers 4.
const THREAD = [
	node(1, '<a@x>', null),
	node(2, '<b@x>', '<a@x>'),
	node(3, '<c@x>', '<a@x>'),
	node(4, '<d@x>', '<c@x>'),
	node(5, '<e@x>', '<d@x>'),
]

/**
 * Mount the tab for a file and let it load.
 *
 * @param {number} fileId The file shown
 * @return {Promise<object>} The wrapper
 */
async function mountTab(fileId = 4) {
	const wrapper = mount(MailThreadTab, { props: { fileId } })
	await flushPromises()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('MailThreadTab', () => {
	it('asks the thread endpoint for the shown file', async () => {
		axios.get.mockResolvedValue({ data: { thread: [], position: null } })
		await mountTab(4)
		expect(axios.get).toHaveBeenCalledWith('/index.php/apps/openregister/api/files/4/thread')
	})

	it('lists every message in date order with the shown one marked', async () => {
		axios.get.mockResolvedValue({ data: { thread: THREAD, position: 3 } })
		const wrapper = await mountTab(4)

		const items = wrapper.findAll('li')
		expect(items.map((li) => li.find('.mail-thread-tab__subject').text())).toEqual([
			'Mail 1', 'Mail 2', 'Mail 3', 'Mail 4', 'Mail 5',
		])
		expect(items.map((li) => li.attributes('aria-current') ?? null)).toEqual([
			null, null, null, 'true', null,
		])
		expect(items[3].classes()).toContain('mail-thread-tab__item--current')
		expect(items[3].find('a').exists()).toBe(false)
	})

	it('opens each other message on click through its file link', async () => {
		axios.get.mockResolvedValue({ data: { thread: THREAD, position: 3 } })
		const wrapper = await mountTab(4)

		const links = wrapper.findAll('a').map((a) => a.attributes('href'))
		expect(links).toEqual(['/index.php/f/1', '/index.php/f/2', '/index.php/f/3', '/index.php/f/5'])
	})

	it('places a reply under the message it answers', async () => {
		axios.get.mockResolvedValue({ data: { thread: THREAD, position: 3 } })
		const wrapper = await mountTab(4)

		expect(wrapper.findAll('li').map((li) => li.attributes('style'))).toEqual([
			'--mail-thread-depth: 0;',
			'--mail-thread-depth: 1;',
			'--mail-thread-depth: 1;',
			'--mail-thread-depth: 2;',
			'--mail-thread-depth: 3;',
		])
	})

	it('shows a gap by its Message-ID without a link', async () => {
		const gap = { messageId: '<lost@x>', parent: null, sentAt: null, from: '', subject: '', gap: true }
		axios.get.mockResolvedValue({ data: { thread: [gap, node(2, '<b@x>', '<lost@x>')], position: 1 } })
		const wrapper = await mountTab(2)

		const first = wrapper.findAll('li')[0]
		expect(first.classes()).toContain('mail-thread-tab__item--gap')
		expect(first.text()).toContain('Not available')
		expect(first.text()).toContain('<lost@x>')
		expect(first.find('a').exists()).toBe(false)
		expect(wrapper.findAll('li')[1].attributes('style')).toBe('--mail-thread-depth: 1;')
	})

	it('says so when the mail is not part of a conversation', async () => {
		axios.get.mockResolvedValue({ data: { thread: [], position: null } })
		const wrapper = await mountTab()
		expect(wrapper.find('.empty').text()).toBe('This message is not part of a conversation')
		expect(wrapper.find('li').exists()).toBe(false)
	})

	it('shows the error the endpoint names', async () => {
		axios.get.mockRejectedValue({ message: 'boom', response: { data: { error: 'File not found' } } })
		const wrapper = await mountTab()
		expect(wrapper.find('.error').text()).toBe('Failed to load the conversation|File not found')
	})

	it('reloads when another file is shown', async () => {
		axios.get.mockResolvedValue({ data: { thread: THREAD, position: 3 } })
		const wrapper = await mountTab(4)
		await wrapper.setProps({ fileId: 5 })
		await flushPromises()
		expect(axios.get).toHaveBeenLastCalledWith('/index.php/apps/openregister/api/files/5/thread')
	})
})

describe('depthsOf', () => {
	it('stops at a cycle instead of recursing forever', () => {
		const depths = depthsOf([
			{ messageId: '<a@x>', parent: '<b@x>' },
			{ messageId: '<b@x>', parent: '<a@x>' },
		])
		expect([...depths.values()].every((d) => Number.isInteger(d))).toBe(true)
	})
})
