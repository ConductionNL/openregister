<?php

/**
 * FileVectorizationStrategyInterface: the file vectorization strategy as the Search registrar resolves it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Vectorization\Strategies
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

namespace OCA\OpenRegister\Service\Vectorization\Strategies;


/**
 * The file strategy under its own container key. VectorizationStrategyInterface
 * is shared by every strategy, so it cannot name one; this interface adds no
 * methods and lets the Search registrar resolve the file strategy without
 * naming its class.
 */
interface FileVectorizationStrategyInterface extends VectorizationStrategyInterface {
}//end interface
