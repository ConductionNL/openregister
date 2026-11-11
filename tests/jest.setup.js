/**
 * Jest setup — define globals that store modules expect from the
 * Nextcloud runtime (OC.requestToken etc.) so unit tests can exercise
 * fetch-based actions without ReferenceError.
 */
globalThis.OC = globalThis.OC || {
	requestToken: 'test-request-token',
	getRootPath: () => '',
	generateUrl: (path) => path,
	linkTo: (app, file) => `/apps/${app}/${file}`,
}

globalThis.OCA = globalThis.OCA || {}

if (!globalThis.fetch) {
	globalThis.fetch = jest.fn()
}

// Date.now() follows the wall clock, which can step backwards when the host
// resyncs it (WSL2 does, by hundreds of milliseconds). Vue skips a DOM event
// whose timestamp is not later than the moment its listener was attached, so a
// step between mount and trigger() silently drops the event. Keep it monotonic.
const wallClockNow = Date.now.bind(Date)
let lastNow = 0
Date.now = () => {
	lastNow = Math.max(lastNow, wallClockNow())
	return lastNow
}
