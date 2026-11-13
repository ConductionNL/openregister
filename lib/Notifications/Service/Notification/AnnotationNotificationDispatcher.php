<?php

/**
 * OpenRegister AnnotationNotificationDispatcher
 *
 * Reads `x-openregister-notifications` from the schema and fires
 * Nextcloud INotificationManager notifications when a triggering event
 * matches. v1 supports trigger types created/updated/transition,
 * recipient kinds users/field, channel `nc-notification`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Notification
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\Service\Notification;

use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\AutomationRecord;
use OCA\OpenRegister\Notifications\Db\DuplicateDispatchException;
use OCA\OpenRegister\Service\AutomationRecordWriter;
use OCA\OpenRegister\Notifications\Db\NotificationDispatchLogMapper;
use OCA\OpenRegister\Notifications\Db\HistoryDelivery;
use OCA\OpenRegister\Notifications\Db\HistoryEntry;
use OCA\OpenRegister\Notifications\Db\HistoryNotice;
use OCA\OpenRegister\Notifications\Db\HistoryTarget;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Notifications\Db\QueuedNotification;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Notifications\Service\Party\PartyMessage;
use OCA\OpenRegister\Notifications\Service\Party\PartyNotificationService;
use OCA\OpenRegister\Service\Task\TaskStateRules;
use OCA\OpenRegister\Service\WriteCause;
use OCP\Activity\IManager as IActivityManager;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Mail\IMailer;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;
use OCA\OpenRegister\Service\Notification\MailingRequest;
use OCA\OpenRegister\Service\Notification\ReferenceOrigin;
use OCA\OpenRegister\Service\Notification\ReferencedAddressNotifierInterface;

/**
 * Reads notification annotations and dispatches matching ones.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)   Dispatcher wires SchemaMapper, INotificationManager,
 * IGroupManager, IUserManager, IMailer, IActivityManager, IClientService, IServerContainer, RateLimiter,
 * IConfig, NotificationHistoryMapper, NotificationCoalescer, NotificationSubscriptionMapper,
 * NotificationDispatchLogMapper, NotificationPreferenceService — every dependency serves a distinct
 * notification channel or cross-cutting concern (audit, dedup, rate-limit, preference).
 * @SuppressWarnings(PHPMD.ExcessiveClassLength)     Class implements the full notification dispatch pipeline
 * (nc-notification, email, activity, webhook, talk channels) plus recipient resolution, rate-limiting,
 * coalescing, idempotency, history audit, and organisation gating; each concern requires its own helper
 * method and cannot be moved to a separate class without breaking the single-dispatch-per-call contract.
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) Complexity is spread across 20+ private helpers each
 * testing a distinct dispatch condition; PHPMD accumulates scores from every helper into the class total.
 * @SuppressWarnings(PHPMD.TooManyMethods)           Each notification channel (nc-notification, email, activity,
 * webhook, talk), each gate (rate-limit, coalesce, preference, subscription, organisation), each helper
 * (recipient resolve, locale, history record, idempotency) requires its own method per the
 * single-responsibility principle.
 *
 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
 */
class AnnotationNotificationDispatcher implements NotificationRuleDispatcherInterface {

	/**
	 * Logger for dispatch diagnostics (from the ledger).
	 *
	 * @var LoggerInterface
	 */
	private readonly LoggerInterface $logger;

	/**
	 * URL generator for action-target deeplinks (from the rendering set).
	 *
	 * @var IURLGenerator|null
	 */
	private readonly ?IURLGenerator $urlGenerator;

	/**
	 * Object resolver for relation-target deeplinks (from the rendering set).
	 *
	 * @var \OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface|null
	 */
	private readonly ?\OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface $objectService;

	/**
	 * Canonical per-app deeplink resolver (from the rendering set).
	 *
	 * @var \OCA\OpenRegister\Service\DeepLinkResolverInterface|null
	 */
	private readonly ?\OCA\OpenRegister\Service\DeepLinkResolverInterface $deepLinkRegistry;

	/**
	 * The resolver of declared actions — lazily built.
	 *
	 * @var ActionLinkResolver|null
	 */
	private ?ActionLinkResolver $lazyActionLinks = null;

	/**
	 * The register's languages for the object being dispatched, default first.
	 *
	 * A translatable value renders in the recipient's language, then these.
	 *
	 * @var array<int, string>
	 */
	private array $registerLanguages = [];

	/**
	 * Notes rules that resolved to no recipients at all.
	 *
	 * @var RuleReachRecorder
	 */
	private RuleReachRecorder $reachRecorder;

	/**
	 * Constructor. The dispatcher's collaborators come in six capability sets,
	 * each built by the container: the channels it delivers over, the gates a
	 * delivery passes, its reach into other apps, what it records, whom a rule
	 * reaches, and how a message is composed.
	 *
	 * @param ChannelSet       $channels     The nc-notification, email, Talk and activity channels.
	 * @param DispatchGates    $gates        Throttle, quiet-hours / digest hold, preference and subscription gates.
	 * @param AppIntegrations  $integrations Webhook HTTP, web-push job list, runtime app services, app lookup.
	 * @param DeliveryLedger   $ledger       Logger, delivery history, idempotency log and reach notes.
	 * @param RuleAudience     $audience     Schema and register lookup, recipient resolver, user config.
	 * @param MessageRendering $rendering    Placeholder evaluator and the deeplink sources.
	 */
	public function __construct(
		private readonly ChannelSet $channels,
		private readonly DispatchGates $gates,
		private readonly AppIntegrations $integrations,
		private readonly DeliveryLedger $ledger,
		private readonly RuleAudience $audience,
		private readonly MessageRendering $rendering,
	) {
		$this->logger           = $ledger->logger;
		$this->urlGenerator     = $rendering->urlGenerator;
		$this->objectService    = $rendering->objectService;
		$this->deepLinkRegistry = $rendering->deepLinkRegistry;
		$this->reachRecorder    = ($ledger->reachRecorder ?? new RuleReachRecorder(logger: $ledger->logger));
	}//end __construct()

	/**
	 * The nc-notification channel sender — injected, or built from this
	 * dispatcher's own dependencies. One implementation either way; the flow
	 * messaging service invokes the same class.
	 *
	 * @return NcNotificationSender The sender.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function ncSender(): NcNotificationSender {
		return $this->channels->ncSender;
	}//end ncSender()

	/**
	 * The email channel sender — injected, or built from this dispatcher's
	 * own dependencies.
	 *
	 * @return EmailSender The sender.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function emailSender(): EmailSender {
		return $this->channels->emailSender;
	}//end emailSender()

	/**
	 * The Talk channel sender — injected, or built from this dispatcher's
	 * own dependencies.
	 *
	 * @return TalkSender The sender.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function talkSender(): TalkSender {
		return $this->channels->talkSender;
	}//end talkSender()

	/**
	 * The recipient resolver — injected, or built from this dispatcher's own
	 * dependencies.
	 *
	 * @return NotificationRecipientResolver The resolver.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function recipientResolver(): NotificationRecipientResolver {
		return $this->audience->recipientResolver;
	}//end recipientResolver()

	/**
	 * The resolver of declared actions, built on first use.
	 *
	 * @return ActionLinkResolver
	 */
	private function actionLinks(): ActionLinkResolver {
		$routes = new AppRouteBase(urlGenerator: $this->urlGenerator);
		$this->lazyActionLinks ??= new ActionLinkResolver(
			routes: $routes,
			tasks: new TaskActionLinks(
				isRejecting: static fn (string $outcome): bool => TaskStateRules::isRejectingOutcome(outcome: $outcome),
				urlGenerator: $this->urlGenerator
			),
			objects: new ObjectDetailLinks(
				logger: $this->logger,
				routes: $routes,
				readObject: $this->objectReader(),
				deepLinkRegistry: $this->deepLinkRegistry
			)
		);

		return $this->lazyActionLinks;
	}//end actionLinks()

	/**
	 * Reads an object for a relation link, with RBAC on; null without an object service.
	 *
	 * @return Closure|null
	 */
	private function objectReader(): ?Closure {
		if ($this->objectService === null) {
			return null;
		}

		return fn (string $lookup): ?ObjectEntity => WriteCause::asLookup(fn () => $this->objectService->find(id: $lookup, _rbac: true));
	}//end objectReader()

	/**
	 * The dialect's placeholder evaluator — injected, or built from this
	 * dispatcher's own dependencies.
	 *
	 * @return NotificationTemplating The evaluator.
	 *
	 * @spec openspec/specs/flow-messaging-nodes/spec.md#requirement-flows-send-through-the-notification-subsystem-never-beside-it
	 */
	private function templating(): NotificationTemplating {
		return $this->rendering->templating;
	}//end templating()

	/**
	 * Fire any notifications declared on the schema whose trigger matches.
	 *
	 * @param ObjectEntity $object The object the event happened on.
	 * @param string $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
	 * @param array<string, mixed> $context Trigger-specific extras (e.g. `action`, `from`, `to`,
	 *                                      `_newData`, `_oldData` for calculatedChange).
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.CyclomaticComplexity)  dispatch() iterates notifications, filters by
	 * organisation gate, idempotency, recipient resolution, rate-limit, coalesce, and preference per
	 * recipient per channel — each branch is a required dispatch condition; extracting sub-methods
	 * would split the sequential guard chain.
	 * @SuppressWarnings(PHPMD.NPathComplexity)       Per-rule: trigger match + org gate + idempotency claim
	 * + subscription filter + rate-limit + coalesce + preference check + channel fan-out produce many
	 * independent paths; all are required for the notification contract.
	 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) dispatch() is the single entry point that chains
	 * all notification gates in order; splitting the chain would break the sequential guard semantics
	 * (e.g. rate-limit must run after idempotency claim).
	 * @SuppressWarnings(PHPMD.LongVariable)          $resolvedIdempotencyKey and $idempotencyKeyTemplate are
	 * descriptive names for distinct lifecycle stages of the same template value; abbreviating would
	 * obscure the claim-before-send ordering that prevents duplicate dispatches.
	 *
	 * @spec openspec/changes/retrofit-2026-05-25-bw-svc-mid1/tasks.md#task-5
	 */
	public function dispatch(ObjectEntity $object, string $trigger, array $context = []): void {
		$schema = $this->loadSchema(object: $object);
		if ($schema === null) {
			return;
		}

		$this->dispatchWithSchema(object: $object, trigger: $trigger, context: $context, schema: $schema);

	}//end dispatch()

