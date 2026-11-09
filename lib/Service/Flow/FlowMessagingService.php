<?php

/**
 * The bridge from a flow's send nodes onto the notification subsystem.
 *
 * A flow gains "tell a person"; it does not gain a second messaging stack.
 * This service is an orchestration-time INVOKER of the ADR-031 subsystem's
 * call-shared units — the channel senders, the recipient resolver, the
 * dialect's placeholder evaluator, the RateLimiter, the per-channel kill
 * switches. It carries no mailer, no Talk client, no recipient resolver and
 * no template syntax of its own; everything that must not fork lives in
 * `lib/Service/Notification/` and is invoked from here.
 *
 * Guards apply in order, each independently sufficient to stop a send:
 * subsystem kill switches, then the recipient's own channel preference, then
 * the post-expansion recipient bound, then the shared rate-limit budget.
 * There is deliberately NO flow-messaging kill switch: stopping a sending
 * flow is the per-flow `enabled` flag or the instance flow kill switch, both
 * of which halt the run before this service is reached.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\FlowEmailSentEvent;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicyInterface;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthorityInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSendException;
use LogicException;
use RuntimeException;

/**
 * Sends on behalf of a flow run, through the notification subsystem.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The service exists to wire the
 * subsystem's units together for the flow caller; each dependency IS one of the
 * shared units the spec obliges it to reuse rather than duplicate.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) One guard chain in the spec's
 * order plus per-channel delivery; PHPMD sums every guard's branches into the
 * class total, and splitting the chain would hide the order it exists to state.
 */
class FlowMessagingService {

	/**
	 * The preference scope flow sends resolve under. Flow sends have no
	 * schema, so their preference overrides live under this pseudo-slug and
	 * the `send` key — one lever per user, honoured through the SAME
	 * override store and resolver the declarative subsystem uses.
	 */
	public const PREFERENCE_SLUG = 'flow';

	public const PREFERENCE_KEY = 'send';

	/**
	 * App-config key for the per-step recipient bound, and its default.
	 * Modest on purpose: a recipient template that expands to the whole
	 * instance is a configuration error to surface, not a broadcast to
	 * perform. Raisable per instance via app config.
	 */
	public const CONFIG_RECIPIENT_BOUND = 'flow_messaging_recipient_bound';

	public const DEFAULT_RECIPIENT_BOUND = 25;

	/**
	 * How many entries each outcome list in the report keeps. The run log's
	 * existing sampling rule, applied to recipients.
	 */
	public const REPORT_SAMPLE = FlowEngine::LOG_ITEM_SAMPLE;

	/**
	 * The send-email step's `externalRecipients` modes. `none` is the default
	 * and refuses every address; `object` sends only to addresses the item
	 * itself holds; `any` sends to every valid address.
	 */
	public const EXTERNAL_NONE = 'none';

	public const EXTERNAL_OBJECT = 'object';

	public const EXTERNAL_ANY = 'any';

	public const EXTERNAL_RECIPIENT_MODES = [self::EXTERNAL_NONE, self::EXTERNAL_OBJECT, self::EXTERNAL_ANY];

	/**
	 * Why an address was refused, as written into `refusedRecipients`.
	 */
	public const REFUSED_EXTERNAL_OFF = 'external-recipients-off';

	public const REFUSED_NOT_ON_ITEM = 'not-on-item';

	public const REFUSED_INVALID_ADDRESS = 'invalid-address';

	/**
	 * Where an address integriq refused lands: no answer (fail closed), else opted out.
	 */
	public const BUCKET_OPTED_OUT = 'optedOut';

	public const BUCKET_AUTHORITY_UNAVAILABLE = 'authorityUnavailable';

	private const REFUSAL_BUCKETS = [OptOutAuthorityInterface::CODE_AUTHORITY_UNAVAILABLE => self::BUCKET_AUTHORITY_UNAVAILABLE];

	/**
	 * The address rules: what counts as an address, which may be mailed.
	 *
	 * @var FlowRecipientAddresses
	 */
	private readonly FlowRecipientAddresses $addresses;

