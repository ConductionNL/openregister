import { createApp, h } from 'vue'
import { createPinia } from 'pinia'
import Settings from './views/settings/Settings.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'
import { ensureIntegrationRegistry } from './integrations/bootstrap.js'

// Bootstrap the integration registry on the admin-settings bundle too —
// any sub-component that calls useIntegrationRegistry() needs the registry
// populated before render. Idempotent. See ADR-019.
ensureIntegrationRegistry()

// Create Pinia instance
const pinia = createPinia()

// Read push status from PHP initial state (provided by OpenRegisterAdmin::getForm()).
const pushStatus = loadState('openregister', 'push_status', 'not_installed')

const app = createApp({
	render: () => h(Settings, { pushStatus }),
})

app.mixin({
	methods: {
		t: translate,
		n: translatePlural,
	},
})
app.use(pinia)
app.mount('#settings')
