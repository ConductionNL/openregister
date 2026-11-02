/**
 * Golden record of useEmailLinks.loadForMessage: the requests it makes and
 * the state it leaves for a fresh load, a cached load with its background
 * refresh, an aborted request and each kind of failure.
 */
import { fetchLinkedObjects, fetchSenderObjects } from '../api/emailLinks.js'
import { useEmailLinks } from './useEmailLinks.js'

jest.mock('../api/emailLinks.js', () => ({
	__esModule: true,
	createQuickLink: jest.fn(),
	deleteEmailLink: jest.fn(),
	fetchLinkedObjects: jest.fn(),
	fetchSenderObjects: jest.fn(),
}))

const LINKED = { results: [{ objectUuid: 'a' }, { objectUuid: 'b' }], total: 7 }
const SENDER = { results: [{ objectUuid: 'b' }, { objectUuid: 'c' }] }

/**
 * Snapshot the reactive state of one composable instance.
 *
 * @param {object} links The composable instance.
 * @return {object} Plain values of every state ref.
 */
function state(links) {
	return {
		linkedObjects: links.linkedObjects.value,
		suggestedObjects: links.suggestedObjects.value,
		loading: links.loading.value,
		error: links.error.value,
		total: links.total.value,
		suggestedTotal: links.suggestedTotal.value,
	}
}

/**
 * Record the calls of both fetch mocks, with the signal reduced to whether
 * it was passed and aborted.
 *
 * @return {object} The recorded calls.
 */
function calls() {
	const plain = (args) =>
		args.map((arg) =>
			arg instanceof AbortSignal
				? { signal: true, aborted: arg.aborted }
				: arg,
		)
	return {
		linked: fetchLinkedObjects.mock.calls.map(plain),
		sender: fetchSenderObjects.mock.calls.map(plain),
	}
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0))

/**
 * Build an error shaped like an axios failure.
 *
 * @param {object} fields Properties to copy onto the error.
 * @return {Error} The error.
 */
function failure(fields) {
	return Object.assign(new Error('x'), fields)
}

beforeEach(() => {
	jest.clearAllMocks()
	fetchLinkedObjects.mockResolvedValue(LINKED)
	fetchSenderObjects.mockResolvedValue(SENDER)
})

describe('useEmailLinks.loadForMessage golden', () => {
	it('fresh load with a sender, then a cached load and its refresh', async () => {
		const links = useEmailLinks()
		await links.loadForMessage(1, 2, 'x@example.com')
		const fresh = { state: state(links), calls: calls() }

		fetchLinkedObjects.mockResolvedValue({ results: [{ objectUuid: 'c' }] })
		await links.loadForMessage(1, 2, 'x@example.com')
		const cached = state(links)
		await flush()
		expect({
			fresh,
			cached,
			refreshed: state(links),
			calls: calls(),
		}).toMatchSnapshot()
	})

	it('fresh load without a sender and with empty answers', async () => {
		fetchLinkedObjects.mockResolvedValue({})
		const links = useEmailLinks()
		await links.loadForMessage(3, 4)
		const noSender = state(links)
		fetchSenderObjects.mockResolvedValue({})
		await links.loadForMessage(3, 5, 's@example.com')
		expect({
			noSender,
			emptySender: state(links),
			calls: calls(),
		}).toMatchSnapshot()
	})

	it('cached load without a sender and a cache bypass', async () => {
		const links = useEmailLinks()
		await links.loadForMessage(1, 2)
		await links.loadForMessage(1, 2)
		await flush()
		const cached = state(links)
		fetchLinkedObjects.mockResolvedValue({ results: [], total: 0 })
		await links.loadForMessage(1, 2, undefined, false)
		expect({ cached, bypass: state(links), calls: calls() }).toMatchSnapshot()
	})

	it('a refresh that fails or whose cache entry was dropped changes nothing', async () => {
		const links = useEmailLinks()
		await links.loadForMessage(1, 2, 's')
		fetchLinkedObjects.mockRejectedValueOnce(new Error('down'))
		await links.loadForMessage(1, 2, 's')
		await flush()
		const failedRefresh = state(links)

		let release
		fetchLinkedObjects.mockReturnValueOnce(
			new Promise((resolve) => {
				release = resolve
			}),
		)
		await links.loadForMessage(1, 2, 's')
		fetchLinkedObjects.mockResolvedValue(LINKED)
		await links.unlinkObject(9, 1, 2)
		release({ results: [{ objectUuid: 'z' }] })
		await flush()
		expect({
			failedRefresh,
			dropped: state(links),
			calls: calls(),
		}).toMatchSnapshot()
	})

	it('a second load aborts the first', async () => {
		let release
		fetchLinkedObjects.mockReturnValueOnce(
			new Promise((resolve) => {
				release = resolve
			}),
		)
		const links = useEmailLinks()
		const first = links.loadForMessage(1, 2)
		const whileLoading = state(links)
		const second = links.loadForMessage(1, 3)
		release(LINKED)
		await Promise.all([first, second])
		expect({
			whileLoading,
			after: state(links),
			calls: calls(),
		}).toMatchSnapshot()
	})

	it.each([
		['abort', failure({ name: 'AbortError' })],
		['canceled', failure({ name: 'CanceledError' })],
		['server', failure({ response: { status: 503 } })],
		['client', failure({ response: { status: 404 } })],
		['timeout', failure({ code: 'ECONNABORTED' })],
		[
			'timeout over 500',
			failure({ code: 'ECONNABORTED', response: { status: 500 } }),
		],
		['network', failure({})],
	])('linked fetch fails: %s', async (_label, err) => {
		const links = useEmailLinks()
		await links.loadForMessage(1, 2, 's')
		fetchLinkedObjects.mockRejectedValue(err)
		await links.loadForMessage(1, 3, 's')
		const failed = state(links)
		fetchLinkedObjects.mockResolvedValue(LINKED)
		await links.loadForMessage(1, 3, 's')
		expect({ failed, retried: state(links), calls: calls() }).toMatchSnapshot()
	})

	it('sender fetch fails after the linked fetch', async () => {
		fetchSenderObjects.mockRejectedValue(failure({ response: { status: 500 } }))
		const links = useEmailLinks()
		await links.loadForMessage(1, 2, 's')
		const failed = state(links)
		fetchSenderObjects.mockResolvedValue(SENDER)
		await links.loadForMessage(1, 2, 's')
		expect({ failed, notCached: state(links), calls: calls() }).toMatchSnapshot()
	})
})
