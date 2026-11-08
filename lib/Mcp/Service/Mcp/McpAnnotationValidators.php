<?php

/**
 * McpAnnotationValidators: builds the x-openregister-mcp annotation validator that callers outside this component used to construct with `new`.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Mcp\Service\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Mcp\Service\Mcp;

/**
 * Constructs exactly what the caller used to construct itself with `new`: a
 * fresh instance with no arguments, typed as its interface, so the caller names
 * only this class and the interface. Types only: no logic lives here.
 *
 * @spec openspec/specs/ai-mcp/spec.md
 */
final class McpAnnotationValidators {

	/**
	 * The x-openregister-mcp annotation validator.
	 *
	 * @return McpAnnotationValidatorInterface A fresh instance.
	 *
	 * @spec openspec/specs/ai-mcp/spec.md
	 */
	public static function validator(): McpAnnotationValidatorInterface {
		return new McpAnnotationValidator();
	}//end validator()
}//end class
