<?php

/**
 * OpenProjectLink entity for linking OpenProject work packages to
 * OpenRegister objects.
 *
 * Tier-2 schema: carries register_id, schema_id, work_package_id,
 * subject, type, status, priority, assignee, project, url and cached_at
 * so the link row alone can hydrate the sidebar tab + picker UX without
 * a per-work-package roundtrip to OpenProject (reached through
 * OpenConnector — AD-4 / AD-22). The cached metadata also keeps
 * historical references readable when the OpenConnector source is
 * temporarily unconfigured or down.
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
 * Class OpenProjectLink
 *
 * @method string getObjectUuid()
 * @method void setObjectUuid(string $objectUuid)
 * @method int getRegisterId()
 * @method void setRegisterId(int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method int getWorkPackageId()
 * @method void setWorkPackageId(int $workPackageId)
 * @method string getSubject()
 * @method void setSubject(string $subject)
 * @method string|null getType()
 * @method void setType(?string $type)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method string|null getPriority()
 * @method void setPriority(?string $priority)
 * @method string|null getAssignee()
 * @method void setAssignee(?string $assignee)
 * @method string|null getProject()
 * @method void setProject(?string $project)
 * @method string|null getUrl()
 * @method void setUrl(?string $url)
 * @method DateTime|null getCachedAt()
 * @method void setCachedAt(?DateTime $cachedAt)
 * @method string|null getLinkedBy()
 * @method void setLinkedBy(string $linkedBy)
 * @method DateTime|null getLinkedAt()
 * @method void setLinkedAt(DateTime $linkedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class OpenProjectLink extends Entity implements JsonSerializable
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
     * The OpenProject work-package id.
     *
     * @var integer|null
     */
    protected ?int $workPackageId = null;

    /**
     * The work-package subject (cached).
     *
     * @var string|null
     */
    protected ?string $subject = null;

    /**
     * The work-package type label (cached).
     *
     * @var string|null
     */
    protected ?string $type = null;

    /**
     * The work-package status label (cached).
     *
     * @var string|null
     */
    protected ?string $status = null;

    /**
     * The work-package priority label (cached).
     *
     * @var string|null
     */
    protected ?string $priority = null;

    /**
     * The work-package assignee label (cached).
     *
     * @var string|null
     */
    protected ?string $assignee = null;

    /**
     * The work-package project label (cached).
     *
     * @var string|null
     */
    protected ?string $project = null;

    /**
     * The cached deep link to the work package.
     *
     * @var string|null
     */
    protected ?string $url = null;

    /**
     * When the cached metadata was last refreshed.
     *
     * @var DateTime|null
     */
    protected ?DateTime $cachedAt = null;

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
        $this->addType(fieldName: 'workPackageId', type: 'integer');
        $this->addType(fieldName: 'subject', type: 'string');
        $this->addType(fieldName: 'type', type: 'string');
        $this->addType(fieldName: 'status', type: 'string');
        $this->addType(fieldName: 'priority', type: 'string');
        $this->addType(fieldName: 'assignee', type: 'string');
        $this->addType(fieldName: 'project', type: 'string');
        $this->addType(fieldName: 'url', type: 'string');
        $this->addType(fieldName: 'cachedAt', type: 'datetime');
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
            'id'            => $this->id,
            'objectUuid'    => $this->objectUuid,
            'registerId'    => $this->registerId,
            'schemaId'      => $this->schemaId,
            'workPackageId' => $this->workPackageId,
            'subject'       => $this->subject,
            'type'          => $this->type,
            'status'        => $this->status,
            'priority'      => $this->priority,
            'assignee'      => $this->assignee,
            'project'       => $this->project,
            'url'           => $this->url,
            'cachedAt'      => $this->cachedAt?->format(DateTime::ATOM),
            'linkedBy'      => $this->linkedBy,
            'linkedAt'      => $this->linkedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
