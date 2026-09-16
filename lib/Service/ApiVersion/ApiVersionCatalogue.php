<?php

/**
 * The set of API contract versions this instance serves.
 *
 * WHY THIS EXISTS. A version a consumer cannot enumerate is a version it
 * cannot plan against, which is the same failure the permission catalogue
 * names one layer down: a set nobody can read cannot be granted. Until this
 * class there was no set at all. Every one of the app's API routes answered
 * with no version on it, so "we are deprecating v1" had nowhere to be written
 * down and no way to reach the five leveranciers it concerned.
 *
 * THE DEFAULT IS THE TRUTH, NOT A PLACEHOLDER. Version 1 is what this
 * instance has always served, on the whole surface, and the built-in
 * declaration says exactly that. It is not a stub waiting for an
 * administrator: an instance nobody configures still publishes a correct,
 * complete contract.
 *
 * FAIL-OPEN ON THE READ, FAIL-CLOSED ON THE DECLARATION — and the two
 * directions are deliberate.
 *
 *  - {@see ApiVersion} refuses a declaration that cannot be honoured, at
 *    construction, because a deprecated version with no end date is worse than
 *    no deprecation.
 *  - This class, reading an administered set that turns out to be malformed,
 *    keeps serving the built-in default and records the refusal. The opposite
 *    choice — refusing to resolve a version at all — takes the whole API down
 *    over a typo in a JSON blob, and an API that stops answering because
 *    somebody mistyped a sunset date is a far larger outage than the one the
 *    strictness was protecting against.
 *
 * The refusals are readable through {@see rejections()} so the fallback is
 * never silent: an administrator who mistyped sees the reason beside the
 * versions rather than a set that looks fine and is not theirs.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiVersion;

use InvalidArgumentException;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Publishes the declared API versions and resolves one by identifier.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 * Reason: named constructors and PHP's own date API. `ApiVersion::fromArray()`,
 *         `ApiVersion::toHttpDate()` and `ApiVersionRefusedException::withdrawn()`
 *         / `::unknown()` are static factories, which is the pattern phpmd's
 *         StaticAccess rule cannot distinguish from a hidden dependency, and
 *         `DateTimeImmutable::createFromFormat()` has no instance form. Injecting
 *         a factory object for either would add a seam with nothing behind it.
 *         The repo carries the same suppression shape on VocabularyController,
 *         CodedValueGuard and DestructionScopeService for the same reason.
 */
class ApiVersionCatalogue {

	/**
	 * The app the configuration lives under.
	 *
	 * @var string
	 */
	public const APP_ID = 'openregister';

	/**
	 * Configuration key holding the administered declaration, a JSON list.
	 *
	 * @var string
	 */
	public const CONFIG_KEY = 'api_versions';

	/**
	 * The identifier of the version this instance has always served.
	 *
	 * @var string
	 */
	public const FIRST = '1';

	/**
	 * The built-in declaration, used when nothing is administered.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public const BUILT_IN = [
		[
			'id' => self::FIRST,
			'status' => ApiVersion::STATUS_SUPPORTED,
			'pathPrefixes' => ['/'],
			'description' => 'The first contract. It covers the whole API surface and is what a caller gets when it names no version.',
		],
	];

	/**
	 * The resolved versions, keyed by identifier, or null before the first read.
	 *
	 * Memoised: the declaration cannot change inside one request, and the
	 * negotiator asks for it on every call the middleware sees.
	 *
	 * @var array<string, ApiVersion>|null
	 */
	private ?array $resolved = null;

	/**
	 * Declarations refused while resolving, keyed by the identifier they claimed.
	 *
	 * @var array<string, string>
	 */
	private array $rejected = [];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Reads the administered declaration.
	 * @param LoggerInterface $logger Records a refused declaration.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Every declared version, in identifier order.
	 *
	 * @return array<string, ApiVersion> Versions keyed by identifier.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function all(): array {
		if ($this->resolved === null) {
			$this->resolved = $this->resolve();
		}

		return $this->resolved;

	}//end all()

