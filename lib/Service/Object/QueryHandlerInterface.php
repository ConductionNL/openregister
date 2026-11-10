<?php

/**
 * QueryHandlerInterface: what callers outside the Service component read from QueryHandler.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Object
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

namespace OCA\OpenRegister\Service\Object;

/**
 * The page-size cap the objects controller clamps to. Outside lib/Service only
 * this constant is read; QueryHandler implements the interface and so keeps
 * `QueryHandler::MAX_PAGE_SIZE` working for its own component.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md#requirement-view-based-search-composition
 */
interface QueryHandlerInterface {

	/**
	 * Hard maximum page size for list/search requests. A client-supplied
	 * `_limit` above this is clamped down, so an oversized request (e.g.
	 * `_limit=1000000`) cannot force an unbounded result load (DoS/OOM).
	 */
	public const MAX_PAGE_SIZE = 1000;
}//end interface
