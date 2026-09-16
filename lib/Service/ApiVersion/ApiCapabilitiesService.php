<?php

/**
 * What this instance can do, and what it will not let you do, published so a
 * client can read it before it tries.
 *
 * WHY THIS EXISTS. The candidate clause is exact: "every integrator currently
 * discovers our upload limit by hitting it". Discovering a limit by crossing
 * it costs the integrator a failed import and costs the gemeente a support
 * call, and neither of them learns the actual number from the failure.
 *
 * TWO HALVES, SPLIT ON WHAT THEY REVEAL (design D-5).
 *
 *  - The unauthenticated half carries the served versions and the limits.
 *    A client that must authenticate to learn the upload limit will not learn
 *    the upload limit, and there is nothing confidential about a ceiling: a
 *    client that knows it can back off before hitting it.
 *  - The session half adds the operational switches. Whether this instance
 *    enforces tenancy, whether access is default-closed, whether the flow
 *    kill switch is thrown — those describe the posture of a specific
 *    gemeente's installation, and an anonymous caller has no business reading
 *    the security posture of a system it has not authenticated to.
 *
 * 🔴 NO REGISTER NAMES, NO SCHEMA NAMES, ANYWHERE IN THE PUBLIC HALF. Not in
 * the limits, not in the version list, not in the contract links. A register
 * name is the name of a thing a gemeente holds records about, and the public
 * answer must stay readable by anyone without telling them what this
 * municipality keeps.
 *
 * 🔑 EVERY PUBLISHED NUMBER IS READ FROM THE THING THAT ENFORCES IT. The page
 * size comes from {@see QueryHandler}'s own constants, the rate limit from
 * {@see SecurityService}'s, and the upload ceiling from the PHP configuration
 * the upload actually runs under. A capabilities document maintained
 * separately from the code it describes is the one failure mode that makes
 * this endpoint worse than nothing, because an integrator sizes against it
 * once and never checks again.
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
 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiVersion;

use OCA\OpenRegister\Service\Object\QueryHandler;
use OCA\OpenRegister\Service\SecurityService;
use OCP\IAppConfig;
use Throwable;

/**
 * Builds the published capabilities answer, in its public and session halves.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiVersion
 */
class ApiCapabilitiesService {

	/**
	 * The app the operational switches live under.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * The path each version's own description is published under.
	 *
	 * @var string
	 */
	private const CONTRACT_PATH = '/apps/openregister/api/versions/';

	/**
	 * The operational switches the session half publishes.
	 *
	 * Each entry is `key => [config key, default]`. Explicit rather than
	 * discovered, because a discovered list would publish every setting an
	 * administrator has ever touched, secrets included. What belongs here is
	 * the small set a consumer would branch on: whether tenancy applies,
	 * whether access is default-closed, whether flow execution is running.
	 *
	 * @var array<string, array{0: string, 1: bool}>
	 */
	private const OPERATIONAL_SWITCHES = [
		'enforceDefaultClosed' => ['enforce_default_closed', false],
		'rbacInheritFromPublic' => ['rbac.inherit_from_public_default', true],
		'allowExternalSchemas' => ['allowExternalSchemas', false],
		'flowOversight' => ['flow_oversight_enabled', true],
		'flowAudit' => ['flow_audit_enabled', false],
		'flowKillSwitch' => ['flow_kill_switch', false],
	];

