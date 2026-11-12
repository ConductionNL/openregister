/**
 * Golden record of the schema actions on a register card: the requests they
 * send, the confirmations they ask, the toasts they show and the events they
 * emit, for every branch of the server's answer.
 *
 * @spec exclude Golden record of client-side register card actions.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */
const ICONS = [
	'Api',
	'CheckCircle',
	'ChevronDown',
	'ChevronUp',
	'CloudUploadOutline',
	'CogOutline',
	'DatabaseOutline',
	'DeleteOutline',
	'DotsHorizontal',
	'Download',
	'Export',
	'FileTreeOutline',
	'InformationOutline',
	'Pencil',
	'Sync',
	'Table',
	'TrashCanOutline',
	'Upload',
]
for (const icon of ICONS) {
	jest.mock(`vue-material-design-icons/${icon}.vue`, () => ({}), {
		virtual: false,
	})
}

const mockLog = []

jest.mock('@conduction/nextcloud-vue', () => ({ CnFormDialog: {} }))
jest.mock('@nextcloud/vue', () => ({}))
jest.mock('@nextcloud/axios', () => {
	const call = (verb) =>
		jest.fn((...args) => {
			mockLog.push([verb, ...args])
			return globalThis.nextAnswer()
		})
	return {
		__esModule: true,
		default: {
			get: call('get'),
			post: call('post'),
			put: call('put'),
			delete: call('delete'),
		},
	}
})
jest.mock('@nextcloud/dialogs', () => ({
	showSuccess: (text) => mockLog.push(['success', text]),
	showError: (text) => mockLog.push(['error', text]),
}))
jest.mock('@nextcloud/l10n', () => ({
	translate: (app, text, vars) =>
		vars ? `${text} ${JSON.stringify(vars)}` : text,
	translatePlural: (app, one) => one,
}))
jest.mock('../../store/store.js', () => ({
	configurationStore: {},
	dashboardStore: { fetchRegisters: () => mockLog.push(['fetchRegisters']) },
	navigationStore: {},
	registerStore: {
		refreshRegisterList: () => mockLog.push(['refreshRegisterList']),
	},
	schemaStore: {},
}))

const RegisterSchemaCard = require('./RegisterSchemaCard.vue').default

const answers = []
globalThis.nextAnswer = () => {
	const next = answers.shift()
	return next instanceof Error ? Promise.reject(next) : Promise.resolve(next)
}

/**
 * A server refusal the way axios reports it.
 *
 * @param {object} data The response body, or null for a network error
 * @return {Error} The error
 */
function refusal(data) {
	const error = new Error('network down')
	if (data) {
		error.response = { data }
	}
	return error
}

/**
 * A bare card context with the card's methods bound to it.
 *
 * @return {object} The context
 */
function card() {
	const ctx = {
		item: { id: 7, title: 'Register seven', required: [] },
		$emit: (name) => mockLog.push(['emit', name]),
	}
	for (const [name, method] of Object.entries(RegisterSchemaCard.methods)) {
		ctx[name] = method.bind(ctx)
	}
	return ctx
}

const SCHEMA = {
	id: 3,
	title: 'Schema three',
	stats: { objects: { total: 5, deleted: 2 } },
}

const STATS = {
	metadata: { count: 4 },
	properties: { count: 6 },
	columns: {
		added: { count: 1 },
		removed: { count: 0 },
		deRequired: { count: 2 },
		unchanged: { count: 3 },
	},
}