	/**
	 * Announces each sent email as a FlowEmailSentEvent.
	 *
	 * @var FlowEmailAnnouncer
	 */
	private readonly FlowEmailAnnouncer $announcer;

	/**
	 * Asks integriq whether an external address may be mailed.
	 *
	 * @var OptOutAuthorityInterface
	 */
	private readonly OptOutAuthorityInterface $optOut;

	/**
	 * Constructor. Every dependency is one of the subsystem's call-shared
	 * units — the same objects the declarative dispatcher invokes.
	 *
	 * @param FlowMessagingChannels $channels The per-channel kill switches and the in-app, email and Talk senders.
	 * @param FlowMessagingRecipients $recipients The recipient resolver, preferences, rate limiter and user lookup.
	 * @param FlowMessagingPlatform $platform The placeholder evaluator, app config, logger and event dispatcher.
	 * @param FlowMessagingOverrides $overrides The ambient step frame and the opt-out seam; null fields build the defaults.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-every-sent-email-is-announced-to-listeners
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	public function __construct(
		private readonly FlowMessagingChannels $channels,
		private readonly FlowMessagingRecipients $recipients,
		private readonly FlowMessagingPlatform $platform,
		FlowMessagingOverrides $overrides,
	) {
		$events          = $platform->eventDispatcher;
		$logger          = $platform->logger;
		$this->addresses = new FlowRecipientAddresses(recipientResolver: $recipients->recipientResolver);
		$this->announcer = new FlowEmailAnnouncer(eventDispatcher: $events, logger: $logger, runContext: $overrides->runContext);
		// The container always resolves the bound OptOutAuthorityInterface; a hand-built
		// service without one could not ask integriq, so it is refused rather than guessed.
		$this->optOut    = ($overrides->optOut ?? throw new LogicException('FlowMessagingService needs an opt-out authority.'));

	}//end __construct()

	/**
	 * Send an in-app Nextcloud notification (web-push riding along) per item.
	 *
	 * @param array $config The step configuration (`recipients`, `title`, `message`).
	 * @param array $items The flow items; one send per recipient per item.
	 * @param array $context The run context (acting user, report handle).
	 * @param string $stepName The step's type id, used as the send's rule identity.
	 *
	 * @return array The outcome report also written to the run log.
	 *
	 * @throws RuntimeException When there is no resolvable acting user, the
	 *                          recipient bound is exceeded, or a send failed.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function sendNotification(array $config, array $items, array $context, string $stepName): array {
		$send = new FlowSend(channel: 'nc-notification', config: $config, items: $items, context: $context);
		$send->stepName = $stepName;
		return $this->sendPerRecipient(send: $send);
	}//end sendNotification()

	/**
	 * The messages a step would send, composed and not sent (flow dry-run).
	 *
	 * Per item: the recipients the step resolves, and the subject and body its
	 * templates give. For `talk` the recipient is the conversation and there is
	 * no subject. Reads only: no rate budget is spent, nothing is delivered and
	 * no report is written.
	 *
	 * @param string $channel `email`, `notification` or `talk`.
	 * @param array $config The step configuration.
	 * @param array $items The flow items.
	 * @param array $context The run context.
	 *
	 * @return array<int, array{channel: string, to: array<int, string>, subject: string, body: string}>
	 *
	 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-can-say-what-it-would-do
	 */
	public function previewMessages(string $channel, array $config, array $items, array $context): array {
		$preview = new FlowMessagePreview(addresses: $this->addresses, templating: $this->platform->templating);

		return $preview->messages(channel: $channel, config: $config, items: $items, context: $this->scalarContext(context: $context));
	}//end previewMessages()

