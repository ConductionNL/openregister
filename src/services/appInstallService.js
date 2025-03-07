/* eslint-disable no-trailing-spaces */
/* eslint-disable jsdoc/check-tag-names */

/**
 * ## AppInstallService Developer Documentation
 *
 * This file provides a robust service class, `AppInstallService`, that manages the installation,
 * uninstallation, and retrieval of Nextcloud apps.  
 * It leverages official nextcloud APIs.
 *
 * ### Basic Usage
 *
 * 1. **Import** the service:
 *
 * ```js
 * import { AppInstallService } from 'path/to/AppInstallService.js'
 *
 * // or use default import:
 * import AppInstallService from 'path/to/AppInstallService.js'
 * ```
 *
 * 2. **Create an instance** of the service:
 *
 * ```js
 * const service = new AppInstallService()
 * ```
 *
 * 3. **Initialize** the service, which ensures the app list is loaded:
 *
 * ```js
 * await service.init()
 * ```
 *
 * 4. **Use** the service methods as needed:
 *
 * ```js
 * // Check if an app is installed:
 * const isInstalled = await service.isAppInstalled('files')
 * console.log(`"files" is installed:`, isInstalled)
 *
 * // Install an app if it's not already installed:
 * try {
 *   await service.installApp('contacts')
 * } catch (err) {
 *   if (err.status === 403 && err.data?.message === 'Password confirmation is required') {
 *     // Handle password confirmation requirement
 *   }
 * }
 *
 * // Uninstall an app:
 * await service.uninstallApp('contacts')
 * ```
 *
 * ### Methods Overview
 *
 * - **init()**
 *   - Asynchronously initializes the service (fetches and caches the app list).
 *   - Must be called before using other methods that rely on the app list.
 *
 * - **invalidateCache()**
 *   - Invalidates the in-memory cached app list. Useful if you suspect the list is outdated.
 *
 * - **reloadCacheList()**
 *   - Invalidates and then re-fetches the app list, updating the cache.
 *
 * - **isAppInstalled(appId)**
 *   - Returns a boolean indicating whether the given `appId` is installed.
 *
 * - **getAppData(appId)**
 *   - Fetches all known metadata for a given `appId`. Throws if the app isn't found in the list.
 *
 * - **installApp(appIds)**
 *   - Installs one or multiple apps (`string` or `string[]`).
 *   - Skips already installed apps.
 *   - Returns `null` if no action is needed (all apps are already installed).
 *   - Otherwise, returns the JSON response from the server.
 *   - May throw RequestError with status 403 if password confirmation is required.
 *
 * - **forceInstallApp(appIds)**
 *   - Calls a "force" endpoint for each given `appId` before performing a normal install.
 *   - Ideal for cases where installation might be blocked by Nextcloud's internal checks.
 *   - Returns the JSON response from the final installation call.
 *   - May throw RequestError with status 403 if password confirmation is required.
 *
 * - **uninstallApp(appIds)**
 *   - Uninstalls one or multiple apps (`string` or `string[]`).
 *   - Skips apps that are not installed.
 *   - Returns `null` if no action is needed (all apps are already uninstalled).
 *   - Otherwise, returns the JSON response from the server.
 *   - May throw RequestError with status 403 if password confirmation is required.
 *
 * ### Internal Details
 *
 * - **Caching**: The first time `AppInstallService` fetches the app list, it stores it in memory (`#appList`).
 *   Subsequent calls rely on this cached data. This helps reduce redundant network requests. Use
 *   `invalidateCache()` or `reloadCacheList()` to refresh it manually.
 *
 * - **Private Methods & Fields** (marked with `#`) are for internal use:
 *   - `#ensureAppListLoaded()`
 *   - `#fetchAppList()`
 *   - `#getCachedAppList()`
 *   - `#findAppInCachedList()`
 *   - `#request()`
 *   - `#token`, `#appList`
 *
 * - **Request Handling**: The service uses `fetch` under the hood to communicate with Nextcloud's
 *   `/settings/apps/*` endpoints. All requests include:
 *   - `credentials: 'include'`
 *   - `mode: 'cors'`
 *   - `referrerPolicy: 'no-referrer'`
 *   - The Nextcloud `requesttoken` header is automatically added.
 *
 * - **Error Handling**: A custom `RequestError` class is provided for handling HTTP and JSON errors.
 *   If a request fails (e.g., non-`200` status or JSON parse error), a `RequestError` will be thrown
 *   containing the `response`, `status`, and `data`. The `data` field may contain a message indicating
 *   that password confirmation is required (status 403). Always wrap calls in `try/catch` if you need 
 *   to handle errors gracefully.
 *
 * ### Prerequisites
 *
 * - `window.OC.requestToken` must be set. If the token
 *   is not found, the constructor will throw an error.
 * - This class is intended to run in a browser-based environment with `fetch` available.
 *
 * ### Example
 *
 * ```js
 * // Create service instance
 * const appInstallService = new AppInstallService()
 *
 * // Initialize to load app list
 * await appInstallService.init()
 *
 * // Check and install multiple apps
 * const appsToInstall = ['calendar', 'contacts', 'notes']
 * try {
 *   const installResponse = await appInstallService.installApp(appsToInstall)
 *   if (installResponse) {
 *     console.log('Apps installed:', installResponse)
 *   } else {
 *     console.log('All requested apps were already installed.')
 *   }
 * } catch (err) {
 *   if (err.status === 403 && err.data?.message === 'Password confirmation is required') {
 *     console.log('Password confirmation needed before installing apps')
 *   } else {
 *     console.error('Failed to install apps:', err)
 *   }
 * }
 * ```
 *
 * @module AppInstallService
 */

