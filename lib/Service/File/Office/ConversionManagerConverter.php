<?php

/**
 * OpenRegister ConversionManagerConverter
 *
 * Converts between ODT and PDF through Nextcloud's file conversion API.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\File\Office
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\File\Office;

use OCA\OpenRegister\Exception\OfficeServerRequiredException;
use OCA\OpenRegister\Service\File\Pdf\Fallback\NcOfficeConverterInterface;
use OCP\Files\Conversion\IConversionManager;
use OCP\Files\File;
use RuntimeException;
use Throwable;

/**
 * Converts documents with whatever office server registered a conversion
 * provider in Nextcloud: Collabora through richdocuments, or Euro-Office.
 *
 * The source is written to {@see ConversionWorkFolder}, converted next to it,
 * read back, and both files are removed. When no provider offers
 * the conversion, {@see OfficeServerRequiredException} is thrown.
 *
 * PDF/A cannot be asked for: the conversion API addresses targets by MIME
 * type, and PDF/A shares `application/pdf`.
 *
 * @spec openspec/specs/export-pdf-format/spec.md
 */
class ConversionManagerConverter implements NcOfficeConverterInterface {

	/**
	 * MIME type per extension of the formats this class converts between.
	 */
	private const MIME_TYPES = [
		'odt' => 'application/vnd.oasis.opendocument.text',
		'pdf' => 'application/pdf',
	];

	/**
	 * Constructor.
	 *
	 * @param IConversionManager   $conversionManager Nextcloud's conversion API.
	 * @param ConversionWorkFolder $workFolder        Where the files are converted.
	 */
	public function __construct(
		private readonly IConversionManager $conversionManager,
		private readonly ConversionWorkFolder $workFolder,
	) {
	}//end __construct()

	/**
	 * Whether an office server can convert ODT to PDF.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function isAvailable(): bool {
		return $this->supports(from: 'odt', to: 'pdf');
	}//end isAvailable()

	/**
	 * Convert PDF bytes to ODT bytes.
	 *
	 * @param string $pdfBytes The PDF.
	 *
	 * @return string The ODT.
	 *
	 * @throws OfficeServerRequiredException When no provider converts PDF to ODT.
	 * @throws RuntimeException When the conversion fails.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function pdfToOdt(string $pdfBytes): string {
		return $this->convert(bytes: $pdfBytes, from: 'pdf');
	}//end pdfToOdt()

	/**
	 * Convert ODT bytes to PDF bytes.
	 *
	 * @param string $odtBytes The ODT.
	 *
	 * @return string The PDF.
	 *
	 * @throws OfficeServerRequiredException When no provider converts ODT to PDF.
	 * @throws RuntimeException When the conversion fails.
	 *
	 * @spec openspec/specs/export-pdf-format/spec.md
	 */
	public function odtToPdf(string $odtBytes): string {
		return $this->convert(bytes: $odtBytes, from: 'odt');
	}//end odtToPdf()

	/**
	 * Whether a registered provider offers a conversion.
	 *
	 * @param string $from Source extension.
	 * @param string $to   Target extension.
	 *
	 * @return bool
	 */
	private function supports(string $from, string $to): bool {
		try {
			foreach ($this->conversionManager->getProviders() as $provider) {
				if ($provider->getFrom() === self::MIME_TYPES[$from] && $provider->getTo() === self::MIME_TYPES[$to]) {
					return true;
				}
			}
		} catch (Throwable) {
			return false;
		}

		return false;
	}//end supports()

	/**
	 * Convert bytes to the other format of the pair.
	 *
	 * @param string $bytes The source bytes.
	 * @param string $from  `odt` or `pdf`; the target is the other one.
	 *
	 * @return string The converted bytes.
	 *
	 * @throws OfficeServerRequiredException When no provider offers the conversion.
	 * @throws RuntimeException When the conversion fails.
	 */
	private function convert(string $bytes, string $from): string {
		$to = 'odt';
		if ($from === 'odt') {
			$to = 'pdf';
		}

		if ($this->supports(from: $from, to: $to) === false) {
			throw new OfficeServerRequiredException();
		}

		$folder = $this->workFolder->get();
		$name = bin2hex(random_bytes(16));
		$source = $folder->newFile($name . '.' . $from, $bytes);

		try {
			$path = $this->conversionManager->convert($source, self::MIME_TYPES[$to], $folder->getPath() . '/' . $name . '.' . $to);
			$result = $folder->get(basename($path));
			if (($result instanceof File) === false) {
				throw new RuntimeException('The conversion did not produce a file');
			}

			$converted = (string) $result->getContent();
			$result->delete();

			return $converted;
		} catch (Throwable $e) {
			throw new RuntimeException('The office server could not convert the document: ' . $e->getMessage(), 0, $e);
		} finally {
			$source->delete();
		}//end try
	}//end convert()
}//end class
