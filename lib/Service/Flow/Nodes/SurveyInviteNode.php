<?php

/**
 * The "Invite to a survey" flow step: one invitation per item, after an optional delay.
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
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow\Nodes;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Service\Flow\FlowItems;
use OCA\OpenRegister\Service\Flow\FlowNodeCall;
use OCA\OpenRegister\Service\Flow\FlowNodeResumeState;
use OCA\OpenRegister\Service\Flow\FlowSuspension;
use OCA\OpenRegister\Service\Flow\FlowValueTemplate;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigForm;
use OCA\OpenRegister\Service\Flow\IFlowNodeConfigKeys;
use OCA\OpenRegister\Service\Flow\IFlowNodeTaxonomy;
use OCA\OpenRegister\Service\Survey\SurveyInvitationRequest;
use OCA\OpenRegister\Service\Survey\SurveyInvitations;
use OCP\IL10N;
use OCP\IURLGenerator;
use UnexpectedValueException;

/**
 * Fires a survey invitation from a flow, which is how the rules engine fires one on an event.
 *
 * No scheduler of its own: a delay suspends the run exactly as the Wait step
 * does, and the engine resumes it. On resume (or without a delay) every item
 * gets one invitation, sent or recorded blocked by the fatigue limit, and the
 * outcome is put on the item under `surveyInvitation` (with the answering `url`
 * for a sent one), so a following "Send email" step can mail the link.
 *
 * The invitation link is minted for the run's owner (`context.triggeredBy`); a
 * run without one mints nothing, and the item says why.
 *
 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
 */
class SurveyInviteNode extends LogicNodeBase implements SurveyInviteNodeInterface, IFlowNodeConfigKeys, IFlowNodeConfigForm, IFlowNodeTaxonomy {

	/**
	 * The step's type id.
	 */
	public const TYPE = 'openregister.survey-invite';

	/**
	 * The item key the outcome is put under.
	 */
	public const OUTPUT_KEY = 'surveyInvitation';

	/**
	 * How long an invitation can be answered when the step does not say.
	 */
	public const DEFAULT_EXPIRES_IN = '30 days';

	/**
	 * The config keys that are required.
	 */
	private const REQUIRED = ['register', 'survey', 'respondent'];

	/**
	 * Constructor.
	 *
	 * @param IL10N             $l10n        Translates the step's labels.
	 * @param IURLGenerator     $urls        Builds the icon and the answering url.
	 * @param SurveyInvitations $invitations Sends one invitation.
	 */
	public function __construct(
		IL10N $l10n,
		IURLGenerator $urls,
		private readonly SurveyInvitations $invitations,
	) {
		parent::__construct(l10n: $l10n, urls: $urls);
	}//end __construct()

