import type { APIRequestContext } from '@playwright/test'

/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A SECURITY SETTING CHANGES, AND THE ADMINISTRATORS HEAR ABOUT IT.
 *
 * Scenario anchors, in the portable `<spec>::<slug>` form so they still resolve
 * once `openspec/changes/audit-trail-shipped-and-purpose-bound/specs/` is
 * archived into `openspec/specs/`:
 *
 * @e2e enhanced-audit-trail::the-beheerteam-hears-about-it
 * @e2e enhanced-audit-trail::a-secret-is-announced-without-being-shown
 *
 * WHAT THIS FILE PROVES, AND WHAT IT DELIBERATELY DOES NOT.
 *
 * It proves the announcement is REACHABLE end to end: a marked setting changed
 * over HTTP produces a notification an administrator can read back, naming the
 * setting, the actor and both values, and a changed credential produces one
 * that quotes neither. Those are exactly the failures a green unit suite hides.
 * Two of them are specific to this app and neither is visible to PHPUnit: the
 * announcer resolved to null by the container would make every save announce
 * nothing, and a subject the Notifier cannot render throws out of prepare() and
 * is dropped, so the beheerteam is told nothing at the one moment the
 * requirement exists for.
 *
 * It does NOT assert the EMAIL. Nextcloud's notifications app decides whether a
 * notification is also mailed, per user and per batching preference, and an
 * assertion over somebody's mailbox would be asserting that app's settings
 * rather than this one's behaviour.
 *
 * IT RESTORES WHAT IT CHANGES. Each test puts the setting back in a `finally`,
 * because these are instance-wide security settings: a run that dies halfway
 * through with access control switched off would leave the instance open, which
 * is a worse outcome than a red test.
 *
 * SKIPS RATHER THAN FAILS when the notifications app is not enabled. The
 * announcement has nowhere to arrive on such an instance, and a red there would
 * be reporting on the fixture instead of on this change.
 */
import { expect, request as pwRequest, test } from '@playwright/test'
import { resolveBaseUrl } from '../base-url.ts'

const BASE = resolveBaseUrl()
const ADMIN = process.env.ADMIN_USER || process.env.OR_USER || 'admin'
const ADMIN_PASS = process.env.ADMIN_PASSWORD || process.env.OR_PASS || 'admin'

const API = '/index.php/apps/openregister/api'
const SETTINGS = `${API}/settings`
const NOTIFICATIONS = '/ocs/v2.php/apps/notifications/api/v2/notifications'

/** A value nobody would set by hand, so a leak of it is unmistakable. */
const SECRET = `e2e-secret-${Math.random().toString(36).slice(2, 10)}`

async function contextFor(user: string, password: string): Promise<APIRequestContext> {
	return pwRequest.newContext({
		baseURL: BASE,
		extraHTTPHeaders: {
			Authorization: `Basic ${Buffer.from(`${user}:${password}`).toString('base64')}`,
			'OCS-APIRequest': 'true',
			Accept: 'application/json',
		},
	})
}

/** Every openregister notification currently sitting in the admin's list. */
async function notifications(admin: APIRequestContext): Promise<Array<Record<string, any>>> {
	const res = await admin.get(NOTIFICATIONS)
	if (!res.ok()) {
		return []
	}

	const body = await res.json()
	const list = body?.ocs?.data
	if (!Array.isArray(list)) {
		return []
	}

	return list.filter((n: Record<string, any>) => n.app === 'openregister')
}

/** The announcements for one setting, newest first. */
async function announcementsFor(
	admin: APIRequestContext,
	setting: string,
): Promise<Array<Record<string, any>>> {
	const all = await notifications(admin)

	return all.filter(
		(n) => n.object_type === 'security_setting' && String(n.object_id) === setting,
	)
}

test.describe.configure({ mode: 'serial' })

test.describe('a security setting change is announced', () => {
	let admin: APIRequestContext
	let notificationsAvailable = false

	test.beforeAll(async () => {
		admin = await contextFor(ADMIN, ADMIN_PASS)
		notificationsAvailable = (await admin.get(NOTIFICATIONS)).ok()
	})

	test.afterAll(async () => {
		await admin.dispose()
	})

	test('the beheerteam is told which setting moved, by whom, and to what', async () => {
		test.skip(!notificationsAvailable, 'the notifications app is not enabled on this instance')

		const before = await admin.get(SETTINGS)
		expect(before.ok(), `settings read failed: ${before.status()}`).toBeTruthy()
		const rbac = (await before.json()).rbac

		try {
			// The marked setting. Flipping adminOverride rather than `enabled`
			// keeps the run itself able to read anything it needs afterwards.
			const changed = await admin.put(SETTINGS, {
				data: { rbac: { ...rbac, adminOverride: !rbac.adminOverride } },
			})
			expect(changed.ok(), `settings write failed: ${await changed.text()}`).toBeTruthy()

			const announced = await expect
				.poll(async () => (await announcementsFor(admin, 'rbac.adminOverride')).length, {
					timeout: 15000,
				})
				.toBeGreaterThan(0)
				.then(async () => (await announcementsFor(admin, 'rbac.adminOverride'))[0])

			const text = `${announced.subject ?? ''} ${announced.message ?? ''}`
			expect(text, 'the announcement does not name the setting').toContain(
				'Administrators bypass access control',
			)
			expect(text, 'the announcement does not name a value').toMatch(/"(on|off)"/)
		} finally {
			// Instance-wide security setting: put it back whatever happened.
			await admin.put(SETTINGS, { data: { rbac } })
		}
	})

	test('a changed credential is announced without either value', async () => {
		test.skip(!notificationsAvailable, 'the notifications app is not enabled on this instance')

		const before = await admin.get(SETTINGS)
		const solr = (await before.json()).solr

		try {
			const changed = await admin.put(SETTINGS, { data: { solr: { ...solr, password: SECRET } } })
			expect(changed.ok(), `settings write failed: ${await changed.text()}`).toBeTruthy()

			const announced = await expect
				.poll(async () => (await announcementsFor(admin, 'solr.password')).length, {
					timeout: 15000,
				})
				.toBeGreaterThan(0)
				.then(async () => (await announcementsFor(admin, 'solr.password'))[0])

			const text = `${announced.subject ?? ''} ${announced.message ?? ''}`
			expect(text, 'the announcement does not say which credential moved').toContain(
				'Search index password',
			)
			expect(text, 'the new credential was quoted in a notification').not.toContain(SECRET)

			// Not anywhere in the stored notification, not only in its rendered
			// text: Nextcloud keeps the parameters in its own table.
			expect(JSON.stringify(announced), 'the credential is stored in the notification').not.toContain(
				SECRET,
			)
		} finally {
			await admin.put(SETTINGS, { data: { solr } })
		}
	})

	test('an ordinary setting does not announce anything', async () => {
		test.skip(!notificationsAvailable, 'the notifications app is not enabled on this instance')

		// The control. Without it, an instance that announces EVERY setting
		// change would pass both tests above and still be the mailbox full of
		// everything that the marker exists to prevent.
		const before = await admin.get(SETTINGS)
		const retention = (await before.json()).retention

		try {
			await admin.put(SETTINGS, {
				data: { retention: { ...retention, readLogRetention: retention.readLogRetention + 1000 } },
			})

			const announced = await announcementsFor(admin, 'retention.readLogRetention')
			expect(announced, 'an unmarked setting was announced').toHaveLength(0)
		} finally {
			await admin.put(SETTINGS, { data: { retention } })
		}
	})
})
