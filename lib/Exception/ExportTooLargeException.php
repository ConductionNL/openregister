<?php

/**
 * Class ExportTooLargeException
 *
 * Thrown when a PDF export request exceeds `ExportService::MAX_PDF_EXPORT_ROWS`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Exception
 * @package   OCA\OpenRegister\Exception
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

namespace OCA\OpenRegister\Exception;

use Exception;

/**
 * Exception thrown when a PDF export's row count exceeds the configured cap.
 *
 * PDF rendering builds a full in-memory box-tree per row (Dompdf), unlike the
 * streaming CSV writer or PhpSpreadsheet's XLSX writer, making it meaningfully
 * more memory-heavy per row. `ExportService::exportToPdf()` throws this
 * exception before any HTML construction or Dompdf rendering begins, so no
 * wasted render work happens once the cap is exceeded.
 *
 * Controllers exposing PDF export MUST catch this exception specifically and
 * map it to HTTP 400 with a structured body identifying the actual row count
 * and the configured limit — never a bare 500, and never a silently truncated
 * PDF.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */
class ExportTooLargeException extends Exception
{

    /**
     * The HTTP status code controllers MUST map this exception to.
     *
     * @var int
     */
    public const HTTP_STATUS = 400;

    /**
     * The actual number of objects the export attempted to render.
     *
     * @var integer
     */
    private int $rowCount;

    /**
     * The configured row-count limit that was exceeded.
     *
     * @var integer
     */
    private int $maxRows;

    /**
     * ExportTooLargeException constructor.
     *
     * @param int            $rowCount The actual number of objects the export attempted to render.
     * @param int            $maxRows  The configured row-count limit that was exceeded.
     * @param Exception|null $previous The previous exception that caused this one, if any.
     */
    public function __construct(int $rowCount, int $maxRows, ?Exception $previous=null)
    {
        $this->rowCount = $rowCount;
        $this->maxRows  = $maxRows;

        $message = sprintf(
            'PDF export row count (%d) exceeds the maximum allowed (%d). '
            .'Narrow the export with filters or use CSV/Excel export instead.',
            $rowCount,
            $maxRows
        );
        parent::__construct(message: $message, code: 0, previous: $previous);

    }//end __construct()

    /**
     * Get the actual number of objects the export attempted to render.
     *
     * @return int
     */
    public function getRowCount(): int
    {
        return $this->rowCount;

    }//end getRowCount()

    /**
     * Get the configured row-count limit that was exceeded.
     *
     * @return int
     */
    public function getMaxRows(): int
    {
        return $this->maxRows;

    }//end getMaxRows()
}//end class
