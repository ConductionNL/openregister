<?php

/**
 * Asks the owning apps which published forms submit into a schema about to change.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Event
 * @package  OCA\OpenRegister\Event
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\Form\FormDependent;
use OCP\EventDispatcher\Event;

/**
 * Dispatched before a schema save; a listener adds each published form of its app that writes into it.
 *
 * The schema carried is the PROPOSED definition, so the forms are judged
 * against what the schema is about to become.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */
class FormDestinationDependentsEvent extends Event {

	/**
	 * The forms announced so far.
	 *
	 * @var array<int, FormDependent>
	 */
	private array $forms = [];

	/**
	 * Constructor.
	 *
	 * @param Schema $schema The proposed schema definition.
	 */
	public function __construct(private readonly Schema $schema) {
		parent::__construct();
	}//end __construct()

	/**
	 * The proposed schema.
	 *
	 * @return Schema The schema.
	 */
	public function getSchema(): Schema {
		return $this->schema;
	}//end getSchema()

	/**
	 * Announce a published form that submits into this schema.
	 *
	 * @param FormDependent $form The form.
	 *
	 * @return void
	 */
	public function addForm(FormDependent $form): void {
		$this->forms[] = $form;
	}//end addForm()

	/**
	 * Every announced form.
	 *
	 * @return array<int, FormDependent> The forms.
	 */
	public function getForms(): array {
		return $this->forms;
	}//end getForms()
}//end class