	/**
	 * The step's type id.
	 *
	 * @return string The id.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function getId(): string {
		return self::TYPE;
	}//end getId()

	/**
	 * The step's name in the palette.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function getDisplayName(): string {
		return $this->l10n->t('Invite to a survey');
	}//end getDisplayName()

	/**
	 * What the step does.
	 *
	 * @return string The description.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function getDescription(): string {
		return $this->l10n->t(
			'Invite someone to answer a survey about the object, optionally after a delay. '
			.'A person asked too often is recorded as blocked instead.'
		);
	}//end getDescription()

	/**
	 * The step's icon.
	 *
	 * @return string The icon url.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function getIcon(): string {
		return $this->urls->imagePath('core', 'actions/mail.svg');
	}//end getIcon()

	/**
	 * The config keys the step reads.
	 *
	 * @return array<int, string> The keys.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function configKeys(): array {
		return ['register', 'survey', 'respondent', 'subject', 'expiresIn', 'delay'];
	}//end configKeys()

	/**
	 * The step's settings form.
	 *
	 * @return array<int, array<string, string>> The fields.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function configForm(): array {
		return [
			[
				'key' => 'register',
				'label' => $this->l10n->t('Survey register'),
				'type' => 'text',
				'help' => $this->l10n->t('The register the survey lives in, by id or slug.'),
			],
			[
				'key' => 'survey',
				'label' => $this->l10n->t('Survey'),
				'type' => 'text',
				'help' => $this->l10n->t('The uuid of the survey to invite to.'),
			],
			[
				'key' => 'respondent',
				'label' => $this->l10n->t('Respondent'),
				'type' => 'text',
				'help' => $this->l10n->t('The address to invite, like "{{ email }}".'),
			],
			[
				'key' => 'subject',
				'label' => $this->l10n->t('About'),
				'type' => 'text',
				'help' => $this->l10n->t('The object the survey asks about; the item\'s own object when empty.'),
			],
			[
				'key' => 'expiresIn',
				'label' => $this->l10n->t('Answerable for'),
				'type' => 'text',
				'help' => $this->l10n->t('A duration like "30 days"; 30 days when empty.'),
			],
			[
				'key' => 'delay',
				'label' => $this->l10n->t('Send after'),
				'type' => 'text',
				'help' => $this->l10n->t('A duration like "3 days" to wait before inviting; at once when empty.'),
			],
		];
	}//end configForm()

	/**
	 * Refuse a step without a register, survey or respondent, or with a duration that does not read.
	 *
	 * @param array<string, mixed> $config The step config.
	 *
	 * @return void
	 *
	 * @throws UnexpectedValueException When the config cannot work.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function validateConfig(array $config): void {
		foreach (self::REQUIRED as $key) {
			if (trim((string)($config[$key] ?? '')) === '') {
				throw new UnexpectedValueException($this->l10n->t('A survey invitation needs a "%s".', [$key]));
			}
		}

		foreach (['expiresIn', 'delay'] as $key) {
			$duration = trim((string)($config[$key] ?? ''));
			if ($duration !== '' && str_contains($duration, '{{') === false && self::after(duration: $duration) === null) {
				throw new UnexpectedValueException($this->l10n->t('"%s" is not a duration this flow can read.', [$duration]));
			}
		}
	}//end validateConfig()

	/**
	 * Wait out the delay, then send one invitation per item and put the outcome on the item.
	 *
	 * @param FlowNodeCall $call The items, config and run context.
	 *
	 * @return array<int, array<string, mixed>> The items, each with its outcome.
	 *
	 * @throws FlowSuspension While the delay runs.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function execute(FlowNodeCall $call): array {
		if ($call->items === []) {
			return [];
		}

		$this->waitOutTheDelay(config: $call->config, context: $call->context);

		$out = [];
		foreach ($call->items as $item) {
			$out[] = $this->withOutcome(item: (array)$item, config: $call->config, owner: (string)($call->context['triggeredBy'] ?? ''));
		}

		return $out;
	}//end execute()

	/**
	 * The step's BPMN kind.
	 *
	 * @return string A send task.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function getKind(): string {
		return IFlowNodeTaxonomy::KIND_SEND_TASK;
	}//end getKind()

	/**
	 * The palette category.
	 *
	 * @return string Messaging.
	 *
	 * @spec openspec/changes/survey-object/specs/survey-object/spec.md#requirement-req-surv-004-an-invitation-is-fired-by-a-rule-with-a-delay-and-a-fatigue-limit
	 */
	public function getCategory(): string {
		return IFlowNodeTaxonomy::CATEGORY_MESSAGING;
	}//end getCategory()

	/**
	 * Suspend the run until the delay is over, unless this call is the resume.
	 *
	 * @param array<string, mixed> $config  The step config.
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return void
	 *
	 * @throws FlowSuspension While the delay runs.
	 */
	private function waitOutTheDelay(array $config, array $context): void {
		$delay = trim((string)($config['delay'] ?? ''));
		if ($delay === '' || self::isResuming(context: $context) === true) {
			return;
		}

		$resumeAt = self::after(duration: $delay);
		if ($resumeAt === null) {
			// A delay that does not read is not a reason to never ask: the
			// config gate refused it already, so this is a template gone wrong.
			return;
		}

		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === true) {
			$resume->set(key: 'waitingUntil', value: $resumeAt->format('c'));
		}

