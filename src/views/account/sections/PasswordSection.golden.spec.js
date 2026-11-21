import axios from '@nextcloud/axios'
import PasswordSection from './PasswordSection.vue'
import { sectionStatus } from '../../../composables/sectionStatus.js'

/**
 * The password section: whether it offers the form, and what a change sends
 * and reports.
 *
 * @spec openspec/specs/account-self-service/spec.md
 */

jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), put: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (path) => `/gen${path}` }))
jest.mock('@nextcloud/l10n', () => ({ __esModule: true, translate: (app, text) => `t:${text}` }))
jest.mock('@nextcloud/vue', () => ({ __esModule: true }))
jest.mock('../../../components/shared/SectionStatus.vue', () => ({ __esModule: true, default: {} }))

/**
 * A bare section state carrying the section's methods.
 *
 * @param {object} state The data
 * @return {object} The section
 */
function sectionWith(state) {
	const section = { ...state }
	for (const [name, method] of Object.entries({ ...sectionStatus.methods, ...PasswordSection.methods })) {
		section[name] = method.bind(section)
	}
	return section
}

/**
 * The section's data, without its methods.
 *
 * @param {object} section The section
 * @return {object} The data
 */
function dataOf(section) {
	return Object.fromEntries(
		Object.entries(section).filter(([, value]) => typeof value !== 'function'),
	)
}

describe('PasswordSection.mounted', () => {
	beforeEach(() => axios.get.mockReset())

	it.each([
		['a backend that allows it', { backendCapabilities: { password: true } }, true],
		['a backend that refuses it', { backendCapabilities: { password: false } }, false],
		['an answer without capabilities', {}, true],
		['an empty answer', null, true],
	])('offers the form for %s', async (label, data, expected) => {
		axios.get.mockResolvedValueOnce({ data })
		const section = sectionWith({ canChangePassword: 'unset' })
		await PasswordSection.mounted.call(section)
		expect(section.canChangePassword).toBe(expected)
		expect(axios.get).toHaveBeenCalledWith('/gen/apps/openregister/api/user/me')
	})

	it('keeps the default when the user cannot be read', async () => {
		axios.get.mockRejectedValueOnce(new Error('offline'))
		const section = sectionWith({ canChangePassword: 'unset' })
		await PasswordSection.mounted.call(section)
		expect(section.canChangePassword).toBe('unset')
	})
})

describe('PasswordSection.changePassword', () => {
	const before = { currentPassword: 'old', newPassword: 'new', loading: false, message: 'stale', isError: true }

	beforeEach(() => axios.put.mockReset())

	/**
	 * Change the password, recording the state while the request runs.
	 *
	 * @param {Function} answer The request's answer
	 * @return {Promise<object>} The data after the change, and during the request
	 */
	async function change(answer) {
		const section = sectionWith(before)
		axios.put.mockImplementation((...args) => {
			section.duringRequest = { loading: section.loading, message: section.message, args }
			return answer()
		})
		await section.changePassword()
		return dataOf(section)
	}

	it.each([
		['its own message', { message: 'Changed!' }],
		['the default message', {}],
	])('clears the form and reports %s', async (label, data) => {
		expect(await change(() => Promise.resolve({ data }))).toMatchSnapshot()
	})

	it.each([
		['the API error', { response: { data: { error: 'Wrong password' } } }],
		['the fallback', new Error('offline')],
	])('keeps the form and reports %s', async (label, failure) => {
		expect(await change(() => Promise.reject(failure))).toMatchSnapshot()
	})
})
