<?php

/**
 * AnalyticsLink entity for linking NC Analytics reports to OpenRegister
 * objects.
 *
 * Tier-2 schema: carries register_id, schema_id, report_title,
 * report_type, subheader, created_at and modified_at so the link row
 * alone can hydrate the sidebar tab + picker UX without a per-report
 * roundtrip to NC Analytics. Replaces the Tier-1 `AnalyticsProvider`'s
 * `[or:{uuid}]` report-name marker convention with a proper persistence
 * layer that survives report renames.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class AnalyticsLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getReportId()
 * @method void setReportId(int $reportId)
 * @method string getReportTitle()
 * @method void setReportTitle(string $reportTitle)
 * @method string|null getReportType()
 * @method void setReportType(?string $reportType)
 * @method string|null getSubheader()
 * @method void setSubheader(?string $subheader)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getModifiedAt()
 * @method void setModifiedAt(?DateTime $modifiedAt)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class AnalyticsLink extends Entity implements JsonSerializable
{

    /**
     * The object uuid.
     *
     * @var string|null
     */
    protected ?string $objectUuid = null;

    /**
     * The register id.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * The schema id.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * The NC Analytics report id (primary key in `analytics_report`).
     *
     * @var integer|null
     */
    protected ?int $reportId = null;

    /**
     * The report display name (cached at link time).
     *
     * @var string|null
     */
    protected ?string $reportTitle = null;

    /**
     * The report type / datasource type (cached at link time).
     *
     * @var string|null
     */
    protected ?string $reportType = null;

    /**
     * The report subheader (cached at link time).
     *
     * @var string|null
     */
    protected ?string $subheader = null;

    /**
     * The cached report created timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * The cached report modified timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $modifiedAt = null;

    /**
     * The linked by uid.
     *
     * @var string|null
     */
    protected ?string $linkedBy = null;

    /**
     * The linked at timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $linkedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'objectUuid', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'reportId', type: 'integer');
        $this->addType(fieldName: 'reportTitle', type: 'string');
        $this->addType(fieldName: 'reportType', type: 'string');
        $this->addType(fieldName: 'subheader', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'modifiedAt', type: 'datetime');
        $this->addType(fieldName: 'linkedBy', type: 'string');
        $this->addType(fieldName: 'linkedAt', type: 'datetime');
    }//end __construct()

    /**
     * JSON serialization.
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'          => $this->id,
            'objectUuid'  => $this->objectUuid,
            'registerId'  => $this->registerId,
            'schemaId'    => $this->schemaId,
            'reportId'    => $this->reportId,
            'reportTitle' => $this->reportTitle,
            'reportType'  => $this->reportType,
            'subheader'   => $this->subheader,
            'createdAt'   => $this->createdAt?->format(DateTime::ATOM),
            'modifiedAt'  => $this->modifiedAt?->format(DateTime::ATOM),
            'linkedBy'    => $this->linkedBy,
            'linkedAt'    => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
