<?php

/**
 * The property a claim was meant to fill is no longer empty.
 *
 * Two callers can both read a property as empty before either writes. The
 * write of a claim is conditional on the property still being empty, so the
 * second one changes nothing and is refused with this exception.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/declared-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

/**
 * A conditional claim found the property already set.
 *
 * @spec openspec/specs/declared-actions/spec.md
 */
class PropertyAlreadySetException extends ValidationException {
}//end class
