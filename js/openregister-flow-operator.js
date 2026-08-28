/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Frontend settings component for the "Run an OpenRegister flow" Nextcloud Flow
 * operation ({@see \OCA\OpenRegister\WorkflowEngine\RunFlowOperation}). Registers
 * the operator with the workflowengine admin UI so an administrator can type the
 * NAME of the flow (declared on the object's schema) to run when the rule fires.
 *
 * Hand-written render-function component (no build step) mirroring the operator
 * pattern used by core NC apps (bookmarks/analytics *-flow.js): the options
 * component receives the operation string as `value` and emits `input` with the
 * new string. Loaded on the Flow settings page via LoadSettingsScriptsListener.
 */
(function () {
	'use strict'

	function register() {
		if (
			!window.OCA
			|| !window.OCA.WorkflowEngine
			|| typeof window.OCA.WorkflowEngine.registerOperator !== 'function'
		) {
			return false
		}

		window.OCA.WorkflowEngine.registerOperator({
			id: 'OCA\\OpenRegister\\WorkflowEngine\\RunFlowOperation',
			// Conduction cobalt.
			color: '#21468B',
			operation: '',
			options: {
				name: 'OpenRegisterRunFlowOperation',
				props: {
					value: {
						type: String,
						default: '',
					},
				},
				render: function (createElement) {
					var self = this
					return createElement('input', {
						class: 'openregister-run-flow-operation',
						attrs: {
							type: 'text',
							placeholder: t('openregister', 'Name of the OpenRegister flow to run'),
							'aria-label': t('openregister', 'OpenRegister flow name'),
						},
						domProps: {
							value: this.value,
						},
						on: {
							input: function (event) {
								self.$emit('input', event.target.value)
							},
						},
						style: {
							width: '100%',
						},
					})
				},
			},
		})

		return true
	}

	// The workflowengine bundle may register the global after this script runs;
	// try immediately, then retry briefly until registerOperator is available.
	if (!register()) {
		var attempts = 0
		var timer = setInterval(function () {
			attempts += 1
			if (register() || attempts > 50) {
				clearInterval(timer)
			}
		}, 100)
	}
}())
