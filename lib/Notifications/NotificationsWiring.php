<?php

/**
 * NotificationsRegistrar: container registrations of the Notifications component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Notifications
 * @package  OCA\OpenRegister\Notifications
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

namespace OCA\OpenRegister\Notifications;

use OCA\OpenRegister\Kernel\Contracts\NotificationPreferencesInterface;
use OCA\OpenRegister\Notifications\Db\MessageMapper;
use OCA\OpenRegister\Notifications\Db\MessageMapperInterface;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapper;
use OCA\OpenRegister\Notifications\Db\NotificationHistoryMapperInterface;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapper;
use OCA\OpenRegister\Notifications\Db\QueuedNotificationMapperInterface;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapper;
use OCA\OpenRegister\Notifications\Db\ScheduledReportMapperInterface;
use OCA\OpenRegister\Notifications\Db\WatcherMapper;
use OCA\OpenRegister\Notifications\Db\WatcherMapperInterface;
use OCA\OpenRegister\Notifications\Search\TimelineEntriesProvider;
use OCA\OpenRegister\Notifications\Service\EmailService;
use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineEntries;
use OCA\OpenRegister\Notifications\Service\ExampleSet\ExampleSetTimelineRowsInterface;
use OCA\OpenRegister\Notifications\Service\EmailServiceInterface;
use OCA\OpenRegister\Notifications\Service\Notification\AnnotationNotificationDispatcher;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSender;
use OCA\OpenRegister\Notifications\Service\Notification\EmailSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSender;
use OCA\OpenRegister\Notifications\Service\Notification\NcNotificationSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidator;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationAnnotationValidatorInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicy;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationChannelPolicyInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingService;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationClearingServiceInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationPreferenceService;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationPreferenceServiceInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolver;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRecipientResolverInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationRuleDispatcherInterface;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplating;
use OCA\OpenRegister\Notifications\Service\Notification\NotificationTemplatingInterface;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthority;
use OCA\OpenRegister\Notifications\Service\Notification\OptOutAuthorityInterface;
use OCA\OpenRegister\Notifications\Service\Notification\RateLimiter;
use OCA\OpenRegister\Notifications\Service\Notification\RateLimiterInterface;
use OCA\OpenRegister\Notifications\Service\Notification\RuleReachRecorder;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSender;
use OCA\OpenRegister\Notifications\Service\Notification\TalkSenderInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRules;
use OCA\OpenRegister\Notifications\Service\Notification\TaskNotificationRulesInterface;
use OCA\OpenRegister\Notifications\Service\Notification\TaskTransitionNotifier;
use OCA\OpenRegister\Notifications\Service\Notification\TaskTransitionNotifierInterface;
use OCA\OpenRegister\Notifications\Service\PresenceService;
use OCA\OpenRegister\Notifications\Service\PresenceServiceInterface;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityService;
use OCA\OpenRegister\Notifications\Service\TimelineVisibilityServiceInterface;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimeline;
use OCA\OpenRegister\Notifications\Service\Timeline\PublicTimelineInterface;
use OCA\OpenRegister\Notifications\Service\WebPush\HexIconService;
use OCA\OpenRegister\Notifications\Service\Webhook\CloudEventFormatter;
use OCA\OpenRegister\Notifications\Service\Webhook\CloudEventFormatterInterface;
use OCA\OpenRegister\Notifications\Service\Webhook\WebhookInterceptionCache;
use OCA\OpenRegister\Notifications\Service\Webhook\WebhookInterceptionCacheInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\App\IAppManager;
use OCP\Files\AppData\IAppDataFactory;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * The wiring behind NotificationsRegistrar.
 *
 * NotificationsRegistrar keeps the public entry points and delegates each of them here.
 *
 * Registers the services of the Notifications component that the container cannot
 * autowire, and binds the interfaces other components type against.
 */
final class NotificationsWiring {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		NotificationRuleDispatcherInterface::class => AnnotationNotificationDispatcher::class,
		NotificationPreferenceServiceInterface::class => NotificationPreferenceService::class,
		NotificationRecipientResolverInterface::class => NotificationRecipientResolver::class,
		EmailServiceInterface::class => EmailService::class,
		NotificationHistoryMapperInterface::class => NotificationHistoryMapper::class,
		TaskNotificationRulesInterface::class => TaskNotificationRules::class,
		RateLimiterInterface::class => RateLimiter::class,
		OptOutAuthorityInterface::class => OptOutAuthority::class,
		WatcherMapperInterface::class => WatcherMapper::class,
		WebhookInterceptionCacheInterface::class => WebhookInterceptionCache::class,
		TimelineVisibilityServiceInterface::class => TimelineVisibilityService::class,
		NcNotificationSenderInterface::class => NcNotificationSender::class,
		PresenceServiceInterface::class => PresenceService::class,
		NotificationTemplatingInterface::class => NotificationTemplating::class,
		CloudEventFormatterInterface::class => CloudEventFormatter::class,
		TalkSenderInterface::class => TalkSender::class,
		PublicTimelineInterface::class => PublicTimeline::class,
		QueuedNotificationMapperInterface::class => QueuedNotificationMapper::class,
		EmailSenderInterface::class => EmailSender::class,
		MessageMapperInterface::class => MessageMapper::class,
		NotificationPreferencesInterface::class => NotificationPreferenceService::class,
		NotificationClearingServiceInterface::class => NotificationClearingService::class,
		NotificationChannelPolicyInterface::class => NotificationChannelPolicy::class,
		ScheduledReportMapperInterface::class => ScheduledReportMapper::class,
		NotificationAnnotationValidatorInterface::class => NotificationAnnotationValidator::class,
		TaskTransitionNotifierInterface::class => TaskTransitionNotifier::class,
		ExampleSetTimelineRowsInterface::class => ExampleSetTimelineEntries::class,
	];

	/**
	 * Register the Notifications component's services.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		$context->registerService(HexIconService::class, self::buildHexIconService(...));

		// One recorder for the whole container, so what it counted survives
		// the dispatcher instance that counted it: the dispatcher reads it from
		// its DeliveryLedger, which the container autowires with this one
		// (a-rule-that-reaches-nobody-says-so, task 3.1).
		$context->registerService(RuleReachRecorder::class, self::buildRuleReachRecorder(...), true);

		// The entries beside the objects. A provider of its own, because a Woo
		// request asks for every mention of a subject and the answer is a
		// list of entries, each naming the case it sits on.
		$context->registerSearchProvider(TimelineEntriesProvider::class);

		foreach (self::BINDINGS as $contract => $service) {
			$context->registerServiceAlias($contract, $service);
		}
	}//end register()

	/**
	 * Build the HexIconService with the app's own app data folder.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return HexIconService
	 */
	private static function buildHexIconService(ContainerInterface $container): HexIconService {
		return new HexIconService(
			appManager: $container->get(IAppManager::class),
			appData: $container->get(IAppDataFactory::class)->get('openregister'),
			logger: $container->get(LoggerInterface::class)
		);
	}//end buildHexIconService()

	/**
	 * Build the one RuleReachRecorder of this container.
	 *
	 * @param ContainerInterface $container The container.
	 *
	 * @return RuleReachRecorder
	 *
	 * @spec openspec/changes/a-rule-that-reaches-nobody-says-so/specs/notificatie-engine/spec.md#requirement-the-record-reaches-a-person-not-only-a-log-req-rrn-03
	 */
	private static function buildRuleReachRecorder(ContainerInterface $container): RuleReachRecorder {
		return new RuleReachRecorder(logger: $container->get(LoggerInterface::class));
	}//end buildRuleReachRecorder()
}//end class
