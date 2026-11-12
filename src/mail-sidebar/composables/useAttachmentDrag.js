/**
 * Makes Nextcloud Mail attachments draggable onto OpenRegister sidebar cards.
 *
 * Mail's MessageAttachment.vue has no native drag support (as of Mail 5.7.x),
 * so we DOM-patch attachment elements at runtime: set draggable=true and a
 * dragstart listener that writes attachment metadata into dataTransfer.
 *
 * This is intentionally brittle — it depends on Mail's `.attachment` CSS class
 * and Vue component props. Track 2 (upstream): https://github.com/nextcloud/mail/pull/10509
 * should retire this composable when native drag support lands.
 *
 * @package
 *
 * @spec openspec/specs/mail-sidebar/spec.md
 */

import { onBeforeUnmount, onMounted } from 'vue'

export const ATTACHMENT_MIME = 'application/x-nc-mail-attachment'
const ATTACHMENT_SELECTOR = '.attachment'
const PATCHED_FLAG = '__orAttachmentPatched'

/**
 * Extract attachment metadata from a DOM element's Vue component instance.
 *
 * Supports Vue 3 (__vueParentComponent.props) and Vue 2 (__vue__.$props).
 *
 * @param {HTMLElement} el An .attachment DOM element.
 * @return {object|null} Attachment metadata or null if unavailable.
 */
function readAttachmentProps(el) {
	// Vue 3, then Vue 2
	return (
		attachmentFromProps(el.__vueParentComponent?.props)
		|| attachmentFromProps(el.__vue__?.$props)
	)
}

/**
 * The attachment metadata a component's props carry.
 *
 * @param {object|undefined} p The component's props.
 * @return {object|null} The metadata, or null without an id and a file name.
 */
function attachmentFromProps(p) {
	if (!p || !p.id || !p.fileName) {
		return null
	}
	return {
		id: p.id,
		fileName: p.fileName,
		mime: p.mime,
		size: p.size,
		url: p.url,
	}
}

/**
 * Parse the current URL for the mail thread (message) ID.
 *
 * @return {number|null} The thread ID or null.
 */
function currentMessageId() {
	const m = window.location.href.match(/\/apps\/mail\/box\/[^/]+\/thread\/(\d+)/)
	return m ? parseInt(m[1], 10) : null
}

/**
 * Build the Mail app attachment download URL.
 *
 * @param {number} messageId The message (thread) ID.
 * @param {string} attachmentId The attachment ID.
 * @return {string} The download URL.
 */
function buildDownloadUrl(messageId, attachmentId) {
	return `/apps/mail/api/messages/${messageId}/attachment/${encodeURIComponent(attachmentId)}`
}

/**
 * Patch a single .attachment element with drag support.
 *
 * @param {HTMLElement} el The attachment element.
 */
function patchElement(el) {
	if (el[PATCHED_FLAG]) {
		return
	}
	el[PATCHED_FLAG] = true
	el.setAttribute('draggable', 'true')
	el.style.cursor = el.style.cursor || 'grab'

	el.addEventListener('dragstart', (event) => {
		const props = readAttachmentProps(el)
		const messageId = currentMessageId()
		if (!props || !messageId) {
			// Nothing to offer — let the browser fall back to default behavior.
			return
		}
		const payload = dragPayload(props, messageId)
		event.dataTransfer.effectAllowed = 'copy'
		writeDragData(event.dataTransfer, payload)
	})
}

/**
 * What a dragged attachment carries.
 *
 * @param {object} props The attachment metadata.
 * @param {number} messageId The message (thread) ID.
 * @return {object} The drag payload.
 */
function dragPayload(props, messageId) {
	return {
		messageId,
		attachmentId: props.id,
		fileName: props.fileName,
		mime: props.mime || 'application/octet-stream',
		size: props.size || 0,
		downloadUrl: buildDownloadUrl(messageId, props.id),
	}
}

/**
 * Put the payload on the drag, as OpenRegister data, a URL and plain text.
 *
 * @param {DataTransfer} dataTransfer The drag's data.
 * @param {object} payload The drag payload.
 */
function writeDragData(dataTransfer, payload) {
	try {
		dataTransfer.setData(ATTACHMENT_MIME, JSON.stringify(payload))
		// Also advertise as a URL so non-OR drop targets can handle it.
		dataTransfer.setData('text/uri-list', payload.downloadUrl)
		dataTransfer.setData('text/plain', payload.fileName)
	} catch (err) {
		console.warn('[openregister] Could not set drag data:', err)
	}
}

/**
 * Scan a root element for .attachment children and patch them all.
 *
 * @param {Element|Document} root Root to scan.
 */
function scan(root) {
	if (!root || !root.querySelectorAll) {
		return
	}
	const els = root.querySelectorAll(ATTACHMENT_SELECTOR)
	for (const el of els) {
		patchElement(el)
	}
}

/**
 * Composable: observes the document for attachment elements and makes them
 * draggable until the parent component unmounts.
 */
export function useAttachmentDrag() {
	let observer = null

	onMounted(() => {
		// Initial scan of whatever's already rendered.
		scan(document.body)

		observer = new MutationObserver((mutations) => {
			for (const m of mutations) {
				for (const node of m.addedNodes) {
					if (node.nodeType !== 1) {
						continue
					}
					if (node.matches && node.matches(ATTACHMENT_SELECTOR)) {
						patchElement(node)
					}
					scan(node)
				}
			}
		})

		observer.observe(document.body, { childList: true, subtree: true })
	})

	onBeforeUnmount(() => {
		if (observer) {
			observer.disconnect()
			observer = null
		}
	})
}
