<?php

/**
 * OpenRegister ManifestService
 *
 * Enriches a host-app manifest.json with a `runtime.user` block derived
 * from the requesting user's OpenRegister profile object.
 *
 * Behaviour summary
 * -----------------
 * 1. If the manifest carries no `currentUserSchema` key → return unchanged.
 * 2. Anonymous request            → `runtime.user = null`.
 * 3. Logged-in user, no profile   → `runtime.user = { id, roles: ["learner"] }`.
 * 4. Logged-in user, profile found → `runtime.user` populated with every
 *    `x-openregister-calculations.*` field evaluated against the profile object.
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
 *
 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service;

use DateTimeInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Service\Calculation\CalculationEvaluatorInterface;
use OCA\OpenRegister\Service\Calculation\EvaluationException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Enriches a manifest with `runtime.user` context for the authenticated user.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Composes ObjectService, SchemaMapper,
 *   CalculationEvaluator, IUserSession, and LoggerInterface; each serves a distinct
 *   concern in the profile-lookup, calculation-evaluation, and manifest-enrichment pipeline.
 *
 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
 */
class ManifestService implements ManifestServiceInterface {
	/**
	 * Maximum accepted length of a manifest-supplied schema slug.
	 *
	 * Bounded length + a strict charset on schemaSlug keep a compromised
	 * manifest.json from steering profile/calculation lookup at arbitrary
	 * schemas — see the manifest review note "schemaSlug not validated".
	 *
	 * @var int
	 */
	private const MAX_SLUG_LENGTH = 128;

	/**
	 * Regex for accepted schema-slug characters. Mirrors the slugs we
	 * actually emit (lowercase alnum + dash + underscore).
	 *
	 * @var string
	 */
	private const SLUG_PATTERN = '/^[A-Za-z0-9_\-]{1,128}$/';

	/**
	 * Manifest annotation that declares which profile-object fields are
	 * safe to surface in `runtime.user`. When the schema's configuration
	 * carries no such allowlist, ONLY the fields named by the calling
	 * manifest's calculation map are surfaced. Raw `profile->getObject()`
	 * fields are never blindly merged into runtime.user — that was the
	 * leak path flagged in the review.
	 *
	 * @var string
	 */
	private const FIELD_ALLOWLIST_KEY = 'x-openregister-manifest-user-fields';

	/**
	 * Built-in profile fields that are ALWAYS safe to surface (no PII,
	 * already exposed via other Nextcloud APIs for the same user).
	 *
	 * @var list<string>
	 */
	private const DEFAULT_SAFE_FIELDS = ['id', 'roles'];

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService OR object retrieval service.
	 * @param SchemaMapperInterface $schemaMapper Schema lookup by slug.
	 * @param CalculationEvaluatorInterface $evaluator Expression evaluator.
	 * @param IUserSession $userSession Nextcloud user session.
	 * @param LoggerInterface $logger PSR logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly SchemaMapperInterface $schemaMapper,
		private readonly CalculationEvaluatorInterface $evaluator,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the manifest enriched with a `runtime.user` block.
	 *
	 * @param array<string, mixed> $manifest Parsed manifest.json from the calling app.
	 *
	 * @return array<string, mixed> Enriched manifest (original if no `currentUserSchema`).
	 *
	 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
	 */
	public function getEnrichedManifest(array $manifest): array {
		// 1. No currentUserSchema declared → return unchanged.
		$schemaSlug = ($manifest['currentUserSchema'] ?? null);
		if ($schemaSlug === null || $schemaSlug === '') {
			return $manifest;
		}

		$manifest['runtime']['user'] = $this->runtimeUser(schemaSlug: $schemaSlug);
		return $manifest;
	}//end getEnrichedManifest()

	/**
	 * The `runtime.user` block for a declared schema slug.
	 *
	 * The slug is validated, and the user resolved, before ANY lookup runs.
	 *
	 * @param mixed $schemaSlug The manifest's currentUserSchema value (not empty).
	 *
	 * @return array<string, mixed>|null Null for an invalid slug or an anonymous request.
	 *
	 * @spec openspec/specs/openregister-app-manifest/spec.md#req-or-man-013
	 */
	private function runtimeUser(mixed $schemaSlug): ?array {
		// 2. Invalid slug (logged) or anonymous request → runtime.user = null.
		$user = $this->userSession->getUser();
		if ($this->isValidSlug(schemaSlug: $schemaSlug) === false || $user === null) {
			return null;
		}

		$userId  = $user->getUID();
		$profile = $this->resolveUserProfile(schemaSlug: (string) $schemaSlug, userId: $userId);
		if ($profile === null) {
			// 3. No profile → minimal fallback.
			return ['id' => $userId, 'roles' => ['learner']];
		}

		// 4. Profile found → evaluate calculations and inject.
		return $this->buildUserContext(userId: $userId, profile: $profile, schemaSlug: (string) $schemaSlug);
	}//end runtimeUser()