	/**
	 * Constructor.
	 *
	 * @param ApiVersionCatalogue $catalogue The declared contract versions.
	 * @param SecurityService $securityService Names the authentication ceiling it enforces.
	 * @param IAppConfig $appConfig Reads the operational switches.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ApiVersionCatalogue $catalogue,
		private readonly SecurityService $securityService,
		private readonly IAppConfig $appConfig,
	) {

	}//end __construct()

	/**
	 * The half any caller may read.
	 *
	 * @return array<string, mixed> The versions, the limits and the contract links.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function publicCapabilities(): array {
		$served = $this->catalogue->served();

		$contracts = [];
		foreach ($served as $version) {
			$identifier = $version->id;
			$contracts[$identifier] = (self::CONTRACT_PATH . $identifier . '/oas');
		}

		return [
			'currentVersion' => $this->catalogue->current()->id,
			'versionHeader' => ApiVersionNegotiator::REQUEST_HEADER,
			'apiVersions' => array_values(array_map(
				static fn (ApiVersion $version): array => $version->jsonSerialize(),
				$this->catalogue->all()
			)),
			'contracts' => $contracts,
			'limits' => $this->limits(),
		];

	}//end publicCapabilities()

	/**
	 * The public half plus the operational switches.
	 *
	 * @return array<string, mixed> The full capabilities answer.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function sessionCapabilities(): array {
		$capabilities = $this->publicCapabilities();
		$capabilities['features'] = $this->operationalSwitches();

		$rejections = $this->catalogue->rejections();
		if ($rejections !== []) {
			$capabilities['versionDeclarationRejections'] = $rejections;
		}

		return $capabilities;

	}//end sessionCapabilities()

	/**
	 * The ceilings this instance enforces.
	 *
	 * @return array<string, mixed> The limits.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function limits(): array {
		return [
			'uploadBytes' => $this->uploadCeilingBytes(),
			'pageSize' => [
				'default' => QueryHandler::DEFAULT_PAGE_SIZE,
				'maximum' => QueryHandler::MAX_PAGE_SIZE,
			],
			'authentication' => $this->securityService->describeAuthRateLimit(),
		];

	}//end limits()

	/**
	 * The upload ceiling, in bytes, as the running PHP configuration sets it.
	 *
	 * The smaller of `upload_max_filesize` and `post_max_size`, because a
	 * caller is stopped by whichever is lower and being told the higher one
	 * is worse than being told nothing. A zero or unset directive means "no
	 * limit from this side", so it does not take part in the comparison.
	 *
	 * @return int|null The ceiling in bytes, or null when neither directive bounds it.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function uploadCeilingBytes(): ?int {
		$candidates = [];
		foreach (['upload_max_filesize', 'post_max_size'] as $directive) {
			$bytes = self::toBytes(value: (string)ini_get($directive));
			if ($bytes !== null && $bytes > 0) {
				$candidates[] = $bytes;
			}
		}

		if ($candidates === []) {
			return null;
		}

		return min($candidates);

	}//end uploadCeilingBytes()

	/**
	 * Parse a PHP size shorthand such as `512M` into bytes.
	 *
	 * @param string $value The directive value.
	 *
	 * @return int|null The byte count, or null when the value names no size.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public static function toBytes(string $value): ?int {
		$trimmed = trim($value);
		if ($trimmed === '') {
			return null;
		}

		$matched = [];
		if (preg_match('/^(-?[0-9]+)\s*([KMGkmg]?)/', $trimmed, $matched) !== 1) {
			return null;
		}

		$size = (int)$matched[1];
		$multipliers = [
			'k' => 1024,
			'm' => (1024 * 1024),
			'g' => (1024 * 1024 * 1024),
		];

		$suffix = strtolower($matched[2]);
		if ($suffix !== '' && isset($multipliers[$suffix]) === true) {
			$size = ($size * $multipliers[$suffix]);
		}

		return $size;

	}//end toBytes()

	/**
	 * The operational switches, read from the configuration that applies them.
	 *
	 * @return array<string, bool> Switch name to state.
	 */
	private function operationalSwitches(): array {
		$switches = [];
		foreach (self::OPERATIONAL_SWITCHES as $name => $declaration) {
			[$key, $default] = $declaration;
			try {
				$switches[$name] = $this->appConfig->getValueBool(self::APP_ID, $key, $default);
			} catch (Throwable) {
				$switches[$name] = $default;
			}
		}

		return $switches;

	}//end operationalSwitches()
}//end class
