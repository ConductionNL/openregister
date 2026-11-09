/**
 * Golden record of the webhook and webhook-log tables' three states: the
 * loading spinner, the empty state and the table itself.
 *
 * @spec exclude Golden record of client-side list markup.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { flushPromises, mount } from '@vue/test-utils'
import WebhookLogsIndex from './WebhookLogsIndex.vue'
import WebhooksIndex from './WebhooksIndex.vue'

/**
 * A stand-in icon that shows its name and size.
 *
 * @param {string} name The icon
 * @return {object} The module
 */
function mockIcon(name) {
	return {
		__esModule: true,
		default: {
			name,
			props: ['size'],
			render() {
				return require('vue').h('i', { class: `icon-${name}`, 'data-size': this.size })
			},
		},
	}
}

/**
 * A stand-in that renders nothing.
 *
 * @return {object} The module
 */
function mockBlank() {
	return { __esModule: true, default: { render: () => null } }
}

jest.mock('@conduction/nextcloud-vue', () => ({
	__esModule: true,
	CnDataTable: {
		name: 'CnDataTable',
		props: ['columns', 'rows'],
		render() {
			return require('vue').h('table', { class: 'cn-data-table', 'data-rows': this.rows.length })
		},
	},
}))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn() } }))
jest.mock('@nextcloud/dialogs', () => ({ showError: jest.fn(), showSuccess: jest.fn() }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, t: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))
jest.mock('@nextcloud/vue', () => jest.requireActual('../../../tests/unit/support/ncGolden.js').ncLibrary())
jest.mock('vue-material-design-icons/Webhook.vue', () => mockIcon('Webhook'))
jest.mock('vue-material-design-icons/FileDocumentOutline.vue', () => mockIcon('FileDocumentOutline'))
jest.mock('vue-material-design-icons/ArrowLeft.vue', () => mockBlank())
jest.mock('vue-material-design-icons/DeleteOutline.vue', () => mockBlank())
jest.mock('vue-material-design-icons/Eye.vue', () => mockBlank())
jest.mock('vue-material-design-icons/PauseCircleOutline.vue', () => mockBlank())
jest.mock('vue-material-design-icons/PlayOutline.vue', () => mockBlank())
jest.mock('vue-material-design-icons/Plus.vue', () => mockBlank())
jest.mock('vue-material-design-icons/Refresh.vue', () => mockBlank())
jest.mock('../../components/shared/ActionMenu.vue', () => mockBlank())
jest.mock('../../components/shared/FilterToggleHeader.vue', () => mockBlank())
jest.mock('../../components/shared/ListActionsBar.vue', () => mockBlank())
jest.mock('../../components/shared/OffsetPager.vue', () => mockBlank())
jest.mock('../../components/WebhooksSidebar.vue', () => mockBlank())
jest.mock('../../services/menuActions.js', () => ({ editAction: () => ({}), refreshAction: () => ({}) }))
jest.mock('../../store/store.js', () => ({
	navigationStore: { getTransferData: () => null, setTransferData: jest.fn(), setModal: jest.fn() },
	webhookStore: { loading: false, webhookList: [], refreshWebhookList: jest.fn() },
}))

/**
 * Mounts a page without its data loads, with the given state.
 *
 * @param {object} component The page
 * @param {object} computed Computed overrides
 * @param {object} data Data overrides
 * @return {Promise<string>} The table container's markup
 */
async function render(component, computed, data = {}) {
	const wrapper = mount(
		{ ...component, computed: { ...component.computed, ...computed }, mounted: undefined },
		{ global: { mocks: { $route: { params: {}, query: {} }, $router: { push: jest.fn() }, t: (app, text) => `t:${text}` }, config: { warnHandler: () => {} } } },
	)
	if (Object.keys(data).length) {
		await wrapper.setData(data)
	}
	await flushPromises()
	return wrapper.find('.tableContainer').html()
}

describe('webhook table states', () => {
	const webhooks = (loading, list) => render(WebhooksIndex, { loading: () => loading, webhooksList: () => list })
	const logs = (loading, list) => render(WebhookLogsIndex, {}, { loading, logsList: list })

	it('webhooks: loading, empty and table', async () => {
		expect([
			await webhooks(true, []),
			await webhooks(false, []),
			await webhooks(false, [{ id: 1, name: 'On' }]),
		].join('\n----\n')).toMatchSnapshot()
	})

	it('webhook logs: loading, empty and table', async () => {
		expect([
			await logs(true, []),
			await logs(false, []),
			await logs(false, [{ id: 1, webhookId: 1, success: true }]),
		].join('\n----\n')).toMatchSnapshot()
	})
})
