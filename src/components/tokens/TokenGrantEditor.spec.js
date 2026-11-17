/**
 * TokenGrantEditor: edits a grant, refuses one that cannot be saved before
 * any request, offers manage nowhere, and asks a preview on one object.
 *
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { mount } from '@vue/test-utils'
import TokenGrantEditor from './TokenGrantEditor.vue'

jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text) => text,
	translatePlural: (app, one) => one,
}))
jest.mock('@nextcloud/vue', () => {
	const { h } = require('vue')
	return {
		__esModule: true,
		NcButton: {
			name: 'NcButton',
			props: { disabled: Boolean, variant: String },
			emits: ['click'],
			render() {
				return h('button', { disabled: this.disabled, onClick: () => this.$emit('click') }, this.$slots.default?.())
			},
		},
	}
})

const stored = { verbs: ['read', 'update'], schemas: ['case'], expiresAt: '2027-03-01T23:59:59+00:00' }

describe('TokenGrantEditor', () => {
	it('shows the stored grant and never offers manage', () => {
		const wrapper = mount(TokenGrantEditor, { props: { grant: stored } })
		const checked = wrapper.findAll('[data-verb]').filter((box) => box.element.checked).map((box) => box.attributes('data-verb'))

		expect(checked).toEqual(['read', 'update'])
		expect(wrapper.find('[data-verb="manage"]').exists()).toBe(false)
		expect(wrapper.find('[data-field="schemas"]').element.value).toBe('case')
		expect(wrapper.find('[data-field="expiresAt"]').element.value).toBe('2027-03-01')
	})

	it('emits the edited grant on save', async () => {
		const wrapper = mount(TokenGrantEditor, { props: { grant: stored } })
		await wrapper.find('[data-verb="update"]').setValue(false)
		await wrapper.find('[data-field="match"]').setValue('{"supplier": "@self.tokenSubject"}')
		await wrapper.find('[data-action="save"]').trigger('click')

		expect(wrapper.emitted('save')).toEqual([[{
			verbs: ['read'],
			expiresAt: '2027-03-01T23:59:59+00:00',
			schemas: ['case'],
			match: { supplier: '@self.tokenSubject' },
		}]])
	})

	it('refuses a grant without an end date or with a broken row condition, naming why', async () => {
		const wrapper = mount(TokenGrantEditor)
		await wrapper.find('[data-field="match"]').setValue('{supplier')
		await wrapper.find('[data-action="save"]').trigger('click')

		expect(wrapper.emitted('save')).toBeUndefined()
		expect(wrapper.text()).toContain('An end date is required')
		expect(wrapper.text()).toContain('The row condition is not valid JSON')
	})

	it('offers removal only when asked', () => {
		expect(mount(TokenGrantEditor).find('[data-action="remove"]').exists()).toBe(false)

		const wrapper = mount(TokenGrantEditor, { props: { grant: stored, removable: true } })
		wrapper.find('[data-action="remove"]').trigger('click')
		expect(wrapper.emitted('remove')).toHaveLength(1)
	})

	it('asks a preview with the grant and the chosen object', async () => {
		const wrapper = mount(TokenGrantEditor, { props: { grant: stored, previewable: true } })
		expect(wrapper.find('[data-action="preview"]').attributes('disabled')).toBeDefined()

		await wrapper.find('[data-field="previewRegister"]').setValue(' zaken ')
		await wrapper.find('[data-field="previewSchema"]').setValue('case')
		await wrapper.find('[data-field="previewObject"]').setValue('abc')
		await wrapper.find('[data-action="preview"]').trigger('click')

		expect(wrapper.emitted('preview')).toEqual([[{ grant: { ...stored }, register: 'zaken', schema: 'case', object: 'abc' }]])
	})

	it('shows the preview answer per action, and a lapsed grant', () => {
		const wrapper = mount(TokenGrantEditor, {
			props: {
				grant: stored,
				previewable: true,
				previewResult: { verbs: [{ verb: 'read', holder: true, token: true }, { verb: 'delete', holder: true, token: false }], expired: true },
			},
		})

		expect(wrapper.find('[data-preview-verb="read"]').text()).toBe('readYesYes')
		expect(wrapper.find('[data-preview-verb="delete"]').text()).toBe('deleteYesNo')
		expect(wrapper.text()).toContain('This grant has lapsed, so the token is refused.')
	})

	it('reloads the form when another grant arrives', async () => {
		const wrapper = mount(TokenGrantEditor, { props: { grant: stored } })
		await wrapper.setProps({ grant: { verbs: ['share'], expiresAt: '2028-01-01T23:59:59+00:00' } })

		expect(wrapper.find('[data-verb="share"]').element.checked).toBe(true)
		expect(wrapper.find('[data-verb="read"]').element.checked).toBe(false)
		expect(wrapper.find('[data-field="expiresAt"]').element.value).toBe('2028-01-01')
	})
})
