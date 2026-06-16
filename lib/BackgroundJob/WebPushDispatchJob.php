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
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\BackgroundJob;

use OCA\OpenRegister\Service\WebPush\WebPushService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * One-shot background job that delivers a Web Push notification to one user.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
 */
class WebPushDispatchJob extends QueuedJob
{

    /**
     * Constructor.
     *
     * @param ITimeFactory    $time           Time factory for the base job.
     * @param WebPushService  $webPushService Encrypt/sign/POST/prune service.
     * @param LoggerInterface $logger         Logger for delivery diagnostics.
     *
     * @return void
     */
    public function __construct(
        ITimeFactory $time,
        private readonly WebPushService $webPushService,
        private readonly LoggerInterface $logger
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
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    protected function run($argument): void
    {
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
     * @spec openspec/changes/openregister-web-push-engine/specs/web-push-delivery/spec.md
     */
    private function buildPayload(array $argument): array
    {
        $originApp = (string) ($argument['originApp'] ?? 'openregister');
        $title     = (string) ($argument['title'] ?? 'OpenRegister');
        $body      = (string) ($argument['body'] ?? '');
        $tag       = (string) ($argument['tag'] ?? '');

        $actions    = [];
        $topLevelUrl = '';
        $rawActions = ($argument['actions'] ?? []);
        if (is_array($rawActions) === true) {
            foreach ($rawActions as $idx => $action) {
                if (is_array($action) === false) {
                    continue;
                }

                $url = (string) ($action['url'] ?? '');
                if ($url === '') {
                    continue;
                }

                if ($topLevelUrl === '') {
                    // First (or primary) action drives the body-click deeplink.
                    $topLevelUrl = $url;
                }

                if (($action['primary'] ?? false) === true) {
                    $topLevelUrl = $url;
                }

                $label = '';
                if (is_array($action['label'] ?? null) === true) {
                    $label = (string) ($action['label']['en'] ?? (reset($action['label']) !== false ? reset($action['label']) : ''));
                }

                $actions[] = [
                    'action' => 'action-'.(string) $idx,
                    'title'  => $label,
                    'url'    => $url,
                ];
            }//end foreach
        }//end if

        return [
            'title'   => $title,
            'body'    => $body,
            'icon'    => '/index.php/apps/openregister/webpush/icon/'.rawurlencode($originApp),
            'badge'   => '/index.php/apps/openregister/webpush/badge/'.rawurlencode($originApp),
            'tag'     => $tag,
            'url'     => $topLevelUrl,
            'actions' => $actions,
        ];
    }//end buildPayload()
}//end class
