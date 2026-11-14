<?php

/**
 * DeclaredProviderResolver — decides whether an app-declared provider is admitted.
 *
 * Admission states:
 *   - `admitted` — a valid `extends` declaration that is provably narrower than its
 *     reviewed base (no approval needed), or a valid declaration whose current
 *     digest carries an administrator approval as its latest decision;
 *   - `pending`  — a valid declaration with no decision on its current digest
 *     (never decided, or edited since the decision);
 *   - `rejected` / `revoked` — the latest decision on the current digest;
 *   - `invalid`  — the declaring file failed validation;
 *   - `unknown`  — no enabled app declares the identifier (disabled, uninstalled).
 *
 * Only an admitted declaration yields a provider entry, and that entry has the same
 * shape the reviewed catalogue serves, so the broker guards it with exactly the same
 * four guards. The entry is always a proxy entry: `inject_only` is forced false.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Credential
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

/**
 * Layers the admission decision over the discovered declarations.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
 */
class DeclaredProviderResolver {

	/**
	 * Usable: narrowing, or approved on the current digest.
	 *
	 * @var string
	 */
	public const STATUS_ADMITTED = 'admitted';

	/**
	 * Waiting for an administrator decision on the current digest.
	 *
	 * @var string
	 */
	public const STATUS_PENDING = 'pending';

	/**
	 * Rejected by an administrator on the current digest.
	 *
	 * @var string
	 */
	public const STATUS_REJECTED = 'rejected';

	/**
	 * Revoked by an administrator on the current digest.
	 *
	 * @var string
	 */
	public const STATUS_REVOKED = 'revoked';

	/**
	 * The declaring file failed validation.
	 *
	 * @var string
	 */
	public const STATUS_INVALID = 'invalid';

	/**
	 * No enabled app declares the identifier.
	 *
	 * @var string
	 */
	public const STATUS_UNKNOWN = 'unknown';

	/**
	 * Decision statuses an approval record may carry, mapped to the admission state they give.
	 *
	 * @var array<string, string>
	 */
	private const DECISIONS = [
		'approved' => self::STATUS_ADMITTED,
		'rejected' => self::STATUS_REJECTED,
		'revoked'  => self::STATUS_REVOKED,
		'pending'  => self::STATUS_PENDING,
	];

	/**
	 * Constructor.
	 *
	 * @param DeclaredProviderLoader    $loader    The discovered declarations.
	 * @param DeclaredProviderApprovals $approvals The administrator decisions.
	 * @param DeclarationValidator      $validator Computes the current digest.
	 */
	public function __construct(
		private readonly DeclaredProviderLoader $loader,
		private readonly DeclaredProviderApprovals $approvals,
		private readonly DeclarationValidator $validator,
	) {
	}//end __construct()

	/**
	 * Whether an identifier is in the declared (namespaced) identifier space.
	 *
	 * Reviewed catalogue identifiers never carry a colon, so a declaration can never
	 * shadow one.
	 *
	 * @param string $identifier The provider identifier.
	 *
	 * @return bool True for `<appId>:<localId>`.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-declared-identifiers-are-namespaced-and-cannot-shadow-the-reviewed-catalogue
	 */
	public function isDeclaredIdentifier(string $identifier): bool {
		return str_contains($identifier, ':');
	}//end isDeclaredIdentifier()

	/**
	 * The admission state of a declared identifier.
	 *
	 * @param string $identifier The `<appId>:<localId>` identifier.
	 *
	 * @return array{status: string, origin: string, lane: string, entry: array<string, mixed>|null}
	 *         `entry` is set only when the status is `admitted`.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
	 */
	public function resolve(string $identifier): array {
		$declaration = null;
		if ($this->isDeclaredIdentifier(identifier: $identifier) === true) {
			$declaration = $this->loader->get(identifier: $identifier);
		}

		if ($declaration === null) {
			return $this->state(status: self::STATUS_UNKNOWN, origin: '', lane: '', entry: null);
		}

		$origin = $declaration['app'];
		if ($declaration['valid'] === false) {
			return $this->state(status: self::STATUS_INVALID, origin: $origin, lane: '', entry: null);
		}

		$lane = $declaration['lane'];
		$status = self::STATUS_ADMITTED;
		if ($lane !== DeclarationValidator::LANE_NARROWING) {
			$status = $this->decidedStatus(
				identifier: $identifier,
				digest: $this->validator->digest(entry: $declaration['entry'])
			);
		}

		$entry = null;
		if ($status === self::STATUS_ADMITTED) {
			$entry = $this->providerEntry(declared: $declaration['entry'], origin: $origin);
		}

		return $this->state(status: $status, origin: $origin, lane: $lane, entry: $entry);
	}//end resolve()

	/**
	 * The admitted provider entry of a declared identifier, or null when it is not admitted.
	 *
	 * @param string $identifier The `<appId>:<localId>` identifier.
	 *
	 * @return array<string, mixed>|null The entry, in the reviewed catalogue's shape.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/credential-broker/spec.md
	 */
	public function admittedEntry(string $identifier): ?array {
		return $this->resolve(identifier: $identifier)['entry'];
	}//end admittedEntry()

	/**
	 * The admission state the latest decision on the current digest gives.
	 *
	 * A decision on any other digest is stale (the declaration changed since), so it is
	 * ignored and the declaration is pending again.
	 *
	 * @param string $identifier The identifier.
	 * @param string $digest     The current digest of the declared entry.
	 *
	 * @return string The status.
	 */
	private function decidedStatus(string $identifier, string $digest): string {
		$latest = null;
		$latestAt = '';
		foreach ($this->approvals->decisionsFor(identifier: $identifier) as $decision) {
			if (($decision['declarationDigest'] ?? null) !== $digest) {
				continue;
			}

			$decidedAt = (string)($decision['decidedAt'] ?? '');
			if ($latest === null || strcmp($decidedAt, $latestAt) >= 0) {
				$latest = $decision;
				$latestAt = $decidedAt;
			}
		}

		if ($latest === null) {
			return self::STATUS_PENDING;
		}

		return (self::DECISIONS[(string)($latest['status'] ?? '')] ?? self::STATUS_PENDING);
	}//end decidedStatus()

	/**
	 * The catalogue-shaped entry of an admitted declaration.
	 *
	 * @param array<string, mixed> $declared The declared entry.
	 * @param string               $origin   The declaring app.
	 *
	 * @return array<string, mixed> The entry.
	 */
	private function providerEntry(array $declared, string $origin): array {
		return [
			'identifier'  => $declared['identifier'],
			'title'       => $declared['title'],
			'baseUrl'     => $declared['baseUrl'],
			'authScheme'  => $declared['authScheme'],
			'allowRules'  => array_map(
				static fn (array $rule): array => ['method' => $rule['method'], 'pathPattern' => $rule['pathPattern']],
				$declared['allowRules']
			),
			'inject_only' => false,
			'origin'      => $origin,
		];
	}//end providerEntry()

	/**
	 * A resolution result.
	 *
	 * @param string                    $status The admission state.
	 * @param string                    $origin The declaring app, '' when unknown.
	 * @param string                    $lane   The admission lane, '' when invalid or unknown.
	 * @param array<string, mixed>|null $entry  The admitted entry.
	 *
	 * @return array{status: string, origin: string, lane: string, entry: array<string, mixed>|null}
	 */
	private function state(string $status, string $origin, string $lane, ?array $entry): array {
		return ['status' => $status, 'origin' => $origin, 'lane' => $lane, 'entry' => $entry];
	}//end state()
}//end class