	/**
	 * Send an email per recipient per item.
	 *
	 * @param array $config The step configuration (`recipients`, `subject`, `body`).
	 * @param array $items The flow items; one send per recipient per item.
	 * @param array $context The run context (acting user, report handle).
	 * @param string $stepName The step's type id, used as the send's rule identity.
	 *
	 * @return array The outcome report also written to the run log.
	 *
	 * @throws RuntimeException When there is no resolvable acting user, the
	 *                          recipient bound is exceeded, or a send failed.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function sendEmail(array $config, array $items, array $context, string $stepName): array {
		$send = new FlowSend(channel: 'email', config: $config, items: $items, context: $context);
		$send->stepName = $stepName;
		$send->titleKey = 'subject';
		$send->bodyKey = 'body';
		return $this->sendPerRecipient(send: $send);
	}//end sendEmail()

	/**
	 * Post a Talk chat message as the run's acting user, per item.
	 *
	 * The conversation is a token or an item-field template. The acting user
	 * MUST be a participant; "not a participant" is a step failure with that
	 * reason, never an auto-join.
	 *
	 * @param array $config The step configuration (`conversation`, `message`).
	 * @param array $items The flow items; one post per item.
	 * @param array $context The run context (acting user, report handle).
	 * @param string $stepName The step's type id, used as the send's rule identity.
	 *
	 * @return array The outcome report also written to the run log.
	 *
	 * @throws RuntimeException When there is no resolvable acting user or a post failed.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function sendTalkMessage(array $config, array $items, array $context, string $stepName): array {
		$send = new FlowSend(channel: 'talk', config: $config, items: $items, context: $context);
		$send->stepName = $stepName;
		$send->actor = $this->resolveActingUser(context: $context);
		foreach ($items as $item) {
			$this->postTalkItem(send: $send, json: (array)($item[FlowItems::JSON] ?? []));
		}

		$report = $this->reportSend(send: $send);
		if ($send->failures !== []) {
			throw new RuntimeException(
				sprintf('%d of %d Talk posts failed: %s', count($send->failures), count($items), implode(' | ', array_slice($send->failures, 0, 3)))
			);
		}

		return $report;
	}//end sendTalkMessage()

	/**
	 * Post one item's Talk message, recording its outcome.
	 *
	 * The conversation is not a person: its rate bucket uses the broadcast
	 * pseudo-recipient convention, outside the shared per-recipient budget.
	 *
	 * @param FlowSend $send The Talk send.
	 * @param array    $json The item's json.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function postTalkItem(FlowSend $send, array $json): void {
		$token = $this->resolveScalarConfig(value: (string)($send->config['conversation'] ?? ''), json: $json, context: $send->context);
		$message = $this->platform->templating->interpolate(
			template: (string)($send->config['message'] ?? ''),
			data: $json,
			context: $this->scalarContext(context: $send->context)
		);
		if ($token === '') {
			$send->failures[] = 'No Talk conversation resolved for an item; nothing was posted for it.';
			$send->add(bucket: 'failed', recipient: $token);
			return;
		}

		if ($this->recipients->rateLimiter->tryConsume(ruleId: $send->stepName, recipient: '__talk__:' . $token) === false) {
			$send->add(bucket: 'rateLimited', recipient: $token);
			return;
		}

		try {
			$outcome = $this->channels->talkSender->postAsUser(token: $token, message: $message, actorUid: $send->actor);
		} catch (TalkSendException $e) {
			$send->failures[] = $e->getMessage();
			$send->add(bucket: 'failed', recipient: $token);
			return;
		}

		if ($outcome === TalkSenderInterface::OUTCOME_KILL_SWITCH) {
			$send->add(bucket: 'skippedByKillSwitch', recipient: $token);
			return;
		}

		$send->add(bucket: 'delivered', recipient: $token);
	}//end postTalkItem()

	/**
	 * The shared per-recipient pipeline for nc-notification and email.
	 *
	 * Order of guards, each sufficient alone: channel kill switch, the
	 * recipient's preference, the post-expansion recipient bound, the
	 * external-address opt-out, the shared rate-limit budget — then the send,
	 * through the subsystem's own sender. Nothing is sent until every
	 * step-wide guard has passed.
	 *
	 * @param FlowSend $send The send.
	 *
	 * @return array The outcome report.
	 *
	 * @throws RuntimeException Missing actor, exceeded bound, or failed sends.
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function sendPerRecipient(FlowSend $send): array {
		$send->actor = $this->resolveActingUser(context: $send->context);
		$this->resolveSendRecipients(send: $send);

		// KILL SWITCH, first and channel-wide: a silenced channel is a skip
		// recorded per recipient, never a failure and never a silent no-op.
		if ($this->channels->channelPolicy->isChannelEnabled(channel: $send->channel) === false) {
			$send->addAll(bucket: 'skippedByKillSwitch');
			return $this->reportSend(send: $send);
		}

		$this->filterByPreference(send: $send);
		$this->assertWithinBound(send: $send);
		$this->askOptOut(send: $send);

		// RATE LIMIT then SEND, per recipient per item. The limiter's buckets
		// are the subsystem's own — a shared budget with declarative sends.
		foreach ($send->perItem as $item) {
			$this->sendItem(send: $send, item: $item);
		}

		$report = $this->reportSend(send: $send);
		if ($send->failures !== []) {
			throw new RuntimeException(
				sprintf('%d %s send(s) failed: %s', count($send->failures), $send->channel, implode(' | ', array_slice($send->failures, 0, 3)))
			);
		}

		return $report;
	}//end sendPerRecipient()

	/**
	 * Resolve every item's recipients, post-expansion, before anything sends.
	 *
	 * An email step also accepts addresses, screened by the step's external
	 * recipient mode; every other channel reads an address as an unknown
	 * recipient, as it always did. The outcome buckets of an email step also
	 * hold the opt-out refusals.
	 *
	 * @param FlowSend $send The send.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function resolveSendRecipients(FlowSend $send): void {
		$mode = $this->addresses->externalRecipientMode(config: $send->config);
		foreach ($send->items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			$resolved = $this->addresses->resolveRecipients(
				recipients: ($send->config['recipients'] ?? []),
				json: $json,
				acceptAddresses: $send->acceptsAddresses()
			);
			$screened = $this->addresses->screenAddresses(addresses: $resolved['addresses'], json: $json, mode: $mode);
			$send->perItem[$index] = new FlowSendItem(json: $json, uids: $resolved['uids'], addresses: $screened['allowed']);
			$send->note(resolved: $resolved, screened: $screened);
		}

		$this->logUnknownRecipients(send: $send);
		if ($send->acceptsAddresses() === true) {
			$send->outcomes[self::BUCKET_OPTED_OUT] = [];
			$send->outcomes[self::BUCKET_AUTHORITY_UNAVAILABLE] = [];
		}
	}//end resolveSendRecipients()

	/**
	 * Log the recipient entries that resolved to no user or group.
	 *
	 * @param FlowSend $send The send, its recipients resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function logUnknownRecipients(FlowSend $send): void {
		if ($send->unknown === []) {
			return;
		}

		$this->platform->logger->info(
			sprintf(
				'[FlowMessagingService] %d recipient entries did not resolve to a user or group: %s',
				count($send->unknown),
				implode(', ', array_slice(array_keys($send->unknown), 0, self::REPORT_SAMPLE))
			)
		);
	}//end logUnknownRecipients()

	/**
	 * Keep the users whose own preference allows the channel; record the rest as skipped.
	 *
	 * A user who turned the channel off stays not-messaged on it, flow or no
	 * flow. Applied before the bound, so a preference-skipped user still
	 * counts toward the total the bound judges. An external address has no
	 * preferences: the step's `externalRecipients` allowlist is its gate.
	 *
	 * @param FlowSend $send The send, its recipients resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function filterByPreference(FlowSend $send): void {
		foreach (array_keys($send->distinct) as $uid) {
			if ($this->preferenceAllows(uid: (string)$uid, channel: $send->channel) === false) {
				$send->add(bucket: 'skippedByPreference', recipient: (string)$uid);
				continue;
			}

			$send->sendable[(string)$uid] = true;
		}
	}//end filterByPreference()

	/**
	 * Refuse the step when its resolved recipients exceed the bound; nothing has been sent yet.
	 *
	 * The bound judges the resolved people, not the config entries. The
	 * refusal is a step failure naming the count and the bound, routed
	 * through the step's `onError` policy, with the report written first.
	 *
	 * @param FlowSend $send The send, its recipients resolved.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the recipients exceed the bound.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function assertWithinBound(FlowSend $send): void {
		$bound = $this->recipientBound();
		if ($send->recipientCount() <= $bound) {
			return;
		}

		$this->reportSend(send: $send);
		throw new RuntimeException(
			sprintf(
				'The recipient list resolved to %d recipients, above the bound of %d; nothing was sent. Narrow the recipients, or raise "%s" in app config.',
				$send->recipientCount(),
				$bound,
				self::CONFIG_RECIPIENT_BOUND
			)
		);
	}//end assertWithinBound()

	/**
	 * Ask the opt-out authority about every external address, once for the whole step.
	 *
	 * @param FlowSend $send The send, its recipients resolved.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	private function askOptOut(FlowSend $send): void {
		$runId = (string)($this->scalarContext(context: $send->context)[FlowRunContext::CONTEXT_RUN] ?? '');
		$send->decisions = $this->optOut->ask(
			channel: 'email',
			category: (string)($this->scalarContext(context: $send->config)['messageCategory'] ?? ''),
			addresses: array_map('strval', array_keys($send->distinctAddresses)),
			correlationId: 'openregister-flow:' . $runId . ':' . $send->stepName
		);
	}//end askOptOut()

	/**
	 * Send one item to its users and its allowed addresses, then enqueue its web push.
	 *
	 * Web push rides along with the nc-notification send under the
	 * dispatcher's existing rules: the job re-resolves each recipient to their
	 * stored subscriptions, so a user without one simply gets nothing.
	 *
	 * @param FlowSend     $send The send.
	 * @param FlowSendItem $item The item.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function sendItem(FlowSend $send, FlowSendItem $item): void {
		$scalars = $this->scalarContext(context: $send->context);
		$templating = $this->platform->templating;
		$item->title = $templating->interpolate(template: (string)($send->config[$send->titleKey] ?? ''), data: $item->json, context: $scalars);
		$item->body = $templating->interpolate(template: (string)($send->config[$send->bodyKey] ?? ''), data: $item->json, context: $scalars);
		$item->kind = FlowEmailSentEvent::KIND_USER;
		foreach ($item->uids as $uid) {
			$item->recipient = $uid;
			$this->sendToUser(send: $send, item: $item);
		}

		$this->sendToAddresses(send: $send, item: $item);
		if ($send->channel === 'nc-notification' && $item->delivered !== []) {
			$this->channels->ncSender->enqueueWebPush(
				recipients: $item->delivered,
				ruleId: $send->stepName,
				originApp: 'openregister',
				subject: $item->title,
				message: $item->body,
				actions: [],
				object: $item->objectReference()
			);
		}
	}//end sendItem()

	/**
	 * Send an item to one of its users, when their preference allows it and the rate budget has room.
	 *
	 * A dispatched email is announced with a {@see FlowEmailSentEvent}.
	 *
	 * @param FlowSend     $send The send.
	 * @param FlowSendItem $item The item, its recipient the user.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function sendToUser(FlowSend $send, FlowSendItem $item): void {
		$uid = $item->recipient;
		if (isset($send->sendable[$uid]) === false) {
			return;
		}

		if ($this->recipients->rateLimiter->tryConsume(ruleId: $send->stepName, recipient: $uid) === false) {
			$send->add(bucket: 'rateLimited', recipient: $uid);
			return;
		}

		$outcome = $this->deliver(send: $send, item: $item);
		if ($outcome === 'dispatched') {
			$send->add(bucket: 'delivered', recipient: $uid);
			$item->delivered[] = $uid;
			$this->announceEmail(send: $send, item: $item);
			return;
		}

		$send->undelivered(recipient: $item->recipient, outcome: $outcome);
	}//end sendToUser()

	/**
	 * Send one item's email to its allowed external addresses.
	 *
	 * The same rate limiter and the same channel sender as a user send; the
	 * address simply skips the user lookup. A refused address is skipped
	 * before the rate limiter: no budget, no event.
	 *
	 * @param FlowSend     $send The send.
	 * @param FlowSendItem $item The item.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	private function sendToAddresses(FlowSend $send, FlowSendItem $item): void {
		$item->kind = FlowEmailSentEvent::KIND_EXTERNAL;
		foreach ($item->addresses as $address => $name) {
			$item->recipient = (string)$address;
			$item->name = $name;
			$decision = $this->optOut->decisionFor(decisions: $send->decisions, address: $item->recipient);
			if ($decision['send'] !== true) {
				$send->add(bucket: (self::REFUSAL_BUCKETS[$decision['code']] ?? self::BUCKET_OPTED_OUT), recipient: $item->recipient);
				continue;
			}

			if ($this->recipients->rateLimiter->tryConsume(ruleId: $send->stepName, recipient: $item->recipient) === false) {
				$send->add(bucket: 'rateLimited', recipient: $item->recipient);
				continue;
			}

			$this->mailAddress(send: $send, item: $item, unsubscribe: $decision['unsubscribe']);
		}
	}//end sendToAddresses()

	/**
	 * Mail one external address and record the outcome; a dispatched email is announced.
	 *
	 * The announced body leaves out the link line, a personal token.
	 *
	 * @param FlowSend     $send        The send.
	 * @param FlowSendItem $item        The item, its recipient the address.
	 * @param array|null   $unsubscribe The unsubscribe link the opt-out authority gave, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-the-send-email-flow-step-asks-integriq-before-it-mails-an-external-address-req-ero-001
	 */
	private function mailAddress(FlowSend $send, FlowSendItem $item, ?array $unsubscribe): void {
		$outcome = $this->channels->emailSender->sendToAddress(
			address: $item->recipient,
			displayName: $item->name,
			subject: $item->title,
			body: $this->optOut->withLink(body: $item->body, unsubscribe: $unsubscribe),
			unsubscribe: $unsubscribe
		);
		if ($outcome === EmailSenderInterface::OUTCOME_DISPATCHED) {
			$send->add(bucket: 'delivered', recipient: $item->recipient);
			$this->announceEmail(send: $send, item: $item);
			return;
		}

		$send->undelivered(recipient: $item->recipient, outcome: $outcome);
	}//end mailAddress()

