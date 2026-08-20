<?php

/**
 * NullNcOfficeConverter
 *
 * No-op {@see NcOfficeConverterInterface} implementation used when the
 * dormant Path B PDF anonymisation fallback feature flag is disabled
 * (`pdf-anonymisation.path-b-enabled = false`, the default).
 *
 * It always reports `isAvailable() === false` so {@see PdfOdtFallbackOrchestrator}
 * short-circuits without attempting an NC Office call.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf\Fallback
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Pdf\Fallback;

use RuntimeException;

/**
 * Null implementation that always reports unavailable.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Pdf\Fallback
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
 */
final class NullNcOfficeConverter implements NcOfficeConverterInterface {
	/**
	 * NC Office bridge is never available in the null implementation.
	 *
	 * @return bool Always false.
	 */
	public function isAvailable(): bool {
		return false;
	}//end isAvailable()

	/**
	 * Always throws — the null implementation has no NC Office bridge.
	 *
	 * @param string $pdfBytes Input bytes (unused).
	 *
	 * @return string Never returns.
	 *
	 * @throws RuntimeException Always — the null implementation is unavailable.
	 *
	 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
	 */
	public function pdfToOdt(string $pdfBytes): string {
		throw new RuntimeException(
			'NC Office bridge is not available (NullNcOfficeConverter is the default). '
			. 'Enable Path B by injecting a concrete NcOfficeConverterInterface implementation '
			. 'and setting pdf-anonymisation.path-b-enabled = true.'
		);
	}//end pdfToOdt()

	/**
	 * Always throws — the null implementation has no NC Office bridge.
	 *
	 * @param string $odtBytes Input bytes (unused).
	 *
	 * @return string Never returns.
	 *
	 * @throws RuntimeException Always — the null implementation is unavailable.
	 *
	 * @spec openspec/specs/pdf-anonymisation-odt-fallback/spec.md
	 */
	public function odtToPdf(string $odtBytes): string {
		throw new RuntimeException(
			'NC Office bridge is not available (NullNcOfficeConverter is the default). '
			. 'Enable Path B by injecting a concrete NcOfficeConverterInterface implementation '
			. 'and setting pdf-anonymisation.path-b-enabled = true.'
		);
	}//end odtToPdf()
}//end class