const SCENARIOS = {
	'syncMagicTable full stats': [
		'syncMagicTable',
		[SCHEMA],
		[{ data: { success: true, statistics: STATS } }],
	],
	'syncMagicTable columns only': [
		'syncMagicTable',
		[SCHEMA],
		[
			{
				data: {
					success: true,
					statistics: {
						columns: {
							added: { count: 0 },
							removed: { count: 2 },
							deRequired: { count: 0 },
							unchanged: { count: 0 },
						},
					},
				},
			},
		],
	],
	'syncMagicTable unchanged columns': [
		'syncMagicTable',
		[SCHEMA],
		[
			{
				data: {
					success: true,
					statistics: {
						columns: {
							added: { count: 0 },
							removed: { count: 0 },
							deRequired: { count: 0 },
							unchanged: { count: 0 },
						},
					},
				},
			},
		],
	],
	'syncMagicTable no details': [
		'syncMagicTable',
		[SCHEMA],
		[{ data: { success: true, statistics: { metadata: { count: 1 } } } }],
	],
	'syncMagicTable no success': [
		'syncMagicTable',
		[SCHEMA],
		[{ data: { success: false } }],
	],
	'syncMagicTable no data': ['syncMagicTable', [SCHEMA], [{}]],
	'syncMagicTable server message': [
		'syncMagicTable',
		[SCHEMA],
		[refusal({ message: 'locked' })],
	],
	'syncMagicTable network error': ['syncMagicTable', [SCHEMA], [refusal(null)]],
	'syncMagicTable empty error': [
		'syncMagicTable',
		[SCHEMA],
		[Object.assign(new Error(''), { response: { data: {} } })],
	],

	'setSchemaConfiguration magic': [
		'setSchemaConfiguration',
		[SCHEMA, 'magic'],
		[
			{
				data: {
					title: 'S',
					type: 'array',
					properties: { a: {}, b: { table: { enabled: false } } },
					required: ['a'],
					description: 'd',
				},
			},
			{},
		],
	],
	'setSchemaConfiguration magic bare schema': [
		'setSchemaConfiguration',
		[SCHEMA, 'magic'],
		[{ data: { title: 'S' } }, {}],
	],
	'setSchemaConfiguration magic null properties': [
		'setSchemaConfiguration',
		[SCHEMA, 'magic'],
		[{ data: { title: 'S', properties: null } }, {}],
	],
	'setSchemaConfiguration blob': [
		'setSchemaConfiguration',
		[SCHEMA, 'blob'],
		[
			{
				data: {
					title: 'S',
					properties: {
						a: { table: { enabled: true } },
						b: { type: 'string' },
					},
				},
			},
			{},
		],
	],
	'setSchemaConfiguration blob bare schema': [
		'setSchemaConfiguration',
		[SCHEMA, 'blob'],
		[{ data: { title: 'S' } }, {}],
	],
	'setSchemaConfiguration fetch fails': [
		'setSchemaConfiguration',
		[SCHEMA, 'magic'],
		[refusal({ message: 'gone' })],
	],
	'setSchemaConfiguration save fails': [
		'setSchemaConfiguration',
		[SCHEMA, 'blob'],
		[{ data: { title: 'S' } }, refusal(null)],
	],

	'validateSchemaObjects success': [
		'validateSchemaObjects',
		[SCHEMA],
		[
			{
				data: {
					success: true,
					statistics: {
						processed: 5,
						updated: 2,
						failed: 1,
						errors: ['e'],
					},
				},
			},
		],
	],
	'validateSchemaObjects success no errors': [
		'validateSchemaObjects',
		[SCHEMA],
		[
			{
				data: {
					success: true,
					statistics: { processed: 5, updated: 2, failed: 0, errors: [] },
				},
			},
		],
	],
	'validateSchemaObjects success errors missing': [
		'validateSchemaObjects',
		[SCHEMA],
		[
			{
				data: {
					success: true,
					statistics: { processed: 1, updated: 0, failed: 0 },
				},
			},
		],
	],
	'validateSchemaObjects no success': [
		'validateSchemaObjects',
		[SCHEMA],
		[{ data: {} }],
	],
	'validateSchemaObjects fails': [
		'validateSchemaObjects',
		[SCHEMA],
		[refusal({ message: 'bad' })],
	],

	'deleteSchemaObjects nothing to delete': [
		'deleteSchemaObjects',
		[{ id: 3, title: 'Empty' }],
		[],
	],
	'deleteSchemaObjects soft': [
		'deleteSchemaObjects',
		[SCHEMA],
		[{ data: { success: true, deleted_count: 3 } }],
	],
	'deleteSchemaObjects soft no count': [
		'deleteSchemaObjects',
		[SCHEMA],
		[{ data: { success: true } }],
	],
	'deleteSchemaObjects soft no success': [
		'deleteSchemaObjects',
		[SCHEMA],
		[{ data: { success: false } }],
	],
	'deleteSchemaObjects soft none active': [
		'deleteSchemaObjects',
		[{ id: 3, title: 'Gone', stats: { objects: { total: 2, deleted: 2 } } }],
		[],
	],
	'deleteSchemaObjects soft declined': [
		'deleteSchemaObjects',
		[SCHEMA],
		[],
		false,
	],
	'deleteSchemaObjects hard mixed': [
		'deleteSchemaObjects',
		[SCHEMA, true],
		[{ data: { success: true, deleted_count: 5 } }],
	],
	'deleteSchemaObjects hard deleted only': [
		'deleteSchemaObjects',
		[
			{ id: 3, title: 'Gone', stats: { objects: { total: 2, deleted: 2 } } },
			true,
		],
		[{ data: { success: true } }],
	],
	'deleteSchemaObjects hard active only': [
		'deleteSchemaObjects',
		[{ id: 3, title: 'Live', stats: { objects: { total: 4 } } }, true],
		[{}],
	],
	'deleteSchemaObjects hard fails': [
		'deleteSchemaObjects',
		[SCHEMA, true],
		[refusal({ message: 'no' })],
	],

	'removeSchemaFromRegister has objects': [
		'removeSchemaFromRegister',
		[SCHEMA],
		[],
	],
	'removeSchemaFromRegister empty': [
		'removeSchemaFromRegister',
		[{ id: 4, title: 'Empty' }],
		[{}],
	],
	'removeSchemaFromRegister declined': [
		'removeSchemaFromRegister',
		[{ id: 4, title: 'Empty', stats: { objects: { total: 0 } } }],
		[],
		false,
	],
	'removeSchemaFromRegister fails': [
		'removeSchemaFromRegister',
		[{ id: 4, title: 'Empty' }],
		[refusal({ message: 'in use' })],
	],
}

describe('register card schema actions golden', () => {
	beforeEach(() => {
		mockLog.length = 0
		answers.length = 0
		jest.spyOn(console, 'error').mockImplementation((...args) =>
			mockLog.push(['console.error', args[0], String(args[1])]),
		)
		jest.spyOn(console, 'warn').mockImplementation((...args) =>
			mockLog.push(['console.warn', ...args]),
		)
	})

	afterEach(() => {
		jest.restoreAllMocks()
	})

	for (const [name, [method, args, replies, confirmed = true]] of Object.entries(
		SCENARIOS,
	)) {
		it(name, async () => {
			answers.push(...replies)
			globalThis.confirm = (text) => {
				mockLog.push(['confirm', text])
				return confirmed
			}
			const input = JSON.parse(JSON.stringify(args))
			await card()[method](...input)
			expect({ mockLog, input }).toMatchSnapshot()
		})
	}
})
