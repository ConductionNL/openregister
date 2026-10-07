import { rememberSetupDismissal } from './wizardDismissal.js'

jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { post: jest.fn() },
}))
jest.mock('@nextcloud/router', () => ({ generateUrl: (path) => path }))

/**
 * A stand-in for the mounted CnAppRoot: the flag and the key it really uses.
 *
 * @return {object} The root, with `dismiss()` to flip the flag.
 */
function fakeRoot() {
	const watchers = []
	return {
		setupWizardDismissKey: () => 'cn-setup-wizard-dismissed:openregister:1',
		$watch: (name, cb) => {
			watchers.push({ name, cb })
			return () => {}
		},
		dismiss() {
			watchers
				.filter((w) => w.name === 'setupWizardDismissed')
				.forEach((w) => w.cb(true))
		},
	}
}

/**
 * An in-memory Storage.
 *
 * @param {object} initial Initial entries.
 * @return {object} The storage.
 */
function memoryStorage(initial = {}) {
	const data = { ...initial }
	return {
		data,
		getItem: (k) => (k in data ? data[k] : null),
		setItem: (k, v) => {
			data[k] = String(v)
		},
	}
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

describe('remembering the setup wizard on the server (live audit)', () => {
	it('posts the dismissal once when the wizard is closed', async () => {
		const root = fakeRoot()
		const storage = memoryStorage()
		const post = jest.fn().mockResolvedValue({})

		rememberSetupDismissal(root, { storage, post })
		expect(post).not.toHaveBeenCalled()

		root.dismiss()
		root.dismiss()
		await flush()

		expect(post).toHaveBeenCalledTimes(1)
		expect(storage.data['openregister-setup-dismissal-recorded']).toBe('1')
	})

	it('posts on load when this browser dismissed it before', async () => {
		const storage = memoryStorage({
			'cn-setup-wizard-dismissed:openregister:1': '1',
		})
		const post = jest.fn().mockResolvedValue({})

		rememberSetupDismissal(fakeRoot(), { storage, post })
		await flush()

		expect(post).toHaveBeenCalledTimes(1)
	})

	it('does not post again once the server has it', async () => {
		const storage = memoryStorage({
			'cn-setup-wizard-dismissed:openregister:1': '1',
			'openregister-setup-dismissal-recorded': '1',
		})
		const post = jest.fn().mockResolvedValue({})
		const root = fakeRoot()

		rememberSetupDismissal(root, { storage, post })
		root.dismiss()
		await flush()

		expect(post).not.toHaveBeenCalled()
	})

	it('tries again on a later close when the post failed', async () => {
		const root = fakeRoot()
		const post = jest
			.fn()
			.mockRejectedValueOnce(new Error('403'))
			.mockResolvedValue({})

		rememberSetupDismissal(root, { storage: memoryStorage(), post })
		root.dismiss()
		await flush()
		root.dismiss()
		await flush()

		expect(post).toHaveBeenCalledTimes(2)
	})
})
