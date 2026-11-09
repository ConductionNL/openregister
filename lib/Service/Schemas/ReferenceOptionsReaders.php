<?php

/**
 * ReferenceOptionsReaders: builds the reference-options reader for callers outside lib/Service.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

/**
 * Builds the reference-options reader the reference-options answer uses.
 *
 * The answer built ReferenceOptionsReader with `new`; this does the same and
 * hands it back typed against ReferenceOptionsReaderInterface.
 */
final class ReferenceOptionsReaders {

	/**
	 * A fresh reference-options reader.
	 *
	 * @return ReferenceOptionsReaderInterface
	 */
	public static function create(): ReferenceOptionsReaderInterface {
		return new ReferenceOptionsReader();
	}//end create()
}//end class
