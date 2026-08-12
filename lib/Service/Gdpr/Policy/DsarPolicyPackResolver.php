<?php

/**
 * OpenRegister Gdpr DsarPolicyPackResolver
 *
 * Reads the two integration-seam provider SELECTORS from the policy pack active
 * for a DSAR case. A case carries a `jurisdiction` key; the active pack is the
 * `dsarPolicyPack` whose `jurisdiction` matches, falling back to the shipped
 * neutral `default` pack (mirroring the declarative `x-openregister-references`
 * pack lookup on the case schema). From that pack this resolver returns the
 * `identityVerifyProvider` / `regulatorEscalateProvider` selector strings the
 * seam registries resolve to a live provider.
 *
 * The SELECTION is declarative (values on the pack, owned by the head change);
 * this resolver only reads it. When no pack resolves or the selector is unset,
 * it returns null so the registry falls back to its OR fail-closed default —
 * the null return is SAFE precisely because the registry never treats it as
 * "verified"/"escalated" (ADR-005 / CWE-863).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Gdpr\Policy
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

namespace OCA\OpenRegister\Service\Gdpr\Policy;

use OCA\OpenRegister\Service\ObjectService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the seam provider selectors from a case's active policy pack.
 */
class DsarPolicyPackResolver {

	/**
	 * Register slug the policy packs live under (declared by the head change).
	 *
	 * @var string
	 */
	public const REGISTER_SLUG = 'dsar-policy-packs';

	/**
	 * Schema slug of the policy-pack entity (declared by the head change).
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'dsarPolicyPack';

	/**
	 * Jurisdiction key of the neutral fail-closed baseline pack.
	 *
	 * @var string
	 */
	public const DEFAULT_JURISDICTION = 'default';

	/**
	 * Pack field naming the bound identity-verify provider.
	 *
	 * @var string
	 */
	public const IDENTITY_SELECTOR = 'identityVerifyProvider';

	/**
	 * Pack field naming the bound regulator-escalate provider.
	 *
	 * @var string
	 */
	public const REGULATOR_SELECTOR = 'regulatorEscalateProvider';