	/**
	 * Fire notifications declared on a pre-resolved Schema.
	 *
	 * Called by dispatch() after schema resolution, and directly by the
	 * SystemEntityNotificationBridge for system entities whose schema is not in
	 * the SchemaMapper but is instead provided by SystemSchemaRules::buildSchema().
	 *
	 * This is the single entry point for the notification pipeline: every
	 * dispatch path (stored objects and system entities) goes through here.
	 *
	 * @param ObjectEntity $object The object (or virtual adapter) the event happened on.
	 * @param string $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
	 * @param array<string, mixed> $context Trigger-specific extras.
	 * @param Schema $schema Pre-resolved schema carrying the rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
	 */
	public function dispatchWithSchema(ObjectEntity $object, string $trigger, array $context, Schema $schema): void {
		$this->fireRules(event: new NotificationEvent(object: $object, trigger: $trigger, context: $context), schema: $schema);
	}//end dispatchWithSchema()

	/**
	 * Fire every rule a schema declares for one event.
	 *
	 * @param NotificationEvent $event  The object, the trigger and its extras.
	 * @param Schema            $schema The schema carrying the rules.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
	 */
	private function fireRules(NotificationEvent $event, Schema $schema): void {
		$notifications = $this->getAnnotation(schema: $schema);
		if ($notifications === null) {
			return;
		}

		$this->registerLanguages = $this->resolveRegisterLanguages(object: $event->object);

		// The canonical INotification subject comes from the trigger, not the
		// rule name, so every schema-declared in-app notification renders.
		$subjectKey = $this->canonicalSubject(trigger: $event->trigger);

		foreach ($notifications as $name => $spec) {
			if (is_array($spec) === false) {
				continue;
			}

			$firing = new RuleFiring(
				ruleId: (string)$name,
				spec: $spec,
				object: $event->object,
				schema: $schema,
				context: $event->context,
				subjectKey: $subjectKey
			);
			$this->fireAndRecord(firing: $firing, trigger: $event->trigger);
		}

	}//end fireRules()

	/**
	 * Fire one rule and leave its automation run record: one per evaluation,
	 * aggregated per channel, skipped with the gate that stopped it. A rule
	 * whose trigger or organisation gate does not match was not evaluated and
	 * leaves none. An error is recorded as `failed` and still thrown.
	 *
	 * @param RuleFiring $firing  The rule and the event.
	 * @param string     $trigger The trigger.
	 *
	 * @return void
	 *
	 * @throws \Throwable Whatever the dispatch throws, after recording it.
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	private function fireAndRecord(RuleFiring $firing, string $trigger): void {
		try {
			$this->fireRule(firing: $firing, trigger: $trigger);
		} catch (\Throwable $e) {
			$this->recordRun(firing: $firing, failure: $e->getMessage());
			throw $e;
		}

		if ($firing->eventId !== '' || $firing->tally->reason() === NotificationRunTally::REASON_DUPLICATE) {
			$this->recordRun(firing: $firing);
		}
	}//end fireAndRecord()

	/**
	 * Write the automation run record of one evaluation. Recipients are counted, never named.
	 *
	 * @param RuleFiring  $firing  The evaluated rule.
	 * @param string|null $failure The error that stopped it, if any.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/automation-run-records/specs/notificatie-engine/spec.md
	 */
	private function recordRun(RuleFiring $firing, ?string $failure = null): void {
		if ($this->ledger->runRecords === null) {
			return;
		}

		$record = AutomationRecordWriter::draft(
			kind: AutomationRecord::KIND_NOTIFICATION,
			key: ($firing->spec['key'] ?? null),
			name: $firing->ruleId,
			object: $firing->object
		);
		$record->setEventId(self::storedId(value: $firing->eventId));
		$record->setOutcome($firing->tally->outcome());
		$record->setReason($firing->tally->reason());
		if ($failure !== null) {
			$record->setOutcome(NotificationRunTally::FAILED);
			$record->setReason(null);
			$record->setMessage($failure);
		}

		$record->setChannels((string)json_encode($firing->tally->counts()));
		$this->ledger->runRecords->write($record);
	}//end recordRun()

	/**
	 * Run one rule through its gates and channels.
	 *
	 * @param RuleFiring $firing  The rule and the event.
	 * @param string     $trigger The event's trigger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/openregister-system-notifications/tasks.md#task-2
	 */
	private function fireRule(RuleFiring $firing, string $trigger): void {
		if ($this->ruleApplies(firing: $firing, trigger: $trigger) === false) {
			return;
		}

		$firing->scopes  = $this->dispatchScopes(firing: $firing);
		$firing->eventId = $this->newEventId();

		$this->resolveFiringRecipients(firing: $firing);
		$this->renderBroadcastSubject(firing: $firing);

		// Parties and referenced addresses are reached before the zero-recipient
		// bail: a rule addressed only to them resolves no account at all.
		$addressesReached  = $this->dispatchToParties(firing: $firing);
		$addressesReached += $this->dispatchToAddresses(firing: $firing);
		for ($reached = 0; $reached < $addressesReached; $reached++) {
			$firing->tally->delivery(channel: 'address', delivered: true);
		}

		if (count($firing->recipients) === 0) {
			// Only when the parties path reached nobody either: a rule
			// addressed to parties has no account recipients by design.
			if ($addressesReached === 0) {
				$firing->tally->skip(reason: NotificationRunTally::REASON_NO_RECIPIENTS);
				$this->reachRecorder->reachedNobody(
					ruleId: $firing->ruleId,
					objectUuid: (string)($firing->object->getUuid() ?? '')
				);
			}

			return;
		}

		$this->resolveRuleDelivery(firing: $firing);
		$this->dispatchBroadcasts(firing: $firing);

		foreach ($firing->recipients as $uid) {
			$delivery = new RecipientDelivery(uid: $uid);
			$delivery->eventId = $firing->eventId;
			$this->deliverToRecipient(firing: $firing, delivery: $delivery);
		}

	}//end fireRule()

	/**
	 * Whether the rule fires for this event: trigger match, organisation pin
	 * and idempotency claim, in that order.
	 *
	 * The idempotency slot is CLAIMED before sending: the unique
	 * (notification_slug, idempotency_key) index makes the insert the
	 * serialisation point between concurrent dispatchers. A failed send after a
	 * successful claim blocks a retry until the window expires, which is
	 * preferred over sending twice.
	 *
	 * @param RuleFiring $firing  The rule and the event.
	 * @param string     $trigger The event's trigger.
	 *
	 * @return bool
	 */
	private function ruleApplies(RuleFiring $firing, string $trigger): bool {
		$matcher = new NotificationTriggerMatcher(
			trigger: $trigger,
			context: array_merge($firing->context, ['_data' => $firing->data])
		);
		if ($matcher->matches(triggerSpec: ($firing->spec['trigger'] ?? [])) === false) {
			return false;
		}

		if ($this->organisationGateAllows(spec: $firing->spec, object: $firing->object) === false) {
			return false;
		}

		return $this->idempotencyAllows(firing: $firing);
	}//end ruleApplies()

	/**
	 * Whether the rule's idempotency claim lets this firing through.
	 *
	 * A rule without an idempotency key always passes. A rule with one passes
	 * only when this firing wins the claim on its resolved key; a lost claim is
	 * logged as a deduplication.
	 *
	 * @param RuleFiring $firing The rule and the event.
	 *
	 * @return bool
	 */
	private function idempotencyAllows(RuleFiring $firing): bool {
		$template = ($firing->spec['idempotencyKey'] ?? null);
		if (is_string($template) === false || $template === '') {
			return true;
		}

		$key = $this->resolveIdempotencyKey(template: $template, firing: $firing);
		if ($this->claimIdempotencyKey(slug: $firing->ruleId, key: $key) === true) {
			return true;
		}

		$this->logger->info(
			sprintf('[AnnotationNotificationDispatcher] deduplicated rule="%s" key="%s"', $firing->ruleId, $key)
		);
		$firing->tally->skip(reason: NotificationRunTally::REASON_DUPLICATE);
		return false;

	}//end idempotencyAllows()

	/**
	 * Resolve the rule's account recipients, record what did not resolve, and
	 * apply the subscription filter when the rule asks for it.
	 *
	 * @param RuleFiring $firing The rule and the event.
	 *
	 * @return void
	 */
	private function resolveFiringRecipients(RuleFiring $firing): void {
		$source   = new RecipientSource(
			data: $firing->data,
			object: $firing->object,
			context: $firing->context,
			roleGroups: $this->roleGroupsFor(schema: $firing->schema)
		);
		$resolved = $this->recipientResolver()->resolveFromSource(recipientsSpec: ($firing->spec['recipients'] ?? []), source: $source);
		$firing->recipients = $resolved['uids'];

		// A group or role the server no longer has is a failed dispatch, not an empty one.
		if ($resolved['unresolved'] !== []) {
			$this->recordUnresolvedRecipients(firing: $firing, unresolved: $resolved['unresolved']);
		}

		if (($firing->spec['requiresSubscription'] ?? false) === true) {
			$firing->recipients = $this->filterBySubscription(
				recipients: $firing->recipients,
				object: $firing->object
			);
		}

	}//end resolveFiringRecipients()

	/**
	 * Render the default-locale subject, the channels, and the body and
	 * category of a mail to a party or a referenced address.
	 *
	 * @param RuleFiring $firing The rule and the event.
	 *
	 * @return void
	 */
	private function renderBroadcastSubject(RuleFiring $firing): void {
		$broadcast = new MessageTarget(firing: $firing, locale: null);
		$firing->broadcastSubject = $this->messages()->subject(target: $broadcast);
		$firing->channels         = (array)($firing->spec['channels'] ?? ['nc-notification']);

		$firing->addressBody = $this->messages()->declaredBody(target: $broadcast);
		if (trim($firing->addressBody) === '') {
			$firing->addressBody = $firing->broadcastSubject;
		}

		$firing->category = self::messageCategory(spec: $firing->spec);
	}//end renderBroadcastSubject()

	/**
	 * The rule's message category. Absent or unknown reads as the default
	 * category, never as exempt.
	 *
	 * @param array<string, mixed> $spec The rule declaration.
	 *
	 * @return string
	 */
	private static function messageCategory(array $spec): string {
		$category = ($spec['messageCategory'] ?? '');
		if (is_string($category) === true && in_array(strtolower(trim($category)), OptOutAuthority::CATEGORIES, true) === true) {
			return strtolower(trim($category));
		}

		return OptOutAuthority::DEFAULT_CATEGORY;
	}//end messageCategory()

