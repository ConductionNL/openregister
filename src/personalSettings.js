import Vue from 'vue'
import { translate, translatePlural } from '@nextcloud/l10n'
import BrowserNotificationsSection from './components/userSettings/BrowserNotificationsSection.vue'

// Personal-settings bundle (openregister-web-push-engine): mounts the per-user
// browser Web Push opt-in toggle into the "Additional settings" personal
// section. The toggle drives window.OCA.OpenRegister.WebPush, which the
// always-loaded push client installs on every page.

Vue.mixin({
	methods: {
		t: translate,
		n: translatePlural,
	},
})

new Vue({
	render: h => h(BrowserNotificationsSection),
}).$mount('#openregister-personal-settings')
