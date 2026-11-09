<?php

/**
 * OpenRegister IdentifierFormats
 *
 * Hands out the identifier checks of this component behind the JSON Schema
 * format interface, so callers outside lib/Formats never name the concrete
 * check.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Format
 * @package  OCA\OpenRegister\Formats
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Formats;

use Opis\JsonSchema\Format;

/**
 * Factory for the identifier checks of the Formats component.
 *
 * @spec openspec/specs/data-import-export/spec.md
 */
final class IdentifierFormats {

	/**
	 * The BSN elfproef.
	 *
	 * @return Format
	 *
	 * @spec openspec/specs/data-import-export/spec.md
	 */
	public static function bsn(): Format {
		return new BsnFormat();
	}//end bsn()
}//end class
