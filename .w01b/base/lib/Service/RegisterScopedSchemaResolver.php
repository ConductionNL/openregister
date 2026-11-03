<?php

/**
 * OpenRegister RegisterScopedSchemaResolver
 *
 * The one implementation of "resolve this schema identifier INSIDE this register".
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
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

namespace OCA\OpenRegister\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Exception\RegisterNotFoundException;
use OCA\OpenRegister\Exception\SchemaNotInRegisterException;
use Throwable;

/**
 * Resolves a schema identifier within the register a caller named.
 *
 * WHY THIS CLASS EXISTS. Schema slugs are unique WITHIN a register, never across
 * the instance. `SchemaMapper::find()` matches `LOWER(slug)` GLOBALLY and returns
 * whichever row its tie-break orders first, so on any instance hosting more than
 * one app the same slug resolves to another app's schema. Measured on the shared
 * dev instance 2026-08-21: `TimeEntry` resolved to planix's schema 161 instead of
 * hrmq's 9466, and `Expense` to pipelinq's 507 instead of hrmq's 5026. A dashboard
 * `stat` widget therefore aggregated another app's rows. Single-app instances and
 * CI cannot reproduce it, which is why the defect keeps coming back.
 *
 * PR #2694 fixed this for `GET /api/schemas/{id}` by writing the scoped resolution
 * inline in `SchemasController`, where it was `private` and therefore unreachable
 * from the dozen other call sites that also hold a register ref. This class is that
 * logic lifted out verbatim — same identifier forms, same tie-breaks, same
 * exception wording — so every path that names a register enforces the SAME
 * boundary with the SAME diagnosis, instead of each one re-deriving a weaker
 * version of it.
 *
 * THE CONTRACT, in one line: a path that names a register MUST NEVER fall back to
 * instance-wide resolution. Neither an unresolvable register (a mistyped boundary
 * name is indistinguishable, from the caller's side, from a correct scoped hit) nor
 * an identifier form the earlier scoping happened not to cover (numeric ids and
 * uuids are as capable of pointing outside the register as slugs are) is a reason
 * to widen the scope back to the whole instance.
 *
 * It is deliberately a plain collaborator over two mappers with no state of its
 * own: consumers that already inject `RegisterMapper` + `SchemaMapper` construct it
 * directly rather than widening their constructors, which keeps existing unit tests
 * (all of which mock the two mappers) exercising the real resolution path.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
 */
class RegisterScopedSchemaResolver {

