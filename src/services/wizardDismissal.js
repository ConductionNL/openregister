/**
 * Remember on the server that the setup wizard was closed or finished.
 *
 * CnAppRoot records a dismissal of the optional setup wizard only in this
 * browser's localStorage. Every other browser, device or cleared profile saw
 * "Set up Open Register" open again on every page, because the server still
 * reported the example data step as unanswered (live audit, 7 October 2026).
 * The wizard emits no event on close, so this watches the flag CnAppRoot sets
 * on both close and finish, and posts the `dismiss-setup` action once.
 *
 * @spec openspec/changes/live-audit-round-one/specs/first-time-setup/spec.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const RECORDED_KEY = 'openregister-setup-dismissal-recorded'

/**
 * Read one localStorage key, or null when storage is unavailable.
 *
 * @param {Storage|null} storage The storage.
 * @param {string} key The key.
 * @return {string|null} The value.
 */
function read(storage, key) {
	try {
		return storage ? storage.getItem(key) : null
	} catch {
		return null
	}
}

/**
 * Write one localStorage key, ignoring a storage that refuses.
 *
 * @param {Storage|null} storage The storage.
 * @param {string} key The key.
 * @param {string} value The value.
 * @return {void}
 */
function write(storage, key, value) {
	try {
		if (storage) {
			storage.setItem(key, value)
		}
	} catch {
		// Best effort: the server call still happened.
	}
}

/**
 * Post the dismissal once the wizard is closed or finished.
 *
 * Also posts when this browser dismissed the wizard before this code shipped,
 * so an install that only ever closed it in one browser is fixed by the next
 * page load there.
 *
 * @param {object} appRoot The mounted CnAppRoot instance.
 * @param {object} [options] Seams for tests.
 * @param {Storage|null} [options.storage] The localStorage to read.
 * @param {() => Promise<unknown>} [options.post] Posts the dismissal.
 * @return {() => void} Stops watching.
 *
 * @spec openspec/changes/live-audit-round-one/specs/first-time-setup/spec.md
 */
export function rememberSetupDismissal(appRoot, options = {}) {
	const storage =
		options.storage !== undefined
			? options.storage
			: typeof window !== 'undefined'
				? window.localStorage
				: null
	const post =
		options.post
		|| (() =>
			axios.post(
				generateUrl('/apps/openregister/api/setup/action/dismiss-setup'),
			))

	let sent = read(storage, RECORDED_KEY) === '1'
	const record = () => {
		if (sent) {
			return
		}
		sent = true
		Promise.resolve()
			.then(() => post())
			.then(() => write(storage, RECORDED_KEY, '1'))
			.catch(() => {
				// A non-admin, or a server that is down: try again next load.
				sent = false
			})
	}

	if (!appRoot || typeof appRoot.$watch !== 'function') {
		return () => {}
	}

	const dismissKey =
		typeof appRoot.setupWizardDismissKey === 'function'
			? appRoot.setupWizardDismissKey()
			: ''
	if (dismissKey !== '' && read(storage, dismissKey) === '1') {
		record()
	}

	return appRoot.$watch('setupWizardDismissed', (dismissed) => {
		if (dismissed === true) {
			record()
		}
	})
}