	/**
	 * The versions that still answer: supported and deprecated, never withdrawn.
	 *
	 * @return array<string, ApiVersion> Versions keyed by identifier.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function served(): array {
		return array_filter($this->all(), static fn (ApiVersion $version): bool => $version->isServed());

	}//end served()

	/**
	 * The declared identifiers, as strings, lowest first.
	 *
	 * 🔴 NOT `array_keys(all())`. PHP turns a numeric string array key into an
	 * integer, so the keys of the version map are ints while every version's
	 * own `id` is a string. Publishing the keys directly shipped
	 * `servedVersions: [1, 2]` beside `apiVersions: [{version: "1"}]`, and a
	 * consumer comparing the two with `===` would find them different forever
	 * while both looked right in the JSON.
	 *
	 * @return array<int, string> The identifiers.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function identifiers(): array {
		return array_values(array_map(static fn (ApiVersion $version): string => $version->id, $this->all()));

	}//end identifiers()

	/**
	 * The identifiers that still answer, as strings, lowest first.
	 *
	 * @return array<int, string> The served identifiers.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function servedIdentifiers(): array {
		return array_values(array_map(static fn (ApiVersion $version): string => $version->id, $this->served()));

	}//end servedIdentifiers()

	/**
	 * One version by identifier, or null when nothing declares it.
	 *
	 * A withdrawn version is returned here rather than hidden: the caller
	 * needs it in order to answer 410 and name the successor, and hiding it
	 * would turn a deliberate withdrawal back into the 404 that design D-3
	 * exists to avoid.
	 *
	 * @param string $identifier The version identifier a consumer named.
	 *
	 * @return ApiVersion|null The declared version, or null when unknown.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function get(string $identifier): ?ApiVersion {
		return ($this->all()[trim($identifier)] ?? null);

	}//end get()

	/**
	 * The version a caller gets when it names none.
	 *
	 * The highest-numbered supported version, so promoting a new contract is
	 * one declaration rather than a declaration and a separate default.
	 *
	 * @return ApiVersion The current version.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function current(): ApiVersion {
		$supported = array_filter(
			$this->all(),
			static fn (ApiVersion $version): bool => ($version->status === ApiVersion::STATUS_SUPPORTED)
		);

		$identifiers = array_keys($supported);
		usort($identifiers, static fn (string $left, string $right): int => ((int)$left <=> (int)$right));

		return $supported[end($identifiers)];

	}//end current()

	/**
	 * Declarations that were refused, keyed by the identifier they claimed.
	 *
	 * @return array<string, string> Identifier to reason.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function rejections(): array {
		if ($this->resolved === null) {
			$this->resolved = $this->resolve();
		}

		return $this->rejected;

	}//end rejections()

	/**
	 * Build the catalogue from the administered declaration, or the built-in one.
	 *
	 * Returns the set rather than writing it to the memo, so a caller assigning
	 * the result is provably left with a non-null value. Writing `$this->resolved`
	 * from inside a void method reads the same to a human and not to psalm, which
	 * flagged `all()` as able to return null.
	 *
	 * @return array<string, ApiVersion> The resolved versions.
	 */
	private function resolve(): array {
		$rejected = [];

		$administered = $this->readAdministered(rejected: $rejected);
		if ($administered !== []) {
			$candidate = $this->buildVersions(declarations: $administered, rejected: $rejected);
			if ($this->isUsable(versions: $candidate, rejected: $rejected) === true) {
				$this->rejected = $rejected;
				return $candidate;
			}

			$this->logger->error(
				'OpenRegister: the administered API version declaration is not usable; falling back to the built-in contract.',
				['rejections' => $rejected]
			);
		}

		$this->rejected = $rejected;

		return $this->buildVersions(declarations: self::BUILT_IN, rejected: $rejected);

	}//end resolve()

