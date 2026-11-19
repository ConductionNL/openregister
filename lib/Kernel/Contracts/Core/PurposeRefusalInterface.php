<?php
/**
 * PurposeRefusalInterface: a read refused because its purpose is missing or not usable.
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

use Throwable;

/**
 * The refusal PurposeAdmissionInterface::enforce() throws, as the HTTP answer a read path returns.
 *
 * @spec openspec/specs/verwerkingsregister-api/spec.md
 */
interface PurposeRefusalInterface extends Throwable {

	/**
	 * The HTTP status the refused read answers with.
	 *
	 * @return int The status code.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function getStatusCode(): int;

	/**
	 * The JSON body the refused read answers with.
	 *
	 * @return array<string, mixed> The error, rule, message and purpose, with any context.
	 *
	 * @spec openspec/specs/verwerkingsregister-api/spec.md
	 */
	public function toResponseBody(): array;
}//end interface
