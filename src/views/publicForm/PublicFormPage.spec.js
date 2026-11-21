import PublicFormPage from './PublicFormPage.vue'

/**
 * The public form page: where it sends the form, and the confirmation after.
 *
 * @spec openspec/changes/capability-rows-verified-a/specs/form-and-journey-registry/spec.md
 */

jest.mock('@conduction/nextcloud-vue', () => ({ __esModule: true, CnFormPage: {} }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => text }))
jest.mock('@nextcloud/router', () => ({
	__esModule: true,
	generateUrl: (url, params) => '/index.php' + url.replace('{formId}', params.formId),
}))
jest.mock('@nextcloud/vue', () => ({ __esModule: true, NcEmptyContent: {} }))

describe('PublicFormPage', () => {
	it('sends the form to its public submit route', () => {
		const endpoint = PublicFormPage.computed.submitEndpoint.call({ form: { id: 'f-1' } })

		expect(endpoint).toBe('/index.php/apps/openregister/api/forms/f-1/submit')
	})

	it('has no form id to send to when the form is not open', () => {
		expect(PublicFormPage.computed.submitEndpoint.call({ form: null })).toBe('/index.php/apps/openregister/api/forms//submit')
	})

	it('starts unsent and shows the confirmation once sent', () => {
		const page = PublicFormPage.data()
		expect(page.sent).toBe(false)

		PublicFormPage.methods.onSent.call(page)
		expect(page.sent).toBe(true)
	})

	it('takes null as the form a visitor may not open', () => {
		expect(PublicFormPage.props.form.default).toBeNull()
	})
})
