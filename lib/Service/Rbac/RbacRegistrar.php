<?php

/**
 * The RBAC service registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Rbac
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Rbac;

use OCA\OpenRegister\Kernel\Contracts\RegisterMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\SchemaMapperInterface;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the shared grant, hierarchy and reveal services that the permission path reads.
 *
 * Application::register() calls register() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every service it wires.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength)  Factory closures list every constructor argument.
 */
final class RbacRegistrar {

	/**
	 * Register the shared token-grant, hierarchy-grant and reveal services.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/archive/retrofit-b2b-crossrefs-2026-04-28/tasks.md
	 */
	public static function register(IRegistrationContext $context): void {
		// 🔴 THE TOKEN GRANT SOURCE MUST BE SHARED, and this is not a
		// performance argument. It is BOUND in the authentication path, where a
		// Consumer is resolved, and READ in the permission handler, where the
		// decision is made. An unshared registration would give those two
		// different objects: the bind would land on one and the read would find
		// an empty other, so every scoped token would silently evaluate as
		// unscoped — a widening, arriving in total silence (row Q13.20).
		$context->registerService(
			TokenGrantSource::class,
			static function () {
				return new TokenGrantSource();
			}
		);

		$context->registerService(
			TokenGrantNarrower::class,
			static function () {
				return new TokenGrantNarrower();
			}
		);

		// The object-hierarchy descent MUST be shared, for the reason the three
		// registrations below it give and one that is sharper here: both of
		// these memoise FOR THE LIFETIME OF ONE REQUEST, and a container that
		// builds an auto-wired class fresh at every injection point would turn
		// a per-request memo into a per-injection one. That is not merely slow.
		// `HierarchyDescender` reads every schema and every register to find
		// the declarations, so an unshared instance pays that on each of the
		// several paths that consult a grant, on every request that holds one.
		//
		// Registered EXPLICITLY rather than left to autowiring for a second
		// reason: `ObjectGrantResolver` takes the expander as a NULLABLE
		// argument, so a wiring failure there would not raise, it would simply
		// stop inheriting grants and say nothing. A named registration is what
		// makes that failure loud (ledger row Q13.23).
		$context->registerService(
			HierarchyDescender::class,
			static function ($c) {
				return new HierarchyDescender(
					db: $c->get(\OCP\IDBConnection::class),
					schemaMapper: $c->get(SchemaMapperInterface::class),
					registerMapper: $c->get(RegisterMapperInterface::class),
					logger: $c->get(\Psr\Log\LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			HierarchyGrantExpander::class,
			static function ($c) {
				return new HierarchyGrantExpander(
					descender: $c->get(HierarchyDescender::class),
					logger: $c->get(\Psr\Log\LoggerInterface::class),
				);
			}
		);

		$context->registerService(
			ObjectGrantResolver::class,
			static function ($c) {
				return new ObjectGrantResolver(
					logger: $c->get(\Psr\Log\LoggerInterface::class),
					container: $c,
					hierarchy: $c->get(HierarchyGrantExpander::class),
				);
			}
		);

		// The reveal collector MUST be shared, and for the sharpest reason on
		// this list: it collects during rendering and is flushed ONCE at the
		// end of the request (ledger row 5.6, D-2). A container that built an
		// auto-wired class fresh at every injection point would give the
		// renderer one instance and the flush another, so the flush would find
		// nothing and every reveal of a BSN would go unrecorded — with no
		// error, and with an audit page that looks like a quiet day.
		$context->registerService(
			RevealCollector::class,
			static function () {
				return new RevealCollector();
			}
		);

		// Shared for the same reason: the grant ObjectsController::show makes
		// must be the one the render path reads (property-explicit-reveal).
		$context->registerService(
			ExplicitReveal::class,
			static function () {
				return new ExplicitReveal();
			}
		);
	}//end register()
}//end class