	/**
	 * Announce a dispatched email with a {@see FlowEmailSentEvent}; other channels announce nothing.
	 *
	 * @param FlowSend     $send The send.
	 * @param FlowSendItem $item The item, its recipient the one mailed.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/flow-send-email-external-recipients/spec.md#requirement-a-send-email-step-reaches-an-address-only-as-far-as-the-step-allows
	 */
	private function announceEmail(FlowSend $send, FlowSendItem $item): void {
		if ($send->channel !== 'email') {
			return;
		}

		$this->announcer->announce(send: $send, item: $item);
	}//end announceEmail()

	/**
	 * Deliver an item to its current user over the send's channel, via the subsystem's own sender.
	 *
	 * @param FlowSend     $send The send.
	 * @param FlowSendItem $item The item, its recipient the user.
	 *
	 * @return string The sender's outcome.
	 */
	private function deliver(FlowSend $send, FlowSendItem $item): string {
		if ($send->channel === 'email') {
			return $this->channels->emailSender->send(uid: $item->recipient, subject: $item->title, body: $item->body);
		}

		return $this->channels->ncSender->send(
			uid: $item->recipient,
			object: $item->objectReference(),
			subjectKey: 'flow_message',
			name: $send->stepName,
			subject: $item->title,
			message: $item->body,
			context: [],
			originApp: 'openregister',
			actions: [],
			// Web-push rides along (enqueued after this item's sends), so the
			// foreground popup is suppressed for the tag exactly as the
			// declarative dispatcher suppresses it.
			webPushActive: true
		);
	}//end deliver()

