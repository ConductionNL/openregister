<?php

/**
 * OpenRegister MagicRbacSupport
 *
 * What magic-table RBAC evaluates with: the shared condition matcher, the container and the logger.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Db
 * @package  OCA\OpenRegister\Db\MagicMapper
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db\MagicMapper;

use OCA\OpenRegister\Service\ConditionMatcher;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * What magic-table RBAC evaluates with: the shared condition matcher, the container and the logger.
 *
 * @spec openspec/specs/rbac-scopes/spec.md
 */
final readonly class MagicRbacSupport {
	/**
	 * Bundle the collaborators.
	 *
	 * @param ConditionMatcher   $conditionMatcher Shared PHP-side match evaluator (the SQL emitter stays in the handler).
	 * @param ContainerInterface $container        Container for service injection.
	 * @param LoggerInterface    $logger           Logger for debugging.
	 */
	public function __construct(
		public ConditionMatcher $conditionMatcher,
		public ContainerInterface $container,
		public LoggerInterface $logger,
	) {
	}//end __construct()
}//end class
