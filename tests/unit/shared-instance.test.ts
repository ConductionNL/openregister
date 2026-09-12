/*
 * SPDX-FileCopyrightText: 2026 Openregister Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The guard that decides whether this suite may touch the instance it is aimed
 * at, tested without an instance.
 *
 * GENERATED FROM hydra/templates/e2e/shared-instance.test.ts.tmpl. It lives
 * under tests/unit/ rather than next to the guard because jest is this repo's
 * unit runner and its testPathIgnorePatterns skips tests/e2e/ entirely, so a
 * copy left beside the guard would never run. describe/expect/it come from
 * jest's globals, which is the only other difference from the template.
 *
 * Worth testing rather than reading, because every case here is one somebody
 * already got wrong: `http://127.0.0.1` parses with an EMPTY port, so a port
 * comparison that trusts `URL.port` misses the shared instance written without
 * one, and a flag holding `1` used to permit every shared instance the suite
 * ever met.
 */

import {
	APP_ID,
	assertInstancePermitted,
	isSharedOrigin,
	normaliseOrigin,
	occPrefix,
	SHARED_INSTANCE_FLAG,
	// ts-jest compiles this file against the repo tsconfig, which does not set
	// allowImportingTsExtensions, so a ".ts" here fails the whole suite with
	// TS5097 before a single case runs.
	// eslint-disable-next-line import-extensions/extensions
} from '../e2e/shared-instance'

/** An environment with neither flag set. */
const NONE = {} as NodeJS.ProcessEnv

describe(`${APP_ID} shared-instance guard`, () => {
	it('folds every loopback spelling onto localhost', () => {
		expect(normaliseOrigin('http://127.0.0.1:8080')).toBe('http://localhost:8080')
		expect(normaliseOrigin('http://[::1]:8080')).toBe('http://localhost:8080')
		expect(normaliseOrigin('http://localhost:8080/')).toBe('http://localhost:8080')
	})

	it('makes the implicit port explicit', () => {
		expect(normaliseOrigin('http://127.0.0.1')).toBe('http://localhost:80')
		expect(normaliseOrigin('https://example.org')).toBe('https://example.org:443')
	})

	it('calls loopback 80 and 8080 shared, and nothing else', () => {
		expect(isSharedOrigin('http://localhost:8080')).toBe(true)
		expect(isSharedOrigin('http://127.0.0.1')).toBe(true)
		expect(isSharedOrigin('http://localhost:8095')).toBe(false)
		expect(isSharedOrigin('http://nextcloud.example.org:8080')).toBe(false)
	})

	it('refuses a shared instance that no flag names', () => {
		expect(() => assertInstancePermitted('http://localhost:8080', NONE)).toThrow(
			/SHARED development instance/,
		)
	})

	it('refuses a flag holding a boolean rather than an origin', () => {
		const env = { [SHARED_INSTANCE_FLAG]: '1' } as unknown as NodeJS.ProcessEnv
		expect(() => assertInstancePermitted('http://localhost:8080', env)).toThrow()
	})

	it('refuses a flag that names a different origin', () => {
		const env = {
			[SHARED_INSTANCE_FLAG]: 'http://localhost:80',
		} as unknown as NodeJS.ProcessEnv
		expect(() => assertInstancePermitted('http://localhost:8080', env)).toThrow()
	})

	it('permits the origin the flag names, in any loopback spelling', () => {
		const env = {
			[SHARED_INSTANCE_FLAG]: 'http://127.0.0.1:8080',
		} as unknown as NodeJS.ProcessEnv
		expect(assertInstancePermitted('http://localhost:8080', env)).toBe(
			'http://localhost:8080',
		)
	})

	it('accepts the fleet-wide spelling too', () => {
		const env = {
			E2E_ALLOW_SHARED_INSTANCE: 'http://localhost:8080',
		} as unknown as NodeJS.ProcessEnv
		expect(assertInstancePermitted('http://localhost:8080', env)).toBe(
			'http://localhost:8080',
		)
	})

	it('lets a disposable rig through without a flag', () => {
		expect(assertInstancePermitted('http://localhost:8095', NONE)).toBe(
			'http://localhost:8095',
		)
	})

	it('binds occ to a named container, and falls back to the server root', () => {
		expect(occPrefix(NONE).join(' ')).toBe('php occ')
		const env = { NEXTCLOUD_CONTAINER: 'nextcloud' } as NodeJS.ProcessEnv
		expect(occPrefix(env).join(' ')).toBe(
			'docker exec -u www-data nextcloud php occ',
		)
	})
})
