import Vue from 'vue'
import { createPinia, PiniaVuePlugin } from 'pinia'
import Settings from './views/settings/Settings.vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import { loadState } from '@nextcloud/initial-state'

// Install Pinia plugin
Vue.use(PiniaVuePlugin)

// Create Pinia instance
const pinia = createPinia()

// Add translation methods to Vue prototype
Vue.mixin({
	methods: {
		t: translate,
		n: translatePlural,
	},
})

// Read push status from PHP initial state (provided by OpenRegisterAdmin::getForm()).
const pushStatus = loadState('openregister', 'push_status', 'not_installed')

new Vue({
	pinia,
	render: h => h(Settings, { props: { pushStatus } }),
}).$mount('#settings')
