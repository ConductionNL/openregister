<?php

/**
 * OpenRegister linked source actions
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

use Closure;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * The link steps the Tier-2 link controllers run on a resolved object, and the picker params they read.
 *
 * Every step is a closure for ObjectLinkActionsTrait::onLinkedObject(), which resolves the object first;
 * the step reads its params only then.
 *
 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
 */
final class LinkedSourceActions {
	/**
	 * The optional `search` param of a picker, as a string.
	 *
	 * @param IRequest $request The HTTP request.
	 *
	 * @return string|null The search, or null when absent.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function search(IRequest $request): ?string {
		return self::optionalString(request: $request, param: 'search');
	}//end search()

	/**
	 * An optional request param, as a string.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param string   $param   The param's name.
	 *
	 * @return string|null The value, or null when absent.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function optionalString(IRequest $request, string $param): ?string {
		$value = $request->getParam($param);
		if ($value === null) {
			return null;
		}

		return (string)$value;
	}//end optionalString()

	/**
	 * A step that links the item a required numeric id param names: 400 without it, 201 with the link.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param string   $param   The id param's name.
	 * @param Closure  $link    Given the object and the id, links the item; returns the link.
	 *
	 * @return Closure The step.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function linkById(IRequest $request, string $param, Closure $link): Closure {
		return static function (ObjectEntity $object) use ($request, $param, $link): JSONResponse {
			$itemId = (int)$request->getParam($param, 0);
			if ($itemId === 0) {
				return LinkedSourceResponses::required(param: $param);
			}

			return LinkedSourceResponses::created(link: $link($object, $itemId));
		};
	}//end linkById()

	/**
	 * A step that links with required text params, read and checked in order: 400 for the first blank one, else 201.
	 *
	 * @param IRequest $request The HTTP request.
	 * @param string[] $params  The text params' names.
	 * @param Closure  $link    Given the object and the texts (untrimmed), links the item; returns the link.
	 *
	 * @return Closure The step.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function linkByTexts(IRequest $request, array $params, Closure $link): Closure {
		return static function (ObjectEntity $object) use ($request, $params, $link): JSONResponse {
			$texts = [];
			foreach ($params as $param) {
				$texts[] = (string)$request->getParam($param, '');
				if (trim(end($texts)) === '') {
					return LinkedSourceResponses::required(param: $param);
				}
			}

			return LinkedSourceResponses::created(link: $link($object, ...$texts));
		};
	}//end linkByTexts()

	/**
	 * A step that removes a link of the object.
	 *
	 * @param Closure $unlink Given the object's uuid, removes the link.
	 *
	 * @return Closure The step.
	 *
	 * @spec openspec/specs/generic-integrations/spec.md#requirement-tier-2-integration-leaf-link-controller-contract
	 */
	public static function unlink(Closure $unlink): Closure {
		return static function (ObjectEntity $object) use ($unlink): JSONResponse {
			$unlink($object->getUuid());

			return LinkedSourceResponses::unlinked();
		};
	}//end unlink()
}//end class
