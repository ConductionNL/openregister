/**
 * Mail Sidebar entry point.
 *
 * This script is injected into the Nextcloud Mail app via OCP\Util::addScript().
 * It creates a container element and mounts the Vue sidebar component.
 *
 * @package OpenRegister
 */

import Vue from 'vue'
import MailSidebar from './mail-sidebar/MailSidebar.vue'

const MOUNT_POINT_ID = 'openregister-mail-sidebar'
const MOUNT_RETRY_INTERVAL = 1000
const MOUNT_MAX_RETRIES = 30

/**
 * Find the parent element to mount the sidebar as a sibling of the Mail app content.
 *
 * We mount as a SIBLING of #app-content-vue, not inside it, because
 * Mail's Vue instance owns that element and will destroy injected children
 * during re-renders.
 *
 * @return {HTMLElement|null} The parent element to inject into, or null.
 */
function findMountParent() {
	// Mount as sibling of #app-content-vue inside #content-vue
	const appContent = document.getElementById('app-content-vue')
	if (appContent && appContent.parentElement) {
		return appContent.parentElement
	}

	// Fallback: try #content or body
	return document.getElementById('content')
		|| document.getElementById('content-vue')
		|| document.body
}

/**
 * Create and inject the sidebar container element as a sibling.
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
 */
function mountSidebar() {
	let retries = 0

	const tryMount = () => {
		console.log('[OpenRegister] tryMount attempt', retries)
		const mountParent = findMountParent()

		if (!mountParent) {
			retries++
			if (retries < MOUNT_MAX_RETRIES) {
				setTimeout(tryMount, MOUNT_RETRY_INTERVAL)
				return
			}
			console.warn('Mail sidebar: could not find mount point, skipping injection')
			return
		}

		// Check if already mounted
		if (document.querySelector('.or-mail-sidebar')) {
			console.log('[OpenRegister] Sidebar already mounted, skipping')
			return
		}

		// Mount Vue instance independently, then append to body.
		// We cannot mount inside any Vue-managed container (#content-vue, #app-content-vue)
		// because the parent Vue app will destroy our injected DOM during re-renders.
		console.log('[OpenRegister] Creating Vue instance...')

		try {
			const app = new Vue({
				render: (h) => h(MailSidebar),
			}).$mount()

<<<<<<< HEAD
		const app = new Vue({
			el: container,
			render: (h) => h(MailSidebar),
		})
		return app
=======
			// Append to document.body — completely outside any Vue-managed tree
			document.body.appendChild(app.$el)
			console.log('[OpenRegister] Mail sidebar mounted to body')
			return app
		} catch (err) {
			console.error('[OpenRegister] Vue mount failed:', err)
		}
>>>>>>> origin/feature/linked-entity-types
	}

	tryMount()
}

console.log('[OpenRegister] mail-sidebar.js loaded')

// Wait for DOM to be ready
if (document.readyState === 'loading') {
	console.log('[OpenRegister] DOM loading, waiting for DOMContentLoaded')
	document.addEventListener('DOMContentLoaded', mountSidebar)
} else {
	console.log('[OpenRegister] DOM ready, mounting immediately')
	mountSidebar()
}
