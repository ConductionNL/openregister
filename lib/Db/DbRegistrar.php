<?php

/**
 * DbRegistrar: container registrations of the Db component.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Db
 * @package  OCA\OpenRegister\Db
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

namespace OCA\OpenRegister\Db;

use OCA\OpenRegister\Files\Db\EntityRelationMapperInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Binds the interfaces other components type against to the Db classes
 * that implement them.
 *
 * An alias resolves to the class's own shared instance, so a caller that
 * injects the interface and one that injects the class by name get the
 * same object.
 *
 * @spec exclude Container wiring; no behaviour of its own.
 */
final class DbRegistrar {

	/**
	 * The interfaces callers outside the component type against, bound to their class.
	 *
	 * @var array<class-string, class-string>
	 */
	public const BINDINGS = [
		AuditTrailMapperInterface::class => AuditTrailMapper::class,
		EntityRelationMapperInterface::class => EntityRelationMapper::class,
		FlowRunMapperInterface::class => FlowRunMapper::class,
		MagicMapperInterface::class => MagicMapper::class,
		OrganisationMapperInterface::class => OrganisationMapper::class,
		RegisterMapperInterface::class => RegisterMapper::class,
		SchemaMapperInterface::class => SchemaMapper::class,
		TranslationMapperInterface::class => TranslationMapper::class,
	];

	/**
	 * Register every binding with the app container.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec exclude Container wiring; no behaviour of its own.
	 */
	public static function register(IRegistrationContext $context): void {
		foreach (self::BINDINGS as $contract => $class) {
			$context->registerServiceAlias($contract, $class);
		}
	}//end register()
}//end class