	/**
	 * The acting user the run executes as — never a system identity.
	 *
	 * A run without a resolvable acting user FAILS the step rather than
	 * sending anonymously: the fallback would be an anonymous messenger
	 * created by an edge case. The failure names the missing actor.
	 *
	 * @param array $context The run context.
	 *
	 * @return string The acting user's uid.
	 *
	 * @throws RuntimeException When no enabled acting user resolves.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function resolveActingUser(array $context): string {
		$uid = ($context[FlowRunServiceInterface::RUN_AS_CONTEXT_KEY] ?? null);
		if (is_string($uid) === false || trim($uid) === '') {
			throw new RuntimeException(
				'This flow run has no acting identity (runAs); a message must have a sender, so nothing was sent.'
			);
		}

		$uid = trim($uid);
		$user = $this->recipients->userManager->get($uid);
		if ($user === null) {
			throw new RuntimeException(
				sprintf('This flow run\'s acting identity "%s" (runAs) is not a user account; nothing was sent.', $uid)
			);
		}

		if ($user->isEnabled() === false) {
			throw new RuntimeException(
				sprintf('This flow run\'s acting identity "%s" (runAs) is a disabled account; nothing was sent on their behalf.', $uid)
			);
		}

		return $uid;
	}//end resolveActingUser()

	/**
	 * Whether the recipient's own preference allows this channel.
	 *
	 * Resolved through the subsystem's preference service under the flow
	 * scope, so an override stored there restricts flow sends the same way a
	 * schema-scoped override restricts declarative ones.
	 *
	 * @param string $uid The recipient.
	 * @param string $channel The channel.
	 *
	 * @return bool True when the send may proceed.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function preferenceAllows(string $uid, string $channel): bool {
		$effective = $this->recipients->preferences->resolveEffective(
			schemaDefault: [
				'enabled' => true,
				'channels' => [$channel],
			],
			userId: $uid,
			schemaSlug: self::PREFERENCE_SLUG,
			notificationKey: self::PREFERENCE_KEY
		);

		if ($effective['enabled'] === false) {
			return false;
		}

		if ($effective['channels'] !== null && in_array($channel, $effective['channels'], true) === false) {
			return false;
		}

		return true;
	}//end preferenceAllows()

	/**
	 * The per-step recipient bound: app-config raisable, never below one.
	 *
	 * @return int The bound.
	 */
	private function recipientBound(): int {
		try {
			$configured = (int)$this->platform->appConfig->getValueInt(
				NotificationChannelPolicyInterface::APP_ID,
				self::CONFIG_RECIPIENT_BOUND,
				self::DEFAULT_RECIPIENT_BOUND
			);
		} catch (\Throwable $e) {
			return self::DEFAULT_RECIPIENT_BOUND;
		}

		return max(1, $configured);
	}//end recipientBound()

