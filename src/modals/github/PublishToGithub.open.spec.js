import PublishToGithub from './PublishToGithub.vue'

// The repositories load when the dialog opens, not when the component mounts:
// the component mounts with every page (its v-if sits inside it).

jest.mock('../../store/store.js', () => ({ navigationStore: { modal: null } }))
jest.mock('@nextcloud/l10n', () => ({ translate: (app, s) => s }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({}))

const { navigationStore } = jest.requireMock('../../store/store.js')

/**
 * A bare context with the methods bound and the open handler's dependencies stubbed.
 *
 * @param {object} props The props
 * @return {object} The context
 */
function context(props) {
	const ctx = {
		...PublishToGithub.data.call({}),
		modalName: 'publishRegister',
		copy: { commitPrefix: 'Publish ' },
		entity: null,
		defaultFilePath: '',
		...props,
	}
	for (const [name, method] of Object.entries(PublishToGithub.methods)) {
		ctx[name] = method.bind(ctx)
	}
	ctx.loadRepositories = jest.fn(async () => {})
	return ctx
}

describe('PublishToGithub opening', () => {
	afterEach(() => {
		navigationStore.modal = null
	})

	it('has no mount hook that loads anything', () => {
		expect(PublishToGithub.mounted).toBeUndefined()
	})

	it('is open only while its own modal is the open one', () => {
		const ctx = context()
		expect(PublishToGithub.computed.isOpen.call(ctx)).toBe(false)
		navigationStore.modal = 'publishConfiguration'
		expect(PublishToGithub.computed.isOpen.call(ctx)).toBe(false)
		navigationStore.modal = 'publishRegister'
		expect(PublishToGithub.computed.isOpen.call(ctx)).toBe(true)
	})

	it('watches the open state from the start', () => {
		expect(PublishToGithub.watch.isOpen.immediate).toBe(true)
	})

	it('loads nothing while closed', () => {
		const ctx = context()
		PublishToGithub.watch.isOpen.handler.call(ctx, false)
		expect(ctx.loadRepositories).not.toHaveBeenCalled()
	})

	it('loads the repositories and fills the defaults when it opens', async () => {
		const ctx = context({ entity: { title: 'Zaken' }, defaultFilePath: 'registers/zaken.json' })
		PublishToGithub.watch.isOpen.handler.call(ctx, true)
		await Promise.resolve()
		await Promise.resolve()
		expect(ctx.loadRepositories).toHaveBeenCalledTimes(1)
		expect(ctx.commitMessage).toBe('Publish Zaken')
		expect(ctx.filePath).toBe('registers/zaken.json')
	})
})
