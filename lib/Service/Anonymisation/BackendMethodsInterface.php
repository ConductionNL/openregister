<?php

/**
 * BackendMethodsInterface: the entity-recognition method values callers outside the component compare against.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Anonymisation
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

namespace OCA\OpenRegister\Service\Anonymisation;

use OCA\OpenRegister\Kernel\Contracts\AnonymisationMethodsInterface;

/**
 * The entity-recognition method values.
 *
 * Declared on an interface so the anonymisation settings endpoints and the
 * entity recognition handler can name the values without naming BackendState.
 * BackendState implements it, so BackendState::METHOD_* still resolves. The
 * values themselves are declared on the Kernel AnonymisationMethodsInterface.
 */
interface BackendMethodsInterface extends AnonymisationMethodsInterface {
}//end interface
