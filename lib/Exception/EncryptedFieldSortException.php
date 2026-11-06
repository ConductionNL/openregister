<?php

/**
 * Sorting on an encrypted property is refused.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/field-level-encryption/spec.md#requirement-encrypted-fields-are-excluded-from-search-and-facets
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * Exception thrown when an order names an encrypted property.
 *
 * The column holds the envelope, whose order means nothing to the caller. A
 * subclass of the filter refusal, so every path that lets that refusal through
 * and {@see \OCA\OpenRegister\Middleware\EncryptedFieldFilterMiddleware} treat
 * it alike.
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
class EncryptedFieldSortException extends EncryptedFieldFilterException {
	/**
	 * What the refused request is told, with the property name in place of `%s`.
	 *
	 * @var string
	 */
	protected const MESSAGE = 'Property "%s" is encrypted at rest and cannot be sorted on.';

	/**
	 * The stable identifier of this refusal in a response body.
	 *
	 * @var string
	 */
	protected const REASON = 'encrypted-field-not-sortable';
}//end class
