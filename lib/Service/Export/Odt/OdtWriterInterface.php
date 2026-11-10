<?php

/**
 * OdtWriterInterface: what callers outside the Service component ask of OdtWriter.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Export\Odt
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

namespace OCA\OpenRegister\Service\Export\Odt;

use RuntimeException;

/**
 * Writing an ODT package from HTML, as the PDF report writer uses it. Only the
 * method it calls.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
interface OdtWriterInterface {

	/**
	 * Write the HTML as an A4 ODT package.
	 *
	 * @param string $html      Export section or report HTML.
	 * @param bool   $landscape Landscape A4 when true, portrait A4 otherwise.
	 *
	 * @return string The ODT bytes.
	 *
	 * @throws RuntimeException When the package cannot be written.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function fromHtml(string $html, bool $landscape): string;
}//end interface
