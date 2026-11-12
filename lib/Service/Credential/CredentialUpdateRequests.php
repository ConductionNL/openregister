<?php

/**
 * CredentialUpdateRequests: reads a credential PATCH from the request it arrived in.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
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

namespace OCA\OpenRegister\Service\Credential;

use OCP\IRequest;

/**
 * The credential controller reads one update per request. The reader is built here, so
 * code outside lib/Service names only the interface.
 */
final class CredentialUpdateRequests {

	/**
	 * The update the request carries.
	 *
	 * @param IRequest $request The PATCH request.
	 *
	 * @return CredentialUpdateRequestInterface
	 */
	public static function fromRequest(IRequest $request): CredentialUpdateRequestInterface {
		return new CredentialUpdateRequest(request: $request);
	}//end fromRequest()
}//end class
