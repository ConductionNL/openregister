/**
 * The Dutch API design rules dialog: reads a register's report, shows the rules
 * grouped by result with their findings, and lets an administrator probe.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { getCurrentUser } from '@nextcloud/auth'
import axios from '@nextcloud/axios'
import { mount } from '@vue/test-utils'
import NlGovConformanceDialog, { groupRules, RESULT_ORDER } from './NlGovConformanceDialog.vue'

jest.mock('@nextcloud/auth', () => ({ getCurrentUser: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: jest.fn(), post: jest.fn() },
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars = {}) => text.replace(/{(\w+)}/g, (m, k) => (k in vars ? String(vars[k]) : m)),
}))
jest.mock('@nextcloud/router', () => ({
	generateUrl: (url, params = {}) => `/gen${url.replace(/{(\w+)}/g, (m, k) => String(params[k]))}`,
}))
jest.mock('@nextcloud/vue', () => ({
	NcDialog: { name: 'NcDialog', props: ['name', 'size', 'open'], template: '<div class="dialog"><h2>{{ name }}</h2><slot /><div class="actions"><slot name="actions" /></div></div>' },
	NcButton: { name: 'NcButton', props: ['disabled', 'variant'], emits: ['click'], template: '<button :disabled="disabled" @click="$emit(\'click\')"><slot /></button>' },
	NcLoadingIcon: { name: 'NcLoadingIcon', template: '<span class="loading" />' },
	NcNoteCard: { name: 'NcNoteCard', props: ['type'], template: '<div class="note"><slot /></div>' },
}))

const report = {
	ruleset: '2.2.1',
	generatedAt: 'not a date',
	probedAt: null,
	rules: [
		{ id: '/core/semver', title: 'Semantic versioning', kind: 'document', result: 'fail', findings: [{ path: 'info.version', message: '"1.0" is not a semantic version' }] },
		{ id: '/core/no-trailing-slash', title: 'No trailing slash', kind: 'document', result: 'pass', findings: [] },
		{ id: '/core/uri-version', title: 'Major version in the URI', kind: 'document', result: 'deviation', deviation: 'The version is negotiated by header', findings: [] },
		{ id: '/core/version-header', title: 'Version header', kind: 'response', result: 'not-probed', findings: [] },
		{ id: '/core/naming-resources', title: 'Resource names', kind: 'functional', result: 'manual' },
		{ id: '/core/http-methods', title: 'HTTP methods', kind: 'document', result: 'pass', findings: [] },
	],
	counts: { pass: 2, fail: 1, deviation: 1, manual: 1, 'not-probed': 1 },
}

/**
 * Let pending promises settle.
 *
 * @return {Promise<void>}
 */
function settle() {
	return new Promise((resolve) => setTimeout(resolve, 0))
}

/**
 * Mount the dialog over register 7.
 *
 * @return {Promise<object>} The wrapper, its report read
 */
async function mountDialog() {
	const wrapper = mount(NlGovConformanceDialog, { propsData: { register: { id: 7, title: 'Zaken' } } })
	await settle()
	return wrapper
}

beforeEach(() => {
	jest.clearAllMocks()
	getCurrentUser.mockReturnValue({ uid: 'admin', isAdmin: true })
	axios.get.mockResolvedValue({ data: report })
})

describe('groupRules', () => {
	it('groups the rules in the fixed order, leaving empty groups out', () => {
		const groups = groupRules(report.rules)

		expect(groups.map((group) => group.result)).toEqual(RESULT_ORDER)
		expect(groups[4].rules.map((rule) => rule.id)).toEqual(['/core/no-trailing-slash', '/core/http-methods'])
		expect(groupRules([{ id: 'a', result: 'pass' }]).map((group) => group.result)).toEqual(['pass'])
	})

	it('gives a rule without findings an empty list and keeps an unknown result last', () => {
		const groups = groupRules([{ id: 'x', result: 'later' }, { id: 'y', result: 'fail' }])

		expect(groups.map((group) => group.result)).toEqual(['fail', 'later'])
		expect(groups[1].rules[0].findings).toEqual([])
		expect(groupRules(null)).toEqual([])
	})
})

describe('NlGovConformanceDialog', () => {
	it('reads the register report and renders it grouped by result', async () => {
		const wrapper = await mountDialog()

		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/registers/7/oas/conformance')
		expect(wrapper.find('h2').text()).toBe('Dutch API design rules for Zaken')
		const headings = wrapper.findAll('.or-nlgov__heading').map((heading) => heading.text())
		expect(headings).toEqual(['Failed (1)', 'Deviations (1)', 'Check by hand (1)', 'Not probed yet (1)', 'Passed (2)'])
		expect(wrapper.find('[data-testid="nlgov-group-fail"]').text()).toContain('info.version: "1.0" is not a semantic version')
		expect(wrapper.find('[data-testid="nlgov-group-deviation"]').text()).toContain('Deviation: The version is negotiated by header')
		expect(wrapper.find('[data-testid="nlgov-meta"]').text()).toBe('Ruleset 2.2.1, generated not a date.')
	})

	it('shows the probe button to an administrator and shows the probed report', async () => {
		const probed = {
			...report,
			probedAt: '2026-11-25T03:00:00Z',
			rules: [{ id: '/core/version-header', title: 'Version header', kind: 'response', result: 'fail', findings: [{ path: 'API-Version', message: '"1" is not a full version' }] }],
		}
		axios.post.mockResolvedValue({ data: probed })
		const wrapper = await mountDialog()

		await wrapper.find('[data-testid="nlgov-probe"]').trigger('click')
		await settle()

		expect(axios.post).toHaveBeenCalledWith('/gen/apps/openregister/api/registers/7/oas/conformance/probe')
		expect(wrapper.find('[data-testid="nlgov-group-fail"]').text()).toContain('/core/version-header')
		expect(wrapper.find('[data-testid="nlgov-group-fail"]').text()).toContain('"1" is not a full version')
		expect(wrapper.find('[data-testid="nlgov-meta"]').text()).toContain('Responses probed')
	})

	it('hides the probe button from anyone else', async () => {
		getCurrentUser.mockReturnValue({ uid: 'caseworker', isAdmin: false })
		const wrapper = await mountDialog()

		expect(wrapper.find('[data-testid="nlgov-probe"]').exists()).toBe(false)
		expect(wrapper.findAll('.or-nlgov__rule')).toHaveLength(6)
	})

	it('says the report could not be read and shows no rules', async () => {
		axios.get.mockRejectedValue(new Error('503'))
		const wrapper = await mountDialog()

		expect(wrapper.find('.note').text()).toBe('The conformance report could not be read.')
		expect(wrapper.findAll('.or-nlgov__rule')).toHaveLength(0)
	})

	it('keeps the report when a probe fails and says so', async () => {
		axios.post.mockRejectedValue(new Error('403'))
		const wrapper = await mountDialog()

		await wrapper.find('[data-testid="nlgov-probe"]').trigger('click')
		await settle()

		expect(wrapper.find('.note').text()).toBe('The responses could not be probed.')
		expect(wrapper.findAll('.or-nlgov__rule')).toHaveLength(6)
	})

	it('closes on its button and when the dialog is dismissed', async () => {
		const wrapper = await mountDialog()

		const buttons = wrapper.findAll('button')
		await buttons[buttons.length - 1].trigger('click')
		wrapper.vm.onOpenChange(true)
		wrapper.vm.onOpenChange(false)

		expect(wrapper.emitted('close')).toHaveLength(2)
	})
})