	/**
	 * The renderer of subjects and bodies for the object being dispatched.
	 *
	 * @return RuleMessageRenderer
	 */
	private function messages(): RuleMessageRenderer {
		return new RuleMessageRenderer(
			templating: $this->templating(),
			languages: $this->registerLanguages,
			appManager: $this->integrations->appManager,
			logger: $this->logger
		);
	}//end messages()

	/**
	 * Resolve the rate limit, coalesce window, origin app, actions and the
	 * default-locale body the account channels share.
	 *
	 * @param RuleFiring $firing The rule and the event.
	 *
	 * @return void
	 */
	private function resolveRuleDelivery(RuleFiring $firing): void {
		if (is_array($firing->spec['rateLimit'] ?? null) === true) {
			$firing->rateLimit = $firing->spec['rateLimit'];
		}

		if (is_array($firing->spec['coalesce'] ?? null) === true) {
			$firing->coalesce = $firing->spec['coalesce'];
		}

		$firing->originApp = $this->resolveOriginApp(spec: $firing->spec, object: $firing->object);
		$firing->actions   = $this->actionLinks()->resolve(
			spec: $firing->spec,
			context: new ActionTargetContext(object: $firing->object, data: $firing->data, originApp: $firing->originApp)
		);

		$firing->broadcastMessage = $this->messages()->body(target: new MessageTarget(firing: $firing, locale: null));
	}//end resolveRuleDelivery()

	/**
	 * Run the once-per-firing channels: web-push (queued out of band),
	 * webhook, Talk and the rule's outbound transports.
	 *
	 * @param RuleFiring $firing The rule and the event.
	 *
	 * @return void
	 */
	private function dispatchBroadcasts(RuleFiring $firing): void {
		if ($firing->hasChannel('web-push') === true) {
			$this->enqueueWebPush(firing: $firing);
		}

		foreach (['webhook', 'talk'] as $channel) {
			if ($firing->hasChannel($channel) === true) {
				$this->dispatchBroadcastChannel(firing: $firing, channel: $channel);
			}
		}

		$this->runOutboundTransports(firing: $firing);

	}//end dispatchBroadcasts()

	/**
	 * Deliver the firing to one account: rate limit, coalesce, preference and
	 * quiet-hours gates, then the per-recipient channels.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient.
	 *
	 * @return void
	 */
	private function deliverToRecipient(RuleFiring $firing, RecipientDelivery $delivery): void {
		if ($this->gates->throttle->rateAllows(firing: $firing, recipient: $delivery->uid) === false) {
			$delivery->status = 'rate-limited';
			$firing->tally->held(gate: NotificationRunTally::REASON_RATE_LIMIT);
			$this->recordSkippedDelivery(firing: $firing, delivery: $delivery);
			return;
		}

		$this->renderForRecipient(firing: $firing, delivery: $delivery);

		if ($this->gates->throttle->coalesceAllows(firing: $firing, recipient: $delivery->uid) === false) {
			$delivery->status = 'coalesced';
			$firing->tally->held(gate: 'coalesced');
			$this->recordSkippedDelivery(firing: $firing, delivery: $delivery);
			return;
		}

		if ($this->applyPreference(firing: $firing, delivery: $delivery) === false) {
			$delivery->status = 'preference-off';
			$firing->tally->held(gate: 'preference');
			$this->recordSkippedDelivery(firing: $firing, delivery: $delivery);
			return;
		}

		if ($this->deliveryWindowOrDigestSuppresses(firing: $firing, delivery: $delivery) === true) {
			$firing->tally->held(gate: NotificationRunTally::REASON_QUIET_HOURS);
			return;
		}

		$this->emitToRecipient(firing: $firing, delivery: $delivery);

	}//end deliverToRecipient()

	/**
	 * Render the subject and body in the recipient's locale.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient.
	 *
	 * @return void
	 */
	private function renderForRecipient(RuleFiring $firing, RecipientDelivery $delivery): void {
		$delivery->locale  = $this->resolveUserLocale(uid: $delivery->uid);
		$target = new MessageTarget(firing: $firing, locale: $delivery->locale);
		$delivery->subject = $this->messages()->subject(target: $target);
		$delivery->message = $this->messages()->body(target: $target);
	}//end renderForRecipient()

	/**
	 * Apply the recipient's stored preference to the rule's channels.
	 *
	 * The schema declares the default; a stored override switches the rule off
	 * or narrows its channels. Webhook and Talk already went out once above.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient; receives the channels kept.
	 *
	 * @return bool False when the recipient switched the rule off.
	 */
	private function applyPreference(RuleFiring $firing, RecipientDelivery $delivery): bool {
		$delivery->channels = $firing->channels;
		if ($this->gates->preferenceService === null) {
			return true;
		}

		$pref = $this->gates->preferenceService->resolve(
			resolution: new PreferenceResolution(
				schemaDefault: $firing->spec,
				userId: $delivery->uid,
				topic: new PreferenceTopic(schemaSlug: $firing->schemaSlug, notificationKey: $firing->ruleId, scopes: $firing->scopes)
			)
		);
		if ($pref['enabled'] === false) {
			return false;
		}

		if ($pref['channels'] !== null) {
			$delivery->channels = array_values(
				array_intersect(NotificationPreferenceService::availableChannelsOf(spec: $firing->spec), $pref['channels'])
			);
		}

		return true;

	}//end applyPreference()

	/**
	 * Send the in-app notice, e-mail and activity the recipient keeps, each
	 * recorded under the firing's event id.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient.
	 *
	 * @return void
	 */
	private function emitToRecipient(RuleFiring $firing, RecipientDelivery $delivery): void {
		$subject = (string)$delivery->subject;
		$origin  = DeliveryOrigin::ofFiring(firing: $firing);
		foreach (['nc-notification', 'email', 'activity'] as $channel) {
			if (in_array($channel, $delivery->channels, true) === false) {
				continue;
			}

			$record = new DeliveryRecord(
				channel: $channel,
				recipient: $delivery->uid,
				status: 'dispatched',
				detail: new DeliveryDetail(subject: $subject, locale: $delivery->locale)
			);
			if ($channel === 'nc-notification') {
				// The shared channel units, the same classes the flow messaging
				// service invokes; the declarative path stays best-effort, so
				// their outcomes are not escalated here.
				$outcome = $this->ncSender()->notify(
					notice: new InAppNotice(
						uid: $delivery->uid,
						object: $firing->object,
						rule: new NoticeRule(
							name: $firing->ruleId,
							originApp: $firing->originApp,
							actions: $firing->actions,
							webPushActive: $firing->hasChannel('web-push')
						),
						content: new NoticeContent(
							subjectKey: $firing->subjectKey,
							subject: $subject,
							message: $delivery->message,
							context: $firing->context
						)
					)
				);
			} elseif ($channel === 'email') {
				$outcome = $this->emailSender()->send(uid: $delivery->uid, subject: $subject, body: $subject);
			} else {
				$this->emitActivity(origin: $origin, record: $record);
				$outcome = NcNotificationSender::OUTCOME_DISPATCHED;
			}

			$firing->tally->delivery(channel: $channel, delivered: $outcome === NcNotificationSender::OUTCOME_DISPATCHED);

			$this->recordHistory(origin: $origin, record: $record);
		}//end foreach

	}//end emitToRecipient()

	/**
	 * Record a delivery that did not go out, once per per-recipient channel,
	 * with the delivery's status. Webhook and Talk are broadcast and keep
	 * their own rows.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient, its status and text.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function recordSkippedDelivery(RuleFiring $firing, RecipientDelivery $delivery): void {
		$origin = new DeliveryOrigin(ruleId: $firing->ruleId, object: $firing->object, eventId: $delivery->eventId);
		$detail = new DeliveryDetail(subject: $delivery->subject, locale: $delivery->locale);
		foreach ($firing->channels as $channel) {
			if (in_array($channel, ['webhook', 'talk'], true) === true) {
				continue;
			}

			$this->recordHistory(
				origin: $origin,
				record: new DeliveryRecord(channel: (string)$channel, recipient: $delivery->uid, status: $delivery->status, detail: $detail)
			);
		}

	}//end recordSkippedDelivery()

	/**
	 * The scopes this dispatch may match a pinned preference against.
	 *
	 * Narrowest first, because the first match wins: the schema the object
	 * lives on, then the domain the rule (or the schema) declares, then the
	 * register. A domain is a leaf app's own word for a slice of its work —
	 * dossiq's "vergunningen" against its "meldingen" — and it is declared
	 * rather than derived, so OpenRegister never has to guess what a leaf app
	 * means by it.
	 *
	 * @param RuleFiring $firing The rule, its triggering object and the owning schema's slug.
	 *
	 * @return array<int, string> The candidate scopes, narrowest first.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-preference-may-be-scoped-to-a-register-a-schema-or-a-declared-domain-req-nrg-003
	 */
	private function dispatchScopes(RuleFiring $firing): array {
		$scopes = [];
		if ($firing->schemaSlug !== '') {
			$scopes[] = 'schema:' . $firing->schemaSlug;
		}

		$domain = ($firing->spec['domain'] ?? null);
		if (is_string($domain) === true && $domain !== '') {
			$scopes[] = 'domain:' . $domain;
		}

		$register = $this->registerScope(object: $firing->object);
		if ($register !== null) {
			$scopes[] = $register;
		}

		return $scopes;
	}//end dispatchScopes()

	/**
	 * The register preference scope of the object, when it names a register.
	 *
	 * @param ObjectEntity $object The triggering object.
	 *
	 * @return string|null The `register:` scope, or null when the object has no register.
	 */
	private function registerScope(ObjectEntity $object): ?string {
		$register = $object->getRegister();
		if (is_string($register) === true && $register !== '') {
			return 'register:' . $register;
		}

		if (is_int($register) === true) {
			return 'register:' . (string)$register;
		}

		return null;
	}//end registerScope()

	/**
	 * Run the rule's declared outbound transports, once per firing.
	 *
	 * Each transport is resolved from the server container by the handler the
	 * rule names, run, and recorded under the firing's event id with its own
	 * outcome. A transport that throws or reports a failure does not stop the
	 * next one and does not stop the notices that already went out: the
	 * independence is the requirement, and it is enforced here rather than
	 * trusted to each implementation.
	 *
	 * @param RuleFiring $firing The rule and the event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
	 */
	private function runOutboundTransports(RuleFiring $firing): void {
		$transports = ($firing->spec['transports'] ?? null);
		if (is_array($transports) === false || $transports === []) {
			return;
		}

		foreach ($transports as $transport) {
			if ($this->isRunnableOutboundTransport(transport: $transport) === true) {
				$this->runAndRecordTransport(run: new OutboundTransportRun(firing: $firing, transport: $transport));
			}
		}
	}//end runOutboundTransports()

