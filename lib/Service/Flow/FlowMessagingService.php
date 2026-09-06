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
 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Notification\EmailSender;
use OCA\OpenRegister\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Service\Notification\RateLimiter;
use OCA\OpenRegister\Service\Notification\TalkSender;
use OCA\OpenRegister\Service\Notification\TalkSendException;
use OCP\IAppConfig;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
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
	 * Constructor. Every dependency is one of the subsystem's call-shared
	 * units — the same objects the declarative dispatcher invokes.
	 *
	 * @param NotificationChannelPolicy $channelPolicy The subsystem's per-channel kill switches.
	 * @param NotificationRecipientResolver $recipientResolver The subsystem's recipient resolver.
	 * @param NotificationTemplating $templating The dialect's placeholder evaluator.
	 * @param NcNotificationSender $ncSender The nc-notification channel sender (web-push rides along).
	 * @param EmailSender $emailSender The email channel sender.
	 * @param TalkSender $talkSender The Talk channel sender.
	 * @param RateLimiter $rateLimiter The subsystem's rate limiter (shared per-recipient budget).
	 * @param NotificationPreferenceService $preferences The recipient preference resolver.
	 * @param IUserManager $userManager Resolves the acting user.
	 * @param IAppConfig $appConfig App config for the recipient bound.
	 * @param LoggerInterface $logger Logger for send diagnostics.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) DI-injected shared units.
	 */
	public function __construct(
		private readonly NotificationChannelPolicy $channelPolicy,
		private readonly NotificationRecipientResolver $recipientResolver,
		private readonly NotificationTemplating $templating,
		private readonly NcNotificationSender $ncSender,
		private readonly EmailSender $emailSender,
		private readonly TalkSender $talkSender,
		private readonly RateLimiter $rateLimiter,
		private readonly NotificationPreferenceService $preferences,
		private readonly IUserManager $userManager,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function sendNotification(array $config, array $items, array $context, string $stepName): array {
		return $this->sendPerRecipient(
			channel: 'nc-notification',
			config: $config,
			items: $items,
			context: $context,
			stepName: $stepName,
			titleKey: 'title',
			bodyKey: 'message'
		);
	}//end sendNotification()

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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	public function sendEmail(array $config, array $items, array $context, string $stepName): array {
		return $this->sendPerRecipient(
			channel: 'email',
			config: $config,
			items: $items,
			context: $context,
			stepName: $stepName,
			titleKey: 'subject',
			bodyKey: 'body'
		);
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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	public function sendTalkMessage(array $config, array $items, array $context, string $stepName): array {
		$actor = $this->resolveActingUser(context: $context);

		$outcomes = $this->emptyOutcomes();
		$failures = [];

		foreach ($items as $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			$token = $this->resolveScalarConfig(value: (string)($config['conversation'] ?? ''), json: $json, context: $context);
			$message = $this->templating->interpolate(
				template: (string)($config['message'] ?? ''),
				data: $json,
				context: $this->scalarContext(context: $context)
			);

			if ($token === '') {
				$failures[] = 'No Talk conversation resolved for an item; nothing was posted for it.';
				$this->addOutcome(outcomes: $outcomes, bucket: 'failed', recipient: $token);
				continue;
			}

			// The conversation is not a person: the rate bucket key uses the
			// broadcast pseudo-recipient convention, outside the shared
			// per-recipient budget.
			if ($this->rateLimiter->tryConsume(ruleId: $stepName, recipient: '__talk__:' . $token) === false) {
				$this->addOutcome(outcomes: $outcomes, bucket: 'rateLimited', recipient: $token);
				continue;
			}

			try {
				$outcome = $this->talkSender->postAsUser(token: $token, message: $message, actorUid: $actor);
			} catch (TalkSendException $e) {
				$failures[] = $e->getMessage();
				$this->addOutcome(outcomes: $outcomes, bucket: 'failed', recipient: $token);
				continue;
			}

			if ($outcome === TalkSender::OUTCOME_KILL_SWITCH) {
				$this->addOutcome(outcomes: $outcomes, bucket: 'skippedByKillSwitch', recipient: $token);
				continue;
			}

			$this->addOutcome(outcomes: $outcomes, bucket: 'delivered', recipient: $token);
		}//end foreach

		$report = $this->buildReport(
			channel: 'talk',
			actor: $actor,
			recipients: 0,
			outcomes: $outcomes,
			unknown: []
		);
		$this->writeReport(context: $context, report: $report);

		if ($failures !== []) {
			throw new RuntimeException(
				sprintf('%d of %d Talk posts failed: %s', count($failures), count($items), implode(' | ', array_slice($failures, 0, 3)))
			);
		}

		return $report;
	}//end sendTalkMessage()

	/**
	 * The shared per-recipient pipeline for nc-notification and email.
	 *
	 * Order of guards, each sufficient alone: channel kill switch, the
	 * recipient's preference, the post-expansion recipient bound, the shared
	 * rate-limit budget — then the send, through the subsystem's own sender.
	 *
	 * @param string $channel The channel (`nc-notification` or `email`).
	 * @param array $config The step configuration.
	 * @param array $items The flow items.
	 * @param array $context The run context.
	 * @param string $stepName The step's type id.
	 * @param string $titleKey The config key holding the title/subject template.
	 * @param string $bodyKey The config key holding the body template.
	 *
	 * @return array The outcome report.
	 *
	 * @throws RuntimeException Missing actor, exceeded bound, or failed sends.
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) The guard chain is the spec's own
	 * order; each branch is one guard with its own outcome bucket.
	 * @SuppressWarnings(PHPMD.NPathComplexity) Guards multiply; all are required.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) The chain reads top to bottom in
	 * the order the spec states it; splitting it would hide the order.
	 */
	private function sendPerRecipient(
		string $channel,
		array $config,
		array $items,
		array $context,
		string $stepName,
		string $titleKey,
		string $bodyKey,
	): array {
		$actor = $this->resolveActingUser(context: $context);

		// Resolve recipients per item, post-expansion, before anything sends.
		$perItem = [];
		$unknown = [];
		$distinct = [];
		foreach ($items as $index => $item) {
			$json = (array)($item[FlowItems::JSON] ?? []);
			$resolved = $this->resolveRecipients(recipients: ($config['recipients'] ?? []), json: $json);
			$perItem[$index] = ['json' => $json, 'uids' => $resolved['uids']];
			foreach ($resolved['uids'] as $uid) {
				$distinct[$uid] = true;
			}

			foreach ($resolved['unknown'] as $bad) {
				$unknown[$bad] = true;
			}
		}

		if ($unknown !== []) {
			$this->logger->info(
				sprintf(
					'[FlowMessagingService] %d recipient entries did not resolve to a user or group: %s',
					count($unknown),
					implode(', ', array_slice(array_keys($unknown), 0, self::REPORT_SAMPLE))
				)
			);
		}

		$outcomes = $this->emptyOutcomes();
		$failures = [];

		// KILL SWITCH, first and channel-wide: a silenced channel is a skip
		// recorded per recipient, never a failure and never a silent no-op.
		if ($this->channelPolicy->isChannelEnabled(channel: $channel) === false) {
			foreach (array_keys($distinct) as $uid) {
				$this->addOutcome(outcomes: $outcomes, bucket: 'skippedByKillSwitch', recipient: (string)$uid);
			}

			$report = $this->buildReport(
				channel: $channel,
				actor: $actor,
				recipients: count($distinct),
				outcomes: $outcomes,
				unknown: array_keys($unknown)
			);
			$this->writeReport(context: $context, report: $report);

			return $report;
		}

		// PREFERENCE, per recipient: a user who turned the channel off stays
		// not-messaged on it, flow or no flow. Applied before the bound so a
		// preference-skipped user still counts toward the resolved total the
		// bound judges (the config addressed them; their settings vetoed it).
		$sendable = [];
		foreach (array_keys($distinct) as $uid) {
			if ($this->preferenceAllows(uid: (string)$uid, channel: $channel) === false) {
				$this->addOutcome(outcomes: $outcomes, bucket: 'skippedByPreference', recipient: (string)$uid);
				continue;
			}

			$sendable[(string)$uid] = true;
		}

		// RECIPIENT BOUND, post-expansion: bounding the resolved humans, not
		// the config entries. Refusal is a step failure naming the count and
		// the bound, routed through the step's `onError` policy — and nothing
		// has been sent yet.
		$bound = $this->recipientBound();
		if (count($distinct) > $bound) {
			$report = $this->buildReport(
				channel: $channel,
				actor: $actor,
				recipients: count($distinct),
				outcomes: $outcomes,
				unknown: array_keys($unknown)
			);
			$this->writeReport(context: $context, report: $report);

			throw new RuntimeException(
				sprintf(
					'The recipient list resolved to %d users, above the bound of %d; nothing was sent. Narrow the recipients, or raise "%s" in app config.',
					count($distinct),
					$bound,
					self::CONFIG_RECIPIENT_BOUND
				)
			);
		}

		// RATE LIMIT then SEND, per recipient per item. The limiter's buckets
		// are the subsystem's own — a shared budget with declarative sends.
		foreach ($perItem as $entry) {
			$title = $this->templating->interpolate(
				template: (string)($config[$titleKey] ?? ''),
				data: $entry['json'],
				context: $this->scalarContext(context: $context)
			);
			$body = $this->templating->interpolate(
				template: (string)($config[$bodyKey] ?? ''),
				data: $entry['json'],
				context: $this->scalarContext(context: $context)
			);

			$deliveredThisItem = [];
			foreach ($entry['uids'] as $uid) {
				if (isset($sendable[$uid]) === false) {
					continue;
				}

				if ($this->rateLimiter->tryConsume(ruleId: $stepName, recipient: $uid) === false) {
					$this->addOutcome(outcomes: $outcomes, bucket: 'rateLimited', recipient: $uid);
					continue;
				}

				$outcome = $this->deliver(
					channel: $channel,
					uid: $uid,
					title: $title,
					body: $body,
					json: $entry['json'],
					stepName: $stepName
				);

				if ($outcome === 'dispatched') {
					$this->addOutcome(outcomes: $outcomes, bucket: 'delivered', recipient: $uid);
					$deliveredThisItem[] = $uid;
					continue;
				}

				if ($outcome === 'kill-switch') {
					$this->addOutcome(outcomes: $outcomes, bucket: 'skippedByKillSwitch', recipient: $uid);
					continue;
				}

				$this->addOutcome(outcomes: $outcomes, bucket: 'failed', recipient: $uid);
				$failures[] = sprintf('%s to "%s" failed (%s)', $channel, $uid, $outcome);
			}//end foreach

			// WEB-PUSH rides along with the nc-notification send under the
			// dispatcher's existing rules, with no flow-side configuration:
			// the job re-resolves each recipient to their stored
			// subscriptions, so a user without one simply gets nothing.
			if ($channel === 'nc-notification' && $deliveredThisItem !== []) {
				$this->ncSender->enqueueWebPush(
					recipients: $deliveredThisItem,
					ruleId: $stepName,
					originApp: 'openregister',
					subject: $title,
					message: $body,
					actions: [],
					object: $this->objectFromItem(json: $entry['json'])
				);
			}
		}//end foreach

		$report = $this->buildReport(
			channel: $channel,
			actor: $actor,
			recipients: count($distinct),
			outcomes: $outcomes,
			unknown: array_keys($unknown)
		);
		$this->writeReport(context: $context, report: $report);

		if ($failures !== []) {
			throw new RuntimeException(
				sprintf('%d %s send(s) failed: %s', count($failures), $channel, implode(' | ', array_slice($failures, 0, 3)))
			);
		}

		return $report;
	}//end sendPerRecipient()

	/**
	 * Deliver one message to one recipient over one channel, via the
	 * subsystem's own sender.
	 *
	 * @param string $channel The channel.
	 * @param string $uid The recipient.
	 * @param string $title The rendered title/subject.
	 * @param string $body The rendered body.
	 * @param array $json The item's json, for the notification's object reference.
	 * @param string $stepName The step's type id.
	 *
	 * @return string The sender's outcome.
	 */
	private function deliver(string $channel, string $uid, string $title, string $body, array $json, string $stepName): string {
		if ($channel === 'email') {
			return $this->emailSender->send(uid: $uid, subject: $title, body: $body);
		}

		return $this->ncSender->send(
			uid: $uid,
			object: $this->objectFromItem(json: $json),
			subjectKey: 'flow_message',
			name: $stepName,
			subject: $title,
			message: $body,
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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function resolveActingUser(array $context): string {
		$uid = ($context[FlowRunService::RUN_AS_CONTEXT_KEY] ?? null);
		if (is_string($uid) === false || trim($uid) === '') {
			throw new RuntimeException(
				'This flow run has no acting identity (runAs); a message must have a sender, so nothing was sent.'
			);
		}

		$uid = trim($uid);
		$user = $this->userManager->get($uid);
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
	 * Resolve a node's recipients config against one item.
	 *
	 * Entries are literal user or group ids, or a template resolving a field
	 * on the item (`{{ assignee }}` / `{{ item.assignee }}`). Resolution goes
	 * through the subsystem's recipient resolver — groups expanded, every uid
	 * verified — and unknown ids are returned for the run log rather than
	 * silently dropped.
	 *
	 * @param mixed $recipients The config value.
	 * @param array $json The item's json.
	 *
	 * @return array{uids: array<int, string>, unknown: array<int, string>}
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity) Three entry shapes (template, user, group)
	 * each with its own verification and unknown-reporting branch.
	 */
	private function resolveRecipients(mixed $recipients, array $json): array {
		$uids = [];
		$unknown = [];

		if (is_string($recipients) === true) {
			$recipients = [$recipients];
		}

		foreach ((array)$recipients as $entry) {
			if (is_string($entry) === false || trim($entry) === '') {
				continue;
			}

			$entry = trim($entry);

			$matches = [];
			if (preg_match('/^\{\{\s*(?:item\.)?([a-zA-Z0-9_.-]+)\s*\}\}$/', $entry, $matches) === 1) {
				$field = $matches[1];
				$resolved = $this->recipientResolver->resolve(
					recipientsSpec: [
						[
							'kind' => 'relation',
							'relation' => $field,
						],
					],
					data: $json,
					object: null,
					context: []
				);
				$candidates = $this->recipientResolver->extractUidsFromRelation(value: ($json[$field] ?? null));
				foreach (array_diff($candidates, $resolved) as $bad) {
					$unknown[] = $bad;
				}

				foreach ($resolved as $uid) {
					$uids[] = $uid;
				}

				continue;
			}//end if

			if ($this->recipientResolver->userExists(uid: $entry) === true) {
				$uids[] = $entry;
				continue;
			}

			if ($this->recipientResolver->groupExists(gid: $entry) === true) {
				$members = $this->recipientResolver->resolve(
					recipientsSpec: [
						[
							'kind' => 'groups',
							'groups' => [$entry],
						],
					],
					data: [],
					object: null,
					context: []
				);
				foreach ($members as $uid) {
					$uids[] = $uid;
				}

				continue;
			}

			$unknown[] = $entry;
		}//end foreach

		return [
			'uids' => array_values(array_unique($uids)),
			'unknown' => array_values(array_unique($unknown)),
		];
	}//end resolveRecipients()

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
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function preferenceAllows(string $uid, string $channel): bool {
		$effective = $this->preferences->resolveEffective(
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
			$configured = (int)$this->appConfig->getValueInt(
				NotificationChannelPolicy::APP_ID,
				self::CONFIG_RECIPIENT_BOUND,
				self::DEFAULT_RECIPIENT_BOUND
			);
		} catch (\Throwable $e) {
			return self::DEFAULT_RECIPIENT_BOUND;
		}

		return max(1, $configured);
	}//end recipientBound()

	/**
	 * A lightweight object reference for an item, for the notification's
	 * object link. An item read from a register carries its uuid; an item
	 * built mid-flow may not, and then the notification simply carries no
	 * object deeplink.
	 *
	 * @param array $json The item's json.
	 *
	 * @return ObjectEntity The reference.
	 */
	private function objectFromItem(array $json): ObjectEntity {
		$object = new ObjectEntity();
		$uuid = ($json['uuid'] ?? ($json['id'] ?? null));
		if (is_string($uuid) === true && $uuid !== '') {
			$object->setUuid($uuid);
		}

		$name = ($json['name'] ?? ($json['title'] ?? null));
		if (is_string($name) === true && $name !== '') {
			$object->setName($name);
		}

		return $object;
	}//end objectFromItem()

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
	 * The empty outcome buckets.
	 *
	 * @return array<string, array<int, string>>
	 */
	private function emptyOutcomes(): array {
		return [
			'delivered' => [],
			'skippedByPreference' => [],
			'skippedByKillSwitch' => [],
			'rateLimited' => [],
			'failed' => [],
		];
	}//end emptyOutcomes()

	/**
	 * Record one outcome.
	 *
	 * @param array<string, array<int, string>> $outcomes The buckets, by reference.
	 * @param string $bucket The outcome bucket.
	 * @param string $recipient The recipient (or conversation token).
	 *
	 * @return void
	 */
	private function addOutcome(array &$outcomes, string $bucket, string $recipient): void {
		$outcomes[$bucket][] = $recipient;
	}//end addOutcome()

	/**
	 * The bounded outcome report for the run log.
	 *
	 * Bounded by the log's sampling rule: each bucket carries its true count
	 * and at most REPORT_SAMPLE entries — a mail archive is not what a run
	 * log is for, and neither is a recipient directory.
	 *
	 * @param string $channel The channel.
	 * @param string $actor The acting user.
	 * @param int $recipients The resolved distinct recipient count.
	 * @param array<string, array<int, string>> $outcomes The outcome buckets.
	 * @param array<int, string> $unknown Unresolvable recipient entries.
	 *
	 * @return array The report.
	 *
	 * @spec openspec/changes/flow-messaging-nodes/specs/flow-messaging-nodes/spec.md#requirement-flow-sends-are-attributed-logged-and-bounded
	 */
	private function buildReport(string $channel, string $actor, int $recipients, array $outcomes, array $unknown): array {
		$report = [
			'channel' => $channel,
			'actor' => $actor,
			'recipients' => $recipients,
		];

		$truncated = false;
		foreach ($outcomes as $bucket => $entries) {
			$report[$bucket] = [
				'count' => count($entries),
				'sample' => array_slice(array_values($entries), 0, self::REPORT_SAMPLE),
			];
			if (count($entries) > self::REPORT_SAMPLE) {
				$truncated = true;
			}
		}

		if ($unknown !== []) {
			$report['unknownRecipients'] = [
				'count' => count($unknown),
				'sample' => array_slice(array_values($unknown), 0, self::REPORT_SAMPLE),
			];
			if (count($unknown) > self::REPORT_SAMPLE) {
				$truncated = true;
			}
		}

		$report['truncated'] = $truncated;

		return $report;
	}//end buildReport()

	/**
	 * Write the report onto the run log via the context's report handle.
	 *
	 * @param array $context The run context.
	 * @param array $report The report.
	 *
	 * @return void
	 */
	private function writeReport(array $context, array $report): void {
		$handle = ($context[FlowStepReport::CONTEXT_KEY] ?? null);
		if (($handle instanceof FlowStepReport) === false) {
			return;
		}

		$handle->put(key: 'messaging', value: $report);
	}//end writeReport()

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

		return trim($this->templating->interpolate(template: $value, data: $json, context: $this->scalarContext(context: $context)));
	}//end resolveScalarConfig()
}//end class
