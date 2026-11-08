<?php

/**
 * ApiVersionNegotiatorInterface: ApiVersionNegotiator as the API caller and API version middleware reaches it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
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

namespace OCA\OpenRegister\Service\ApiVersion;

use OCP\IRequest;

/**
 * ApiVersionNegotiator, typed for callers outside the Service component.
 *
 * Its outside callers, the API caller and API version middleware, call negotiate()
 * and acceptableIdentifiers() and read RESPONSE_HEADER, so those are the whole contract. Binding it in ContractBindings lets that caller resolve
 * ApiVersionNegotiator without naming the class.
 */
interface ApiVersionNegotiatorInterface {
	/**
	 * The response header naming the contract that answered.
	 *
	 * Sent on every answer, including one the caller did not ask a version
	 * for, because a consumer that can read which contract served it can
	 * notice a default moving under it before its parser does.
	 *
	 * @var string
	 */
	public const RESPONSE_HEADER = 'API-Version';

	/**
	 * Resolve the version a request speaks.
	 *
	 * @param IRequest $request The incoming request.
	 *
	 * @return ApiVersionNegotiation What was asked for, and what answers.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function negotiate(IRequest $request): ApiVersionNegotiation;

	/**
	 * The identifiers this instance would accept, for a refusal message.
	 *
	 * @return array<int, string> The served identifiers, lowest first.
	 *
	 * @spec openspec/specs/openapi-generation/spec.md
	 */
	public function acceptableIdentifiers(): array;
}//end interface
