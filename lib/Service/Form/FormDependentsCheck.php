<?php

/**
 * Saving a schema re-checks every published form that submits into it.
 *
 * ADR-117 decision 2: a schema change that makes a published form invalid
 * is reported against that form. Q7 (Ruben, 10 October 2026): the default
 * unpublishes the broken form and tells its author; the owning app may set
 * `formDestinationBreak` to `refuse` to block the schema change instead.
 * Q9: this applies from day one, with no report-only release.
 *
 * Two steps, so nothing is unpublished for a save that never happens:
 * assess() judges the forms against the PROPOSED schema and decides whether
 * the save is refused; apply() unpublishes and notifies after the save.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Form
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

namespace OCA\OpenRegister\Service\Form;

use DateTime;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Event\FormDestinationDependentsEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Notification\IManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Assess the dependent forms of a proposed schema, then apply the outcome.
 *
 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
 */
class FormDependentsCheck {

	/**
	 * The owning app's setting: `unpublish` (default) or `refuse`.
	 *
	 * @var string
	 */
	public const BREAK_SETTING = 'formDestinationBreak';

	/**
	 * The notification subject an author receives.
	 *
	 * @var string
	 */
	public const SUBJECT = 'form_unpublished';

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher         $dispatcher    Asks the owning apps for their forms.
	 * @param FormDestinationValidator $validator     Judges each form.
	 * @param IAppConfig               $appConfig     Reads each owning app's break setting.
	 * @param IManager                 $notifications Tells an author their form was unpublished.
	 * @param LoggerInterface          $logger        Records an unpublish that failed.
	 */
	public function __construct(
		private readonly IEventDispatcher $dispatcher,
		private readonly FormDestinationValidator $validator,
		private readonly IAppConfig $appConfig,
		private readonly IManager $notifications,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Judge every published form that submits into the proposed schema.
	 *
	 * @param Schema $schema The proposed schema definition.
	 *
	 * @return array<string, mixed> The assessment: `refused`, `schema`, and `affected` (id, app, title, break, findings, form).
	 *
	 * @psalm-return array{refused: bool, schema: string, affected: list<array<string, mixed>>}
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function assess(Schema $schema): array {
		$event = new FormDestinationDependentsEvent(schema: $schema);
		$this->dispatcher->dispatchTyped($event);

		$affected = [];
		$refused = false;
		foreach ($event->getForms() as $form) {
			$options = [];
			if ($form->audience !== null) {
				$options['audience'] = $form->audience;
			}

			$findings = $this->validator->validate(mapping: $form->mapping, schema: $schema, options: $options);
			if ($findings === []) {
				continue;
			}

			$break = $this->appConfig->getValueString($form->app, self::BREAK_SETTING, 'unpublish');
			if ($break !== 'refuse') {
				$break = 'unpublish';
			}

			$refused = ($refused || $break === 'refuse');
			$affected[] = ['id' => $form->id, 'app' => $form->app, 'title' => $form->title, 'break' => $break, 'findings' => $findings, 'form' => $form];
		}

		return ['refused' => $refused, 'schema' => (string)$schema->getSlug(), 'affected' => $affected];
	}//end assess()

	/**
	 * After the save: unpublish each broken form and tell its author.
	 *
	 * @param array{refused: bool, schema: string, affected: array<int, array<string, mixed>>} $assessment The assessment of the saved schema.
	 *
	 * @return array<int, array<string, mixed>> Each affected form (id, app, title, outcome, findings), for the save response.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function apply(array $assessment): array {
		$listed = [];
		foreach ($assessment['affected'] as $entry) {
			$outcome = 'refused';
			if ($assessment['refused'] === false) {
				$outcome = $this->unpublish(form: $entry['form'], schema: $assessment['schema'], findings: $entry['findings']);
			}

			$listed[] = ['id' => $entry['id'], 'app' => $entry['app'], 'title' => $entry['title'], 'outcome' => $outcome, 'findings' => $entry['findings']];
		}

		return $listed;
	}//end apply()

	/**
	 * Unpublish one form through its owning app, and notify its author.
	 *
	 * @param FormDependent                    $form     The form.
	 * @param string                           $schema   The schema slug.
	 * @param array<int, array<string, mixed>> $findings Why.
	 *
	 * @return string `unpublished`, or `unpublish-failed` when the owning app could not.
	 */
	private function unpublish(FormDependent $form, string $schema, array $findings): string {
		try {
			if ($form->unpublish !== null) {
				($form->unpublish)();
			}
		} catch (Throwable $exception) {
			$this->logger->error(
				message: '[FormDependentsCheck] An owning app could not unpublish a form broken by a schema change',
				context: ['form' => $form->id, 'app' => $form->app, 'schema' => $schema, 'exception' => $exception->getMessage()]
			);

			return 'unpublish-failed';
		}

		if ($form->author !== null && $form->author !== '') {
			$notification = $this->notifications->createNotification();
			$notification->setApp('openregister')
				->setUser($form->author)
				->setDateTime(new DateTime())
				->setObject('form', $form->id)
				->setSubject(self::SUBJECT, ['formTitle' => $form->title, 'schema' => $schema, 'count' => count($findings)]);
			$this->notifications->notify($notification);
		}

		return 'unpublished';
	}//end unpublish()
}//end class
