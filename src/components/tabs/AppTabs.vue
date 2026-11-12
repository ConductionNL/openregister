<script>
import { h } from 'vue'

/**
 * Minimal tabbed-panel container, replacing `bootstrap-vue`'s `<BTabs>`.
 *
 * WHY THIS EXISTS
 * ---------------
 * `bootstrap-vue@2` is Vue 2 only — there is no Vue 3 release — and nine
 * OpenRegister surfaces used its `<BTabs>` / `<BTab>` pair. Neither
 * `@nextcloud/vue@9` nor `@conduction/nextcloud-vue` ships a generic in-page
 * tabbed panel (`NcAppSidebarTab` only works inside `NcAppSidebar`), so the
 * choice was to hand-roll one or to restructure nine views.
 *
 * The API is deliberately the subset of `<BTabs>` those nine call sites
 * actually used, so the migration diff in each of them stays a rename:
 *
 *   - `v-model` — the active tab INDEX (same as BTabs).
 *   - `<AppTab>` children, each with either a `title` prop or a `#title` slot.
 *   - `<AppTab active>` marks the initially-selected tab when no v-model
 *     value is supplied.
 *   - `content-class` / `justified` are accepted and applied, so existing
 *     markup does not have to be edited.
 *
 * ⚠️ A generic tabbed panel belongs in `@conduction/nextcloud-vue`, not here —
 * every app migrating off bootstrap-vue needs the same component. This copy is
 * app-local only until the library ships one.
 *
 * Accessibility: the tab strip is a real `role="tablist"` with roving
 * `aria-selected` / `aria-controls` wiring and arrow-key navigation, which
 * `<BTabs>` also provided and which a naive `<div @click>` reimplementation
 * would have silently dropped (WCAG 2.1 AA, SC 4.1.2).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
/**
 * The classes of the tab strip.
 *
 * @param {boolean} justified Whether the tabs share the width
 * @param {boolean} pills Whether the tabs render as pills
 * @return {Array} The class list
 */
function tabListClasses(justified, pills) {
	return [
		'app-tabs__list',
		{
			'app-tabs__list--justified': justified,
			'app-tabs__list--pills': pills,
		},
	]
}

/**
 * The classes of one tab button.
 *
 * @param {boolean} active Whether the tab is the selected one
 * @param {boolean} disabled Whether the tab is disabled
 * @return {Array} The class list
 */
function tabClasses(active, disabled) {
	return [
		'app-tabs__tab',
		{
			'app-tabs__tab--active': active,
			'app-tabs__tab--disabled': disabled,
		},
	]
}

/**
 * The props of one tab button: its ids, classes, state and focus order.
 *
 * @param {object} tab The registered tab
 * @param {boolean} active Whether the tab is the selected one
 * @return {object} The button props
 */
function tabButtonProps(tab, active) {
	const disabled = tab.disabled === true
	return {
		key: tab.uid,
		type: 'button',
		role: 'tab',
		id: `app-tab-${tab.uid}`,
		class: tabClasses(active, disabled),
		disabled,
		'aria-selected': active ? 'true' : 'false',
		'aria-controls': `app-tabpanel-${tab.uid}`,
		tabindex: active ? 0 : -1,
	}
}

