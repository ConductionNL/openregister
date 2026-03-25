/**
 * Mail Sidebar entry point.
 *
 * This script is injected into the Nextcloud Mail app via OCP\Util::addScript().
 * It creates a container element and mounts the Vue sidebar component.
 *
 * @package OpenRegister
 */

console.info('[OpenRegister] mail-sidebar.js loaded')

import Vue from 'vue'
import MailSidebar from './mail-sidebar/MailSidebar.vue'

console.info('[OpenRegister] Vue and MailSidebar imported successfully')

const MOUNT_POINT_ID = 'openregister-mail-sidebar'
const MOUNT_RETRY_INTERVAL = 1000
const MOUNT_MAX_RETRIES = 30

/**
 * Check if we're on a Mail app page.
 *
 * @return {boolean} True if the current page is the Mail app.
 */
function isMailPage() {
	return window.location.pathname.includes('/apps/mail')
}

/**
 * Create and inject the sidebar container element as a sibling to the content area.
 * Mounts outside the Mail Vue app tree to avoid Virtual DOM conflicts.
 *
 * @return {HTMLElement} The created container element.
 */
function createContainer() {
	const container = document.createElement('div')
	container.id = MOUNT_POINT_ID
	container.setAttribute('role', 'complementary')
	container.setAttribute('aria-label', 'OpenRegister: Linked Objects sidebar')
	// Mount as a direct child of body, positioned fixed on the right
	document.body.appendChild(container)
	return container
}

/**
 * Mount the Vue sidebar application.
 */
function mountSidebar() {
	// Only mount on Mail app pages
	if (!isMailPage()) {
		console.debug('[OpenRegister] Not a Mail page, skipping sidebar injection')
		return
	}

	// Check if already mounted
	if (document.getElementById(MOUNT_POINT_ID)) {
		console.debug('[OpenRegister] Sidebar already mounted')
		return
	}

	console.info('[OpenRegister] Mounting mail sidebar')
	const container = createContainer()

	new Vue({
		el: container,
		render: (h) => h(MailSidebar),
	})

	console.info('[OpenRegister] Mail sidebar mounted successfully')
}

// Wait for DOM to be ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', mountSidebar)
} else {
	mountSidebar()
}