	/**
	 * Whether a manifest-supplied slug may be used for a lookup; logs a rejection.
	 *
	 * A compromised or malformed manifest must not be able to point
	 * profile/calculation resolution at an attacker-controlled string
	 * (length, charset, non-printable injection). Fails closed.
	 *
	 * @param mixed $schemaSlug The slug to check.
	 *
	 * @return bool True when the slug is a bounded string of the accepted charset.
	 */
	private function isValidSlug(mixed $schemaSlug): bool {
		if (is_string($schemaSlug) === true
			&& strlen($schemaSlug) <= self::MAX_SLUG_LENGTH
			&& preg_match(self::SLUG_PATTERN, $schemaSlug) === 1
		) {
			return true;
		}

		$this->logger->warning(
			message: sprintf('[ManifestService] Rejected invalid currentUserSchema slug %s', var_export($schemaSlug, true)),
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
		return false;
	}//end isValidSlug()

	/**
	 * Locate the user's profile object for the given schema slug.
	 *
	 * Filters by `ncUserId === $userId` inside the magic table for that schema.
	 *
	 * @param string $schemaSlug Schema slug declared in the manifest.
	 * @param string $userId     Nextcloud user ID.
	 *
	 * @return ObjectEntity|null The profile object, or null if not found.
	 */
	private function resolveUserProfile(string $schemaSlug, string $userId): ?ObjectEntity {
		if ($this->profileSchemaExists(schemaSlug: $schemaSlug) === false) {
			return null;
		}

		return $this->findProfile(schemaSlug: $schemaSlug, userId: $userId);
	}//end resolveUserProfile()

	/**
	 * Whether the schema named by the slug exists; logs why when it does not.
	 *
	 * @param string $schemaSlug Validated schema slug.
	 *
	 * @return bool True when the schema was found.
	 */
	private function profileSchemaExists(string $schemaSlug): bool {
		$schemas = $this->profileSchemas(schemaSlug: $schemaSlug);
		if ($schemas === null) {
			return false;
		}

		if (count($schemas) === 0) {
			$this->logger->debug(
				message: sprintf('[ManifestService] Schema "%s" not found', $schemaSlug),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return false;
		}

		return true;
	}//end profileSchemaExists()

	/**
	 * The schemas matching the slug; logs a failed lookup.
	 *
	 * @param string $schemaSlug Validated schema slug.
	 *
	 * @return array|null The schemas (at most one), or null when the lookup failed.
	 */
	private function profileSchemas(string $schemaSlug): ?array {
		try {
			return $this->schemaMapper->findBySlug(slug: $schemaSlug, limit: 1);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[ManifestService] Schema lookup failed for "%s": %s', $schemaSlug, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}
	}//end profileSchemas()

	/**
	 * Find the profile object of the user in the schema.
	 *
	 * @param string $schemaSlug Validated schema slug.
	 * @param string $userId     Nextcloud user ID.
	 *
	 * @return ObjectEntity|null The first match, or null when none or the lookup failed.
	 */
	private function findProfile(string $schemaSlug, string $userId): ?ObjectEntity {
		try {
			// RBAC + multitenancy MUST remain on. The profile lookup is per
			// current user — disabling RBAC/multitenancy here would silently
			// return profiles from other tenants whenever the same NC UID
			// exists in more than one tenant. The (schema, ncUserId) filter
			// is a *narrowing* filter on top of the tenant scope, not a
			// substitute for it.
			$results = $this->objectService->findAll(config: ['limit' => 1, 'filters' => ['schema' => $schemaSlug, 'ncUserId' => $userId]]);
		} catch (Throwable $e) {
			$this->logger->warning(
				message: sprintf('[ManifestService] Profile lookup failed for user "%s": %s', $userId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return null;
		}

		if (count($results) > 0) {
			return $results[0];
		}

		return null;
	}//end findProfile()

	/**
	 * Build the `runtime.user` block from the profile object.
	 *
	 * Copies only the allowlisted profile fields and then overlays every
	 * `x-openregister-calculations.*` result that is NOT materialised
	 * (materialised fields are already in the stored data). Non-materialised
	 * calculations are evaluated at read-time here so that the manifest always
	 * reflects the freshest derived state.
	 *
	 * @param string       $userId     Nextcloud user ID (always included as `id`).
	 * @param ObjectEntity $profile    The user's profile object entity.
	 * @param string       $schemaSlug Schema slug (used for schema re-lookup to read calcs).
	 *
	 * @return array<string, mixed> The `runtime.user` data block.
	 */
	private function buildUserContext(string $userId, ObjectEntity $profile, string $schemaSlug): array {
		$data = $profile->getObject() ?? [];

		// Resolve the field allowlist BEFORE copying anything from the
		// profile object. Without an allowlist every stored field — including
		// schema-internal or PII fields — would leak verbatim to anything
		// consuming the manifest.
		$calcs   = $this->getCalculations(schemaSlug: $schemaSlug);
		$allowed = $this->resolveAllowedFieldNames(schemaSlug: $schemaSlug, calcs: $calcs);

		// Always surface the Nextcloud user ID as `id`.
		$context = array_merge($this->pickFields(data: $data, names: $allowed), ['id' => $userId]);
		if ($calcs === null) {
			return $context;
		}

		$data['@self'] = $this->buildSelfMeta(profile: $profile);
		return $this->applyCalculations(context: $context, data: $data, calcs: $calcs, userId: $userId);
	}//end buildUserContext()

	/**
	 * The named fields present in the data, in the order of the names.
	 *
	 * @param array<string, mixed> $data  The profile data.
	 * @param list<string>         $names The allowed field names.
	 *
	 * @return array<string, mixed> The picked fields.
	 */
	private function pickFields(array $data, array $names): array {
		$picked = [];
		foreach ($names as $field) {
			if (array_key_exists($field, $data) === true) {
				$picked[$field] = $data[$field];
			}
		}

		return $picked;
	}//end pickFields()

	/**
	 * Build the `@self` metadata block injected into the evaluation data map
	 * so calculations can reference `@self.created`, `@self.uuid`, etc.
	 *
	 * @param ObjectEntity $profile The profile entity.
	 *
	 * @return array<string, mixed>
	 */
	private function buildSelfMeta(ObjectEntity $profile): array {
		return [
			'id' => $profile->getUuid(),
			'uuid' => $profile->getUuid(),
			'register' => $profile->getRegister(),
			'schema' => $profile->getSchema(),
			'owner' => $profile->getOwner(),
			'created' => $this->atom(moment: $profile->getCreated()),
			'updated' => $this->atom(moment: $profile->getUpdated()),
		];
	}//end buildSelfMeta()

	/**
	 * Format a moment as ATOM, or null when absent.
	 *
	 * @param DateTimeInterface|null $moment The moment.
	 *
	 * @return string|null The ATOM string, or null.
	 */
	private function atom(?DateTimeInterface $moment): ?string {
		if ($moment === null) {
			return null;
		}

		return $moment->format(DateTimeInterface::ATOM);
	}//end atom()

	/**
	 * Evaluate the calculations from the schema and merge the non-materialised
	 * results into $context.
	 *
	 * @param array<string, mixed> $context Base context (already contains filtered profile fields).
	 * @param array<string, mixed> $data    Evaluation data including `@self`.
	 * @param array<string, mixed> $calcs   Calculation spec map from the schema.
	 * @param string               $userId  Nextcloud user id (used in warning messages only).
	 *
	 * @return array<string, mixed> Enriched context.
	 */
	private function applyCalculations(array $context, array $data, array $calcs, string $userId): array {
		foreach ($calcs as $name => $spec) {
			if (is_array($spec) === false) {
				continue;
			}

			$outcome = $this->evaluateCalculation(name: (string) $name, spec: $spec, data: $data, userId: $userId);
			// Only inject non-materialised computations here; materialised ones
			// are already stored in the profile payload and surfaced via $context.
			if ($outcome['ok'] === true && ($spec['materialise'] ?? false) !== true) {
				$context[(string) $name] = $this->serialise(value: $outcome['value']);
			}
		}

		return $context;
	}//end applyCalculations()

	/**
	 * Evaluate one calculation; a failing expression is logged and reported as not ok.
	 *
	 * @param string               $name   The calculation name.
	 * @param array<string, mixed> $spec   The calculation spec.
	 * @param array<string, mixed> $data   Evaluation data including `@self`.
	 * @param string               $userId Nextcloud user id (used in warning messages only).
	 *
	 * @return array{ok: bool, value: mixed} Whether it evaluated, and its raw value.
	 */
	private function evaluateCalculation(string $name, array $spec, array $data, string $userId): array {
		try {
			return ['ok' => true, 'value' => $this->evaluator->evaluate($data, ($spec['expression'] ?? null))];
		} catch (EvaluationException $e) {
			$this->logger->warning(
				message: sprintf('[ManifestService] Calculation "%s" failed for user "%s": %s', $name, $userId, $e->getMessage()),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			return ['ok' => false, 'value' => null];
		}
	}//end evaluateCalculation()

	/**
	 * Read `x-openregister-calculations` from the schema identified by slug.
	 *
	 * @param string $schemaSlug The schema slug.
	 *
	 * @return array<string, mixed>|null The calculations map, or null when absent.
	 */
	private function getCalculations(string $schemaSlug): ?array {
		$calcs = ($this->schemaConfiguration(schemaSlug: $schemaSlug)['x-openregister-calculations'] ?? null);
		if (is_array($calcs) === true) {
			return $calcs;
		}

		return null;
	}//end getCalculations()

	/**
	 * Resolve the list of profile fields safe to copy into runtime.user.
	 *
	 * Source of truth, in order:
	 * 1. The default safe fields (id, roles).
	 * 2. `x-openregister-manifest-user-fields` on the schema configuration
	 *    — an explicit author-provided allowlist (list<string>).
	 * 3. The keys of `x-openregister-calculations` whose entries are
	 *    materialised — these are derived values the schema author has
	 *    already declared safe to surface.
	 *
	 * NEVER returns the full key list of the profile object: that was the
	 * leak path flagged by review (raw `$profile->getObject()` merge).
	 *
	 * @param string                    $schemaSlug Validated schema slug.
	 * @param array<string, mixed>|null $calcs      Calculation map for the schema.
	 *
	 * @return list<string> Distinct field names allowed in runtime.user.
	 */
	private function resolveAllowedFieldNames(string $schemaSlug, ?array $calcs): array {
		$allowed = array_merge(
			self::DEFAULT_SAFE_FIELDS,
			($this->loadFieldAllowlist(schemaSlug: $schemaSlug) ?? []),
			$this->materialisedNames(calcs: ($calcs ?? []))
		);

		return array_values(array_unique($allowed));
	}//end resolveAllowedFieldNames()

	/**
	 * The string names of the materialised calculations.
	 *
	 * @param array<int|string, mixed> $calcs Calculation map for the schema.
	 *
	 * @return list<string> The names.
	 */
	private function materialisedNames(array $calcs): array {
		$names = [];
		foreach ($calcs as $name => $spec) {
			if (is_string($name) === true && is_array($spec) === true && ($spec['materialise'] ?? false) === true) {
				$names[] = $name;
			}
		}

		return $names;
	}//end materialisedNames()

	/**
	 * Load the explicit user-field allowlist from the schema configuration.
	 *
	 * @param string $schemaSlug Validated schema slug.
	 *
	 * @return list<string>|null List of allowed field names, or null when
	 *                           the schema declares no allowlist.
	 */
	private function loadFieldAllowlist(string $schemaSlug): ?array {
		$allowlist = ($this->schemaConfiguration(schemaSlug: $schemaSlug)[self::FIELD_ALLOWLIST_KEY] ?? null);
		if (is_array($allowlist) === false) {
			return null;
		}

		return array_values(
			array_filter(
				$allowlist,
				static fn (mixed $entry): bool => is_string($entry) === true && $entry !== ''
			)
		);
	}//end loadFieldAllowlist()

	/**
	 * The configuration of the schema identified by slug.
	 *
	 * @param string $schemaSlug Validated schema slug.
	 *
	 * @return array<string, mixed>|null The configuration, or null when the schema is absent or unreadable.
	 */
	private function schemaConfiguration(string $schemaSlug): ?array {
		try {
			$schemas = $this->schemaMapper->findBySlug(slug: $schemaSlug, limit: 1);
		} catch (Throwable) {
			return null;
		}

		if (count($schemas) === 0) {
			return null;
		}

		return ($schemas[0]->getConfiguration() ?? []);
	}//end schemaConfiguration()

	/**
	 * Serialise a calculation result into a JSON-friendly value.
	 *
	 * @param mixed $value Raw evaluator output.
	 *
	 * @return mixed JSON-safe representation.
	 */
	private function serialise(mixed $value): mixed {
		if ($value instanceof DateTimeInterface) {
			return $this->atom(moment: $value);
		}

		return $value;
	}//end serialise()
}//end class
