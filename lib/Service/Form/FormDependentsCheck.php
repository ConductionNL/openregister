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
class FormDependentsCheck implements FormDependentsCheckInterface {

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
	 * The schemas whose save a caller is judging itself, by id, for this request.
	 *
	 * @var array<int, true>
	 */
	private array $claimed = [];

	/**
	 * Asks the owning apps for their forms.
	 *
	 * @var IEventDispatcher
	 */
	private readonly IEventDispatcher $dispatcher;

	/**
	 * Tells an author their form was unpublished.
	 *
	 * @var IManager
	 */
	private readonly IManager $notifications;

	/**
	 * Constructor.
	 *
	 * @param FormDependentsSignals    $signals   The event and notification channels the form dependents check reports through.
	 * @param FormDestinationValidator $validator Judges each form.
	 * @param IAppConfig               $appConfig Reads each owning app's break setting.
	 * @param LoggerInterface          $logger    Records an unpublish that failed.
	 */
	public function __construct(
		FormDependentsSignals $signals,
		private readonly FormDestinationValidator $validator,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
		$this->dispatcher = $signals->dispatcher;
		$this->notifications = $signals->notifications;
	}//end __construct()

	/**
	 * Say that the caller of this save judges the dependent forms itself.
	 *
	 * The schema API assesses BEFORE the write, so it can refuse the save, and
	 * applies after it. Every other writer of a schema (the configuration
	 * importer, the import endpoint, the AI and MCP tools) has no response to
	 * refuse, and is covered by {@see \OCA\OpenRegister\Listener\FormDestinationSchemaListener}, which
	 * skips a save its caller has claimed so no form is unpublished or
	 * announced twice.
	 *
	 * @param int $schemaId The schema about to be saved.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function claim(int $schemaId): void {
		$this->claimed[$schemaId] = true;
	}//end claim()

	/**
	 * Whether the caller of this schema's save judges the dependent forms itself.
	 *
	 * @param int $schemaId The schema.
	 *
	 * @return bool True when it was claimed in this request.
	 *
	 * @spec openspec/changes/form-destination-validator/specs/form-destination/spec.md#requirement-saving-a-schema-must-re-check-the-forms-that-submit-into-it
	 */
	public function isClaimed(int $schemaId): bool {
		return isset($this->claimed[$schemaId]);
	}//end isClaimed()

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
		foreach ($event->getForms() as $form) {
			$entry = $this->judged(form: $form, schema: $schema);
			if ($entry !== null) {
				$affected[] = $entry;
			}
		}

		$refused = in_array('refuse', array_column($affected, 'break'), true);

		return ['refused' => $refused, 'schema' => (string)$schema->getSlug(), 'affected' => $affected];
	}//end assess()

	/**
	 * One form judged against the proposed schema: its affected entry, or null when the schema breaks nothing of it.
	 *
	 * @param FormDependent $form   The form.
	 * @param Schema        $schema The proposed schema definition.
	 *
	 * @return array<string, mixed>|null The entry: id, app, title, break, findings, form.
	 */
	private function judged(FormDependent $form, Schema $schema): ?array {
		$findings = $this->validator->validate(mapping: $form->mapping, destination: new FormDestination(schema: $schema, audience: $form->audience));
		if ($findings === []) {
			return null;
		}

		$break = $this->breakOf(app: $form->app);

		return ['id' => $form->id, 'app' => $form->app, 'title' => $form->title, 'break' => $break, 'findings' => $findings, 'form' => $form];
	}//end judged()

	/**
	 * What the owning app wants when a schema change breaks its form: `refuse`, else `unpublish`.
	 *
	 * @param string $app The owning app's id.
	 *
	 * @return string `refuse` or `unpublish`.
	 */
	private function breakOf(string $app): string {
		$break = $this->appConfig->getValueString($app, self::BREAK_SETTING, 'unpublish');
		if ($break !== 'refuse') {
			return 'unpublish';
		}

		return $break;
	}//end breakOf()

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
				$outcome = $this->unpublish(run: new FormUnpublishRun(form: $entry['form'], schema: $assessment['schema'], findings: $entry['findings']));
			}

			$listed[] = ['id' => $entry['id'], 'app' => $entry['app'], 'title' => $entry['title'], 'outcome' => $outcome, 'findings' => $entry['findings']];
		}

		return $listed;
	}//end apply()

	/**
	 * Unpublish one form through its owning app, and notify its author.
	 *
	 * @param FormUnpublishRun $run The form, the schema slug and why.
	 *
	 * @return string `unpublished`, `unpublish-failed` when the owning app could not, or
	 *                `unpublish-unavailable` when it registered the form without a way to unpublish it.
	 */
	private function unpublish(FormUnpublishRun $run): string {
		if ($run->form->unpublish === null) {
			return $this->unavailable(run: $run);
		}

		try {
			($run->form->unpublish)();
		} catch (Throwable $exception) {
			return $this->failed(run: $run, exception: $exception);
		}

		$this->notifyAuthor(run: $run);

		return 'unpublished';
	}//end unpublish()

	/**
	 * Record that a broken form registered no way to be unpublished; it stays published.
	 *
	 * Nothing was unpublished: say so, and do not tell the author it was.
	 *
	 * @param FormUnpublishRun $run The form and the schema slug.
	 *
	 * @return string `unpublish-unavailable`.
	 */
	private function unavailable(FormUnpublishRun $run): string {
		$this->logger->warning(
			message: '[FormDependentsCheck] A form broken by a schema change has no way to be unpublished; it stays published',
			context: ['form' => $run->form->id, 'app' => $run->form->app, 'schema' => $run->schema]
		);

		return 'unpublish-unavailable';
	}//end unavailable()

	/**
	 * Record that the owning app could not unpublish a broken form.
	 *
	 * @param FormUnpublishRun $run       The form and the schema slug.
	 * @param Throwable        $exception What the owning app threw.
	 *
	 * @return string `unpublish-failed`.
	 */
	private function failed(FormUnpublishRun $run, Throwable $exception): string {
		$this->logger->error(
			message: '[FormDependentsCheck] An owning app could not unpublish a form broken by a schema change',
			context: ['form' => $run->form->id, 'app' => $run->form->app, 'schema' => $run->schema, 'exception' => $exception->getMessage()]
		);

		return 'unpublish-failed';
	}//end failed()

	/**
	 * Tell the form's author, when known, that a schema change unpublished it.
	 *
	 * @param FormUnpublishRun $run The form, the schema slug and why.
	 *
	 * @return void
	 */
	private function notifyAuthor(FormUnpublishRun $run): void {
		$form = $run->form;
		if ($form->author === null || $form->author === '') {
			return;
		}

		$notification = $this->notifications->createNotification();
		$notification->setApp('openregister')
			->setUser($form->author)
			->setDateTime(new DateTime())
			->setObject('form', $form->id)
			->setSubject(self::SUBJECT, ['formTitle' => $form->title, 'schema' => $run->schema, 'count' => count($run->findings)]);
		$this->notifications->notify($notification);
	}//end notifyAuthor()
}//end class