	/**
	 * Whether a transport declaration is an outbound transport that names a handler.
	 *
	 * @param mixed $transport One entry of the rule's `transports` list.
	 *
	 * @return bool
	 */
	private function isRunnableOutboundTransport(mixed $transport): bool {
		if (is_array($transport) === false || (string)($transport['kind'] ?? '') !== 'outbound') {
			return false;
		}

		return (string)($transport['handler'] ?? '') !== '';
	}//end isRunnableOutboundTransport()

	/**
	 * Run one outbound transport and record its outcome under the firing's event id.
	 *
	 * @param OutboundTransportRun $run The firing and the transport.
	 *
	 * @return void
	 */
	private function runAndRecordTransport(OutboundTransportRun $run): void {
		$failure = $this->runOneOutboundTransport(run: $run);

		$status = 'dispatched';
		if ($failure !== null) {
			$status = 'failed';
		}

		$this->recordHistory(
			origin: DeliveryOrigin::ofFiring(firing: $run->firing),
			record: new DeliveryRecord(channel: 'outbound', recipient: $run->handler, status: $status, detail: new DeliveryDetail(errorMessage: $failure))
		);
	}//end runAndRecordTransport()

	/**
	 * Resolve and invoke one outbound transport, turning any fault into a string.
	 *
	 * @param OutboundTransportRun $run The firing and the transport: its `handler` (DI tag or FQCN) and `config`.
	 *
	 * @return string|null Why it did not land, or null when it did.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
	 */
	private function runOneOutboundTransport(OutboundTransportRun $run): ?string {
		try {
			$service = $this->integrations->serverContainer->get($run->handler);
		} catch (\Throwable $e) {
			return sprintf('transport "%s" could not be resolved: %s', $run->handler, $e->getMessage());
		}

		if (($service instanceof OutboundTransportInterface) === false) {
			return sprintf('transport "%s" does not implement OutboundTransportInterface', $run->handler);
		}

		return $this->sendThroughTransport(run: $run, service: $service);
	}//end runOneOutboundTransport()

	/**
	 * Hand the firing to a resolved outbound transport, turning a throw into its message.
	 *
	 * @param OutboundTransportRun       $run     The firing and the transport's config.
	 * @param OutboundTransportInterface $service The resolved transport.
	 *
	 * @return string|null Why it did not land, or null when it did.
	 */
	private function sendThroughTransport(OutboundTransportRun $run, OutboundTransportInterface $service): ?string {
		try {
			return $service->send(
				object: $run->firing->object,
				notificationName: $run->firing->ruleId,
				recipients: $run->firing->recipients,
				context: $run->firing->context,
				config: $run->config,
				eventId: $run->firing->eventId
			);
		} catch (\Throwable $e) {
			return $e->getMessage();
		}
	}//end sendThroughTransport()

	/**
	 * Mint the identifier that ties one firing's transports together.
	 *
	 * Opaque and per-firing. It is not the idempotency key: that one is
	 * authored by the schema and deduplicates ACROSS firings, while this one is
	 * minted here and only groups the transports WITHIN one.
	 *
	 * @return string The event identifier.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
	 */
	private function newEventId(): string {
		try {
			return bin2hex(random_bytes(16));
		} catch (\Throwable $e) {
			// An exhausted entropy source must not stop a notification. A
			// weaker id still groups this firing's transports, which is all the
			// column claims; it is not a security token.
			return sprintf('%013x%06x', (int)(microtime(true) * 1000), mt_rand(0, 0xFFFFFF));
		}
	}//end newEventId()

	/**
	 * Evaluate the delivery-window (quiet-hours) / digest-schedule gate for
	 * one recipient and, when it suppresses, persist a `QueuedNotification`
	 * row (never drop the event) and record notification history with the
	 * matching `queued-*` status.
	 *
	 * Only the per-recipient channels (nc-notification, email, activity) are
	 * held. A `critical: true` rule bypasses the gate.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient, its text and the channels it keeps.
	 *
	 * @return bool True when the event was queued (caller must skip immediate dispatch).
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function deliveryWindowOrDigestSuppresses(RuleFiring $firing, RecipientDelivery $delivery): bool {
		if ($this->isHoldable(firing: $firing, delivery: $delivery) === false) {
			return false;
		}

		$hold = $this->gates->deliveryHold->decide(firing: $firing, uid: $delivery->uid);
		if ($hold === null) {
			return false;
		}

		$this->holdDelivery(run: new HeldDeliveryRun(firing: $firing, delivery: $delivery), hold: $hold);

		return true;
	}//end deliveryWindowOrDigestSuppresses()

	/**
	 * The recipient's channels that a hold applies to (nc-notification, email, activity).
	 *
	 * @param RecipientDelivery $delivery The recipient and the channels it keeps.
	 *
	 * @return array<int, string>
	 */
	private function queueableChannels(RecipientDelivery $delivery): array {
		return array_values(
			array_intersect($delivery->channels, ['nc-notification', 'email', 'activity'])
		);
	}//end queueableChannels()

	/**
	 * Whether the delivery may be held at all: it keeps a queueable channel and the rule is not critical.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient and the channels it keeps.
	 *
	 * @return bool
	 */
	private function isHoldable(RuleFiring $firing, RecipientDelivery $delivery): bool {
		if (count($this->queueableChannels(delivery: $delivery)) === 0) {
			return false;
		}

		return ($firing->spec['critical'] ?? false) !== true;
	}//end isHoldable()

	/**
	 * Keep the held row when rows are kept, mark the delivery queued and record it skipped.
	 *
	 * @param HeldDeliveryRun $run  The firing and the recipient delivery.
	 * @param HoldDecision    $hold Why the delivery waits, and since when.
	 *
	 * @return void
	 */
	private function holdDelivery(HeldDeliveryRun $run, HoldDecision $hold): void {
		if ($this->gates->deliveryHold->keepsRows() === true) {
			$this->keepHeldRow(run: $run, hold: $hold);
		}

		// Queued rows carry no event id.
		$run->delivery->status  = 'queued-quiet-hours';
		$run->delivery->eventId = null;
		if ($hold->reason === QueuedNotification::REASON_DIGEST_SCHEDULE) {
			$run->delivery->status = 'queued-digest';
		}

		$this->recordSkippedDelivery(firing: $run->firing, delivery: $run->delivery);
	}//end holdDelivery()

	/**
	 * Build the held delivery's `QueuedNotification` row and persist it.
	 *
	 * @param HeldDeliveryRun $run  The firing and the recipient delivery.
	 * @param HoldDecision    $hold Why the delivery waits, and since when.
	 *
	 * @return void
	 */
	private function keepHeldRow(HeldDeliveryRun $run, HoldDecision $hold): void {
		$entity = $this->queuedNotificationFor(firing: $run->firing, delivery: $run->delivery);
		$entity->setReason($hold->reason);
		$entity->setPayload($this->heldPayload(run: $run));
		$entity->setDueAtHint(DateTime::createFromImmutable($hold->at));
		$entity->setCreatedAt(DateTime::createFromImmutable($hold->at));

		$this->keepOrWarn(run: $run, row: $entity);
	}//end keepHeldRow()

	/**
	 * Persist a held row; a failed insert is logged, never thrown.
	 *
	 * @param HeldDeliveryRun    $run The firing and the recipient delivery, for the log line.
	 * @param QueuedNotification $row The row, with reason and payload set.
	 *
	 * @return void
	 */
	private function keepOrWarn(HeldDeliveryRun $run, QueuedNotification $row): void {
		try {
			$this->gates->deliveryHold->keep(row: $row);
		} catch (\Throwable $e) {
			$this->logger->warning(
				sprintf(
					'[AnnotationNotificationDispatcher] failed to queue rule="%s" recipient="%s": %s',
					$run->firing->ruleId,
					$run->delivery->uid,
					$e->getMessage()
				)
			);
		}
	}//end keepOrWarn()

	/**
	 * The JSON payload a held row carries: the rendered text and the object it is about.
	 *
	 * @param HeldDeliveryRun $run The firing and the recipient delivery.
	 *
	 * @return string
	 */
	private function heldPayload(HeldDeliveryRun $run): string {
		return (string)json_encode(
			array_merge($this->heldMessage(run: $run), $this->heldObjectRef(object: $run->firing->object))
		);
	}//end heldPayload()

	/**
	 * The rendered text and how to send it again, as a held row stores it.
	 *
	 * @param HeldDeliveryRun $run The firing and the recipient delivery.
	 *
	 * @return array<string, mixed>
	 */
	private function heldMessage(HeldDeliveryRun $run): array {
		return [
			'subject' => $run->delivery->subject,
			'message' => $run->delivery->message,
			'channels' => $this->queueableChannels(delivery: $run->delivery),
			'subjectKey' => $run->firing->subjectKey,
			'name' => $run->firing->ruleId,
			'originApp' => $run->firing->originApp,
			'actions' => $run->firing->actions,
			'locale' => $run->delivery->locale,
			'action' => (string)($run->firing->context['action'] ?? ''),
		];
	}//end heldMessage()

	/**
	 * The object a held row is about, as it stores it.
	 *
	 * @param ObjectEntity $object The triggering object.
	 *
	 * @return array<string, mixed>
	 */
	private function heldObjectRef(ObjectEntity $object): array {
		return [
			'objectUuid' => (string)($object->getUuid() ?? ''),
			'objectRegister' => $object->getRegister(),
			'objectSchema' => $object->getSchema(),
			'objectName' => $object->getName(),
		];
	}//end heldObjectRef()

	/**
	 * A queued notification row for this recipient, without reason or payload.
	 *
	 * @param RuleFiring        $firing   The rule and the event.
	 * @param RecipientDelivery $delivery The recipient.
	 *
	 * @return QueuedNotification
	 */
	private function queuedNotificationFor(RuleFiring $firing, RecipientDelivery $delivery): QueuedNotification {
		$entity = new QueuedNotification();
		$entity->setSchemaId((int)$firing->schema->getId());
		$entity->setRuleKey($firing->ruleId);
		$entity->setRecipient($delivery->uid);
		$entity->setObjectUuid((string)($firing->object->getUuid() ?? ''));

		return $entity;
	}//end queuedNotificationFor()

