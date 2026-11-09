<?php

/**
 * OpenRegister Web Push Dispatch Job
 *
 * Out-of-band background job that performs the Web Push send for one
 * recipient. Enqueued by AnnotationNotificationDispatcher::enqueueWebPush()
 * via `IJobList::add(WebPushDispatchJob::class, $args)` so the originating
 * object-save request never blocks on VAPID signing / aes128gcm encryption /
 * push-service POST. This is a one-shot QueuedJob: the scheduler runs it once
 * and removes it (no `registerJob` / RepairStep mis-registration — the fleet
 * bug where jobs were registered through an unsupported IRegistrationContext
 * call and never ran is deliberately avoided; QueuedJob.run() auto-executes
 * for every IJobList-added argument set).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category BackgroundJob
 * @package  OCA\OpenRegister\BackgroundJob
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Notifications\BackgroundJob;

use OCA\OpenRegister\Notifications\Service\WebPush\WebPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot background job that delivers a Web Push notification to one user.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/web-push-delivery/spec.md
 */
class WebPushDispatchJob extends QueuedJob {
	/**
	 * Constructor.
	 *
	 * @param ITimeFactory $time Time factory for the base job.
	 * @param WebPushService $webPushService Encrypt/sign/POST/prune service.
	 * @param LoggerInterface $logger Logger for delivery diagnostics.
	 *
	 * @return void
	 */
	public function __construct(
		ITimeFactory $time,
		private readonly WebPushService $webPushService,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(time: $time);
	}//end __construct()

	/**
	 * Run the job: build the payload and deliver it to the recipient.
	 *
	 * @param array<string, mixed> $argument Job arguments:
	 *                                       - uid: recipient uid (required)
	 *                                       - ruleId: rule id (diagnostics)
	 *                                       - originApp: origin app id (icon)
	 *                                       - title: notification title
	 *                                       - body: notification body
	 *                                       - tag: dedup/collapse tag
	 *                                       - actions: resolved action buttons
	 *
	 * @return void
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	protected function run($argument): void {
		$uid = ($argument['uid'] ?? null);
		if (is_string($uid) === false || $uid === '') {
			$this->logger->warning('[WebPushDispatchJob] Missing recipient uid; skipping.');
			return;
		}

		$payload = $this->buildPayload(argument: $argument);

		try {
			$delivered = $this->webPushService->deliver(uid: $uid, payload: $payload);
			$this->logger->debug(
				sprintf('[WebPushDispatchJob] Delivered web-push to %d endpoint(s) for "%s".', $delivered, $uid)
			);
		} catch (\Throwable $e) {
			// Web-push is best-effort and out of band — never escalate.
			$this->logger->warning(
				sprintf('[WebPushDispatchJob] Delivery to "%s" failed: %s', $uid, $e->getMessage())
			);
		}
	}//end run()

	/**
	 * Build the Service-Worker-facing JSON payload from the job argument.
	 *
	 * Shape matches js/openregister-push-sw.js (title/body/icon/badge/tag/url/
	 * actions[].action/.title/.url).
	 *
	 * @param array<string, mixed> $argument The job argument.
	 *
	 * @return array<string, mixed> The payload.
	 *
	 * @spec openspec/specs/web-push-delivery/spec.md
	 */
	private function buildPayload(array $argument): array {
		$originApp = (string)($argument['originApp'] ?? 'openregister');
		$title = (string)($argument['title'] ?? 'OpenRegister');
		$body = (string)($argument['body'] ?? '');
		$tag = (string)($argument['tag'] ?? '');

		// Two parallel structures matching the Service Worker contract
		// (js/openregister-push-sw.js): `actions` is [{action, title}] passed to
		// showNotification() to render the buttons; `data.actions` is a
		// { actionId: url } map used by notificationclick to route a clicked
		// button, and `data.url` routes a click on the notification body.
		$buttons = $this->actionButtons(rawActions: ($argument['actions'] ?? []));

		return [
			'title' => $title,
			'body' => $body,
			'icon' => '/index.php/apps/openregister/webpush/icon/' . rawurlencode($originApp),
			'badge' => '/index.php/apps/openregister/webpush/badge/' . rawurlencode($originApp),
			'tag' => $tag,
			'actions' => $buttons['actions'],
			'data' => [
				'url' => $buttons['url'],
				'actions' => $buttons['urls'],
			],
		];
	}//end buildPayload()

	/**
	 * The action buttons, their click routes and the body-click deeplink.
	 *
	 * @param mixed $rawActions The job argument's `actions`.
	 *
	 * @return array{actions: list<array{action: string, title: string}>, urls: array<string, string>, url: string}
	 */
	private function actionButtons(mixed $rawActions): array {
		$buttons = ['actions' => [], 'urls' => [], 'url' => ''];
		if (is_array($rawActions) === false) {
			return $buttons;
		}

		foreach ($rawActions as $idx => $action) {
			$url = $this->actionUrl(action: $action);
			if ($url === '') {
				continue;
			}

			// First (or primary) action drives the body-click deeplink.
			$buttons['url'] = $this->bodyClickUrl(current: $buttons['url'], url: $url, action: $action);

			$actionKey = 'action-' . (string)$idx;
			$buttons['actions'][] = ['action' => $actionKey, 'title' => $this->actionLabel(action: $action)];
			$buttons['urls'][$actionKey] = $url;
		}

		return $buttons;
	}//end actionButtons()

	/**
	 * An action's URL; empty when the action is not an array or has none.
	 *
	 * @param mixed $action One declared action.
	 *
	 * @return string The URL.
	 */
	private function actionUrl(mixed $action): string {
		if (is_array($action) === false) {
			return '';
		}

		return (string)($action['url'] ?? '');
	}//end actionUrl()

	/**
	 * The body-click deeplink after this action: the first URL, overridden by a primary action.
	 *
	 * @param string               $current The deeplink so far.
	 * @param string               $url     This action's URL.
	 * @param array<string, mixed> $action  This action.
	 *
	 * @return string The deeplink.
	 */
	private function bodyClickUrl(string $current, string $url, array $action): string {
		if ($current === '' || ($action['primary'] ?? false) === true) {
			return $url;
		}

		return $current;
	}//end bodyClickUrl()

	/**
	 * An action's button title: the English label, else the first label, else empty.
	 *
	 * @param array<string, mixed> $action This action.
	 *
	 * @return string The title.
	 */
	private function actionLabel(array $action): string {
		if (is_array($action['label'] ?? null) === false) {
			return '';
		}

		$fallbackLabel = '';
		$firstLabel = reset($action['label']);
		if ($firstLabel !== false) {
			$fallbackLabel = $firstLabel;
		}

		return (string)($action['label']['en'] ?? $fallbackLabel);
	}//end actionLabel()
}//end class
