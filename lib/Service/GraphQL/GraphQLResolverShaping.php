<?php

/**
 * OpenRegister GraphQLResolverShaping
 *
 * The translation and aggregation collaborators that shape a GraphQL result.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service\GraphQL;

/**
 * The translation and aggregation collaborators that shape a GraphQL result.
 *
 * Groups collaborators of {@see GraphQLResolver} so its constructor stays small;
 * the container autowires it.
 */
final class GraphQLResolverShaping {

	/**
	 * Bundle the collaborators.
	 *
	 * @param \OCA\OpenRegister\Service\Object\TranslationHandler $translationHandler Translation handler
	 * @param ListAggregations                                    $aggregations       Runs a list query's `groupBy` and declared aggregation.
	 */
	public function __construct(
		public readonly \OCA\OpenRegister\Service\Object\TranslationHandler $translationHandler,
		public readonly ListAggregations $aggregations,
	) {
	}//end __construct()
}//end class