	/**
	 * The context's scalar values, for template interpolation. The node
	 * context carries handles (the guard, the report); the dialect evaluator
	 * only ever renders scalars, and handing it the full context would put
	 * objects where it expects values.
	 *
	 * @param array $context The run context.
	 *
	 * @return array<string, mixed> The scalar entries only.
	 */
	private function scalarContext(array $context): array {
		return array_filter($context, static fn (mixed $value): bool => is_scalar($value) === true);
	}//end scalarContext()

	/**
	 * The send's bounded report, written onto the run log via the context's report handle.
	 *
	 * @param FlowSend $send The send.
	 *
	 * @return array The report.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function reportSend(FlowSend $send): array {
		$report = $send->report(sample: self::REPORT_SAMPLE);
		$handle = ($send->context[FlowStepReport::CONTEXT_KEY] ?? null);
		if ($handle instanceof FlowStepReport) {
			$handle->put(key: 'messaging', value: $report);
		}

		return $report;
	}//end reportSend()

	/**
	 * Resolve a config value that is a literal or a single-field template.
	 *
	 * @param string $value The config value.
	 * @param array $json The item's json.
	 * @param array $context The run context.
	 *
	 * @return string The resolved value.
	 */
	private function resolveScalarConfig(string $value, array $json, array $context): string {
		$value = trim($value);
		if (str_contains($value, '{{') === false) {
			return $value;
		}

		return trim($this->platform->templating->interpolate(template: $value, data: $json, context: $this->scalarContext(context: $context)));
	}//end resolveScalarConfig()
}//end class
