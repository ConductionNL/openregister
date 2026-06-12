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