	/**
	 * Flush a group of `QueuedNotification` rows sharing the same
	 * `(rule_key, recipient)` pair, called by `NotificationQueueFlushJob`
	 * once it has determined the group's holding condition has cleared.
	 *
	 * Reuses the existing channel fan-out unchanged (the nc-notification and
	 * email senders, `emitActivity`). A single row flushes as its own
	 * pre-resolved subject/message; multiple rows are merged into one
	 * summary message so the recipient gets one notification instead of N.
	 * Notification history is updated to `dispatched` on success.
	 *
	 * @param array<int, QueuedNotification> $queuedRows Rows sharing one (rule_key, recipient) pair.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	public function dispatchQueued(array $queuedRows): void {
		if (count($queuedRows) === 0) {
			return;
		}

		$flush = new QueuedFlush(first: $queuedRows[0], summary: $this->buildQueuedSummary(rows: $queuedRows));

		$this->flushToNotifications(flush: $flush);
		$this->flushToEmail(flush: $flush);
		$this->flushToActivity(flush: $flush);

	}//end dispatchQueued()

	/**
	 * Send a flushed group as one in-app notification, when it was held for that channel.
	 *
	 * @param QueuedFlush $flush The group's recipient, text, channels and object.
	 *
	 * @return void
	 */
	private function flushToNotifications(QueuedFlush $flush): void {
		if (in_array('nc-notification', $flush->channels, true) === false) {
			return;
		}

		$this->sendQueuedNotification(flush: $flush);
		$this->recordHistory(
			origin: $flush->origin,
			record: new DeliveryRecord(channel: 'nc-notification', recipient: $flush->recipient, status: 'dispatched', detail: $flush->detail)
		);
	}//end flushToNotifications()

	/**
	 * Hand a flushed group to the nc-notification sender.
	 *
	 * @param QueuedFlush $flush The group's recipient, text, payload and object.
	 *
	 * @return void
	 */
	private function sendQueuedNotification(QueuedFlush $flush): void {
		$this->ncSender()->notify(
			notice: new InAppNotice(
				uid: $flush->recipient,
				object: $flush->object,
				rule: new NoticeRule(
					name: $flush->name,
					originApp: (string)($flush->payload['originApp'] ?? 'openregister'),
					actions: (array)($flush->payload['actions'] ?? []),
					webPushActive: false
				),
				content: new NoticeContent(
					subjectKey: (string)($flush->payload['subjectKey'] ?? $this->canonicalSubject(trigger: 'updated')),
					subject: $flush->subject,
					message: $flush->message,
					context: []
				)
			)
		);
	}//end sendQueuedNotification()

	/**
	 * Send a flushed group as one e-mail, when it was held for that channel.
	 *
	 * @param QueuedFlush $flush The group's recipient, text and channels.
	 *
	 * @return void
	 */
	private function flushToEmail(QueuedFlush $flush): void {
		if (in_array('email', $flush->channels, true) === false) {
			return;
		}

		$emailBody = $flush->subject;
		if ($flush->message !== '') {
			$emailBody = $flush->message;
		}

		$this->emailSender()->send(uid: $flush->recipient, subject: $flush->subject, body: $emailBody);
		$this->recordHistory(
			origin: $flush->origin,
			record: new DeliveryRecord(channel: 'email', recipient: $flush->recipient, status: 'dispatched', detail: $flush->detail)
		);
	}//end flushToEmail()

	/**
	 * Emit a flushed group as one activity, when it was held for that channel.
	 *
	 * @param QueuedFlush $flush The group's recipient, name, channels and object.
	 *
	 * @return void
	 */
	private function flushToActivity(QueuedFlush $flush): void {
		if (in_array('activity', $flush->channels, true) === false) {
			return;
		}

		$record = new DeliveryRecord(channel: 'activity', recipient: $flush->recipient, status: 'dispatched', detail: $flush->detail);
		$this->emitActivity(origin: new DeliveryOrigin(ruleId: $flush->name, object: $flush->object), record: $record);
		$this->recordHistory(origin: $flush->origin, record: $record);
	}//end flushToActivity()

	/**
	 * Build the flushed subject/message for a group of queued rows. A
	 * single row flushes verbatim; multiple rows are merged into a
	 * digest-style summary (breakdown by the stored `action`, e.g.
	 * "3 nieuw, 2 gewijzigd" — reusing the rolling-digest breakdown
	 * pattern already documented for the coalescer).
	 *
	 * @param array<int, QueuedNotification> $rows Rows sharing one (rule_key, recipient) pair.
	 *
	 * @return array{0: string, 1: string} [subject, message]
	 */
	private function buildQueuedSummary(array $rows): array {
		if (count($rows) === 1) {
			$payload = $rows[0]->getDecodedPayload();
			return [(string)($payload['subject'] ?? ''), (string)($payload['message'] ?? '')];
		}

		$subject = sprintf('%d meldingen (%s)', count($rows), $this->queuedBreakdown(rows: $rows));

		return [$subject, $this->queuedTitles(rows: $rows)];
	}//end buildQueuedSummary()

	/**
	 * The per-action breakdown of a queued group, e.g. "3 nieuw, 2 gewijzigd".
	 *
	 * @param array<int, QueuedNotification> $rows Rows sharing one (rule_key, recipient) pair.
	 *
	 * @return string
	 */
	private function queuedBreakdown(array $rows): string {
		$labels = [
			'created' => 'nieuw',
			'updated' => 'gewijzigd',
		];

		$parts = [];
		foreach ($this->queuedActionCounts(rows: $rows) as $action => $count) {
			$parts[] = sprintf('%d %s', $count, ($labels[$action] ?? $action));
		}

		return implode(', ', $parts);
	}//end queuedBreakdown()

	/**
	 * How many rows of a queued group carry each stored action ('other' when none).
	 *
	 * @param array<int, QueuedNotification> $rows Rows sharing one (rule_key, recipient) pair.
	 *
	 * @return array<int|string, int> Count per action, in first-seen order.
	 */
	private function queuedActionCounts(array $rows): array {
		$counts = [];
		foreach ($rows as $row) {
			$payload = $row->getDecodedPayload();
			$action = (string)($payload['action'] ?? '');
			if ($action === '') {
				$action = 'other';
			}

			$counts[$action] = (($counts[$action] ?? 0) + 1);
		}

		return $counts;
	}//end queuedActionCounts()

	/**
	 * The first ten non-empty subjects of a queued group, and how many more there are.
	 *
	 * @param array<int, QueuedNotification> $rows Rows sharing one (rule_key, recipient) pair.
	 *
	 * @return string
	 */
	private function queuedTitles(array $rows): string {
		$titles = [];
		foreach (array_slice($rows, 0, 10) as $row) {
			$payload = $row->getDecodedPayload();
			$titles[] = (string)($payload['subject'] ?? '');
		}

		$message = implode('; ', array_filter($titles));
		if (count($rows) > 10) {
			$message .= sprintf(' ... en %d meer', (count($rows) - 10));
		}

		return $message;
	}//end queuedTitles()

	/**
	 * Dispatch a broadcast-style channel (webhook / talk) once per firing.
	 *
	 * Webhook and Talk go to one shared endpoint, so they are rate limited and
	 * coalesced once per dispatch under the `__webhook__` / `__talk__` key and
	 * recorded as a single history row with the outcome they had.
	 *
	 * @param RuleFiring $firing  The rule and the event.
	 * @param string     $channel 'webhook' | 'talk'.
	 *
	 * @return void
	 */
	private function dispatchBroadcastChannel(RuleFiring $firing, string $channel): void {
		$recipientKey = '__'.$channel.'__';
		$status       = 'dispatched';
		$failure      = null;
		if ($this->gates->throttle->rateAllows(firing: $firing, recipient: $recipientKey) === false) {
			$status = 'rate-limited';
		} elseif ($this->gates->throttle->coalesceAllows(firing: $firing, recipient: $recipientKey) === false) {
			$status = 'coalesced';
		} elseif ($channel === 'webhook') {
			$failure = $this->emitWebhook(firing: $firing);
		} else {
			$this->emitTalk(spec: $firing->spec, message: $firing->broadcastSubject);
		}

		// Recording `dispatched` for a call that threw would make a dead
		// integration invisible.
		if ($failure !== null) {
			$status = 'failed';
		}

		$this->recordHistory(
			origin: DeliveryOrigin::ofFiring(firing: $firing),
			record: new DeliveryRecord(
				channel: $channel,
				recipient: $recipientKey,
				status: $status,
				detail: new DeliveryDetail(subject: $firing->broadcastSubject, errorMessage: $failure)
			)
		);

	}//end dispatchBroadcastChannel()

	/**
	 * Claim the idempotency slot for (slug, key) atomically.
	 *
	 * Inserts the dedup row up-front so the unique
	 * (notification_slug, idempotency_key) index is the authoritative
	 * serialisation point under concurrency. Returns true when the
	 * claim succeeded (caller may dispatch) and false when the row
	 * already exists within the dedup window (caller must skip).
	 *
	 * A null mapper (test contexts, older fixtures) always allows so
	 * no test has to construct the mapper just to pass the guard.
	 *
	 * Side-effects:
	 *   - Runs a best-effort prune before claiming so the table does
	 *     not grow unboundedly without a separate cron job.
	 *   - On non-duplicate DB error returns true (the dispatch should
	 *     not be blocked by infrastructure failure) and logs at warning
	 *     level so the operator can investigate.
	 *
	 * @param string $slug The notification annotation key.
	 * @param string $key The resolved idempotency key.
	 *
	 * @return bool True when the dispatch may proceed (claim succeeded
	 *              or mapper unavailable); false when a competing
	 *              dispatcher already claimed this (slug, key).
	 */
	private function claimIdempotencyKey(string $slug, string $key): bool {
		if ($this->ledger->dispatchLogMapper === null) {
			return true;
		}

		// Prune expired rows lazily — best-effort, failures are swallowed
		// inside the mapper.
		$this->ledger->dispatchLogMapper->pruneExpired();

		try {
			$this->ledger->dispatchLogMapper->record(
				notificationSlug: $slug,
				idempotencyKey: $key
			);
			return true;
		} catch (DuplicateDispatchException) {
			// Concurrent dispatcher beat us to the (slug, key) slot, or
			// a previous send within the window already recorded it.
			// Either way: do not dispatch.
			return false;
		} catch (\Throwable $e) {
			// Genuine DB failure (table missing in test fixtures, etc.).
			// Fail-open: dispatch proceeds so a transient infra issue
			// doesn't silently drop user-visible notifications.
			$this->logger->warning(
				sprintf(
					'[AnnotationNotificationDispatcher] idempotency claim failed (slug=%s key=%s): %s',
					$slug,
					$key,
					$e->getMessage()
				)
			);
			return true;
		}//end try

	}//end claimIdempotencyKey()

