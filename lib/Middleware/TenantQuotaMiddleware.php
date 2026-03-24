<?php

/**
 * Tenant Quota Middleware
 *
 * Enforces per-organisation request quotas, bandwidth quotas, and organisation
 * status checks before controller execution. Uses APCu for high-performance
 * counter management.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use DateTime;
use OCA\OpenRegister\Service\OrganisationService;
use OCA\OpenRegister\Service\TenantLifecycleService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Middleware that enforces tenant quotas and organisation status.
 *
 * @package OCA\OpenRegister\Middleware
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TenantQuotaMiddleware extends Middleware
{
    /**
     * Environment-based request quota multipliers.
     */
    private const ENV_REQUEST_MULTIPLIER = [
        TenantLifecycleService::ENV_DEVELOPMENT => 10,
        TenantLifecycleService::ENV_TEST        => 5,
        TenantLifecycleService::ENV_ACCEPTANCE  => 2,
        TenantLifecycleService::ENV_PRODUCTION  => 1,
    ];

    /**
     * Environment-based bandwidth quota multipliers.
     */
    private const ENV_BANDWIDTH_MULTIPLIER = [
        TenantLifecycleService::ENV_DEVELOPMENT => 5,
        TenantLifecycleService::ENV_TEST        => 3,
        TenantLifecycleService::ENV_ACCEPTANCE  => 2,
        TenantLifecycleService::ENV_PRODUCTION  => 1,
    ];

    /**
     * Constructor
     *
     * @param OrganisationService $organisationService Organisation service
     * @param IUserSession        $userSession         User session
     * @param IGroupManager       $groupManager        Group manager
     * @param LoggerInterface     $logger              Logger
     */
    public function __construct(
        private readonly OrganisationService $organisationService,
        private readonly IUserSession $userSession,
        private readonly IGroupManager $groupManager,
        private readonly LoggerInterface $logger
    ) {
    }//end __construct()

    /**
     * Check quotas and organisation status before controller execution.
     *
     * @param string $controller Controller class name
     * @param string $methodName Method name
     *
     * @return void
     *
     * @throws \OCP\AppFramework\Http\TenantQuotaExceededException
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeController(string $controller, string $methodName): void
    {
        // Skip for non-authenticated requests (public endpoints).
        $user = $this->userSession->getUser();
        if ($user === null) {
            return;
        }

        $organisation = $this->organisationService->getActiveOrganisation();
        if ($organisation === null) {
            return;
        }

        // Check organisation status.
        $status = $organisation->getStatus() ?? TenantLifecycleService::STATUS_ACTIVE;

        if ($status === TenantLifecycleService::STATUS_SUSPENDED) {
            throw new TenantStatusException(
                'Organisation is suspended',
                $status,
                403
            );
        }

        if ($status === TenantLifecycleService::STATUS_DEPROVISIONING) {
            throw new TenantStatusException(
                'Organisation is being deprovisioned',
                $status,
                403
            );
        }

        if ($status === TenantLifecycleService::STATUS_PROVISIONING) {
            $isAdmin = $this->groupManager->isAdmin($user->getUID());
            if ($isAdmin === false) {
                throw new TenantStatusException(
                    'Organisation is being provisioned',
                    $status,
                    403
                );
            }
        }

        // Check request quota.
        $this->checkRequestQuota(organisation: $organisation);
    }//end beforeController()

    /**
     * Track bandwidth after controller execution.
     *
     * @param string   $controller Controller class name
     * @param string   $methodName Method name
     * @param Response $response   The response
     *
     * @return Response The unmodified response
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterController(string $controller, string $methodName, Response $response): Response
    {
        $organisation = $this->organisationService->getActiveOrganisation();
        if ($organisation === null) {
            return $response;
        }

        $orgUuid = $organisation->getUuid();
        if ($orgUuid === null) {
            return $response;
        }

        // Track bandwidth from response content length.
        if ($response instanceof JSONResponse) {
            $encoded       = json_encode($response->getData());
            $content       = ($encoded !== false ? $encoded : '');
            $contentLength = strlen($content);
        } else {
            // Estimate from headers or use 0.
            $contentLength = 0;
        }

        if ($contentLength > 0) {
            $hourBucket   = (new DateTime())->format('YmdH');
            $bandwidthKey = "or_bw_{$orgUuid}_{$hourBucket}";

            if (function_exists('apcu_enabled') === true && apcu_enabled() === true) {
                apcu_inc($bandwidthKey, $contentLength, $success);
                if ($success === false) {
                    apcu_store($bandwidthKey, $contentLength, 7200);
                }
            }
        }

        return $response;
    }//end afterController()

    /**
     * Handle exceptions thrown during quota/status checks.
     *
     * @param string     $controller Controller class name
     * @param string     $methodName Method name
     * @param \Exception $exception  The exception
     *
     * @return Response|null A JSON error response or null to re-throw
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function afterException(string $controller, string $methodName, \Exception $exception): ?Response
    {
        if ($exception instanceof TenantStatusException) {
            return new JSONResponse(
                [
                    'error'  => $exception->getMessage(),
                    'status' => $exception->getStatus(),
                ],
                $exception->getCode()
            );
        }

        if ($exception instanceof TenantQuotaExceededException) {
            $response = new JSONResponse(
                [
                    'error'   => $exception->getMessage(),
                    'quota'   => $exception->getQuota(),
                    'resetAt' => $exception->getResetAt(),
                ],
                429
            );

            $response->addHeader('Retry-After', (string) $exception->getRetryAfter());

            return $response;
        }

        return null;
    }//end afterException()

    /**
     * Check request quota using APCu counters.
     *
     * @param \OCA\OpenRegister\Db\Organisation $organisation The active organisation
     *
     * @return void
     *
     * @throws TenantQuotaExceededException If quota is exceeded
     */
    private function checkRequestQuota(object $organisation): void
    {
        $requestQuota = $organisation->getRequestQuota();
        if ($requestQuota === null) {
            // Null quota means unlimited.
            return;
        }

        // Apply environment multiplier.
        $environment    = $organisation->getEnvironment() ?? TenantLifecycleService::ENV_PRODUCTION;
        $multiplier     = self::ENV_REQUEST_MULTIPLIER[$environment] ?? 1;
        $effectiveQuota = $requestQuota * $multiplier;

        $orgUuid    = $organisation->getUuid();
        $hourBucket = (new DateTime())->format('YmdH');
        $counterKey = "or_quota_{$orgUuid}_{$hourBucket}";

        if (function_exists('apcu_enabled') === false || apcu_enabled() === false) {
            // APCu not available; skip quota enforcement.
            return;
        }

        $currentCount = apcu_fetch($counterKey, $success);
        if ($success === false) {
            $currentCount = 0;
        }

        if ($currentCount >= $effectiveQuota) {
            // Calculate seconds until next hour.
            $now      = new DateTime();
            $nextHour = new DateTime();
            $nextHour->modify('+1 hour');
            $nextHour->setTime((int) $nextHour->format('H'), 0, 0);
            $retryAfter = $nextHour->getTimestamp() - $now->getTimestamp();

            throw new TenantQuotaExceededException(
                'Request quota exceeded',
                $effectiveQuota,
                $nextHour->format('c'),
                max(1, $retryAfter)
            );
        }

        // Increment counter (TTL: 2 hours to survive hour boundary).
        apcu_inc($counterKey, 1, $incSuccess);
        if ($incSuccess === false) {
            apcu_store($counterKey, 1, 7200);
        }
    }//end checkRequestQuota()
}//end class
