import axios from '@nextcloud/axios'
import { showError, showSuccess } from '@nextcloud/dialogs'
import RegistersIndex from './RegistersIndex.vue'

// The names-cache warmup action: one toast per outcome, worded from the response.

jest.mock('@nextcloud/axios', () => ({ post: jest.fn() }))
jest.mock('@nextcloud/dialogs', () => ({
	showError: jest.fn(),
	showSuccess: jest.fn(),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, one, many) => many,
}))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('vue-material-design-icons/Api.vue', () => ({}))
jest.mock('vue-material-design-icons/CloudUploadOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/CogOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/DotsHorizontal.vue', () => ({}))
jest.mock('vue-material-design-icons/Download.vue', () => ({}))
jest.mock('vue-material-design-icons/Eye.vue', () => ({}))
jest.mock('vue-material-design-icons/InformationOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Pencil.vue', () => ({}))
jest.mock('vue-material-design-icons/Refresh.vue', () => ({}))
jest.mock('vue-material-design-icons/TrashCanOutline.vue', () => ({}))
jest.mock('vue-material-design-icons/Upload.vue', () => ({}))
jest.mock('../../components/cards/RegisterSchemaCard.vue', () => ({}))
jest.mock('../../store/store.js', () => ({
	configurationStore: {},
	navigationStore: {},
	registerStore: {},
	schemaStore: {},
}))

const RESPONSES = {
	grew: {
		data: {
			success: true,
			loaded_names: 12,
			execution_time: '40ms',
			old_cache: { distributed_name_cache_size: 3 },
			new_cache: { distributed_name_cache_size: 9 },
		},
	},
	shrunk: {
		data: {
			success: true,
			loaded_names: 2,
			execution_time: '5ms',
			old_cache: { distributed_name_cache_size: 9 },
			new_cache: { distributed_name_cache_size: 4 },
		},
	},
	same: {
		data: {
			success: true,
			loaded_names: 0,
			old_cache: { distributed_name_cache_size: 7 },
			new_cache: { distributed_name_cache_size: 7 },
		},
	},
	bare: { data: { success: true } },
	newOnly: { data: { success: 1, new_cache: { distributed_name_cache_size: 5 } } },
	oldOnly: {
		data: { success: true, old_cache: { distributed_name_cache_size: 5 } },
	},
	notSuccess: { data: { success: false, loaded_names: 4 } },
	noData: { data: null },
}

const FAILURES = {
	serverMessage: {
		response: { data: { message: 'Forbidden' } },
		message: 'Request failed',
	},
	plainMessage: { message: 'Network down' },
	emptyServerMessage: { response: { data: { message: '' } }, message: 'Timeout' },
	nothing: {},
	responseWithoutData: { response: {} },
}

/**
 * Everything the action said and sent.
 *
 * @return {object} The recorded calls
 */
function recorded() {
	return {
		post: axios.post.mock.calls,
		success: showSuccess.mock.calls,
		error: showError.mock.calls,
	}
}

describe('RegistersIndex.warmupNamesCache', () => {
	beforeEach(() => {
		jest.clearAllMocks()
		jest.spyOn(console, 'error').mockImplementation(() => {})
	})

	afterEach(() => jest.restoreAllMocks())

	it.each(Object.keys(RESPONSES))('answers %s', async (name) => {
		axios.post.mockResolvedValue(RESPONSES[name])
		await RegistersIndex.methods.warmupNamesCache.call({})
		expect(recorded()).toMatchSnapshot()
	})

	it.each(Object.keys(FAILURES))('fails with %s', async (name) => {
		axios.post.mockRejectedValue(FAILURES[name])
		await RegistersIndex.methods.warmupNamesCache.call({})
		expect(recorded()).toMatchSnapshot()
	})
})
