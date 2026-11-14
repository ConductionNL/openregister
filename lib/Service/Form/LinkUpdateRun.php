<?php

/**
 * An update run started from an `edit` link.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Form;

use OCA\OpenRegister\Db\AccessLink;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Exception\FormSubmitRefusedException;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use OCA\OpenRegister\Service\Sharing\LinkJourneyGuard;
use OCP\IL10N;

/**
 * Change one existing object through a journey whose mode is `update`, opened by an `edit` link.
 *
 * The run is prefilled with the object's values for the fields the journey
 * declares, and a submit changes those fields only, through the ordinary
 * object save. A field the journey does not declare is neither shown nor
 * changed: an answer for one refuses the submit. The link is the authority,
 * so the save runs with the group rules off, as a link's comment and upload do.
 *
 * @spec openspec/changes/or-form-and-journey-registry/specs/form-and-journey-registry/spec.md#requirement-a-form-link-may-open-an-existing-record-for-change-req-fjr-008
 */
class LinkUpdateRun {

	/**
	 * Wire the run.
	 *
	 * @param ObjectOperationsInterface $objects Saves the object.
	 * @param LinkJourneyGuard          $guard   Decides whether the link's journey still fits its object.
	 * @param IL10N                     $l10n    Translates refusals.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objects,
		private readonly LinkJourneyGuard $guard,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Start an update run through an `edit` link: the first step, prefilled with the object's values.
	 *
	 * Only the fields the journey declares are read back, so a field it does
	 * not declare is never shown. A link that does not declare `edit`, names
	 * another journey or a journey that no longer fits the object, and an
	 * unpublished journey all answer the same 404.
	 *
	 * @param AccessLink   $link   The admitted link.
	 * @param ObjectEntity $object The link's subject object.
	 *
	 * @return array<string, mixed> The run: journey, object, step, steps, fields, declared and answers.
	 *
	 * @throws FormSubmitRefusedException 404 when the link cannot start an update run.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-2
	 */
	public function start(AccessLink $link, ObjectEntity $object): array {
		$journey  = $this->linkJourney(link: $link, object: $object);
		$first    = $journey->stepIds()[0];
		$declared = $this->declaredFields(journey: $journey);

		return [
			'journey' => $journey->id,
			'object' => (string)$object->getUuid(),
			'step' => $first,
			'steps' => $journey->stepIds(),
			'fields' => $journey->fieldsOf(stepId: $first),
			'declared' => $declared,
			'answers' => $this->declaredAnswers(journey: $journey, data: $object->getObject()),
		];
	}//end start()

	/**
	 * Submit an update run through an `edit` link: change the declared fields of the link's object.
	 *
	 * Every answer is checked against the fields the journey declares before
	 * anything is written; one undeclared field refuses the whole submit, so
	 * the object keeps every value. The change goes through the ordinary
	 * object save, with its validation and audit, as an update of that object.
	 *
	 * @param AccessLink           $link    The admitted link.
	 * @param ObjectEntity         $object  The link's subject object.
	 * @param array<string, mixed> $answers The answers, keyed by field name.
	 *
	 * @return array<string, mixed> The outcome: journey, object, changed, answers and the saved object.
	 *
	 * @throws FormSubmitRefusedException 404 as start(); 422 with one finding per undeclared field.
	 *
	 * @spec openspec/changes/or-form-and-journey-registry/tasks.md#task-6-2
	 */
	public function submit(AccessLink $link, ObjectEntity $object, array $answers): array {
		$journey  = $this->linkJourney(link: $link, object: $object);
		$declared = $this->declaredFields(journey: $journey);
		$this->assertDeclared(declared: $declared, answers: $answers);

		$changes = [];
		foreach ($this->fieldProperties(journey: $journey) as $field => $property) {
			if (array_key_exists($field, $answers) === true) {
				$changes[$property] = $answers[$field];
			}
		}

		$saved = $this->objects->saveObject(
			object: array_merge($object->getObject(), $changes),
			options: new ObjectSaveOptions(
				target: new ObjectFindTarget(register: $object->getRegister(), schema: $object->getSchema()),
				uuid: (string)$object->getUuid()
			),
			_rbac: false,
			_multitenancy: false
		);

		return [
			'journey' => $journey->id,
			'object' => (string)$object->getUuid(),
			'changed' => array_keys($changes),
			'answers' => $this->declaredAnswers(journey: $journey, data: $saved->getObject()),
			'saved' => $saved,
		];
	}//end submit()

