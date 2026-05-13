const mountedCallbacks = []
const beforeUnmountCallbacks = []

jest.mock('vue', () => ({
	onMounted: (cb) => mountedCallbacks.push(cb),
	onBeforeUnmount: (cb) => beforeUnmountCallbacks.push(cb),
}))

const { useAttachmentDrag, ATTACHMENT_MIME } = require('./useAttachmentDrag.js')

class MockDataTransfer {
	constructor() {
		this.data = {}
		this.effectAllowed = 'none'
	}

	setData(type, value) {
		this.data[type] = value
	}
}

describe('useAttachmentDrag', () => {
	let observerInstance
	let disconnectSpy

	beforeEach(() => {
		mountedCallbacks.length = 0
		beforeUnmountCallbacks.length = 0
		document.body.innerHTML = ''
		disconnectSpy = jest.fn()
		global.MutationObserver = jest.fn().mockImplementation((cb) => {
			observerInstance = { cb, observe: jest.fn(), disconnect: disconnectSpy }
			return observerInstance
		})
	})

	it('patches attachment element and writes drag payload', async () => {
		const attachment = document.createElement('div')
		attachment.className = 'attachment'
		attachment.__vue__ = {
			$props: {
				id: 'att-1',
				fileName: 'contract.pdf',
				mime: 'application/pdf',
				size: 123,
			},
		}
		document.body.appendChild(attachment)
		window.history.pushState({}, '', '/apps/mail/box/2/thread/77')

		useAttachmentDrag()
		mountedCallbacks[0]()

		expect(attachment.getAttribute('draggable')).toBe('true')

		const event = new Event('dragstart')
		event.dataTransfer = new MockDataTransfer()
		attachment.dispatchEvent(event)

		const payload = JSON.parse(event.dataTransfer.data[ATTACHMENT_MIME])
		expect(payload.messageId).toBe(77)
		expect(payload.attachmentId).toBe('att-1')
		expect(payload.fileName).toBe('contract.pdf')
		expect(payload.downloadUrl).toBe('/apps/mail/api/messages/77/attachment/att-1')
		expect(event.dataTransfer.data['text/uri-list']).toBe('/apps/mail/api/messages/77/attachment/att-1')
	})

	it('does not double-patch already scanned attachment', () => {
		const attachment = document.createElement('div')
		attachment.className = 'attachment'
		attachment.__vue__ = { $props: { id: 'att-2', fileName: 'a.txt' } }
		const addEventListenerSpy = jest.spyOn(attachment, 'addEventListener')
		document.body.appendChild(attachment)

		useAttachmentDrag()
		mountedCallbacks[0]()

		observerInstance.cb([{ addedNodes: [attachment] }])
		observerInstance.cb([{ addedNodes: [attachment] }])

		expect(addEventListenerSpy).toHaveBeenCalledTimes(1)
	})

	it('disconnects observer on unmount', () => {
		useAttachmentDrag()
		mountedCallbacks[0]()
		beforeUnmountCallbacks[0]()
		expect(disconnectSpy).toHaveBeenCalledTimes(1)
	})
})
