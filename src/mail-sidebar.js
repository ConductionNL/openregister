/**
 * Mail Sidebar entry point.
 *
 * This script is injected into the Nextcloud Mail app via OCP\Util::addScript().
 * It creates a container element and mounts the Vue sidebar component.
 *
 * @package OpenRegister
 *
<<<<<<< HEAD
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-51
 * @spec openspec/changes/retrofit-2026-05-24-mail-sidebar/tasks.md#task-5
=======
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-51
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */

import Vue from 'vue'
import MailSidebar from './mail-sidebar/MailSidebar.vue'
<<<<<<< HEAD
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'

// Bootstrap the integration registry on the mail-sidebar bundle so any
// sub-component that uses useIntegrationRegistry() sees the populated
// singleton even when the user lands directly on the Mail app.
// Idempotent. See ADR-019.
ensureIntegrationRegistry()

console.info('[OpenRegister] mail-sidebar.js loaded')
console.info('[OpenRegister] Vue and MailSidebar imported successfully')

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
 * @spec openspec/changes/retrofit-2026-04-23-annotate-openregister/tasks.md#task-51
 * @spec openspec/changes/retrofit-2026-05-24-mail-sidebar/tasks.md#task-5
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
 * @spec openspec/changes/retrofit-2026-05-24-mail-sidebar/tasks.md#task-5
=======

const MOUNT_POINT_ID = 'openregister-mail-sidebar'
const MOUNT_RETRY_INTERVAL = 1000
const MOUNT_MAX_RETRIES = 30

/**
 * Attempt to find a suitable mount point in the Mail app DOM.
 *
 * @return {HTMLElement|null} The mount point element or null.
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-51
 */
function findMountPoint() {
	// Try the Mail app content area
	const appContent = document.getElementById('app-content-vue')
		|| document.getElementById('app-content')
		|| document.querySelector('.app-content')
		|| document.querySelector('#content')

	return appContent || null
}

/**
 * Create and inject the sidebar container element.
 *
 * @param {HTMLElement} parent The parent element to append to.
 * @return {HTMLElement} The created container element.
 */
function createContainer(parent) {
	const container = document.createElement('div')
	container.id = MOUNT_POINT_ID
	container.setAttribute('role', 'complementary')
	container.setAttribute('aria-label', 'OpenRegister: Linked Objects sidebar')
	parent.appendChild(container)
	return container
}

/**
 * Mount the Vue sidebar application.
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
 */
function mountSidebar() {
	let retries = 0

	const tryMount = () => {
<<<<<<< HEAD
		if (!isMailAppPage()) {
=======
		const mountPoint = findMountPoint()

		if (!mountPoint) {
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
			retries++
			if (retries < MOUNT_MAX_RETRIES) {
				setTimeout(tryMount, MOUNT_RETRY_INTERVAL)
				return
			}
<<<<<<< HEAD
			console.debug('[OpenRegister] Not a Mail page, skipping sidebar injection')
			return
		}

		// Check if already mounted (works for both expanded and collapsed sidebar).
		if (document.getElementById(SIDEBAR_ROOT_ID)) {
			console.debug('[OpenRegister] Sidebar already mounted')
			return
		}

		try {
			console.info('[OpenRegister] Mounting mail sidebar')
			const app = new Vue({
				render: (h) => h(MailSidebar),
			}).$mount()
			app.$el.id = SIDEBAR_ROOT_ID
			document.body.appendChild(app.$el)
			console.info('[OpenRegister] Mail sidebar mounted successfully')
			return app
		} catch (err) {
			console.error('[OpenRegister] Mail sidebar mount failed:', err)
		}
=======
			console.warn('Mail sidebar: could not find mount point, skipping injection')
			return
		}

		// Check if already mounted
		if (document.getElementById(MOUNT_POINT_ID)) {
			return
		}

		const container = createContainer(mountPoint)

		const app = new Vue({
			el: container,
			render: (h) => h(MailSidebar),
		})
		return app
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
	}

	tryMount()
}

<<<<<<< HEAD
// Wait for DOM to be ready.
=======
// Wait for DOM to be ready
>>>>>>> 23880afe22b6f7f799fd5c26a65e169f6b16c773
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mountSidebar)
} else {
	mountSidebar()
}
