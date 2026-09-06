/*
 * SPDX-FileCopyrightText: 2026 Open Register Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * OAuth2 connect e2e: the parts of the connect flow that can be asserted without
 * a live third-party provider.
 *
 * A connect flow's middle is somebody else's consent screen, so an end-to-end test
 * cannot drive the whole of it. What it CAN assert is everything on either side of
 * that screen, and those are the parts this repository owns:
 *
 * 1. the client-metadata document is public, self-consistent, and names this
 *    instance's own callback (the AT Protocol contract: a client IS the document
 *    it publishes, and its identifier is that document's URL);
 * 2. the callback refuses a forged, absent or malformed state, with the same
 *    uniform answer each time and never a hint of which check failed;
 * 3. the provider list offers the OAuth2 providers and says which of them need a
 *    server address;
 * 4. the personal settings page shows the connected-accounts panel with its
 *    status chips and its reconnect action.
 *
 * Scenarios about the exchange itself, the relay, and re-authorisation are pinned
 * by PHPUnit instead: each needs either a provider that will issue a real code or
 * a second Nextcloud instance to relay to, and a Playwright test that mocked both
 * would be asserting its own mocks.
 *
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-starting-a-connection-returns-an-authorization-url-bound-to-the-caller
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-state-value-is-signed-single-use-and-short-lived
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-the-callback-exchanges-the-code-and-mints-a-token-set-credential
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-re-authorisation-overrides-the-same-credential
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-disconnecting-revokes-upstream-where-it-can-and-disables-locally
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-bluesky-is-its-own-client-and-mastodon-registers-per-instance
 * @spec openspec/changes/credential-oauth2-connect-flow/specs/credential-oauth2-connect/spec.md#requirement-a-person-can-connect-see-and-repair-a-connection-from-personal-settings
 */
import { expect, test } from '@playwright/test'

const ADMIN = process.env.OR_USER || 'admin'
const PASSWORD = process.env.OR_PASSWORD || 'admin'
const AUTH = { username: ADMIN, password: PASSWORD }

const METADATA_PATH = '/apps/openregister/oauth2/client-metadata.json'
const CALLBACK_PATH = '/apps/openregister/oauth2/callback'
const PROVIDERS_PATH = '/apps/openregister/api/credentials/providers'
const SETTINGS_PATH = '/settings/user/additional'

test.describe('OAuth2 client metadata', () => {
	test('is public and identifies itself by its own URL', async ({
		request,
		baseURL,
	}) => {
		// No credentials on purpose: an AT Protocol client document has to be
		// readable by an authorization server that has never heard of this user.
		const response = await request.get(METADATA_PATH)
		expect(response.status()).toBe(200)

		const metadata = await response.json()
		expect(metadata.client_id).toBe(`${baseURL}${METADATA_PATH}`)
		expect(metadata.redirect_uris).toEqual([`${baseURL}${CALLBACK_PATH}`])
		expect(metadata.dpop_bound_access_tokens).toBe(true)
		expect(metadata.token_endpoint_auth_method).toBe('none')
	})
})

test.describe('the OAuth2 callback', () => {
	test('refuses a forged state without saying which check failed', async ({
		request,
	}) => {
		const forged = await request.get(
			`${CALLBACK_PATH}?state=bm90LWEtc3RhdGU.c2lnbmF0dXJl&code=AUTH_CODE_HERE`,
		)
		expect(forged.status()).toBe(400)

		const body = await forged.text()
		// A uniform answer is the point: anything that distinguished "bad signature"
		// from "expired" from "already used" would be an oracle for forging one.
		expect(body).not.toContain('signature')
		expect(body).not.toContain('expired')
		expect(body).not.toContain('nonce')
	})

	test('refuses a callback carrying no code', async ({ request }) => {
		const noCode = await request.get(
			`${CALLBACK_PATH}?state=bm90LWEtc3RhdGU.c2lnbmF0dXJl`,
		)
		expect(noCode.status()).toBe(400)
	})

	test('refuses a value that is not a state at all', async ({ request }) => {
		const rubbish = await request.get(
			`${CALLBACK_PATH}?state=rubbish&code=AUTH_CODE_HERE`,
		)
		expect(rubbish.status()).toBe(400)
	})
})

test.describe('the provider list', () => {
	test('offers the OAuth2 providers and says which need a server address', async ({
		request,
	}) => {
		const response = await request.get(PROVIDERS_PATH, { headers: authHeader() })
		expect(response.status()).toBe(200)

		const providers = (await response.json()).results as Array<
			Record<string, unknown>
		>
		const byId = new Map(
			providers.map((entry) => [entry.identifier as string, entry]),
		)

		expect(byId.get('mastodon')?.kind).toBe('oauth2-token-set')
		expect(byId.get('mastodon')?.requiresInstanceBaseUrl).toBe(true)
		expect(byId.get('google-search-console')?.requiresInstanceBaseUrl).toBe(
			false,
		)
		// The classic entries keep their kind, which is how the panel filters them out.
		expect(byId.get('github')?.kind).toBe('secret')

		// The allow-rules and endpoints are an internal guardrail and must not be published.
		expect(JSON.stringify(providers)).not.toContain('allowRules')
		expect(JSON.stringify(providers)).not.toContain('tokenEndpoint')
	})
})

test.describe('the connected-accounts panel', () => {
	test('shows the connect action on personal settings', async ({ page }) => {
		await page.goto(SETTINGS_PATH)

		const section = page.getByTestId('oauth2-connections-section')
		await expect(section).toBeVisible()
		await expect(page.getByTestId('oauth2-connect-button')).toBeVisible()
		await expect(page.getByTestId('oauth2-provider-select')).toBeVisible()
	})

	test('offers a reconnect action on a connection that needs one', async ({
		page,
	}) => {
		await page.goto(SETTINGS_PATH)

		const relinkChips = page.locator('[data-testid^="oauth2-status-"]', {
			hasText: 'Relink needed',
		})
		const count = await relinkChips.count()
		test.skip(count === 0, 'no connection on this instance is in relink_needed')

		const credentialId = await relinkChips
			.first()
			.evaluate((element) =>
				element.getAttribute('data-testid')?.replace('oauth2-status-', ''),
			)
		await expect(
			page.getByTestId(`oauth2-reconnect-${credentialId}`),
		).toBeVisible()
	})

	test('shows no token anywhere on the panel', async ({ page }) => {
		await page.goto(SETTINGS_PATH)
		await expect(page.getByTestId('oauth2-connections-section')).toBeVisible()

		const panelText = await page
			.getByTestId('oauth2-connections-section')
			.innerText()
		expect(panelText).not.toContain('Bearer ')
		expect(panelText).not.toContain('refresh_token')
		expect(panelText).not.toContain('access_token')
	})
})

/**
 * Basic-auth header, matching the other API-driven specs in this directory.
 *
 * @return The Authorization header.
 */
function authHeader(): Record<string, string> {
	const encoded = Buffer.from(`${AUTH.username}:${AUTH.password}`).toString(
		'base64',
	)

	return { Authorization: `Basic ${encoded}` }
}
