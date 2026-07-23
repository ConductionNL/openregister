<?php

/**
 * AnalyticsSeries entity — a page-level, pre-computed chart series a leaf
 * app registers with OpenRegister so a dashboard chart widget can render
 * it without a per-object roundtrip.
 *
 * A series carries a Chart.js-style payload: `labels` (the x-axis
 * categories) and `datasets` (named series with their data points). The
 * leaf supplies the numbers (e.g. procest's SLA breach counts per week);
 * OR stores them under a stable `series_key`, scoped to a register/schema
 * and a visibility tier, and exposes them through the AnalyticsSeries
 * render surface.
 *
 * OR deliberately does NOT compute the series — the leaf owns the maths.
 * This is the reusable persistence + render contract only.
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
 *
 * @spec openspec/specs/integration-leaf-foundation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Class AnalyticsSeries
 *
 * @method string|null getSeriesKey()
 * @method void setSeriesKey(?string $seriesKey)
 * @method int|null getRegisterId()
 * @method void setRegisterId(?int $registerId)
 * @method int|null getSchemaId()
 * @method void setSchemaId(?int $schemaId)
 * @method string|null getTitle()
 * @method void setTitle(?string $title)
 * @method string|null getChartType()
 * @method void setChartType(?string $chartType)
 * @method string|null getLabels()
 * @method void setLabels(?string $labels)
 * @method string|null getDatasets()
 * @method void setDatasets(?string $datasets)
 * @method string|null getVisibility()
 * @method void setVisibility(?string $visibility)
 * @method string|null getCreatedBy()
 * @method void setCreatedBy(?string $createdBy)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 *
 * @psalm-suppress PropertyNotSetInConstructor $id is set by Nextcloud's Entity base class
 */
class AnalyticsSeries extends Entity implements JsonSerializable
{

    /**
     * Stable series key (unique per instance).
     *
     * @var string|null
     */
    protected ?string $seriesKey = null;

    /**
     * Optional register scope.
     *
     * @var integer|null
     */
    protected ?int $registerId = null;

    /**
     * Optional schema scope.
     *
     * @var integer|null
     */
    protected ?int $schemaId = null;

    /**
     * Display title for the chart widget.
     *
     * @var string|null
     */
    protected ?string $title = null;

    /**
     * Chart type hint (line / bar / pie / doughnut / …). Render layer
     * decides how to draw it.
     *
     * @var string|null
     */
    protected ?string $chartType = null;

    /**
     * JSON-encoded array of x-axis labels.
     *
     * @var string|null
     */
    protected ?string $labels = null;

    /**
     * JSON-encoded array of datasets (each `{label, data, …}`).
     *
     * @var string|null
     */
    protected ?string $datasets = null;

    /**
     * Visibility scope: 'private' (creator), 'group' or 'public'.
     * Governs who may fetch the series through the render surface.
     *
     * @var string|null
     */
    protected ?string $visibility = null;

    /**
     * The uid that registered the series.
     *
     * @var string|null
     */
    protected ?string $createdBy = null;

    /**
     * Creation timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $createdAt = null;

    /**
     * Last-update timestamp.
     *
     * @var DateTime|null
     */
    protected ?DateTime $updatedAt = null;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->addType(fieldName: 'seriesKey', type: 'string');
        $this->addType(fieldName: 'registerId', type: 'integer');
        $this->addType(fieldName: 'schemaId', type: 'integer');
        $this->addType(fieldName: 'title', type: 'string');
        $this->addType(fieldName: 'chartType', type: 'string');
        $this->addType(fieldName: 'labels', type: 'string');
        $this->addType(fieldName: 'datasets', type: 'string');
        $this->addType(fieldName: 'visibility', type: 'string');
        $this->addType(fieldName: 'createdBy', type: 'string');
        $this->addType(fieldName: 'createdAt', type: 'datetime');
        $this->addType(fieldName: 'updatedAt', type: 'datetime');
    }//end __construct()

    /**
     * Decode the stored labels JSON into an array.
     *
     * @return array<int,mixed> The labels, or [] when unset / invalid.
     */
    public function getLabelsArray(): array
    {
        if ($this->labels === null || $this->labels === '') {
            return [];
        }

        $decoded = json_decode($this->labels, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getLabelsArray()

    /**
     * Decode the stored datasets JSON into an array.
     *
     * @return array<int,mixed> The datasets, or [] when unset / invalid.
     */
    public function getDatasetsArray(): array
    {
        if ($this->datasets === null || $this->datasets === '') {
            return [];
        }

        $decoded = json_decode($this->datasets, true);
        if (is_array($decoded) === true) {
            return $decoded;
        }

        return [];
    }//end getDatasetsArray()

    /**
     * JSON serialization — returns the full chart-ready render contract
     * (labels + datasets decoded back to arrays).
     *
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id'         => $this->id,
            'seriesKey'  => $this->seriesKey,
            'registerId' => $this->registerId,
            'schemaId'   => $this->schemaId,
            'title'      => $this->title,
            'chartType'  => $this->chartType,
            'labels'     => $this->getLabelsArray(),
            'datasets'   => $this->getDatasetsArray(),
            'visibility' => $this->visibility,
            'createdBy'  => $this->createdBy,
            'createdAt'  => $this->createdAt?->format(DateTime::ATOM),
            'updatedAt'  => $this->updatedAt?->format(DateTime::ATOM),
        ];
    }//end jsonSerialize()
}//end class