	/**
	 * Resolve a `${@self.<field>}` idempotency-key template against the object.
	 *
	 * The template syntax mirrors the spec example:
	 *   `${@self.id}-T30-${@self.dueDate}`
	 *
	 * Each `${@self.<field>}` token is replaced with the value of
	 * `<field>` from the object's stored data (or the object's built-in
	 * accessor for `id` and `uuid`). Unknown tokens are replaced with
	 * an empty string so the template never returns null.
	 *
	 * Values are cast to string and limited to 128 characters each to
	 * avoid the 512-char column limit being hit by adversarial data.
	 *
	 * @param string     $template Raw idempotency-key template.
	 * @param RuleFiring $firing   The owning object and its pre-fetched data array.
	 *
	 * @return string The resolved key.
	 */
	private function resolveIdempotencyKey(string $template, RuleFiring $firing): string {
		$object = $firing->object;
		$data   = $firing->data;

		return preg_replace_callback(
			'/\$\{@self\.([a-zA-Z0-9_.-]+)\}/',
			static function (array $matches) use ($object, $data): string {
				$field = $matches[1];

				// Built-in accessors for the most common fields.
				if ($field === 'id' || $field === 'uuid') {
					return substr((string)($object->getUuid() ?? ''), 0, 128);
				}

				// Fall through to the stored object data.
				$value = ($data[$field] ?? null);
				if ($value === null) {
					return '';
				}

				if (is_scalar($value) === false) {
					return '';
				}

				return substr((string)$value, 0, 128);
			},
			$template
		) ?? $template;

	}//end resolveIdempotencyKey()

	/**
	 * Persist a row in `openregister_notification_history`.
	 *
	 * Best-effort: a null mapper (older test fixtures) or a database
	 * failure must never block the actual dispatch. When the mapper
	 * is missing or throws we log at debug level + return — the
	 * notification user-experience takes precedence over audit
	 * completeness.
	 *
	 * @param DeliveryOrigin $origin The rule, object and event the row belongs to.
	 * @param DeliveryRecord $record The channel, recipient, status, subject, locale and failure.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function recordHistory(DeliveryOrigin $origin, DeliveryRecord $record): void {
		if ($this->ledger->historyMapper === null) {
			return;
		}

		$object = $origin->object;
		try {
			$this->ledger->historyMapper->record(
				entry: new HistoryEntry(
					delivery: new HistoryDelivery(
						ruleId: $origin->ruleId,
						channel: $record->channel,
						recipient: $record->recipient,
						status: $record->status
					),
					target: new HistoryTarget(
						schemaId: self::storedId(value: $object->getSchema()),
						registerId: self::storedId(value: $object->getRegister()),
						objectUuid: self::storedId(value: $object->getUuid()),
						eventId: $origin->eventId
					),
					notice: new HistoryNotice(
						subject: $record->detail->subject,
						errorMessage: $record->detail->errorMessage,
						locale: $record->detail->locale
					)
				)
			);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf(
					'[AnnotationNotificationDispatcher] history record failed (rule=%s channel=%s): %s',
					$origin->ruleId,
					$record->channel,
					$e->getMessage()
				)
			);
		}//end try

	}//end recordHistory()

	/**
	 * An object identifier as a history column stores it: null when absent or empty.
	 *
	 * @param mixed $value The object's register, schema or uuid.
	 *
	 * @return string|null
	 */
	private static function storedId(mixed $value): ?string {
		if ($value === null || $value === '') {
			return null;
		}

		return (string)$value;
	}//end storedId()

	/**
	 * Decide whether the rule's organisation gate (if declared) lets
	 * the current object through.
	 *
	 * The rule may declare:
	 * - no `organisation` field — the gate is open and every object passes.
	 * - a single string — must match the object's organisation exactly.
	 * - an array of strings — any-of match: at least one entry must equal
	 *   the object's organisation.
	 *
	 * Matching is loose-equal-string (the saved organisation column may
	 * be a UUID or a slug; schema authors typically pin by the same
	 * representation they store). When the object has no organisation
	 * set, only rules without a gate (or rules whose gate explicitly
	 * lists `null`/empty-string) match — guarantees that org-pinned
	 * rules never fire for legacy un-tenanted data.
	 *
	 * @param array<string, mixed> $spec The notification spec block.
	 * @param ObjectEntity $object The object the event happened on.
	 *
	 * @return bool True when dispatch may proceed.
	 */
	private function organisationGateAllows(array $spec, ObjectEntity $object): bool {
		$pinned = ($spec['organisation'] ?? null);
		if ($pinned === null) {
			return true;
		}

		return $this->organisationPinMatches(pinned: $pinned, objectOrg: (string)($object->getOrganisation() ?? ''));
	}//end organisationGateAllows()

	/**
	 * Whether a declared organisation pin matches the object's organisation.
	 *
	 * @param mixed  $pinned    The rule's `organisation` value: a string, a list of strings, or anything else.
	 * @param string $objectOrg The object's organisation, '' when it has none.
	 *
	 * @return bool True only on an exact match; a malformed pin never matches.
	 */
	private function organisationPinMatches(mixed $pinned, string $objectOrg): bool {
		if (is_string($pinned) === true) {
			return $pinned === $objectOrg;
		}

		if (is_array($pinned) === true) {
			return $this->anyOrganisationPinMatches(pins: $pinned, objectOrg: $objectOrg);
		}

		// Malformed gate (not string / array) — fail closed so an
		// accidental misconfiguration doesn't silently leak the
		// notification cross-tenant.
		return false;
	}//end organisationPinMatches()

	/**
	 * Whether any string in an any-of organisation pin equals the object's organisation.
	 *
	 * @param array<int|string, mixed> $pins      The pinned organisations.
	 * @param string                   $objectOrg The object's organisation, '' when it has none.
	 *
	 * @return bool
	 */
	private function anyOrganisationPinMatches(array $pins, string $objectOrg): bool {
		foreach ($pins as $candidate) {
			if (is_string($candidate) === true && $candidate === $objectOrg) {
				return true;
			}
		}

		return false;
	}//end anyOrganisationPinMatches()

	/**
	 * Post a chat message into a Talk room.
	 *
	 * Uses the standard NC Talk REST API at
	 * /ocs/v2.php/apps/spreed/api/v1/chat/{token}. Goes through the
	 * server-local HTTP loopback so we avoid round-tripping the public
	 * URL — Talk routes are local. Skip silently when the Talk app
	 * isn't enabled (status check from the IAppManager would be ideal
	 * but we let the HTTP 404 path log at warning instead).
	 *
	 * @param array<string, mixed> $spec Notification spec block.
	 * @param string $message Already-interpolated subject.
	 *
	 * @return void
	 */
	private function emitTalk(array $spec, string $message): void {
		$talk = ($spec['talk'] ?? null);
		if (is_array($talk) === false) {
			return;
		}

		$token = (string)($talk['token'] ?? '');
		if ($token === '') {
			return;
		}

		// The shared Talk send unit — the same class the flow messaging
		// service invokes. The declarative path stays a best-effort bot
		// broadcast, so the outcome is not escalated here.
		$this->talkSender()->postAsBot(token: $token, message: $message);
	}//end emitTalk()

	/**
	 * POST a JSON payload to the configured webhook URL.
	 *
	 * @param RuleFiring $firing The rule, the object, its recipients and the default-locale subject.
	 *
	 * @return string|null Why the call did not land, or null when it did (or was deliberately skipped).
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-one-rule-reaches-a-person-and-an-integration-recorded-once-req-nrg-004
	 */
	private function emitWebhook(RuleFiring $firing): ?string {
		$hook = ($firing->spec['webhook'] ?? null);
		if (is_array($hook) === false) {
			return null;
		}

		// When the webhook is declared persistent, NotificationsAnnotationInstaller
		// has already provisioned a Webhook entity that the standard webhook
		// delivery pipeline (retry, HMAC, dead-letter) handles on the same
		// events. Skipping here prevents a double-fire (inline POST + pipeline
		// delivery) for the same notification.
		if (($hook['persistent'] ?? false) === true) {
			return null;
		}

		return $this->postWebhook(hook: $hook, firing: $firing);
	}//end emitWebhook()

	/**
	 * POST the firing to a declared, non-persistent webhook with a valid URL.
	 *
	 * The URL must be http or https. The call goes through Nextcloud's HTTP client,
	 * which pins the address it resolves and refuses local and private addresses at
	 * connect time, so the address checked is the address called; redirects are not
	 * followed.
	 *
	 * @param array<string, mixed> $hook   The rule's `webhook` block.
	 * @param RuleFiring           $firing The rule, the object, its recipients and the default-locale subject.
	 *
	 * @return string|null Why the call did not land, or null when it did.
	 */
	private function postWebhook(array $hook, RuleFiring $firing): ?string {
		$url = (string)($hook['url'] ?? '');
		if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
			return 'webhook url missing or malformed';
		}

		$scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
		if ($scheme !== 'http' && $scheme !== 'https') {
			return 'webhook url refused: only http and https are allowed';
		}

