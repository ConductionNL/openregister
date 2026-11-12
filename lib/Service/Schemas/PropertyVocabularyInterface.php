<?php

/**
 * PropertyVocabularyInterface: what callers outside the Service component ask of PropertyVocabulary.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Schemas
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

namespace OCA\OpenRegister\Service\Schemas;

/**
 * The property vocabulary a schema may use, as the property-vocabulary
 * controller reads it. Only the methods that caller calls.
 */
interface PropertyVocabularyInterface {

	/**
	 * The whole vocabulary: types, constraints, modifiers, pass-through keys and the counts.
	 *
	 * @return array<string, mixed> Types, constraints, modifiers, pass-through keys and the counts.
	 */
	public function all(): array;

	/**
	 * The accepted property keys.
	 *
	 * @return array<int, string> The accepted property keys.
	 */
	public function keys(): array;
}//end interface
