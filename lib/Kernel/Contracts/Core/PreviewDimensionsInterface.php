<?php
/**
 * PreviewDimensionsInterface: the size, in pixels, the preview handler is asked for.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * The width and height, in pixels, the file preview handler (Core) renders a preview at.
 *
 * A value: the caller passes it, nothing binds it. The Files preview size implements it.
 *
 * @spec openspec/specs/file-actions/spec.md
 */
interface PreviewDimensionsInterface {

	/**
	 * The width in pixels.
	 *
	 * @return int The width.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function width(): int;

	/**
	 * The height in pixels.
	 *
	 * @return int The height.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function height(): int;

	/**
	 * Describes the size as width x height.
	 *
	 * @return string The label.
	 *
	 * @spec openspec/specs/file-actions/spec.md
	 */
	public function label(): string;
}//end interface
