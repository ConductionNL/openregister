<?php

/**
 * Raised when a schema declares a date kind the calendar feed cannot read.
 *
 * This is deliberately its own type rather than a bare InvalidArgumentException.
 * Schema::validateConfigurationArray() drops a configuration key it cannot
 * validate and keeps the rest, which is the right degradation for a feature
 * that then simply does not fire. A date-kind typo is the opposite shape: the
 * schema would save, look annotated to whoever wrote it, and publish an agenda
 * that is quietly missing the term they just declared. Nobody looks at a
 * calendar and concludes the schema is wrong. So this one fails loudly, and the
 * message names the property.
 *
 * @category Exception
 * @package  OCA\OpenRegister\Exception
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
 * @spec openspec/changes/object-dates-as-a-calendar-feed/specs/calendar-provider/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Exception;

use InvalidArgumentException;

/**
 * An unusable `calendarProvider.dates` declaration.
 */
class CalendarDateKindException extends InvalidArgumentException {

}//end class
