<?php

/**
 * ScheduledReport entity — a recurring `ExportService` export configuration owned by one user.
 *
 * Infrastructure DB state (NOT an OpenRegister object/register — ADR-001, same
 * reasoning as `PushSubscription`): a scheduling config for a recurring
 * register/schema export, owned by exactly one user, with no cross-app
 * business meaning and no audit/relation value. Backed by the
 * `openregister_scheduled_reports` table.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class ScheduledReport
 *
 * @method string|null getOwner()
 * @method void setOwner(?string $owner)
 * @method string|null getName()
 * @method void setName(?string $name)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getFilters()
 * @method void setFilters(?string $filters)
 * @method string|null getFormat()
 * @method void setFormat(?string $format)
 * @method string|null getScheduleType()
 * @method void setScheduleType(?string $scheduleType)
 * @method int|null getScheduleHour()
 * @method void setScheduleHour(?int $scheduleHour)
 * @method int|null getScheduleDayOfWeek()
 * @method void setScheduleDayOfWeek(?int $scheduleDayOfWeek)
 * @method int|null getScheduleDayOfMonth()
 * @method void setScheduleDayOfMonth(?int $scheduleDayOfMonth)
 * @method string|null getDeliveryFolder()
 * @method void setDeliveryFolder(?string $deliveryFolder)
 * @method bool|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 * @method DateTime|null getLastRunAt()
 * @method void setLastRunAt(?DateTime $lastRunAt)
 * @method string|null getLastStatus()
 * @method void setLastStatus(?string $lastStatus)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 *
 * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
 *
 * @SuppressWarnings(PHPMD.TooManyFields) 17 columns is the row's actual
 *     shape (design.md's table) — scheduling config, owner, delivery
 *     target, and last-run outcome each need their own field; splitting
 *     this into sub-objects would just move the field count, not reduce it.
 */
class ScheduledReport extends Entity implements JsonSerializable
{

    /**
     * The owning Nextcloud user id.
     *
     * @var string|null
     */
    protected ?string $owner = null;

    /**
     * User-facing label.
     *
     * @var string|null
     */
    protected ?string $name = null;

    /**
     * The register whose data is exported.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The schema whose data is exported (required for `csv`).
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * Opaque JSON-encoded `@self.*` filter map, same shape the export endpoints accept.
     *
     * @var string|null
     */
    protected ?string $filters = null;

    /**
     * Export format: csv|excel|pdf.
     *
     * @var string|null
     */
    protected ?string $format = null;

    /**
     * Schedule cadence: daily|weekly|monthly.
     *
     * @var string|null
     */
    protected ?string $scheduleType = null;

    /**
     * Target hour of day (0-23), informational — see design.md due-logic notes.
     *
     * @var integer|null
     */
    protected ?int $scheduleHour = null;

    /**
     * Target day of week (0=Monday..6=Sunday), required when scheduleType is weekly.
     *
     * @var integer|null
     */
    protected ?int $scheduleDayOfWeek = null;

    /**
     * Target day of month (1-28), required when scheduleType is monthly.
     *
     * @var integer|null
     */
    protected ?int $scheduleDayOfMonth = null;

    /**
     * Nextcloud Files delivery folder, relative to the owner's home. Default 'Reports/'.
     *
     * @var string|null
     */
    protected ?string $deliveryFolder = null;

    /**
     * Whether this report is scheduled to run.
     *
     * @var boolean|null
     */
    protected ?bool $enabled = null;

    /**
     * When this report last ran (any outcome).
     *
     * @var DateTime|null
     */
    protected ?DateTime $lastRunAt = null;

    /**
     * The outcome of the last run: success|failed.
     *
     * @var string|null
     */
    protected ?string $lastStatus = null;

    /**
     * The failure reason when lastStatus is failed.
     *
     * @var string|null
     */
    protected ?string $lastError = null;

    /**
     * When this report was created.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * When this report was last updated.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updatedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'owner', type: 'string');
        $this->addType(fieldName: 'name', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'filters', type: 'string');
        $this->addType(fieldName: 'format', type: 'string');
        $this->addType(fieldName: 'scheduleType', type: 'string');
        $this->addType(fieldName: 'scheduleHour', type: 'integer');
        $this->addType(fieldName: 'scheduleDayOfWeek', type: 'integer');
        $this->addType(fieldName: 'scheduleDayOfMonth', type: 'integer');
        $this->addType(fieldName: 'deliveryFolder', type: 'string');
        $this->addType(fieldName: 'enabled', type: 'boolean');
        $this->addType(fieldName: 'lastRunAt', type: 'datetime');
        $this->addType(fieldName: 'lastStatus', type: 'string');
        $this->addType(fieldName: 'lastError', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'updatedAt', type: 'datetime');
    }//end __construct()

    /**
     * The decoded filter map, or an empty array when none is stored.
     *
     * @return array<string, mixed>
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function getFiltersArray(): array
    {
        if ($this->filters === null || $this->filters === '') {
            return [];
        }

        $decoded = json_decode($this->filters, true);
        if (is_array($decoded) === false) {
            return [];
        }

        return $decoded;
    }//end getFiltersArray()

    /**
     * JSON serialization — the row is only ever exposed to its owner or an admin (controller-gated).
     *
     * @return array<string,mixed>
     *
     * @spec openspec/changes/scheduled-report-jobs/specs/scheduled-report-jobs/spec.md
     */
    public function jsonSerialize(): array
    {
        return [
            'id'                 => $this->id,
            'owner'              => $this->owner,
            'name'               => $this->name,
            'registerId'         => $this->registerId,
            'schemaId'           => $this->schemaId,
            'filters'            => $this->getFiltersArray(),
            'format'             => $this->format,
            'scheduleType'       => $this->scheduleType,
            'scheduleHour'       => $this->scheduleHour,
            'scheduleDayOfWeek'  => $this->scheduleDayOfWeek,
            'scheduleDayOfMonth' => $this->scheduleDayOfMonth,
            'deliveryFolder'     => $this->deliveryFolder,
            'enabled'            => $this->enabled,
            'lastRunAt'          => $this->lastRunAt?->format(DateTime::ATOM),
            'lastStatus'         => $this->lastStatus,
            'lastError'          => $this->lastError,
            'createdAt'          => $this->createdAt?->format(DateTime::ATOM),
            'updatedAt'          => $this->updatedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
