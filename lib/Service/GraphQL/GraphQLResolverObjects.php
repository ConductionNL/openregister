<?php

/**
 * OpenRegister GraphQLResolverObjects
 *
 * The object and register collaborators the GraphQL resolver reads and writes through.
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

use OCA\OpenRegister\Db\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Object\GetObject;
use OCA\OpenRegister\Service\Object\RelationHandler;

/**
 * The object and register collaborators the GraphQL resolver reads and writes through.
 *
 * Groups collaborators of {@see GraphQLResolver} so its constructor stays small;
 * the container autowires it.
 */
final class GraphQLResolverObjects {

	/**
	 * Bundle the collaborators.
	 *
	 * @param GetObject                 $getObject       Object finder
	 * @param ObjectOperationsInterface $objectService   Object service
	 * @param RelationHandler           $relationHandler Relation handler
	 * @param RegisterMapperInterface   $registerMapper  Register mapper
	 */
	public function __construct(
		public readonly GetObject $getObject,
		public readonly ObjectOperationsInterface $objectService,
		public readonly RelationHandler $relationHandler,
		public readonly RegisterMapperInterface $registerMapper,
	) {
	}//end __construct()
}//end class