		return $this->sendWebhook(hook: $hook, options: $this->webhookOptions(hook: $hook, firing: $firing));
	}//end postWebhook()

	/**
	 * The request options of a webhook call: the JSON payload, the headers and the timeout.
	 *
	 * @param array<string, mixed> $hook   The rule's `webhook` block.
	 * @param RuleFiring           $firing The rule, the object, its recipients and the default-locale subject.
	 *
	 * @return array<string, mixed>
	 */
	private function webhookOptions(array $hook, RuleFiring $firing): array {
		$headers = [];
		if (is_array($hook['headers'] ?? null) === true) {
			$headers = $hook['headers'];
		}

		return [
			'json' => $this->webhookPayload(firing: $firing),
			'headers' => array_merge(['Content-Type' => 'application/json'], $headers),
			'timeout' => 5,
			'allow_redirects' => false,
			'nextcloud' => ['allow_local_address' => false],
		];
	}//end webhookOptions()

	/**
	 * The JSON body a webhook receives for this firing.
	 *
	 * @param RuleFiring $firing The rule, the object, its recipients and the default-locale subject.
	 *
	 * @return array<string, mixed>
	 */
	private function webhookPayload(RuleFiring $firing): array {
		return [
			'notification' => $firing->ruleId,
			'subject' => $firing->broadcastSubject,
			'object' => [
				'uuid' => (string)($firing->object->getUuid() ?? ''),
				'register' => $firing->object->getRegister(),
				'schema' => $firing->object->getSchema(),
				'data' => $firing->object->getObject() ?? [],
			],
			'recipients' => $firing->recipients,
			'context' => $firing->context,
			'timestamp' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
		];
	}//end webhookPayload()

	/**
	 * Make the webhook call, turning a failure into its logged message.
	 *
	 * @param array<string, mixed> $hook    The rule's `webhook` block: its `url` and `method`.
	 * @param array<string, mixed> $options The request options.
	 *
	 * @return string|null Why the call did not land, or null when it did.
	 */
	private function sendWebhook(array $hook, array $options): ?string {
		$url = (string)($hook['url'] ?? '');
		try {
			$client = $this->integrations->httpClient->newClient();
			$client->request(strtoupper((string)($hook['method'] ?? 'POST')), $url, $options);
			return null;
		} catch (\Throwable $e) {
			// Returned rather than rethrown: the transports of one firing are
			// independent, so a dead endpoint must leave the in-app notice
			// delivered and record itself as the one thing that failed.
			$this->logger->warning(
				sprintf('[AnnotationNotificationDispatcher] webhook %s failed: %s', $url, $e->getMessage())
			);
			return $e->getMessage();
		}
	}//end sendWebhook()

	/**
	 * Read a schema's `authorization.roles` assignment as a role-to-groups map.
	 *
	 * The same map `PermissionHandlerInterface` expands for a lifecycle transition, read
	 * here so a notification rule may address `{"kind": "role", "role": "..."}`
	 * in the schema's own vocabulary rather than repeating the group ids the
	 * assignment happens to hold today.
	 *
	 * @param Schema $schema The schema whose authorization block carries the assignment.
	 *
	 * @return array<string, array<int, string>> Role name to assigned Nextcloud group ids.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-group-or-a-declared-role-is-a-recipient-req-nrg-001
	 */
	private function roleGroupsFor(Schema $schema): array {
		try {
			$authorization = $schema->getAuthorization();
		} catch (\Throwable $e) {
			return [];
		}

		if (is_array($authorization) === false || is_array(($authorization['roles'] ?? null)) === false) {
			return [];
		}

		return $this->roleGroupMap(roles: $authorization['roles']);
	}//end roleGroupsFor()

	/**
	 * The named roles of an `authorization.roles` block, each with its string group ids.
	 *
	 * @param array<mixed, mixed> $roles Role name to assigned groups, as declared.
	 *
	 * @return array<string, array<int, string>> Role name to assigned Nextcloud group ids.
	 */
	private function roleGroupMap(array $roles): array {
		$map = [];
		foreach ($roles as $roleName => $groups) {
			if (is_string($roleName) === false || $roleName === '') {
				continue;
			}

			$map[$roleName] = array_values(array_filter((array)$groups, 'is_string'));
		}

		return $map;
	}//end roleGroupMap()

	/**
	 * Record a recipient entry that named something the server does not have.
	 *
	 * A rule addressing a deleted group resolves to nobody, and a dispatch to
	 * nobody is indistinguishable from a dispatch that was never wanted. The
	 * history row is what separates them: it carries the group (or role) in the
	 * recipient column and `recipient-unresolved` as its status, so the fault is
	 * readable rather than a silence (ADR-005).
	 *
	 * @param RuleFiring $firing The rule that named it, its triggering object and event identifier.
	 * @param array<int, array{kind: string, id: string, reason: string}> $unresolved The resolver's report.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md#requirement-a-group-or-a-declared-role-is-a-recipient-req-nrg-001
	 */
	private function recordUnresolvedRecipients(RuleFiring $firing, array $unresolved): void {
		$origin = DeliveryOrigin::ofFiring(firing: $firing);
		foreach ($unresolved as $entry) {
			$this->logger->warning(
				sprintf(
					'[AnnotationNotificationDispatcher] rule="%s" addressed %s "%s" which did not resolve (%s)',
					$firing->ruleId,
					$entry['kind'],
					$entry['id'],
					$entry['reason']
				)
			);

			$this->recordHistory(
				origin: $origin,
				record: new DeliveryRecord(
					channel: $entry['kind'],
					recipient: $entry['id'],
					status: 'recipient-unresolved',
					detail: new DeliveryDetail(errorMessage: $entry['reason'])
				)
			);
		}

	}//end recordUnresolvedRecipients()

	/**
	 * Verify that a uid corresponds to an actual Nextcloud user.
	 *
	 * Delegates to the shared recipient resolver, whose per-request cache and
	 * only-cache-definitive-verdicts posture this method used to carry.
	 *
	 * @param string $uid Candidate Nextcloud user identifier.
	 *
	 * @return bool True when the uid corresponds to a real Nextcloud user.
	 */
	private function userExists(string $uid): bool {
		return $this->recipientResolver()->userExists(uid: $uid);
	}//end userExists()

	/**
	 * Resolve a Nextcloud user's preferred locale.
	 *
	 * Reads `core.lang` from the user's NC config — the same value
	 * Nextcloud's own UI consults for translations. Returns the bare
	 * 2-letter language code (`nl`, `en`, …) so the result aligns with
	 * the per-locale subject map keys. Returns null when the IConfig
	 * dependency is absent (older test fixtures) or when the user has
	 * no preference set; callers fall through to the default locale
	 * fallback chain in `resolveLocalizedSubject()`.
	 *
	 * @param string $uid Nextcloud user identifier.
	 *
	 * @return string|null The 2-letter language code, or null when unknown.
	 */
	private function resolveUserLocale(string $uid): ?string {
		if ($this->audience->config === null) {
			return null;
		}

		try {
			$raw = $this->audience->config->getUserValue($uid, 'core', 'lang', '');
		} catch (\Throwable $e) {
			return null;
		}

		return $this->bareLanguage(raw: $raw);
	}//end resolveUserLocale()

	/**
	 * The bare language of a stored `core.lang` value.
	 *
	 * @param mixed $raw The stored value.
	 *
	 * @return string|null The lower-case 2-letter language code, or null when nothing is stored.
	 */
	private function bareLanguage(mixed $raw): ?string {
		if (is_string($raw) === false || $raw === '') {
			return null;
		}

		// NC stores values like `nl`, `en_GB`, `de_DE`: the region suffix goes,
		// so the lookup matches the ISO 639-1 keys of the per-locale subject map.
		return strtolower(explode('_', $raw, 2)[0]);
	}//end bareLanguage()

	/**
	 * The register's default language, then its other languages.
	 *
	 * @param ObjectEntity $object The object being dispatched.
	 *
	 * @return array<int, string> Language codes, empty when the register is unknown.
	 *
	 * @spec openspec/changes/notification-links-in-releases-and-case-insensitive-order/specs/notificatie-engine/spec.md#requirement-a-translatable-value-must-fill-its-placeholder
	 */
	private function resolveRegisterLanguages(ObjectEntity $object): array {
		try {
			$register = $this->objectRegister(object: $object);
		} catch (\Throwable $e) {
			return [];
		}

		if ($register === null) {
			return [];
		}

		$languages = array_merge([$register->getDefaultLanguage()], ($register->getLanguages() ?? []));

		return array_values(array_unique(array_filter($languages, static fn ($code): bool => is_string($code) === true && $code !== '')));
	}//end resolveRegisterLanguages()

	/**
	 * The register of the object being dispatched, null when it is unknown.
	 *
	 * @param ObjectEntity $object The object.
	 *
	 * @return Register|null
	 */
	private function objectRegister(ObjectEntity $object): ?Register {
		$registerId = $object->getRegister();
		if ($this->audience->registerMapper === null || $registerId === null || (string)$registerId === '') {
			return null;
		}

		return $this->audience->registerMapper->find($registerId, _rbac: false, _multitenancy: false);
	}//end objectRegister()

	/**
	 * Map a trigger to the canonical INotification subject the Notifier
	 * renders. Decouples the displayed subject from the schema author's
	 * rule name so every schema-declared in-app notification renders.
	 *
	 * @param string $trigger 'created' | 'updated' | 'transition' | 'calculatedChange'.
	 *
	 * @return string The canonical subject identifier.
	 */
	private function canonicalSubject(string $trigger): string {
		return match ($trigger) {
			'created' => 'object_created',
			'transition' => 'object_transitioned',
			default => 'object_updated',
		};
	}//end canonicalSubject()

	/**
	 * Resolve the rule's originApp: the declared value, else the app owning
	 * the schema's register, else 'openregister'.
	 *
	 * @param array<string, mixed> $spec The notification rule spec.
	 * @param ObjectEntity $object The triggering object.
	 *
	 * @return string The resolved origin app id.
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 */
	private function resolveOriginApp(array $spec, ObjectEntity $object): string {
		$declared = ($spec['originApp'] ?? null);
		if (is_string($declared) === true && $declared !== '') {
			return $declared;
		}

		// Default: the app that owns the schema's register.
		return $this->owningAppOf(object: $object);
	}//end resolveOriginApp()

	/**
	 * The app that owns the object's register, else 'openregister'.
	 *
	 * @param ObjectEntity $object The triggering object.
	 *
	 * @return string The owning app id.
	 */
	private function owningAppOf(ObjectEntity $object): string {
		try {
			$owningApp = $this->objectRegister(object: $object)?->getApplication();
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[AnnotationNotificationDispatcher] originApp register lookup failed: %s', $e->getMessage())
			);
			return 'openregister';
		}

		if (is_string($owningApp) === true && $owningApp !== '') {
			return $owningApp;
		}

		return 'openregister';
	}//end owningAppOf()

	/**
	 * Enqueue a background web-push dispatch job per recipient.
	 *
	 * Push I/O (VAPID signing + aes128gcm encryption + endpoint POST) runs in
	 * WebPushDispatchJob so the originating object-save request returns
	 * immediately. Anonymous / non-uid recipients are skipped (web-push is
	 * user+browser scoped).
	 *
	 * @param RuleFiring $firing The rule, its recipients, default-locale text and actions.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/notificatie-engine/spec.md
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	private function enqueueWebPush(RuleFiring $firing): void {
		if ($this->integrations->jobList === null) {
			$this->logger->debug('[AnnotationNotificationDispatcher] web-push declared but IJobList unavailable.');
			return;
		}

		// The shared ride-along unit — the same class the flow messaging
		// service rides web-push along with. Recipients are pre-verified here
		// so the unit's own check is a second, cheap gate.
		$this->ncSender()->enqueuePush(
			push: new PushRideAlong(
				recipients: array_values(
					array_filter(
						$firing->recipients,
						fn (mixed $uid): bool => is_string($uid) === true && $uid !== '' && $this->userExists(uid: $uid) === true
					)
				),
				object: $firing->object,
				rule: new NoticeRule(name: $firing->ruleId, originApp: $firing->originApp, actions: $firing->actions),
				content: new NoticeContent(subjectKey: '', subject: $firing->broadcastSubject, message: $firing->broadcastMessage)
			)
		);
	}//end enqueueWebPush()

	/**
	 * Send a rule addressed to the parties on an object, over their own addresses.
	 *
	 * A party without a Nextcloud account cannot be a resolved uid, so the
	 * `parties` kind never reaches the per-recipient loop above. It is sent
	 * here, through the one unit that knows how a party is reached: the
	 * correspondence address it holds, and nothing at all when an indicator on
	 * the party refuses the send.
	 *
	 * The service is resolved from the container rather than injected. It
	 * reaches the object layer, which dispatches notifications, so a
	 * constructor dependency would close that cycle.
	 *
	 *
	 * @param RuleFiring $firing The rule, its recipients, channels and the mail's subject, body and category.
	 *
	 * @return int How many people the party path reached.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-a-party-without-an-account-carries-its-own-fields-and-is-reachable-req-prm-002
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-party-mail-carries-the-rule-s-message-as-its-body-req-ero-006
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-a-parties-notification-asks-integriq-before-it-mails-a-party-req-ero-003
	 */
	private function dispatchToParties(RuleFiring $firing): int {
		// Returns a COUNT rather than void, because "this rule reached nobody"
		// cannot be decided from the account recipients alone: a rule addressed
		// to `parties` legitimately resolves to zero accounts while still
		// reaching people by e-mail. Reporting those as unreachable would be a
		// false alarm on every party-addressed rule.
		$reached = 0;

		if ($firing->hasChannel('email') === false) {
			return $reached;
		}

		$objectUuid = (string)($firing->object->getUuid() ?? '');
		if ($objectUuid === '') {
			return $reached;
		}

		foreach ($firing->recipientsSpec() as $recipient) {
			if (is_array($recipient) === false || (string)($recipient['kind'] ?? '') !== 'parties') {
				continue;
			}

			$role = trim((string)($recipient['role'] ?? ''));
			$onlyRole = null;
			if ($role !== '') {
				$onlyRole = $role;
			}

			$sent = $this->integrations->serverContainer->get(PartyNotificationService::class)->notifyParties(
				message: new PartyMessage(objectUuid: $objectUuid, subject: $firing->broadcastSubject, body: $firing->addressBody, category: $firing->category),
				role: $onlyRole
			);

			foreach ($sent as $outcome) {
				$reached++;
				$this->recordHistory(
					origin: new DeliveryOrigin(ruleId: $firing->ruleId, object: $firing->object),
					record: new DeliveryRecord(
						channel: 'email',
						recipient: 'party:' . $outcome['party'],
						status: $outcome['outcome'],
						detail: new DeliveryDetail(subject: $firing->broadcastSubject)
					)
				);
			}
		}//end foreach

		return $reached;
	}//end dispatchToParties()

	/**
	 * Send a rule's `email` entries: addresses read through a reference.
	 *
	 * Resolved from the container for the same reason as the party service:
	 * it reaches the object layer, which dispatches notifications. A rule
	 * without an `email` entry never resolves it.
	 *
	 *
	 * @param RuleFiring $firing The rule, its recipients, channels and the mail's subject, body and category.
	 *
	 * @return int How many entries were handed to the mailer.
	 *
	 * @spec openspec/specs/external-recipient-opt-out/spec.md#requirement-an-email-recipient-reads-its-address-through-a-reference-req-ero-007
	 */
	private function dispatchToAddresses(RuleFiring $firing): int {
		if ($firing->hasChannel('email') === false) {
			return 0;
		}

		$hasEntry = false;
		foreach ($firing->recipientsSpec() as $recipient) {
			if (is_array($recipient) === true && (string)($recipient['kind'] ?? '') === ReferencedAddressNotifierInterface::KIND) {
				$hasEntry = true;
				break;
			}
		}

		if ($hasEntry === false) {
			return 0;
		}

		$outcomes = $this->integrations->serverContainer->get(ReferencedAddressNotifierInterface::class)->mail(
			origin: new ReferenceOrigin(object: $firing->object, data: $firing->data, schema: $firing->schema),
			request: new MailingRequest(
				recipients: $firing->recipientsSpec(),
				subject: $firing->broadcastSubject,
				body: $firing->addressBody,
				category: $firing->category
			)
		);

		$reached = 0;
		foreach ($outcomes as $outcome) {
			if ($outcome['outcome'] === EmailSender::OUTCOME_DISPATCHED) {
				$reached++;
			}

			$this->recordHistory(
				origin: new DeliveryOrigin(ruleId: $firing->ruleId, object: $firing->object),
				record: new DeliveryRecord(
					channel: 'email',
					recipient: 'field:' . $outcome['field'],
					status: $outcome['outcome'],
					detail: new DeliveryDetail(subject: $firing->broadcastSubject)
				)
			);
		}

		return $reached;
	}//end dispatchToAddresses()

	/**
	 * Publish an entry to the Nextcloud Activity stream.
	 *
	 * The entry is filed under the origin's rule name and points at the
	 * origin's object uuid, or at the rule name when the object has none.
	 *
	 * @param DeliveryOrigin $origin The rule name and object the entry is filed under.
	 * @param DeliveryRecord $record The affected user and the pre-interpolated activity text.
	 *
	 * @return void
	 */
	private function emitActivity(DeliveryOrigin $origin, DeliveryRecord $record): void {
		$uid       = $record->recipient;
		$name      = $origin->ruleId;
		$subject   = (string)$record->detail->subject;
		$objectRef = (string)($origin->object->getUuid() ?? '');
		if ($objectRef === '') {
			$objectRef = $name;
		}

		try {
			$event = $this->channels->activityManager->generateEvent();
			$event
				->setApp('openregister')
				->setType('openregister_objects')
				->setAffectedUser($uid)
				->setSubject($name, ['_text' => $subject])
				->setObject('object', 0, $objectRef)
				->setTimestamp(time());
			$this->channels->activityManager->publish($event);
		} catch (\Throwable $e) {
			$this->logger->debug(
				sprintf('[AnnotationNotificationDispatcher] activity to "%s" failed (%s)', $uid, $e->getMessage())
			);
		}
	}//end emitActivity()

	/**
	 * Resolve the schema referenced by an object, returning null on failure.
	 *
	 * @param ObjectEntity $object The object whose schema should be looked up.
	 *
	 * @return Schema|null The resolved schema, or null when missing/unresolvable.
	 */
	private function loadSchema(ObjectEntity $object): ?Schema {
		$ref = $object->getSchema();
		if ($ref === null || $ref === '') {
			return null;
		}

		try {
			return $this->audience->schemaMapper->find($ref, _multitenancy: false);
		} catch (\Throwable) {
			return null;
		}
	}//end loadSchema()

	/**
	 * Pull the `x-openregister-notifications` annotation off a schema.
	 *
	 * @param Schema $schema The schema whose annotation should be read.
	 *
	 * @return array<string, mixed>|null
	 */
	private function getAnnotation(Schema $schema): ?array {
		$config = ($schema->getConfiguration() ?? []);
		$value = ($config['x-openregister-notifications'] ?? null);
		if (is_array($value) === true) {
			return $value;
		}

		return null;
	}//end getAnnotation()

	/**
	 * Filter resolved recipients down to users who have subscribed to
	 * the object's (register, schema). A recipient is a uid string, as the
	 * recipient resolver returns it, or a `{kind, uid}` entry. Entries that
	 * are not a user (email literals, webhook urls, etc) pass through
	 * unchanged because the subscription store is user-scoped only.
	 *
	 * Null-safe: when the SubscriptionMapper isn't wired (legacy
	 * fixtures) or the object lacks register/schema metadata, the
	 * filter is a no-op and every recipient is kept.
	 *
	 * @param array<int, mixed> $recipients The resolved recipient list.
	 * @param ObjectEntity $object The object the event fired on.
	 *
	 * @return array<int, mixed>
	 */
	private function filterBySubscription(array $recipients, ObjectEntity $object): array {
		if ($this->gates->subscriptionMapper === null) {
			return $recipients;
		}

		$registerId = $object->getRegister();
		$schemaId = $object->getSchema();
		if (is_numeric($registerId) === false || is_numeric($schemaId) === false) {
			return $recipients;
		}

		try {
			$subscribed = $this->gates->subscriptionMapper->findSubscribedUids(
				registerId: (int)$registerId,
				schemaId: (int)$schemaId
			);
		} catch (\Throwable $e) {
			// A query failure MUST NOT block dispatch — log and pass
			// every recipient through.
			$this->logger->warning(
				'[AnnotationNotificationDispatcher] subscription lookup failed: ' . $e->getMessage(),
				['file' => __FILE__, 'line' => __LINE__]
			);
			return $recipients;
		}

		$subscribedSet = array_flip($subscribed);

		return array_values(
			array_filter(
				$recipients,
				fn (mixed $recipient): bool => $this->keepsSubscribedRecipient(recipient: $recipient, subscribedSet: $subscribedSet)
			)
		);

	}//end filterBySubscription()

	/**
	 * Whether a recipient survives the subscription filter.
	 *
	 * A uid string, or a `{kind: user, uid}` entry, survives only when the
	 * uid subscribed. Entries that are not a user (email literals, webhook
	 * urls, talk rooms) bypass the filter so legacy wire shapes still
	 * receive notifications.
	 *
	 * @param mixed              $recipient     A uid or a recipient entry.
	 * @param array<string, int> $subscribedSet The subscribed uids, as keys.
	 *
	 * @return bool
	 */
	private function keepsSubscribedRecipient(mixed $recipient, array $subscribedSet): bool {
		if (is_string($recipient) === true) {
			return isset($subscribedSet[$recipient]);
		}

		if (is_array($recipient) === false) {
			return true;
		}

		return $this->keepsSubscribedEntry(entry: $recipient, subscribedSet: $subscribedSet);
	}//end keepsSubscribedRecipient()

	/**
	 * Whether a recipient entry survives the subscription filter: only a user entry is filtered.
	 *
	 * @param array<string, mixed> $entry         A recipient entry.
	 * @param array<string, int>   $subscribedSet The subscribed uids, as keys.
	 *
	 * @return bool
	 */
	private function keepsSubscribedEntry(array $entry, array $subscribedSet): bool {
		$kind = ($entry['kind'] ?? null);
		$uid  = ($entry['uid'] ?? null);
		if ($kind !== 'user' || is_string($uid) === false || $uid === '') {
			return true;
		}

		return isset($subscribedSet[$uid]);
	}//end keepsSubscribedEntry()
}//end class
