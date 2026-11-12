<?php

/**
 * OpenRegister objects provider output
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Search
 * @package  OCA\OpenRegister\Search
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

namespace OCA\OpenRegister\Search;

use OCA\OpenRegister\Service\Search\ObjectSearchResultFormatterInterface;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * How the objects search provider names itself, formats its entries and reports problems.
 *
 * @spec openspec/specs/zoeken-filteren/spec.md
 */
final class ObjectsProviderOutput {
	/**
	 * Bundle the services.
	 *
	 * @param IL10N                                $l10n            The localization service.
	 * @param ObjectSearchResultFormatterInterface $resultFormatter Shared result-formatting service.
	 * @param LoggerInterface                      $logger          Logger for debugging search operations.
	 *
	 * @spec openspec/specs/zoeken-filteren/spec.md
	 */
	public function __construct(
		public readonly IL10N $l10n,
		public readonly ObjectSearchResultFormatterInterface $resultFormatter,
		public readonly LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
