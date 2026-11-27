import axios from '@nextcloud/axios'
import LinkJourneyForm from './LinkJourneyForm.vue'

/**
 * The `edit` link form: prefilled from the run, saved back through the link.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('./refusalMessage.js', () => ({ __esModule: true, refusalMessage: (e) => `refused:${e.response.status}` }))
jest.mock('../../services/accessLinks.js', () => ({
	__esModule: true,
	passwordOptions: (password) => (password ? { headers: { 'X-Password': password } } : {}),
	publicLinkUrl: (anchor, suffix = '') => `/public/${anchor}${suffix}`,
}))

/**
 * A bare form carrying the component's data and methods.
 *
 * @param {object} props The props
 * @return {object} The form
 */
function form(props = {}) {
	const emitted = []
	const vm = { anchor: 'abc', password: '', ...LinkJourneyForm.data(), ...props, $emit: (...args) => emitted.push(args) }
	for (const [name, method] of Object.entries(LinkJourneyForm.methods)) {
		vm[name] = typeof method === 'function' ? method.bind(vm) : method
	}
	vm.emitted = emitted
	return vm
}

const RUN = {
	journey: 'j1',
	object: 'o1',
	step: 'contact',
	declared: ['telefoon', 'email'],
	answers: { telefoon: '0612345678' },
}

beforeEach(() => {
	axios.get.mockReset()
	axios.post.mockReset()
})

test('the run is loaded through the link and every declared field is prefilled', async () => {
	axios.get.mockResolvedValue({ data: { link: {}, run: RUN } })
	const vm = form({ password: 'secret' })

	await vm.load()

	expect(axios.get).toHaveBeenCalledWith('/public/abc/journey', { headers: { 'X-Password': 'secret' } })
	expect(vm.run).toEqual(RUN)
	expect(vm.answers).toEqual({ telefoon: '0612345678', email: '' })
	expect(vm.error).toBe('')
})

test('a save posts the declared answers, shows the notice and tells the page', async () => {
	axios.post.mockResolvedValue({ data: { changed: ['telefoon'], answers: { telefoon: '0687654321', email: '' } } })
	const vm = form()
	vm.answers = { telefoon: '0687654321', email: '' }

	await vm.save()

	expect(axios.post).toHaveBeenCalledWith('/public/abc/journey', { answers: { telefoon: '0687654321', email: '' } }, {})
	expect(vm.notice).toBe('t:Updated')
	expect(vm.busy).toBe(false)
	expect(vm.emitted).toEqual([['saved', { changed: ['telefoon'], answers: { telefoon: '0687654321', email: '' } }]])
})

test('a refused load or save shows the refusal and saves nothing', async () => {
	axios.get.mockRejectedValue({ response: { status: 404 } })
	const vm = form()
	await vm.load()
	expect(vm.run).toBeNull()
	expect(vm.error).toBe('refused:404')

	axios.post.mockRejectedValue({ response: { status: 422 } })
	await vm.save()
	expect(vm.error).toBe('refused:422')
	expect(vm.notice).toBe('')
	expect(vm.emitted).toEqual([])
	expect(vm.busy).toBe(false)
})