	/**
	 * The journey an admitted `edit` link may run on its object; the one 404 otherwise.
	 *
	 * @param AccessLink   $link   The link.
	 * @param ObjectEntity $object The link's subject object.
	 *
	 * @return JourneyDefinition The journey.
	 *
	 * @throws FormSubmitRefusedException 404.
	 */
	private function linkJourney(AccessLink $link, ObjectEntity $object): JourneyDefinition {
		$journeyId = $link->journeyId();
		$journey   = null;
		if ($journeyId !== null
			&& $link->allows(capability: AccessLink::CAP_EDIT) === true
			&& $link->getSubjectType() === AccessLink::SUBJECT_OBJECT
			&& $link->getSubjectId() === $object->getUuid()
		) {
			$journey = $this->guard->journeyFor(object: $object, journeyId: $journeyId);
		}

		if ($journey === null || $journey->isPublished() === false || $journey->stepIds() === []) {
			throw new FormSubmitRefusedException(message: $this->l10n->t('This journey does not exist.'), status: 404);
		}

		return $journey;
	}//end linkJourney()

	/**
	 * Every field name the journey declares, over all its steps.
	 *
	 * @param JourneyDefinition $journey The journey.
	 *
	 * @return list<string> The field names.
	 */
	private function declaredFields(JourneyDefinition $journey): array {
		$ids = $journey->stepIds();

		return $journey->fieldsInScope(stepId: $ids[count($ids) - 1]);
	}//end declaredFields()

	/**
	 * Field name to the object property it changes, from every write's mapping; a field without one is its own property.
	 *
	 * @param JourneyDefinition $journey The journey.
	 *
	 * @return array<string, string> The properties, keyed by field.
	 */
	private function fieldProperties(JourneyDefinition $journey): array {
		$properties = [];
		foreach ($this->declaredFields(journey: $journey) as $field) {
			$properties[$field] = $field;
		}

		foreach ($journey->stepIds() as $stepId) {
			foreach ($journey->writesOf(stepId: $stepId) as $write) {
				foreach (array_filter((array)(($write['mapping'] ?? [])['fields'] ?? []), 'is_array') as $mapped) {
					$name     = (string)($mapped['field'] ?? '');
					$property = (string)($mapped['property'] ?? '');
					if ($name !== '' && $property !== '' && isset($properties[$name]) === true) {
						$properties[$name] = $property;
					}
				}
			}
		}

		return $properties;
	}//end fieldProperties()

	/**
	 * The object's values for the declared fields, read through the field-to-property map.
	 *
	 * @param JourneyDefinition    $journey The journey.
	 * @param array<string, mixed> $data    The object's data.
	 *
	 * @return array<string, mixed> The answers, keyed by field; a property the object lacks is left out.
	 */
	private function declaredAnswers(JourneyDefinition $journey, array $data): array {
		$answers = [];
		foreach ($this->fieldProperties(journey: $journey) as $field => $property) {
			if (array_key_exists($property, $data) === true) {
				$answers[$field] = $data[$property];
			}
		}

		return $answers;
	}//end declaredAnswers()

	/**
	 * Refuse every answer for a field the journey does not declare.
	 *
	 * @param list<string>         $declared The declared fields.
	 * @param array<string, mixed> $answers  The answers.
	 *
	 * @return void
	 *
	 * @throws FormSubmitRefusedException 422 with one finding per undeclared field.
	 */
	private function assertDeclared(array $declared, array $answers): void {
		$findings = [];
		foreach (array_keys($answers) as $field) {
			if (in_array((string)$field, $declared, true) === false) {
				$findings[] = [
					'field' => (string)$field,
					'code' => 'out-of-journey',
					'message' => $this->l10n->t('The field %1$s is not part of this journey.', [(string)$field]),
				];
			}
		}

		if ($findings !== []) {
			throw new FormSubmitRefusedException(
				message: $this->l10n->t('Some answers are not part of this journey.'),
				status: 422,
				findings: $findings
			);
		}
	}//end assertDeclared()
}//end class