	/**
	 * Why a candidate declaration could not be served, without storing it.
	 *
	 * The administration surface needs this before it writes: the read path
	 * deliberately falls back to the built-in contract when a stored
	 * declaration is unusable, which is right at read time and wrong at write
	 * time. An administrator who saved a broken declaration would get a 200,
	 * see the built-in contract come back, and have no idea their edit did
	 * nothing.
	 *
	 * Touches no memo and no field, so asking does not change what this
	 * instance serves.
	 *
	 * @param array<int, mixed> $declarations The candidate declarations.
	 *
	 * @return array<string, string> The refusals, keyed by the version they concern.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/openapi-generation/spec.md
	 */
	public function inspect(array $declarations): array {
		$rejected = [];
		$usable = array_values(array_filter($declarations, 'is_array'));
		if (count($usable) !== count($declarations)) {
			$rejected['*'] = 'Every entry must be a version declaration object.';
		}

		$versions = $this->buildVersions(declarations: $usable, rejected: $rejected);
		$this->isUsable(versions: $versions, rejected: $rejected);

		return $rejected;

	}//end inspect()

	/**
	 * Read and decode the administered declaration.
	 *
	 * @return array<int, array<string, mixed>> The declarations, or an empty list.
	 */
	private function readAdministered(array &$rejected): array {
		try {
			$raw = trim((string)$this->appConfig->getValueString(self::APP_ID, self::CONFIG_KEY, ''));
		} catch (Throwable $e) {
			$this->logger->warning(
				'OpenRegister: could not read the API version configuration; using the built-in contract.',
				['exception' => $e]
			);
			return [];
		}

		if ($raw === '') {
			return [];
		}

		$decoded = json_decode($raw, true);
		if (is_array($decoded) === false || $decoded === []) {
			$rejected['*'] = 'The api_versions configuration is not a JSON list of version declarations.';
			return [];
		}

		return array_values(array_filter($decoded, 'is_array'));

	}//end readAdministered()

	/**
	 * Turn declarations into versions, recording each refusal.
	 *
	 * @param array<int, array<string, mixed>> $declarations The raw declarations.
	 *
	 * @return array<string, ApiVersion> Versions keyed by identifier.
	 */
	private function buildVersions(array $declarations, array &$rejected): array {
		$versions = [];
		foreach ($declarations as $index => $declaration) {
			try {
				$version = ApiVersion::fromArray(declaration: $declaration);
			} catch (InvalidArgumentException $e) {
				$claimed = (string)($declaration['id'] ?? ('#' . $index));
				$rejected[$claimed] = $e->getMessage();
				continue;
			}

			if (isset($versions[$version->id]) === true) {
				$rejected[$version->id] = 'Declared twice; a version an administrator cannot tell apart cannot be deprecated.';
				continue;
			}

			$versions[$version->id] = $version;
		}

		uksort($versions, static fn (string $left, string $right): int => ((int)$left <=> (int)$right));

		return $versions;

	}//end buildVersions()

	/**
	 * Whether a candidate set can be served.
	 *
	 * Two conditions, both of them about what a consumer would experience:
	 * a set with nothing supported has no answer for a caller that names no
	 * version, and a withdrawal pointing at a version that does not answer
	 * sends an integrator somewhere that refuses them too.
	 *
	 * @param array<string, ApiVersion> $versions The candidate set.
	 *
	 * @return bool True when the set can be served.
	 */
	private function isUsable(array $versions, array &$rejected): bool {
		$supported = array_filter(
			$versions,
			static fn (ApiVersion $version): bool => ($version->status === ApiVersion::STATUS_SUPPORTED)
		);

		if ($supported === []) {
			$rejected['*'] = 'No version is supported; a caller naming no version would have nothing to be answered with.';
			return false;
		}

		foreach ($versions as $version) {
			if ($version->successor === null) {
				continue;
			}

			$successor = ($versions[$version->successor] ?? null);
			if ($successor === null || $successor->isServed() === false) {
				$rejected[$version->id] = 'Its successor ' . $version->successor . ' is not a version this instance serves.';
				return false;
			}
		}

		return true;

	}//end isUsable()
}//end class
