import { getCurrentUser } from '@nextcloud/auth'
import { canEditPacks } from './migrationPacks.js'

jest.mock('@nextcloud/auth', () => ({ __esModule: true, getCurrentUser: jest.fn() }))
jest.mock('@nextcloud/axios', () => ({ __esModule: true, default: { get: jest.fn(), post: jest.fn() } }))
jest.mock('@nextcloud/router', () => ({ __esModule: true, generateUrl: (p) => p }))
jest.mock('../views/migrationPack/l10n.js', () => ({ __esModule: true, t: (s) => s }))

describe('canEditPacks', () => {
	it('allows an administrator', () => {
		getCurrentUser.mockReturnValue({ isAdmin: true })
		expect(canEditPacks()).toBe(true)
	})

	it.each([
		['a non-admin', () => ({ isAdmin: false })],
		['no user', () => null],
		['a truthy non-boolean flag', () => ({ isAdmin: 1 })],
	])('refuses %s', (_, user) => {
		getCurrentUser.mockImplementation(user)
		expect(canEditPacks()).toBe(false)
	})

	it('fails closed when the user lookup throws', () => {
		getCurrentUser.mockImplementation(() => {
			throw new Error('no session')
		})
		expect(canEditPacks()).toBe(false)
	})
})
