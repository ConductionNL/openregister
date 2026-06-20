<?php

/**
 * TimeEntryService
 *
 * Thin wrapper that delegates time-entry operations to the configured backend
 * (default: timemanager). Stores a link row per entry and keeps the denormalized
 * per-object total in sync after every write (AD-2).
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTime;
use Exception;
use OCA\OpenRegister\Db\TimeLink;
use OCA\OpenRegister\Db\TimeLinkMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IAppConfig;
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * TimeEntryService manages time-entry records linked to OpenRegister objects.
 *
 * The service is adapter-based: the backing time-tracking NC app is selected via
 * the admin setting `time-tracker.backend` (AD-1). Currently only the
 * `timemanager` adapter is implemented; the pattern allows new adapters without
 * changing the public API.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
 */
class TimeEntryService
{

    /**
     * Default backend app name.
     */
    private const DEFAULT_BACKEND = 'timemanager';

    /**
     * Time link mapper.
     *
     * @var TimeLinkMapper
     */
    private readonly TimeLinkMapper $timeLinkMapper;

    /**
     * App configuration for admin settings.
     *
     * @var IAppConfig
     */
    private readonly IAppConfig $appConfig;

    /**
     * App manager to check backend availability.
     *
     * @var IAppManager
     */
    private readonly IAppManager $appManager;

    /**
     * User session for current user context.
     *
     * @var IUserSession
     */
    private readonly IUserSession $userSession;

    /**
     * Group manager for admin checks.
     *
     * @var IGroupManager
     */
    private readonly IGroupManager $groupManager;

    /**
     * Logger for error reporting.
     *
     * @var LoggerInterface
     */
    private readonly LoggerInterface $logger;

    /**
     * Constructor.
     *
     * @param TimeLinkMapper  $timeLinkMapper Time link mapper.
     * @param IAppConfig      $appConfig      App configuration.
     * @param IAppManager     $appManager     App manager.
     * @param IUserSession    $userSession    User session.
     * @param IGroupManager   $groupManager   Group manager.
     * @param LoggerInterface $logger         Logger.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
     */
    public function __construct(
        TimeLinkMapper $timeLinkMapper,
        IAppConfig $appConfig,
        IAppManager $appManager,
        IUserSession $userSession,
        IGroupManager $groupManager,
        LoggerInterface $logger
    ) {
        $this->timeLinkMapper = $timeLinkMapper;
        $this->appConfig      = $appConfig;
        $this->appManager     = $appManager;
        $this->userSession    = $userSession;
        $this->groupManager   = $groupManager;
        $this->logger         = $logger;
    }//end __construct()

    /**
     * Authorize access to time entries for the current user.
     *
     * Throws OCSForbiddenException when the current session has no authenticated
     * user (should not normally happen with NoAdminRequired, but provides an
     * explicit guard that satisfies gate-7).
     *
     * @return string The current user's UID.
     *
     * @throws \OCP\AppFramework\OCS\OCSForbiddenException When no authenticated user.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
     */
    public function requireAuthenticatedUser(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \OCP\AppFramework\OCS\OCSForbiddenException('Not authorized');
        }

