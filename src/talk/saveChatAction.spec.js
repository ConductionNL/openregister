/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The Talk action "Save chat to object": how it reads the conversation, what
 * it hands the picker and what it reports.
 *
 * @spec openspec/changes/files-leaf-save-to-object/specs/file-actions/spec.md#requirement-a-talk-action-saves-a-conversation-to-a-register-object
 */

const mockGet = jest.fn()
jest.mock('@nextcloud/axios', () => ({
	__esModule: true,
	default: { get: (...args) => mockGet(...args) },
}))
jest.mock('@nextcloud/router', () => ({
	generateOcsUrl: (url, params = {}) => '/ocs/v2.php/' + url.replace(/{(\w+)}/g, (m, k) => params[k]),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) => text.replace(/{(\w+)}/g, (m, k) => (vars && k in vars ? vars[k] : m)),
}))
const mockSpawn = jest.fn()
jest.mock('@nextcloud/vue/functions/dialog', () => ({
	spawnDialog: (...args) => mockSpawn(...args),
}), { virtual: true })
const mockSuccess = jest.fn()
const mockError = jest.fn()
jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: (msg) => mockSuccess(msg),
	showError: (msg) => mockError(msg),
}))
jest.mock('../dialogs/files/AttachToObjectDialog.vue', () => ({ name: 'AttachToObjectDialog' }))

const {
	PAGE_SIZE,
	createSaveChatAction,
	fetchConversationMessages,
	messageText,
	registerSaveChatAction,
	saveChat,
} = require('./saveChatAction.js')

/**
 * A Talk chat message.
 *
 * @param {number} id The message id
 * @param {object} extra Fields to set
 * @return {object}
 */
function talkMessage(id, extra = {}) {
	return {
		id,
		timestamp: 1700000000 + id,
		actorDisplayName: 'Anna',
		message: 'Message ' + id,
		messageParameters: [],
		systemMessage: '',
		...extra,
	}
}

/**
 * Talk's answer for one page of messages.
 *
 * @param {Array} messages The page
 * @return {object}
 */
function page(messages) {
	return { data: { ocs: { data: messages } } }
}

beforeEach(() => {
	jest.clearAllMocks()
})

describe('messageText', () => {
	it('replaces Talk placeholders with the names Talk gives them', () => {
		expect(messageText({
			message: 'See {file} from {mention-user1}',
			messageParameters: { file: { name: 'plan.pdf' }, 'mention-user1': { name: 'Bea' } },
		})).toBe('See plan.pdf from Bea')
	})

	it('keeps a placeholder Talk gives no name', () => {
		expect(messageText({ message: 'A {thing}', messageParameters: [] })).toBe('A {thing}')
	})
})

describe('fetchConversationMessages', () => {
	it('reads the conversation without marking it read, leaves system messages out and puts the oldest first', async () => {
		mockGet.mockResolvedValueOnce(page([
			talkMessage(3),
			talkMessage(2, { systemMessage: 'user_added', message: 'You added Bea' }),
			talkMessage(1, { actorDisplayName: 'Bea' }),
		]))

		const messages = await fetchConversationMessages('tok1')

		expect(mockGet).toHaveBeenCalledTimes(1)
		expect(mockGet).toHaveBeenCalledWith('/ocs/v2.php/apps/spreed/api/v1/chat/tok1', {
			params: { lookIntoFuture: 0, limit: PAGE_SIZE, setReadMarker: 0 },
		})
		expect(messages).toEqual([
			{ timestamp: 1700000001, actorDisplayName: 'Bea', message: 'Message 1' },
			{ timestamp: 1700000003, actorDisplayName: 'Anna', message: 'Message 3' },
		])
	})

	it('pages backwards from the oldest message it has until Talk has no older ones', async () => {
		const first = Array.from({ length: PAGE_SIZE }, (_, i) => talkMessage(1000 - i))
		mockGet
			.mockResolvedValueOnce(page(first))
			.mockRejectedValueOnce({ response: { status: 304 } })

		const messages = await fetchConversationMessages('tok1')

		expect(mockGet).toHaveBeenCalledTimes(2)
		expect(mockGet.mock.calls[1][1].params.lastKnownMessageId).toBe(1000 - PAGE_SIZE + 1)
		expect(messages).toHaveLength(PAGE_SIZE)
		expect(messages[0].message).toBe('Message ' + (1000 - PAGE_SIZE + 1))
	})

	it('passes a failure other than "nothing older" on', async () => {
		mockGet.mockRejectedValueOnce({ response: { status: 404 } })

		await expect(fetchConversationMessages('tok1')).rejects.toEqual({ response: { status: 404 } })
	})
})

describe('saveChat', () => {
	const metadata = { token: 'tok1', displayName: 'Case 12 intake' }

	it('hands the picker the conversation name and its messages and names the object it was saved to', async () => {
		mockGet.mockResolvedValueOnce(page([talkMessage(1)]))
		mockSpawn.mockResolvedValue({ object: { title: 'Case 12' }, attached: [{ id: 9 }], skipped: 0 })

		await expect(saveChat({ metadata })).resolves.toBe(true)

		expect(mockSpawn).toHaveBeenCalledWith({ name: 'AttachToObjectDialog' }, {
			chat: {
				conversation: 'Case 12 intake',
				messages: [{ timestamp: 1700000001, actorDisplayName: 'Anna', message: 'Message 1' }],
			},
		})
		expect(mockSuccess).toHaveBeenCalledWith('Added to Case 12')
		expect(mockError).not.toHaveBeenCalled()
	})

	it('reports nothing when the picker is closed without a pick', async () => {
		mockGet.mockResolvedValueOnce(page([talkMessage(1)]))
		mockSpawn.mockResolvedValue(undefined)

		await expect(saveChat({ metadata })).resolves.toBeNull()

		expect(mockSuccess).not.toHaveBeenCalled()
		expect(mockError).not.toHaveBeenCalled()
	})

	it('does not open the picker for a conversation without messages', async () => {
		mockGet.mockResolvedValueOnce(page([talkMessage(1, { systemMessage: 'conversation_created' })]))

		await expect(saveChat({ metadata })).resolves.toBe(false)

		expect(mockSpawn).not.toHaveBeenCalled()
		expect(mockError).toHaveBeenCalledWith('The conversation has no messages to save.')
	})

	it('reports a conversation Talk would not hand over', async () => {
		mockGet.mockRejectedValueOnce({ response: { status: 403 } })

		await expect(saveChat({ metadata })).resolves.toBe(false)

		expect(mockSpawn).not.toHaveBeenCalled()
		expect(mockError).toHaveBeenCalledWith('The conversation could not be read.')
	})

	it('does nothing without a conversation token', async () => {
		await expect(saveChat({ metadata: {} })).resolves.toBeNull()

		expect(mockGet).not.toHaveBeenCalled()
		expect(mockSpawn).not.toHaveBeenCalled()
	})
})

describe('registerSaveChatAction', () => {
	it('registers the labelled action with Talk', () => {
		const talk = { registerMessageAction: jest.fn() }

		expect(registerSaveChatAction(talk)).toBe(true)

		expect(talk.registerMessageAction).toHaveBeenCalledWith({
			label: 'Save chat to object',
			icon: 'icon-folder',
			callback: saveChat,
		})
	})

	it('registers nothing where Talk offers no message actions', () => {
		expect(registerSaveChatAction(undefined)).toBe(false)
		expect(registerSaveChatAction({})).toBe(false)
	})

	it('builds a fresh action each time', () => {
		expect(createSaveChatAction()).not.toBe(createSaveChatAction())
	})
})
