/**
 * @spec exclude Unit test of a list-view sidebar toggle.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import { sidebarToggle } from './sidebarToggle.js'

describe('sidebarToggle', () => {
	it('flips the view\'s sidebarOpen flag', () => {
		const view = { sidebarOpen: false, ...sidebarToggle }
		view.toggleSidebar()
		expect(view.sidebarOpen).toBe(true)
		view.toggleSidebar()
		expect(view.sidebarOpen).toBe(false)
	})
})
