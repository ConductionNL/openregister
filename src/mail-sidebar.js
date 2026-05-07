/**
 * Mail Sidebar entry point.
 *
 * This script is injected into the Nextcloud Mail app via OCP\Util::addScript().
 * It creates a container element and mounts the Vue sidebar component.
 *
 * @package OpenRegister
 *
 * @spec openspec/changes/retrofit-annotate-openregister-2026-04-23/tasks.md#task-51
 */

import Vue from 'vue'
import MailSidebar from './mail-sidebar/MailSidebar.vue'

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
	// Mail-specific content areas only. Do NOT fall back to `#content` —
	// that's Mail's own Vue mount target, and Vue 2's $mount('#content')
	// replaces the element wholesale, taking any container we appended
	// inside it with it. (Symptom: sidebar briefly appears, then vanishes
	// the instant Mail finishes mounting.)
	return document.getElementById('app-content-vue')
		|| document.getElementById('app-content')
		|| document.querySelector('.app-content')
		|| null
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
 */
function mountSidebar() {
	let retries = 0

	const tryMount = () => {
		const mountPoint = findMountPoint()

		if (!mountPoint) {
			retries++
			if (retries < MOUNT_MAX_RETRIES) {
				setTimeout(tryMount, MOUNT_RETRY_INTERVAL)
				return
			}
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
	}

	tryMount()
}

// Wait for DOM to be ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mountSidebar)
} else {
	mountSidebar()
}
