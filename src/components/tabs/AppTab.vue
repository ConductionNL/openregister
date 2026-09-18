<script>
import { getCurrentInstance, h } from 'vue'

/**
 * Fallback counter for the tab identity below.
 *
 * `getCurrentInstance()` supplies the uid in every normal render; this only
 * fires when a tab is constructed outside a component instance. It used to be
 * `Math.random().toString(36).slice(2)`, which CodeQL flagged as
 * js/insecure-randomness (alert 1885, severity high) because the value reaches
 * an identity check. The uid is a UI registration key — AppTabs looks it up to
 * decide which panel renders — and never a secret, so the alert is wrong about
 * the context but right that the RNG has no business here.
 *
 * A counter rather than `crypto.randomUUID()`: that API is restricted to secure
 * contexts and is undefined over plain http, which Nextcloud dev instances are.
 * A counter also cannot collide within a page, which the random form could.
 *
 * @type {number}
 */
let fallbackUid = 0

/**
 * One panel inside {@link AppTabs}. Replaces `bootstrap-vue`'s `<BTab>`.
 *
 * Registers itself with the parent `AppTabs` through provide/inject — Vue 3
 * removed `$children`, so a parent cannot discover its tab children by walking
 * the tree the way `<BTabs>` did.
 *
 * Supported props mirror the subset of `<BTab>` that OpenRegister used:
 *   - `title` — plain string label, or supply a `#title` slot instead.
 *   - `active` — marks this tab as the initially selected one.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
export default {
	name: 'AppTab',
	inject: {
		appTabs: { default: null },
	},

	props: {
		/** Plain-text tab label; ignored when a `#title` slot is supplied. */
		title: {
			type: String,
			default: '',
		},

		/** Select this tab initially. */
		active: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			uid: getCurrentInstance()?.uid ?? `apptab-${++fallbackUid}`,
		}
	},

	created() {
		this.appTabs?.register({
			uid: this.uid,
			title: this.title,
			titleSlot: this.$slots.title ?? null,
			active: this.active,
		})
	},

	beforeUnmount() {
		this.appTabs?.unregister(this.uid)
	},

	/**
	 * Render the panel, or nothing when this tab is not the active one.
	 *
	 * @return {object|null} The panel vnode.
	 */
	render() {
		if (this.appTabs === null) {
			return h('div', this.$slots.default?.())
		}
		if (this.appTabs.isActive(this.uid) !== true) {
			return null
		}
		return h(
			'div',
			{
				role: 'tabpanel',
				id: `app-tabpanel-${this.uid}`,
				'aria-labelledby': `app-tab-${this.uid}`,
				class: 'app-tab-panel',
			},
			this.$slots.default?.(),
		)
	},
}
</script>
