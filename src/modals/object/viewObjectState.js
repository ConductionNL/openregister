/**
 * The initial state of the ViewObject modal, grouped by what each part of the
 * modal keeps. Every call returns fresh objects so no two modals share state.
 *
 * @spec exclude Client-side initial state of the object modal.
 * @license EUPL-1.2
 * @copyright 2026 Conduction B.V.
 */

/**
 * The dialog's titles and its save/copy outcome.
 *
 * @return {object} The dialog state
 */
export function dialogState() {
	return {
		closeModalTimeout: null,
		activeAttachment: null,
		registerTitle: '',
		schemaTitle: '',
		isUpdated: false,
		isCopied: false,
		error: null,
		success: null,
	}
}

/**
 * The form and JSON editors and their synchronisation flags.
 *
 * @return {object} The editor state
 */
export function editorState() {
	return {
		formData: {},
		jsonData: '',
		activeTab: 0,
		isInternalUpdate: false, // Flag to prevent infinite loops during synchronization
		// Vue 3 initialises `instance.update` to null and only assigns the render
		// effect in setupRenderEffect, i.e. AFTER the `immediate: true` watchers
		// below have already run. Calling $forceUpdate() in that window makes Vue
		// queue a null job and throw `Cannot read properties of null (reading
		// 'flags')`. Vue 2's $forceUpdate() guarded on `vm._watcher &&` so the same
		// call was a silent no-op there. Gate the call on this flag instead.
		isMounted: false,
		objectEditors: {},
	}
}

/**
 * The modal's tabs, in order.
 *
 * @return {Array<string>} The tab names
 */
export function viewObjectTabs() {
	return [
		'Properties',
		'Metadata',
		'Data',
		'Uses',
		'Used by',
		'Contracts',
		'Files',
	]
}

/**
 * The files tab's selection and label editing.
 *
 * @return {object} The attachment state
 */
export function attachmentState() {
	return {
		selectedAttachments: [],
		fileIdsLoading: [],
		editingLabelsFileId: null,
		editingLabels: [],
		availableLabels: [],
		labelsLoading: false,
	}
}

/**
 * The register and schema chosen for a new object when several are possible.
 *
 * @return {object} The unconfirmed selection
 */
export function newObjectSelection() {
	return {
		selectedRegisterForNewObject: null,
		selectedSchemaForNewObject: null,
		registerSchemaSelectionConfirmed: false,
	}
}

/**
 * The files tab's first page.
 *
 * @return {object} The paging state
 */
export function filesPage() {
	return {
		filesCurrentPage: 1,
		filesPerPage: 10,
	}
}

/**
 * The property being edited and its pending debounced saves.
 *
 * @return {object} The property save state
 */
export function propertySaveState() {
	return {
		selectedProperty: null,
		isSaving: false,
		propertyChangeDebounceTimers: {}, // Track debounce timers per property
		pendingNotifications: {}, // Store pending notification info per property
	}
}
