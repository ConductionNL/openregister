/**
 * Send every `@nextcloud/axios` request at once, without first asking the
 * browser's Cookie Store for an XSRF cookie Nextcloud never sets.
 *
 * Since axios 1.21 its XHR adapter reads the `XSRF-TOKEN` cookie through the
 * asynchronous `window.cookieStore.get()` before EVERY same-origin request,
 * and only calls `send()` once that answers. Under a page load that answer
 * took 100-400 ms per request in Chrome (measured on OpenRegister's dashboard:
 * `open()` at 985 ms, `send()` at 1393 ms), so every API call of the page
 * started late. Nextcloud protects requests with the `requesttoken` header
 * that `@nextcloud/axios` already sends; there is no `XSRF-TOKEN` cookie to
 * read. `withXSRFToken: false` skips the read, so `send()` follows `open()`
 * synchronously again.
 *
 * Import this module FIRST in every entry: each webpack runtime (this app's
 * pages and the separately compiled global script) has its own client.
 *
 * @spec openspec/specs/frontend-store-client-state/spec.md
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'

/**
 * Turn off the XSRF cookie read on an axios client.
 *
 * @spec openspec/specs/frontend-store-client-state/spec.md
 * @param {{defaults: object}} client The axios client
 * @return {{defaults: object}} The same client
 */
export function withoutXsrfCookieRead(client) {
	client.defaults.withXSRFToken = false
	return client
}

withoutXsrfCookieRead(axios)
