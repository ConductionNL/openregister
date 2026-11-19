<?php

/**
 * ReadPurposeRefusalInterface: a read refused because it carries no usable purpose.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Contract
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

use Throwable;

/**
 * What a reader that catches the refusal needs to answer the caller.
 *
 * ReadPurposeGuardInterface::enforce() throws it; the Compliance refusal
 * implements it.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
interface ReadPurposeRefusalInterface extends Throwable {

	/**
	 * The HTTP status the caller should answer with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function getStatusCode(): int;

	/**
	 * The refusal as the API publishes it.
	 *
	 * @return array<string, mixed> The response body.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function toResponseBody(): array;
}//end interface