class AppInstallService {

	/** @type {string} */
	#token

	/**
	 * Caches the array of apps once fetched.
	 * @type {Promise<object[]>|null}
	 */
	#appList = null

	/**
	 * @type {boolean}
	 */
	hasInit = false

	constructor() {
		this.#token = this.#getToken()
	}

	/**
	 * Async initializer for the service, ensuring the app list is loaded.
	 */
	async init() {
		await this.#ensureAppListLoaded()
		this.hasInit = true
	}

	/**
	 * Fetches and stores the app list if not already loaded.
	 * Ensures you only fetch once and cache the results in memory.
	 * @private
	 */
	async #ensureAppListLoaded() {
		if (!this.#appList) {
			this.#appList = await this.#fetchAppList()
		}
	}

	/**
	 * Retrieves the request token from the Nextcloud global object.
	 * @private
	 * @return {string}
	 */
	#getToken() {
		return window.OC.requestToken
	}

	/**
	 * Helper function for making fetch calls with uniform error handling.
	 * @private
	 * @param {string} url - The URL to fetch
	 * @param {object} [fetchOptions] - The fetch options
	 * @return {Promise<any>} Parsed JSON response
	 * @throws {RequestError} - If the response is not OK or JSON parsing fails. For 403 errors, includes parsed response data.
	 */
	async #request(url, fetchOptions = {}) {
		const response = await fetch(url, {
			...fetchOptions,
			credentials: 'include',
			mode: 'cors',
			referrerPolicy: 'no-referrer',
			headers: {
				accept: 'application/json, text/plain, */*',
				'accept-language': 'en-US,en;q=0.9,nl;q=0.8',
				requesttoken: this.#token,
				'x-requested-with': 'XMLHttpRequest, XMLHttpRequest',
				// Merge any custom headers
				...fetchOptions.headers,
			},
		})

		let data
		try {
			data = await response.json()
		} catch (err) {
			throw new RequestError(
				`Failed to parse JSON response: ${err.message}`,
				response,
			)
		}

		if (!response.ok) {
			throw new RequestError(
				`Request failed with status ${response.status}`,
				response,
				data,
			)
		}

		return data
	}

	/**
	 * Fetch the full list of apps from the server.
	 * @private
	 * @return {Promise<object[]>} - Resolves to an array of apps
	 */
	async #fetchAppList() {
		const data = await this.#request('/index.php/settings/apps/list', {
			method: 'GET',
		})
		if (!data || !data.apps) {
			// In case the response structure doesn't match expectations:
			throw new Error('[AppInstallService] Unexpected response format from /apps/list')
		}
		return data.apps
	}

	/**
	 * Retrieves the local cached list of apps (ensuring it's loaded first).
	 * @private
	 * @return {Promise<object[]>}
	 */
	async #getCachedAppList() {
		await this.#ensureAppListLoaded()
		return this.#appList // by now, it must be resolved
	}

	/**
	 * Finds an app in the cached list by ID.
	 * @private
	 * @param {string} appId - The app ID
	 * @return {Promise<object|null>}
	 */
	async #findAppInCachedList(appId) {
		const apps = await this.#getCachedAppList()
		return apps.find((app) => app.id === appId) || null
	}

	/**
	 * Invalidates the cached app list.
	 */
	async invalidateCache() {
		this.#appList = null
	}

	/**
	 * Invalidates the apps list cache and then re-fetches the list and caches it.
	 */
	async reloadCacheList() {
		this.invalidateCache()
		await this.#ensureAppListLoaded()
	}

	/**
	 * Check if an app is installed by passing it an app ID.
	 * @param { string } appId - The app ID
	 * @return { Promise<boolean> } - True if the app is installed, false otherwise
	 */
	async isAppInstalled(appId) {
		const appData = await this.#findAppInCachedList(appId)
		if (!appData) {
			throw new Error(`[AppInstallService] App "${appId}" not found in list`)
		}
		return appData.active
	}

	/**
	 * Get app data by passing it an app ID
	 * @param { string } appId - The app ID
	 * @return { Promise<object> } - The app data
	 */
	async getAppData(appId) {
		const appData = await this.#findAppInCachedList(appId)
		if (!appData) {
			throw new Error(`[AppInstallService] App "${appId}" not found in list`)
		}
		return appData
	}

	/**
	 * Install an app or multiple apps.
	 * Skips any apps already installed.
	 * Invalidates the cached app list after installation.
	 * @param {string | string[]} appIds - The app ID or an array of app IDs
	 * @return {Promise<object|null>} The JSON response or null if no installation was necessary
	 * @throws {RequestError} - If the network request fails or password confirmation is required (status 403)
	 */
	async installApp(appIds) {
		if (!appIds) {
			throw new Error('[AppInstallService] No app IDs provided')
		}

		if (!Array.isArray(appIds)) {
			appIds = [appIds]
		}

		const appsToInstall = []
		for (const appId of appIds) {
			const alreadyInstalled = await this.isAppInstalled(appId).catch(() => false)
			if (!alreadyInstalled) {
				appsToInstall.push(appId)
			}
		}

		// If all apps are already installed, return early
		if (appsToInstall.length === 0) {
			return null
		}

		// Otherwise, install the remaining apps
		return this.#request('/index.php/settings/apps/enable', {
			method: 'POST',
			headers: { 'content-type': 'application/json' },
			body: JSON.stringify({
				appIds: appsToInstall,
				groups: [],
			}),
		}).finally(() => this.reloadCacheList())
	}

	/**
	 * Force-install an app or multiple apps.
	 * Invalidates the cached app list after installation.
	 * @param {string | string[]} appIds - The app ID or an array of app IDs
	 * @return {Promise<object>} The response from the final install call
	 * @throws {RequestError} - If the force calls or install fails, or password confirmation is required (status 403)
	 */
	async forceInstallApp(appIds) {
		if (!Array.isArray(appIds)) {
			appIds = [appIds]
		}

		// Force each app individually
		await Promise.allSettled(
			appIds.map((appId) =>
				this.#request('/index.php/settings/apps/force', {
					method: 'POST',
					headers: { 'content-type': 'application/json' },
					body: JSON.stringify({ appId }),
				}),
			),
		)

		// Then proceed with normal installation
		return this.installApp(appIds)
	}

	/**
	 * Uninstall an app or multiple apps.
	 * Invalidates the cached app list after uninstallation.
	 * @param {string | string[]} appIds - The app ID or an array of app IDs
	 * @return {Promise<object>} The response from the final uninstall call
	 * @throws {RequestError} - If the network request fails or password confirmation is required (status 403)
	 */
	async uninstallApp(appIds) {
		if (!appIds) {
			throw new Error('[AppInstallService] No app IDs provided')
		}

		if (!Array.isArray(appIds)) {
			appIds = [appIds]
		}

		const appsToUninstall = []
		for (const appId of appIds) {
			const isInstalled = await this.isAppInstalled(appId).catch(() => false)
			if (isInstalled) {
				appsToUninstall.push(appId)
			}
		}

		// If all apps are not installed, return early
		if (appsToUninstall.length === 0) {
			return null
		}

		return this.#request('/index.php/settings/apps/disable', {
			method: 'POST',
			headers: {
				'content-type': 'application/json',
				requesttoken: this.#token,
				'x-requested-with': 'XMLHttpRequest, XMLHttpRequest',
			},
			body: JSON.stringify({ appIds }),
		}).finally(() => this.reloadCacheList())
	}

}

/**
 * Custom error class for HTTP request failures.
 */
class RequestError extends Error {

	/**
	 * @param {string} message - Error message
	 * @param {Response} response - The fetch Response object
	 * @param {any} [data] - The parsed response body (if available)
	 */
	constructor(message, response, data) {
		super(message)
		this.name = 'RequestError'
		this.response = response
		this.status = response.status
		this.data = data
	}

}

export { RequestError, AppInstallService }
export default AppInstallService
