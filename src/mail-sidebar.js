/**
 * Mail Sidebar entry point.
 *
 * This script is injected into the Nextcloud Mail app via OCP\Util::addScript().
 * It creates a container element and mounts the Vue sidebar component.
 *
 * @package
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-webpack-entry-point-for-mail-sidebar-bundle
 * @spec openspec/specs/mail-sidebar/spec.md
 */

import Vue from 'vue'
import MailSidebar from './mail-sidebar/MailSidebar.vue'
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'

// Bootstrap the integration registry on the mail-sidebar bundle so any
// sub-component that uses useIntegrationRegistry() sees the populated
// singleton even when the user lands directly on the Mail app.
// Idempotent. See ADR-019.
ensureIntegrationRegistry()

const MOUNT_RETRY_INTERVAL = 1000
const MOUNT_MAX_RETRIES = 30
const SIDEBAR_ROOT_ID = 'openregister-mail-sidebar'

/**
 * Verify that we're on a Mail app page before mounting.
 *
 * We can't rely on Mail-owned DOM elements existing because Vue destroys its
 * root container during re-renders, so instead we check whether the Mail
 * initial-state is present in the page.
 *
 * @return {boolean} True if the Mail app is initialising.
 *
 * @spec openspec/specs/mail-sidebar/spec.md#requirement-webpack-entry-point-for-mail-sidebar-bundle
 * @spec openspec/specs/mail-sidebar/spec.md
 */
function isMailAppPage() {
	return !!document.getElementById('initial-state-mail-accounts')
}

/**
 * Mount the Vue sidebar application directly onto document.body.
 *
 * We MUST NOT mount inside any Vue-managed container (#content, #content-vue,
 * #app-content-vue) because the parent Vue app destroys its DOM children on
 * re-renders, taking our sidebar with it.
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */
function mountSidebar() {
	let retries = 0

	const tryMount = () => {
		if (!isMailAppPage()) {
			retries++
			if (retries < MOUNT_MAX_RETRIES) {
				setTimeout(tryMount, MOUNT_RETRY_INTERVAL)
				return
			}
			return
		}

		// Check if already mounted (works for both expanded and collapsed sidebar).
		if (document.getElementById(SIDEBAR_ROOT_ID)) {
			return
		}

		try {
			const app = new Vue({
				render: (h) => h(MailSidebar),
			}).$mount()
			app.$el.id = SIDEBAR_ROOT_ID
			document.body.appendChild(app.$el)
			return app
		} catch (err) {
			console.error('[OpenRegister] Mail sidebar mount failed:', err)
		}
	}

	tryMount()
}

// Wait for DOM to be ready.
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mountSidebar)
} else {
	mountSidebar()
}
