<?php

/**
 * OpenRegister Packaged File Checksum
 *
 * The checksum an e-Depot package carries for a file, and when it was made.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use DateTime;
use RuntimeException;

/**
 * Computes a file's SHA-256 at packaging time, dates it, and checks fixity.
 *
 * ## Why the checksum is computed here, every time
 *
 * MDTO requires `checksumDatum`, defined as "Datum waarop de checksum is
 * gemaakt". A checksum read from stored data carries no such date, so the
 * only honest date is one this class can vouch for: it hashes the bytes about
 * to be packaged and records that moment.
 *
 * ## Why recomputing is not enough on its own
 *
 * Recomputing alone would launder a changed file. If the bytes no longer match
 * a SHA-256 recorded for them, a fresh checksum would describe the changed
 * bytes and the package would look intact. So a stored SHA-256 that disagrees
 * is a fixity failure and the file is refused. A stored value that is not
 * SHA-256-shaped cannot be compared, and is not used.
 *
 * @psalm-suppress UnusedClass
 */
class PackagedFileChecksum {

	/**
	 * Compute, date and fixity-check the checksum for one file.
	 *
	 * @param string $path The file about to be packaged.
	 * @param mixed $stored The checksum stored with the file reference, if any.
	 *
	 * @return array{checksum: string, checksumDate: string} The SHA-256 and an xsd:dateTime.
	 *
	 * @throws RuntimeException When the file cannot be read, or its stored SHA-256 no longer matches.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-generated-mdto-documents-must-validate-against-the-vendored-mdto-xml-1-0-1-xsd
	 */
	public function compute(string $path, mixed $stored): array {
		$checksum = hash_file('sha256', $path);
		if ($checksum === false) {
			throw new RuntimeException('Cannot read ' . $path . ' to checksum it, so it is not transferred');
		}

		if (is_string($stored) === true
			&& preg_match('/^[0-9a-fA-F]{64}$/', $stored) === 1
			&& hash_equals(strtolower($stored), $checksum) === false
		) {
			throw new RuntimeException(
				'Fixity failure: the SHA-256 recorded for ' . $path
				. ' does not match its bytes, so it is not transferred'
			);
		}

		return ['checksum' => $checksum, 'checksumDate' => (new DateTime())->format(DateTime::ATOM)];
	}//end compute()
}//end class
