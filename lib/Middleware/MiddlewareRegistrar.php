<?php

/**
 * The middleware registrations of OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers OpenRegister's controller middlewares. Nextcloud runs them in
 * registration order, so the order below is part of their behaviour.
 *
 * Application::register() calls register() once.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) A registrar names every middleware it wires.
 */
final class MiddlewareRegistrar {

	/**
	 * Register the middlewares, in the order Nextcloud runs them.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 */
	public static function register(IRegistrationContext $context): void {
		// Register the LanguageMiddleware for Accept-Language header parsing.
		$context->registerMiddleware(LanguageMiddleware::class);

		// Register the TenantQuotaMiddleware for tenant quota enforcement and status checks.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\TenantQuotaMiddleware::class);

		// Register the OasValidationMiddleware for opt-in request-body
		// validation against per-operation OAS schemas. Activates only on
		// POST/PUT/PATCH with `?_validate=true`; pass-through otherwise.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\OasValidationMiddleware::class);

		// Writes the request's reveals of audited properties, once, after the
		// controller has answered (ledger row 5.6, D-2). Registered LAST of the
		// middlewares so it runs closest to the response: everything the read
		// path was going to collect has been collected by then, and a
		// middleware that flushed earlier would write a shorter trail than the
		// request actually produced.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\RevealAuditMiddleware::class);

		// Register the RateLimitMiddleware to wire SecurityService brute-force
		// protection into the inbound API auth path (issue #1834). Records
		// failed Basic/Bearer/session auth on protected endpoints (keyed on
		// identity+trusted-IP) and pre-emptively blocks locked-out callers.
		// Fail-OPEN: any limiter error allows the request through.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\RateLimitMiddleware::class);

		// Register the PublicApiCorsMiddleware: reflects the request Origin onto
		// `@PublicPage` responses so browsers on other origins (e.g. a marketing
		// site posting a lead into a public-create schema) can read the result.
		// Scoped to public endpoints only and never sets allow-credentials.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\PublicApiCorsMiddleware::class);

		// Register the ChatCompatMiddleware (or-chat-proxy-deprecation): adds
		// Deprecation/Sunset/Link response headers to every chat/agents/
		// conversations controller response and forwards those requests
		// server-side to hermiq. ON by default since
		// or-chat-engine-decommission; operators opt out via the
		// `openregister.chat.proxyTo` appconfig value (any value != 'hermiq').
		// Any failure falls back to serving the request locally unchanged.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\ChatCompatMiddleware::class);

		// Register the ObjectSourceErrorMiddleware (dbal-virtual-registers D8):
		// maps DbalObjectSourceException thrown during reads against an
		// external-database-backed schema onto its declared 502/503 status —
		// an unreachable or failing external database must never surface as a
		// bare 500. All other exceptions are rethrown untouched.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\ObjectSourceErrorMiddleware::class);

		// Register the EncryptedFieldFilterMiddleware (field-level-object-encryption):
		// maps EncryptedFieldFilterException — thrown when a caller filters/
		// searches/facets on a field flagged `x-openregister-encrypted` — onto a
		// clear HTTP 400 instead of a silent zero-row result or a bare 500.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\EncryptedFieldFilterMiddleware::class);

		// Register the UnknownMetadataFieldMiddleware: maps
		// UnknownMetadataFieldException — thrown when a `@self` filter names a
		// field that no metadata column corresponds to — onto an HTTP 400 that
		// names the field and lists the filterable ones, instead of the opaque
		// driver-level 500 an unresolvable column name used to produce.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\UnknownMetadataFieldMiddleware::class);

		// Register the RelatedRowFilterMiddleware: maps
		// RelatedRowFilterNotSupportedException — a `_related`/`_referenced`
		// filter on a multi-schema facet route that cannot honour it — onto an
		// HTTP 400 with the exception's message, not an uncaught 500.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\RelatedRowFilterMiddleware::class);

		// Register the MaintenanceModeMiddleware (admin-operations-console
		// D-6): while maintenance mode holds, every controller but the
		// operations console is refused with the administered message, so the
		// instance can be closed without locking out the administrator who
		// closed it and has to open it again.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\MaintenanceModeMiddleware::class);

		// Register the ApiVersionMiddleware (api-as-a-versioned-surface): names
		// the contract version that answered on every API response, carries the
		// RFC 8594 end date when that version is deprecated, and refuses a call
		// naming a withdrawn version with 410 or an undeclared one with 400.
		// It decorates and refuses; it never changes which controller runs, so a
		// deprecated version keeps exactly the behaviour it had before.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\ApiVersionMiddleware::class);

		// Register the ApiCallerMiddleware (api-as-a-versioned-surface): binds a
		// caller to its administered source addresses, bounds it to its
		// administered ceiling, and records which route and version it called.
		// Registered AFTER ApiVersionMiddleware so the version this call speaks
		// is already resolvable when the record is written.
		//
		// An instance that has administered neither a ceiling nor a binding
		// behaves exactly as it did before: the limiter fails open, the binding
		// only refuses a caller an administrator actually bound, and the record
		// swallows its own failures.
		$context->registerMiddleware(\OCA\OpenRegister\Middleware\ApiCallerMiddleware::class);
	}//end register()
}//end class