export default {
	name: 'AppTabs',
	provide() {
		return {
			appTabs: {
				register: this.registerTab,
				unregister: this.unregisterTab,
				isActive: (uid) => {
					const tab = this.tabs.find((entry) => entry.uid === uid)
					return (
						tab !== undefined
						&& this.tabs.indexOf(tab) === this.activeIndex
					)
				},
			},
		}
	},

	props: {
		/** Index of the active tab. */
		modelValue: {
			type: Number,
			default: null,
		},

		/** Class applied to the content area (bootstrap-vue compatibility). */
		contentClass: {
			type: String,
			default: '',
		},

		/** Stretch the tab buttons to fill the strip. */
		justified: {
			type: Boolean,
			default: false,
		},

		/** Pill-shaped tab buttons (bootstrap-vue compatibility). */
		pills: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],
	data() {
		return {
			/** Internal index, used when no v-model is bound. */
			internalIndex: 0,
			/** Bumped on every child registration to force a re-render. */
			registrationTick: 0,
			/** Registered child tabs, in declaration order. */
			tabs: [],
		}
	},

	computed: {
		/**
		 * Currently active tab index.
		 *
		 * @return {number} The active index.
		 */
		activeIndex() {
			return this.modelValue === null ? this.internalIndex : this.modelValue
		},
	},

	created() {
		// Children register through provide/inject rather than by walking
		// $children, which Vue 3 removed.
		this.$.provides = this.$.provides || {}
	},

	methods: {
		/**
		 * Register a child tab.
		 *
		 * @param {object} tab The child descriptor `{ uid, title, titleSlot, active }`.
		 * @return {void}
		 */
		registerTab(tab) {
			this.tabs.push(tab)
			if (
				tab.active === true
				&& tab.disabled !== true
				&& this.modelValue === null
			) {
				this.internalIndex = this.tabs.length - 1
			}
			this.registrationTick += 1
		},

		/**
		 * Unregister a child tab.
		 *
		 * @param {number|string} uid The child's uid.
		 * @return {void}
		 */
		unregisterTab(uid) {
			this.tabs = this.tabs.filter((entry) => entry.uid !== uid)
			this.registrationTick += 1
		},

		/**
		 * Select a tab by index.
		 *
		 * @param {number} index The index to activate.
		 * @return {void}
		 */
		select(index) {
			if (index < 0 || index >= this.tabs.length) {
				return
			}
			if (this.tabs[index]?.disabled === true) {
				return
			}
			this.internalIndex = index
			this.$emit('update:modelValue', index)
		},

		/**
		 * Arrow-key navigation across the tab strip.
		 *
		 * @param {KeyboardEvent} event The keydown event.
		 * @return {void}
		 */
		onKeydown(event) {
			const last = this.tabs.length - 1
			if (event.key === 'ArrowRight') {
				this.select(this.activeIndex >= last ? 0 : this.activeIndex + 1)
			} else if (event.key === 'ArrowLeft') {
				this.select(this.activeIndex <= 0 ? last : this.activeIndex - 1)
			} else if (event.key === 'Home') {
				this.select(0)
			} else if (event.key === 'End') {
				this.select(last)
			} else {
				return
			}
			event.preventDefault()
			this.$nextTick(() => {
				const buttons = this.$refs.tablist?.querySelectorAll('[role="tab"]')
				buttons?.[this.activeIndex]?.focus()
			})
		},

		/**
		 * Render the tab strip.
		 *
		 * @return {object} The tablist vnode.
		 */
		renderTabList() {
			return h(
				'div',
				{
					ref: 'tablist',
					class: tabListClasses(this.justified, this.pills),
					role: 'tablist',
					onKeydown: this.onKeydown,
				},
				this.tabs.map((tab, index) => this.renderTab(tab, index)),
			)
		},

		/**
		 * Render one tab button of the strip.
		 *
		 * @param {object} tab The registered tab
		 * @param {number} index Its position in the strip
		 * @return {object} The tab button vnode.
		 */
		renderTab(tab, index) {
			return h(
				'button',
				{
					...tabButtonProps(tab, index === this.activeIndex),
					onClick: () => this.select(index),
				},
				tab.titleSlot ? tab.titleSlot() : tab.title,
			)
		},
	},

	/**
	 * Render the tab strip above the (slot-provided) tab panels.
	 *
	 * @return {object} The root vnode.
	 */
	render() {
		// Referenced so a child registration invalidates this render.
		// `void` marks the read as deliberate: a bare `this.registrationTick`
		// is indistinguishable from `this.someFn` written instead of
		// `this.someFn()`, which is exactly what no-unused-expressions exists
		// to catch. The dependency is still tracked — `void` evaluates it.
		void this.registrationTick
		return h('div', { class: 'app-tabs' }, [
			this.renderTabList(),
			h(
				'div',
				{ class: ['app-tabs__content', this.contentClass] },
				this.$slots.default?.(),
			),
		])
	},
}
</script>

<style scoped>
.app-tabs__list {
	display: flex;
	gap: 4px;
	border-bottom: 1px solid var(--color-border);
	margin-bottom: 12px;
	overflow-x: auto;
}

.app-tabs__list--justified .app-tabs__tab {
	flex: 1 1 0;
}

.app-tabs__tab {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 6px;
	padding: 8px 14px;
	background: transparent;
	border: none;
	border-bottom: 2px solid transparent;
	border-radius: 0;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	cursor: pointer;
	white-space: nowrap;
}

.app-tabs__tab:hover,
.app-tabs__tab:focus-visible {
	background-color: var(--color-background-hover);
	color: var(--color-main-text);
}

.app-tabs__tab--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
	font-weight: 600;
}
</style>
