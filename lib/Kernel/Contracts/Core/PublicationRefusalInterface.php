<?php
/**
 * PublicationRefusalInterface: whether an object may be published, as file publishing asks it.
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

use Exception;

/**
 * The refusal file publishing (Core) asks for before it shares an object's file publicly.
 *
 * The party indicators (Processes) answer it and bind the answer in their own wiring.
 *
 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
 */
interface PublicationRefusalInterface {

	/**
	 * Refuse to publish an object when something on it says so.
	 *
	 * @param string $objectUuid The object about to be published.
	 *
	 * @return void
	 *
	 * @throws Exception 403 naming what refuses the publication.
	 *
	 * @spec openspec/specs/party-model/spec.md#requirement-an-indicator-on-a-party-declares-its-effect-and-is-honoured-req-prm-003
	 */
	public function assertPublicationAllowed(string $objectUuid): void;
}//end interface
