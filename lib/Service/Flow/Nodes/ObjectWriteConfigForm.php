<?php

/**
 * The fields an object-write step is edited through.
 *
 * `fields` and `match` are deliberately absent: they are structured values whose
 * shape depends on the schema being written, and the editor's JSON pane
 * represents them honestly where a flat text field would not.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow\Nodes
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-object-write-bulk/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use OCP\IL10N;

/**
 * Describes the editor form of an object-write step.
 */
final class ObjectWriteConfigForm {

	/**
	 * Constructor.
	 *
	 * @param IL10N $l10n Translations.
	 */
	public function __construct(private readonly IL10N $l10n) {
	}//end __construct()

	/**
	 * The field descriptions, in editor order.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 *
	 * @spec openspec/specs/flow-object-write-bulk/spec.md
	 */
	public function fields(): array {
		return array_merge(
			$this->registerFields(),
			$this->operationFields(),
			$this->payloadFromFields(),
			$this->bulkFields(),
			$this->skipWhenFields(),
			$this->replaceFields(),
			$this->outputFields(),
			$this->subjectRoleFields(),
			$this->onMissingFields(),
			$this->onNoMatchFields(),
			$this->permanentFields()
		);
	}//end fields()

	/**
	 * The form fields for `register`, `schema`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function registerFields(): array {
		return [
			[
				'key' => 'register', 'label' => $this->l10n->t('Register'), 'type' => 'select',
				'help' => $this->l10n->t('The register the objects are written into.'),
				'required' => true, 'optionsFrom' => '/apps/openregister/api/registers',
			],
			[
				'key' => 'schema', 'label' => $this->l10n->t('Schema'), 'type' => 'select',
				'help' => $this->l10n->t('The schema the objects are validated against. A slug is resolved within the chosen register only.'),
				'required' => true, 'optionsFrom' => '/apps/openregister/api/schemas',
			],
		];
	}//end registerFields()

	/**
	 * The form fields for `operation`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function operationFields(): array {
		return [
			[
				'key' => 'operation', 'label' => $this->l10n->t('Operation'), 'type' => 'text',
				'help' => $this->l10n->t('One of: create, update, upsert, delete. Update, upsert and delete need at least one match pair.'),
				'required' => true,
			],
		];
	}//end operationFields()

	/**
	 * The form fields for `payloadFrom`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function payloadFromFields(): array {
		return [
			[
				'key' => 'payloadFrom', 'label' => $this->l10n->t('Write object at path'), 'type' => 'text',
				'help' => $this->l10n->t(
					'Write the object found at this path WHOLE, instead of listing properties in "fields". Use it when the properties are '
					. 'not known up front — a synchronization with no mapping, for example. Configure this or "fields", never both.'
				),
				'required' => false,
			],
		];
	}//end payloadFromFields()

	/**
	 * The form fields for `bulk`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function bulkFields(): array {
		return [
			[
				'key' => 'bulk', 'label' => $this->l10n->t('Write the whole page in one call'), 'type' => 'boolean',
				'help' => $this->l10n->t(
					'Create and upsert only. One bulk save covers every item — much faster for large pages, but per-object lifecycle '
					. 'events are not dispatched, and a bulk upsert must match on "uuid" alone with "replace" on.'
				),
			],
		];
	}//end bulkFields()

	/**
	 * The form fields for `skipWhen`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function skipWhenFields(): array {
		return [
			[
				'key' => 'skipWhen', 'label' => $this->l10n->t('Skip an item when this field says so'), 'type' => 'text',
				'help' => $this->l10n->t(
					'A field on the item. When it holds true, or the word "skip", that item passes through UNWRITTEN and still travels on '
					. 'to later steps. Use it to leave unchanged objects alone. Filtering those items out instead would remove them from '
					. 'what a synchronization sweep counts as reached, and the sweep would then delete them.'
				),
			],
		];
	}//end skipWhenFields()

	/**
	 * The form fields for `replace`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function replaceFields(): array {
		return [
			[
				'key' => 'replace', 'label' => $this->l10n->t('Replace instead of patch'), 'type' => 'boolean',
				'help' => $this->l10n->t(
					'An update normally patches only the fields named. Replacing writes the payload as the whole object — fields it does '
					. 'not name are gone.'
				),
			],
		];
	}//end replaceFields()

	/**
	 * The form fields for `output`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function outputFields(): array {
		return [
			[
				'key' => 'output', 'label' => $this->l10n->t('Field to store the written object in'), 'type' => 'text',
				'help' => $this->l10n->t(
					'With a field name, the incoming item is preserved and the written object is added under it. Empty means the written '
					. 'object replaces the item.'
				),
			],
		];
	}//end outputFields()

	/**
	 * The form fields for `subjectRole`, `maxWrites`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function subjectRoleFields(): array {
		return [
			[
				'key' => 'subjectRole', 'label' => $this->l10n->t('Record the written object as'), 'type' => 'text',
				'help' => $this->l10n->t(
					'Your own word for what this object is to this flow, such as "case" or "besluit". A later step can then attach its '
					. 'task to it by that name. Leave it empty and the step records nothing.'
				),
			],
			[
				'key' => 'maxWrites', 'label' => $this->l10n->t('Write cap'), 'type' => 'number',
				'help' => $this->l10n->t('The most writes one step execution may perform. The instance default applies when empty.'),
			],
		];
	}//end subjectRoleFields()

	/**
	 * The form fields for `onMissing`, `onConflict`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function onMissingFields(): array {
		return [
			[
				'key' => 'onMissing', 'label' => $this->l10n->t('When a field value is missing'), 'type' => 'text',
				'help' => $this->l10n->t('"omit" (default) drops the field from the payload; "fail" fails the item.'),
			],
			[
				'key' => 'onConflict', 'label' => $this->l10n->t('When a created identifier is taken'), 'type' => 'text',
				'help' => $this->l10n->t(
					'"overwrite" (default) keeps the historical upsert behaviour; "fail" refuses, for writes that are claims — locks, '
					. 'slots, leases. Not available in bulk.'
				),
			],
		];
	}//end onMissingFields()

	/**
	 * The form fields for `onNoMatch`, `confirmDelete`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function onNoMatchFields(): array {
		return [
			[
				'key' => 'onNoMatch', 'label' => $this->l10n->t('When a delete matches nothing'), 'type' => 'text',
				'help' => $this->l10n->t('"error" (default) fails the item; "skip" passes it through with "deleted": false.'),
			],
			[
				'key' => 'confirmDelete', 'label' => $this->l10n->t('Confirm deletion'), 'type' => 'boolean',
				'help' => $this->l10n->t('A delete step will not save without this deliberate second acknowledgement.'),
			],
		];
	}//end onNoMatchFields()

	/**
	 * The form fields for `permanent`.
	 *
	 * @return array<int, array<string, mixed>> The field descriptions.
	 */
	private function permanentFields(): array {
		return [
			[
				'key' => 'permanent', 'label' => $this->l10n->t('Destroy instead of tombstone'), 'type' => 'boolean',
				'help' => $this->l10n->t(
					'Deletes are soft by default, which is what makes a mistaken flow recoverable. Turn this on only for rows that are '
					. 'claims whose identifier must become claimable again.'
				),
			],
		];
	}//end permanentFields()
}//end class