	/**
	 * Constructor.
	 *
	 * @param ObjectService $objectService RBAC + tenant scoped object store (pack read).
	 * @param LoggerInterface $logger Logger for pack-read diagnostics.
	 */
	public function __construct(
		private readonly ObjectService $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The active pack's `identityVerifyProvider` selector for a case.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return string|null The selector id, or null when unset/no pack resolves.
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-identity-verify-seam/spec.md
	 */
	public function identityVerifyProviderId(array $case): ?string {
		return $this->selectorFor(case: $case, field: self::IDENTITY_SELECTOR);
	}//end identityVerifyProviderId()

	/**
	 * The active pack's `regulatorEscalateProvider` selector for a case.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return string|null The selector id, or null when unset/no pack resolves.
	 *
	 * @spec openspec/changes/dsar-integration-seams/specs/dsar-regulator-escalate-seam/spec.md
	 */
	public function regulatorEscalateProviderId(array $case): ?string {
		return $this->selectorFor(case: $case, field: self::REGULATOR_SELECTOR);
	}//end regulatorEscalateProviderId()

	/**
	 * The full policy-pack payload active for a case (jurisdiction match,
	 * `default` fallback), or null when no pack resolves.
	 *
	 * Public read surface for consumers that need MORE than the two seam
	 * selectors: the DPIA pattern-detection job reads the pack's
	 * `dpiaDetection` block and the privacy-officer notification recipient
	 * resolver reads `privacyOfficerGroup`. Both inherit the resolver's
	 * fail-safe semantics — no pack ⇒ null ⇒ the consumer no-ops.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 * @param bool $systemContext True for background-job (user-less) reads: the
	 *                            pack query bypasses RBAC/tenancy scoping, because
	 *                            a system sweep has no session to scope by.
	 *
	 * @return array<string, mixed>|null The active pack payload, or null.
	 *
	 * @spec openspec/changes/dsar-escalation-and-dpia/specs/dsar-dpia-detection/spec.md
	 *   (Requirement: Detection configuration lives in the DSAR policy pack)
	 */
	public function activePackForCase(array $case, bool $systemContext = false): ?array {
		if ($systemContext === false) {
			return $this->resolveActivePack(case: $case);
		}

		$wanted = $this->stringField(data: $case, key: 'jurisdiction');
		$default = null;
		foreach ($this->loadPacks(_rbac: false, _multitenancy: false) as $pack) {
			$packJurisdiction = $this->stringField(data: $pack, key: 'jurisdiction');
			if ($wanted !== '' && $packJurisdiction === $wanted) {
				return $pack;
			}

			if ($packJurisdiction === self::DEFAULT_JURISDICTION) {
				$default = $pack;
			}
		}

		return $default;
	}//end activePackForCase()

	/**
	 * Read one selector field off the case's active pack.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 * @param string $field The pack selector field to read.
	 *
	 * @return string|null The non-empty selector value, or null.
	 */
	private function selectorFor(array $case, string $field): ?string {
		$pack = $this->resolveActivePack(case: $case);
		if ($pack === null) {
			return null;
		}

		$value = $this->stringField(data: $pack, key: $field);
		if ($value !== '') {
			return $value;
		}

		return null;
	}//end selectorFor()

	/**
	 * Resolve the active pack for a case: the pack whose `jurisdiction` matches
	 * the case's `jurisdiction`, falling back to the neutral `default` pack.
	 *
	 * @param array<string, mixed> $case The case's serialised payload.
	 *
	 * @return array<string, mixed>|null The active pack, or null when none exists.
	 */
	private function resolveActivePack(array $case): ?array {
		$wanted = $this->stringField(data: $case, key: 'jurisdiction');
		$default = null;
		foreach ($this->loadPacks() as $pack) {
			$packJurisdiction = $this->stringField(data: $pack, key: 'jurisdiction');
			if ($wanted !== '' && $packJurisdiction === $wanted) {
				return $pack;
			}

			if ($packJurisdiction === self::DEFAULT_JURISDICTION) {
				$default = $pack;
			}
		}

		return $default;
	}//end resolveActivePack()

	/**
	 * Read a string field off an array, defaulting to an empty string.
	 *
	 * @param array<string, mixed> $data The source array.
	 * @param string $key The field to read.
	 *
	 * @return string The string value, or '' when absent/non-string.
	 */
	private function stringField(array $data, string $key): string {
		$value = ($data[$key] ?? null);
		if (is_string($value) === true) {
			return $value;
		}

		return '';
	}//end stringField()

	/**
	 * Load every policy-pack object in the register (RBAC + tenant scoped).
	 *
	 * A read failure returns an empty set, which resolves the case fail-closed
	 * through the registry default (never fail-open) — an unreadable pack MUST
	 * NOT be treated as "identity verified" / "escalation done".
	 *
	 * @param bool $_rbac Whether the pack read is RBAC scoped (false only for system sweeps).
	 * @param bool $_multitenancy Whether the pack read is tenant scoped (false only for system sweeps).
	 *
	 * @return array<int, array<string, mixed>> The rendered pack rows.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) RBAC/multitenancy flags follow established API patterns.
	 */
	private function loadPacks(bool $_rbac = true, bool $_multitenancy = true): array {
		try {
			$rows = $this->objectService->findAll(
				config: [
					'filters' => [
						'register' => self::REGISTER_SLUG,
						'schema' => self::SCHEMA_SLUG,
					],
				],
				_rbac: $_rbac,
				_multitenancy: $_multitenancy
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				sprintf('[DsarPolicyPackResolver] policy-pack read failed: %s', $e->getMessage())
			);
			return [];
		}

		$packs = [];
		foreach ($rows as $row) {
			if (is_array($row) === true) {
				$packs[] = $row;
			}
		}

		return $packs;
	}//end loadPacks()
}//end class
