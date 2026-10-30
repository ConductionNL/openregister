<?php

/**
 * The Kernel contract bindings of the Db layer.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Kernel\Contracts\AuditTrailMapperInterface;
use OCA\OpenRegister\Db\MagicMapper\MagicRbacHandler;
use OCA\OpenRegister\Db\MagicMapper\MagicTableHandler;
use OCA\OpenRegister\Kernel\Contracts\MagicMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectAccessRulesInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectTableLocatorInterface;
use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaMapperInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers each Db contract as an alias of the mapper that implements it.
 *
 * An alias resolves to the mapper's own shared instance, so a class that
 * injects the contract and one that injects the mapper by its class name get
 * the same object. Other apps keep resolving the mappers by class name.
 *
 * @spec openspec/specs/objects-crud/spec.md
 */
final class DbContractBindings {

	/**
	 * Contract => the mapper that implements it.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		AuditTrailMapperInterface::class => AuditTrailMapper::class,
		MagicMapperInterface::class => MagicMapper::class,
		ObjectAccessRulesInterface::class => MagicRbacHandler::class,
		ObjectTableLocatorInterface::class => MagicTableHandler::class,
		RegisterMapperInterface::class => RegisterMapper::class,
		SchemaMapperInterface::class => SchemaMapper::class,
	];

	/**
	 * Register every binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/objects-crud/spec.md
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $mapper) {
			$context->registerServiceAlias($contract, $mapper);
		}
	}//end register()
}//end class
