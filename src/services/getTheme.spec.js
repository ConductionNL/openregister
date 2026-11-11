import { getTheme } from './getTheme.js'

describe('getTheme', () => {
	const ATTRS = ['data-theme-light', 'data-theme-dark', 'data-theme-default']
	let originalMatchMedia

	beforeEach(() => {
		originalMatchMedia = window.matchMedia
		ATTRS.forEach((a) => document.body.removeAttribute(a))
	})

	afterEach(() => {
		window.matchMedia = originalMatchMedia
		ATTRS.forEach((a) => document.body.removeAttribute(a))
	})

	it('is light when Nextcloud forces the light theme', () => {
		document.body.setAttribute('data-theme-light', '')
		document.body.setAttribute('data-theme-dark', '')
		expect(getTheme()).toBe('light')
	})

	it('is dark when Nextcloud forces the dark theme', () => {
		document.body.setAttribute('data-theme-dark', '')
		expect(getTheme()).toBe('dark')
	})

	it('follows the system preference under the default theme', () => {
		document.body.setAttribute('data-theme-default', '')
		window.matchMedia = jest.fn(() => ({ matches: true }))
		expect(getTheme()).toBe('light')
		expect(window.matchMedia).toHaveBeenCalledWith('(prefers-color-scheme: light)')

		window.matchMedia = jest.fn(() => ({ matches: false }))
		expect(getTheme()).toBe('dark')
	})

	it('defaults to light without any theme attribute', () => {
		window.matchMedia = jest.fn(() => ({ matches: false }))
		expect(getTheme()).toBe('light')
		expect(window.matchMedia).not.toHaveBeenCalled()
	})
})