	/**
	 * Constructor.
	 *
	 * @param RegisterMapper $registerMapper Resolves the register ref (id, uuid, or slug).
	 * @param SchemaMapper   $schemaMapper   Resolves the schema ref within the register's carried ids.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly RegisterMapper $registerMapper,
		private readonly SchemaMapper $schemaMapper,
	) {
	}//end __construct()


	/**
	 * Resolve a register ref, refusing rather than widening when it does not resolve.
	 *
	 * An unresolvable register is NOT a reason to resolve the schema globally. The
	 * softening this replaces argued the caller "gets what they would have got
	 * without the parameter" — but the caller passed the parameter precisely to rule
	 * that read out, and a typo that silently reverts to instance-wide resolution
	 * looks identical to a correct scoped hit. Refusing loudly is the only
	 * observable behaviour.
	 *
	 * The lookup runs with `_rbac: false, _multitenancy: false`, matching the
	 * metadata-read it scopes: this resolves WHICH schema is meant, it grants
	 * nothing — the caller's own read-permission gate still runs on the result.
	 *
	 * @param int|string $registerRef The register id, uuid, or slug.
	 *
	 * @return Register The resolved register.
	 *
	 * @throws RegisterNotFoundException When the named register does not resolve.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function resolveRegister(int|string $registerRef): Register {
		try {
			return $this->registerMapper->find(id: $registerRef, _rbac: false, _multitenancy: false);
		} catch (Throwable $e) {
			// RegisterNotFoundException::__construct types `$previous` as
			// \Exception, but mapper failures can surface as any \Throwable — an
			// \Error would be dropped rather than chained.
			$previous = null;
			if (($e instanceof \Exception) === true) {
				$previous = $e;
			}

			throw new RegisterNotFoundException(
				registerSlugOrId: (string)$registerRef,
				previous: $previous,
				remedies: 'The schema was therefore not resolved, because naming a register makes it a '
					. 'boundary and falling back to instance-wide resolution would serve a schema from '
					. 'outside it. Omit the register to resolve the identifier globally.'
			);
		}
	}//end resolveRegister()


	/**
	 * Resolve a schema identifier among the schemas an already-resolved register carries.
	 *
	 * The boundary holds for EVERY identifier form — numeric id, uuid, and slug
	 * alike — because {@see SchemaMapper::findInIds()} mirrors `find()`'s identifier
	 * forms and tie-breaks constrained to the register's carried ids. Scoping slugs
	 * only (the earlier shape) left a numeric id or uuid resolving globally, which
	 * is the same silent cross-app read wearing a different identifier.
	 *
	 * @param Register   $register  The register that bounds the resolution.
	 * @param int|string $schemaRef The schema id, uuid, or slug.
	 *
	 * @return Schema The schema, resolved among the register's carried schemas only.
	 *
	 * @throws SchemaNotInRegisterException When the register does not carry the identifier.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function resolveSchemaWithin(Register $register, int|string $schemaRef): Schema {
		$registerSchemaIds = ($register->getSchemas() ?? []);

		$scoped = $this->schemaMapper->findInIds(id: $schemaRef, schemaIds: $registerSchemaIds);
		if ($scoped !== null) {
			return $scoped;
		}

		// THE BOUNDARY EXISTS FOR SLUGS, NOT FOR UNIQUE IDENTIFIERS.
		// A slug is not unique instance-wide — several registers legitimately
		// carry a `TimeEntry`, and resolving one globally is what served
		// another app's schema into a leaf app's forms and aggregations. A
		// numeric id and a uuid are unique BY CONSTRUCTION, so scoping them
		// adds no protection; all it can do is refuse a caller whose register
		// happens to have a stale `schemas` list. That refusal is exactly what
		// broke `POST /api/objects/{registerId}/{schemaId}` for existing
		// clients, so a unique identifier resolves globally and the membership
		// list is treated as the cache it is.
		if ($this->isUniqueIdentifier(ref: $schemaRef) === true) {
			try {
				return $this->schemaMapper->find($schemaRef);
			} catch (\Throwable) {
				// Genuinely absent, not merely unlisted — fall through to the
				// refusal below. The global lookup widens for AMBIGUITY, never
				// for absence.
			}
		}

		throw new SchemaNotInRegisterException(
			schemaSlug: (string)$schemaRef,
			registerId: $register->getId(),
			registerSlug: $register->getSlug(),
			candidatesElsewhere: $this->schemaMapper->countBySlug(slug: (string)$schemaRef),
			registerSchemaCount: count($registerSchemaIds)
		);
	}//end resolveSchemaWithin()


	/**
	 * Resolve a register ref and a schema ref together, with the register as the boundary.
	 *
	 * The pair is resolved in this order on purpose. Resolving the schema first and
	 * the register second is the shape that reads correctly and behaves wrongly: by
	 * the time the register is known, the schema has already been resolved against
	 * the whole instance, so the register can no longer bound anything. That
	 * ordering is exactly how the aggregation endpoints lost the boundary while
	 * still accepting a `{register}` path segment.
	 *
	 * @param int|string $registerRef The register id, uuid, or slug.
	 * @param int|string $schemaRef   The schema id, uuid, or slug.
	 *
	 * @return array{register: Register, schema: Schema} The resolved pair.
	 *
	 * @throws RegisterNotFoundException    When the named register does not resolve.
	 * @throws SchemaNotInRegisterException When the register does not carry the schema.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	public function resolvePair(int|string $registerRef, int|string $schemaRef): array {
		$register = $this->resolveRegister(registerRef: $registerRef);

		return [
			'register' => $register,
			'schema'   => $this->resolveSchemaWithin(register: $register, schemaRef: $schemaRef),
		];
	}//end resolvePair()
	/**
	 * Whether a schema reference is unique instance-wide by construction.
	 *
	 * Numeric ids and uuids identify exactly one schema; slugs do not. Only
	 * the ambiguous form needs the register as a boundary.
	 *
	 * @param int|string $ref The schema reference.
	 *
	 * @return bool True when the reference cannot be ambiguous.
	 *
	 * @spec openspec/specs/register-scoped-slug-resolution/spec.md
	 */
	private function isUniqueIdentifier(int|string $ref): bool {
		if (is_int($ref) === true) {
			return true;
		}

		if (ctype_digit($ref) === true) {
			return true;
		}

		return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) === 1;
	}//end isUniqueIdentifier()

}//end class