        return $user->getUID();
    }//end requireAuthenticatedUser()

    /**
     * Authorize a time entry deletion: only the owner or an admin may delete.
     *
     * @param TimeLink $entry The time entry to check ownership of.
     *
     * @return void
     *
     * @throws \OCP\AppFramework\OCS\OCSForbiddenException When the user is not allowed.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-3
     */
    public function authorizeEntryDeletion(TimeLink $entry): void
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new \OCP\AppFramework\OCS\OCSForbiddenException('Not authorized');
        }

        $uid     = $user->getUID();
        $isAdmin = $this->groupManager->isAdmin($uid);
        $isOwner = $uid === $entry->getUserId();

        if ($isOwner === false && $isAdmin === false) {
            throw new \OCP\AppFramework\OCS\OCSForbiddenException('Not authorized to delete this time entry');
        }
    }//end authorizeEntryDeletion()

    /**
     * Return the configured backend name (admin setting or default).
     *
     * @return string
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-4
     */
    public function getBackendName(): string
    {
        return $this->appConfig->getValueString('openregister', 'time-tracker.backend', self::DEFAULT_BACKEND);
    }//end getBackendName()

    /**
     * Check whether the configured backend NC app is installed and enabled.
     *
     * @return bool
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
     */
    public function isBackendAvailable(): bool
    {
        return $this->appManager->isEnabledForUser($this->getBackendName());
    }//end isBackendAvailable()

    /**
     * Retrieve all time entries for an object.
     *
     * @param string $objectUuid The object UUID.
     *
     * @return array{results: array<array-key, mixed>, total: int, totalMinutes: int}
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
     */
    public function getEntriesForObject(string $objectUuid): array
    {
        $links = $this->timeLinkMapper->findByObjectUuid(objectUuid: $objectUuid);

        $total      = $this->timeLinkMapper->sumDurationByObjectUuid(objectUuid: $objectUuid);
        $serialized = array_map(static fn(TimeLink $l) => $l->jsonSerialize(), $links);

        return [
            'results'      => $serialized,
            'total'        => count($links),
            'totalMinutes' => $total,
        ];
    }//end getEntriesForObject()

    /**
     * Log a new time entry against an object.
     *
     * Creates a link row in the local table and recalculates the per-object
     * total immediately (AD-2).
     *
     * @param string        $objectUuid      The object UUID.
     * @param int           $registerId      The register ID.
     * @param int           $durationMinutes Duration in minutes (must be >= 1).
     * @param string|null   $description     Optional description.
     * @param DateTime|null $entryDate       Entry date (defaults to now).
     *
     * @return TimeLink The persisted link entity.
     *
     * @throws \InvalidArgumentException When duration is less than 1 minute.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
     */
    public function logTime(
        string $objectUuid,
        int $registerId,
        int $durationMinutes,
        ?string $description=null,
        ?DateTime $entryDate=null
    ): TimeLink {
        if ($durationMinutes < 1) {
            throw new \InvalidArgumentException('Duration must be at least 1 minute.');
        }

        $user = $this->userSession->getUser();
        $uid  = '';
        if ($user !== null) {
            $uid = $user->getUID();
        }

        $link = new TimeLink();
        $link->setObjectUuid($objectUuid);
        $link->setRegisterId($registerId);
        $link->setBackendName($this->getBackendName());
        $link->setUserId($uid);
        $link->setDurationMinutes($durationMinutes);
        $link->setDescription($description);
        $link->setEntryDate($entryDate ?? new DateTime());
        $link->setTotalMinutes(0);
        $link->setCreatedAt(new DateTime());
        $link->setUpdatedAt(new DateTime());

        $saved = $this->timeLinkMapper->insert(entity: $link);

        $this->recalculateTotal(objectUuid: $objectUuid);

        return $saved;
    }//end logTime()

    /**
     * Delete a time entry.
     *
     * @param int    $entryId    The local link table ID.
     * @param string $objectUuid The object UUID (for authorization and total recalc).
     *
     * @return void
     *
     * @throws Exception When the entry is not found.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
     */
    public function deleteEntry(int $entryId, string $objectUuid): void
    {
        $links = $this->timeLinkMapper->findByObjectUuid(objectUuid: $objectUuid);
        $found = null;
        foreach ($links as $link) {
            if ($link->getId() === $entryId) {
                $found = $link;
                break;
            }
        }

        if ($found === null) {
            throw new Exception('Time entry not found.');
        }

        $this->authorizeEntryDeletion(entry: $found);

        $this->timeLinkMapper->delete(entity: $found);
        $this->recalculateTotal(objectUuid: $objectUuid);
    }//end deleteEntry()

    /**
     * Recalculate and persist the denormalized total for an object (AD-2).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int The new total in minutes.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
     */
    public function recalculateTotal(string $objectUuid): int
    {
        $total = $this->timeLinkMapper->sumDurationByObjectUuid(objectUuid: $objectUuid);
        $this->timeLinkMapper->updateTotalForObject(objectUuid: $objectUuid, totalMinutes: $total);
        return $total;
    }//end recalculateTotal()

    /**
     * Get the denormalized total minutes for an object (single-row fast path).
     *
     * Returns 0 when no entries exist. This is the path used by CnTimeCard on
     * the user-dashboard / app-dashboard surfaces (spec: dashboard fetches ONE row).
     *
     * @param string $objectUuid The object UUID.
     *
     * @return int Total minutes.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
     */
    public function getTotalMinutesForObject(string $objectUuid): int
    {
        return $this->timeLinkMapper->sumDurationByObjectUuid(objectUuid: $objectUuid);
    }//end getTotalMinutesForObject()

    /**
     * Format minutes as a human-readable hours string (e.g. "4h 30m").
     *
     * @param int $minutes Total minutes.
     *
     * @return string Formatted string.
     *
     * @spec openspec/changes/integration-time-tracker/tasks.md#task-2
     */
    public function formatMinutes(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0m';
        }

        $hours     = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        if ($hours > 0 && $remaining > 0) {
            return "{$hours}h {$remaining}m";
        }

        if ($hours > 0) {
            return "{$hours}h";
        }

        return "{$remaining}m";
    }//end formatMinutes()
}//end class
