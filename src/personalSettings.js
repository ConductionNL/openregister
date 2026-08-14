import { translate, translatePlural } from '@nextcloud/l10n'
import { createApp } from 'vue'
import PersonalRoot from './components/userSettings/PersonalRoot.vue'

// Personal-settings bundle. Mounts the per-user browser Web Push opt-in toggle
// (openregister-web-push-engine; it drives window.OCA.OpenRegister.WebPush, which
// the always-loaded push client installs on every page) AND the credential-broker
// wallet.
//
// The broker had shipped with no UI at all: CnCredentials existed in the library
// and the REST surface existed on the server, but nothing mounted the component,
// so the only way to create a credential was to hand-craft a POST. That is a large
// part of why every app in the fleet kept custody of its own secrets — the
// sanctioned path was not reachable from a browser.
//
// CnCredentials talks straight to /apps/openregister/api/credentials over axios and
// holds no store, so this entry needs no Pinia.

const app = createApp(PersonalRoot)

app.mixin({
	methods: {
		t: translate,
		n: translatePlural,
	},
})

app.mount('#openregister-personal-settings')
