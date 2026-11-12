<?php

/**
 * OpenRegister linked source responses
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Support;

use Exception;
use JsonSerializable;
use OCP\AppFramework\Http\JSONResponse;

/**
 * The answers the Tier-2 link controllers (xWiki, Polls, Talk, OpenProject, TimeTracker, Photos) give.
 *
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */
final class LinkedSourceResponses {
	/**
	 * The 201 answer carrying the link just made.
	 *
	 * @param JsonSerializable $link The link.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function created(JsonSerializable $link): JSONResponse {
		return new JSONResponse($link->jsonSerialize(), 201);
	}//end created()

	/**
	 * The answer to a removed link.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function unlinked(): JSONResponse {
		return new JSONResponse(['success' => true]);
	}//end unlinked()

	/**
	 * The 400 answer for a missing request param.
	 *
	 * @param string $param The param's name.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function required(string $param): JSONResponse {
		return new JSONResponse(['error' => $param . ' is required'], 400);
	}//end required()

	/**
	 * A picker listing: the items with their count.
	 *
	 * @param array $items The items.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function listed(array $items): JSONResponse {
		return new JSONResponse(['results' => $items, 'total' => count($items)]);
	}//end listed()

	/**
	 * A service failure: its own code when that code carries HTTP intent, else 400.
	 *
	 * @param Exception $exception   The failure.
	 * @param int[]     $passThrough The codes answered as themselves.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function failure(Exception $exception, array $passThrough): JSONResponse {
		$code = $exception->getCode();
		if (in_array($code, $passThrough, true) === true) {
			return new JSONResponse(['error' => $exception->getMessage()], $code);
		}

		return new JSONResponse(['error' => $exception->getMessage()], 400);
	}//end failure()

	/**
	 * The 503 answer for an unconfigured or unreachable external source, with the cause the UI banner shows.
	 *
	 * @param string $error The error message.
	 * @param mixed  $cause The 4-state cause.
	 *
	 * @return JSONResponse The response.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function sourceUnavailable(string $error, mixed $cause): JSONResponse {
		return new JSONResponse(['error' => $error, 'details' => ['cause' => $cause]], 503);
	}//end sourceUnavailable()
}//end class
