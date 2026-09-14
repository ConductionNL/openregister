<?php

/**
 * TimelinePermissionException: a timeline act the caller may not make.
 *
 * Separate from the validation exception because the answers differ: a 403 is
 * "you, not this", and a 400 is "this, whoever you are". Collapsing them tells
 * a caller to fix the payload when the payload was fine.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\OpenRegister\Service\Timeline
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git-id>
 * @link      https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Timeline;

use Exception;

/**
 * A timeline act refused for want of permission.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Timeline
 */
class TimelinePermissionException extends Exception {
}//end class
