<?php

/**
 * Renders a request to act on somebody's behalf.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notification
 * @package  OCA\OpenRegister\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Notification;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Notification\INotification;

/**
 * The consent prompt of a delegation grant.
 *
 * 🔴 EVERY WORD HERE IS SERVER-AUTHORED. The only values interpolated are the
 * two uids, both read from the grant record. The requester's stated reason is
 * deliberately absent, see {@see \OCA\OpenRegister\Service\Delegation\DelegationNotifier}.
 * A requester that could write into this sentence would be authoring the
 * prompt that asks for its own privilege, and a person reading it would have
 * no way to tell which half the system was vouching for.
 *
 * The two ACTIONS are the decision. They are deliberately not "OK" and
 * "Cancel": a consent dialog whose buttons do not name the outcome is one
 * people dismiss, and dismissing is not deciding.
 *
 * @spec openspec/specs/delegation-grants/spec.md
 */
class DelegationConsentRenderer extends SubjectRenderer {

	/**
	 * Constructor.
	 *
	 * @param IURLGenerator $urlGenerator URL generator for notification icons and actions
	 * @param DisplayNames $names Names the requester
	 */
	public function __construct(IURLGenerator $urlGenerator, private readonly DisplayNames $names) {
		parent::__construct(urlGenerator: $urlGenerator);
	}//end __construct()

	/**
	 * Render a request to act on somebody's behalf.
	 *
	 * @param INotification $notification The notification to prepare
	 * @param IL10N $l The localization instance
	 *
	 * @return INotification|null The prepared notification, or null for another subject
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	public function render(INotification $notification, IL10N $l): ?INotification {
		if ($notification->getSubject() !== 'delegation_consent_requested') {
			return null;
		}

		$parameters = $notification->getSubjectParameters();
		$draft = new ConsentPromptDraft(notification: $notification, l: $l);
		$requester = $this->requester(uid: (string)($parameters['principal'] ?? ''));

		$this->ask(draft: $draft, requester: $requester);
		$this->explain(draft: $draft, requester: $requester);
		$this->addDecisions(draft: $draft, grantUuid: (string)($parameters['grantUuid'] ?? ''));

		return $this->withAppIcon(notification: $notification);
	}//end render()

	/**
	 * The requester as a rich object parameter.
	 *
	 * @param string $uid The uid of the requester.
	 *
	 * @return array<string, string> The parameter
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function requester(string $uid): array {
		return ['type' => 'user', 'id' => $uid, 'name' => $this->names->forUid(uid: $uid)];
	}//end requester()

	/**
	 * Write the question: rich first, parsed always, and neither is optional.
	 *
	 * `setRichSubject` with a `user` parameter is what lets a client render the
	 * requester as a real user reference: the display name a colleague recognises,
	 * with an avatar and semantic markup a screen reader can announce as a person
	 * rather than as a bare token. `setParsedSubject` is the plain-text fallback every
	 * other surface reads: the email digest, the OCS API, a client that does not do
	 * rich text. A notification with only one of the two is either unreadable somewhere
	 * or unrenderable somewhere, and NC's own `isValidParsed()` refuses the second case.
	 *
	 * The two say the SAME sentence. A rich subject that read differently from its
	 * fallback would mean two people looking at the same security decision on
	 * different clients were answering different questions.
	 *
	 * @param ConsentPromptDraft $draft The prompt being written
	 * @param array<string, string> $requester The requester as a rich object parameter
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function ask(ConsentPromptDraft $draft, array $requester): void {
		$draft->notification->setRichSubject(
			$draft->l->t('{requester} asks to act on your behalf'),
			['requester' => $requester]
		);
		$draft->notification->setParsedSubject(
			$draft->l->t('%s asks to act on your behalf', [$requester['name']])
		);
	}//end ask()

	/**
	 * Write what allowing means, before the buttons.
	 *
	 * A consent prompt whose consequence is not stated before the controls is one
	 * people answer by reflex, and a reader using a screen reader meets the buttons in
	 * reading order, so the sentence has to carry the whole decision on its own.
	 *
	 * Three facts, in the order a person needs them: what they gain, that it is
	 * recorded, and that it is reversible.
	 *
	 * @param ConsentPromptDraft $draft The prompt being written
	 * @param array<string, string> $requester The requester as a rich object parameter
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function explain(ConsentPromptDraft $draft, array $requester): void {
		$draft->notification->setRichMessage(
			$draft->l->t(
				'If you allow this, {requester} may perform work using your permissions until the grant '
				. 'expires or you withdraw it. Anything they do will be recorded as done on your behalf. '
				. 'You can withdraw the grant at any time.'
			),
			['requester' => $requester]
		);
		$draft->notification->setParsedMessage($this->plainExplanation(draft: $draft, name: $requester['name']));
	}//end explain()

	/**
	 * The same sentence for a surface without rich text.
	 *
	 * @param ConsentPromptDraft $draft The prompt being written
	 * @param string $name The display name of the requester
	 *
	 * @return string The message
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function plainExplanation(ConsentPromptDraft $draft, string $name): string {
		return $draft->l->t(
			'If you allow this, %s may perform work using your permissions until the grant expires '
			. 'or you withdraw it. Anything they do will be recorded as done on your behalf. '
			. 'You can withdraw the grant at any time.',
			[$name]
		);
	}//end plainExplanation()

	/**
	 * Add the two answers.
	 *
	 * The labels NAME THE OUTCOME rather than acknowledging the dialog. "OK"/"Cancel"
	 * on a consent prompt is the shape people dismiss: neither word says what happens,
	 * so the decision is made by muscle memory. Out of context, which is how a screen
	 * reader reaches a button list, "OK" is meaningless and "Allow" is not.
	 *
	 * @param ConsentPromptDraft $draft The prompt being written
	 * @param string $grantUuid The grant the answer is for
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function addDecisions(ConsentPromptDraft $draft, string $grantUuid): void {
		$base = $this->urlGenerator->linkToRouteAbsolute('openregister.delegation.answer', ['uuid' => $grantUuid]);

		$this->addAllow(draft: $draft, base: $base);
		$this->addDeny(draft: $draft, base: $base);
	}//end addDecisions()

	/**
	 * Add the primary answer.
	 *
	 * @param ConsentPromptDraft $draft The prompt being written
	 * @param string $base The route that takes the answer
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function addAllow(ConsentPromptDraft $draft, string $base): void {
		$action = $draft->notification->createAction()
			->setLabel('allow')
			->setParsedLabel($draft->l->t('Allow'))
			->setLink($base . '?allow=1', 'POST')
			->setPrimary(true);
		$draft->notification->addParsedAction($action);
	}//end addAllow()

	/**
	 * Add the other answer.
	 *
	 * @param ConsentPromptDraft $draft The prompt being written
	 * @param string $base The route that takes the answer
	 *
	 * @return void
	 *
	 * @spec openspec/specs/delegation-grants/spec.md
	 */
	private function addDeny(ConsentPromptDraft $draft, string $base): void {
		$action = $draft->notification->createAction()
			->setLabel('deny')
			->setParsedLabel($draft->l->t('Deny'))
			->setLink($base . '?allow=0', 'POST')
			->setPrimary(false);
		$draft->notification->addParsedAction($action);
	}//end addDeny()
}//end class