		throw new FlowSuspension(resumeAt: $resumeAt, reason: sprintf('inviting to the survey at %s', $resumeAt->format('c')));
	}//end waitOutTheDelay()

	/**
	 * One item with its invitation outcome.
	 *
	 * @param array<string, mixed> $item   The item.
	 * @param array<string, mixed> $config The step config.
	 * @param string               $owner  The run's owner.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function withOutcome(array $item, array $config, string $owner): array {
		$wrapped = is_array($item[FlowItems::JSON] ?? null);
		$json = $item;
		if ($wrapped === true) {
			$json = $item[FlowItems::JSON];
		}

		$json[self::OUTPUT_KEY] = $this->invite(json: $json, config: $config, owner: $owner);
		if ($wrapped === true) {
			$item[FlowItems::JSON] = $json;
			return $item;
		}

		return $json;
	}//end withOutcome()

	/**
	 * Send one invitation for an item's record.
	 *
	 * @param array<string, mixed> $json   The item's record.
	 * @param array<string, mixed> $config The step config.
	 * @param string               $owner  The run's owner.
	 *
	 * @return array<string, mixed> The outcome: id, state, blocked, reason and url, or error.
	 */
	private function invite(array $json, array $config, string $owner): array {
		$value = static fn (string $key): string => trim((string)FlowValueTemplate::render(value: (string)($config[$key] ?? ''), json: $json));
		$subject = $value('subject');
		if ($subject === '') {
			$subject = (string)($json['id'] ?? $json['uuid'] ?? ($json['@self']['id'] ?? ''));
		}

		$expiresIn = $value('expiresIn');
		if ($expiresIn === '') {
			$expiresIn = self::DEFAULT_EXPIRES_IN;
		}

		$expiry = self::after(duration: $expiresIn);

		try {
			$sent = $this->invitations->invite(
				request: new SurveyInvitationRequest(
					register: $value('register'),
					survey: $value('survey'),
					subjectObject: $subject,
					respondent: $value('respondent'),
					expiresAt: ($expiry?->format('c') ?? ''),
					minter: $owner
				)
			);
		} catch (InvalidArgumentException $refused) {
			return ['error' => $refused->getMessage()];
		}

		$outcome = [
			'id' => (string)($sent['invitation']['id'] ?? ''),
			'state' => (string)($sent['invitation']['state'] ?? ''),
			'blocked' => $sent['blocked'],
			'reason' => $sent['reason'],
		];
		if ($sent['anchor'] !== null && $sent['anchor'] !== '') {
			$outcome['url'] = $this->urls->linkToRouteAbsolute('openregister.surveyAnswer.show', ['anchor' => $sent['anchor']]);
		}

		return $outcome;
	}//end invite()

	/**
	 * Whether this call resumes a suspended run.
	 *
	 * @param array<string, mixed> $context The run context.
	 *
	 * @return bool True on the resume.
	 */
	private static function isResuming(array $context): bool {
		$resume = ($context[FlowNodeResumeState::CONTEXT_KEY] ?? null);
		if ($resume instanceof FlowNodeResumeState === true) {
			return $resume->isResuming();
		}

		return (($context['resuming'] ?? false) === true);
	}//end isResuming()

	/**
	 * The moment a duration after now, or null when it does not read.
	 *
	 * @param string $duration A duration like "3 days"; a bare number is seconds.
	 *
	 * @return DateTime|null The moment.
	 */
	private static function after(string $duration): ?DateTime {
		$duration = trim($duration);
		if (ctype_digit($duration) === true) {
			return (new DateTime())->setTimestamp(time() + (int)$duration);
		}

		$timestamp = strtotime('+'.ltrim($duration, '+'));
		if ($timestamp === false || $timestamp <= time()) {
			return null;
		}

		return (new DateTime())->setTimestamp($timestamp);
	}//end after()
}//end class
