<?php

/**
 * Writes the caller record, and resolves the two things it needs to write it.
 *
 * WHY THE ROUTE AND NOT THE URL. `/apps/openregister/api/objects/zaken/melding/8f2a…`
 * is one of a million paths, and a table with a row per object id would be
 * larger than the objects it describes while answering a question nobody asked.
 * What an administrator needs is "who calls the object read", so the recorder
 * collapses an expanded path back to its shape: identifiers become `{id}`.
 *
 * 🔴 THE COLLAPSE IS LOSSY ON PURPOSE AND MUST STAY CONSERVATIVE. A segment is
 * only collapsed when it is unambiguously an identifier: a uuid, a long hex
 * string, or a run of digits. Collapsing a segment that is actually a register
 * or schema slug would merge two different endpoints into one row and quietly
 * halve the number of routes an administrator can see, which is exactly the
 * kind of adjacent answer this record exists to avoid.
 *
 * 🔴 RECORDING NEVER FAILS A CALL. Every write is swallowed. This table is an
 * operational aid; a gemeente's API failing because its usage log deadlocked
 * would be an outage caused by an ornament.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
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

namespace OCA\OpenRegister\Service\ApiCaller;

use DateTime;
use OCA\OpenRegister\Db\ApiCallRecordMapper;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records one API call against its caller.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 */
class ApiCallRecorder {

	/**
	 * The app the switch lives under.
	 *
	 * @var string
	 */
	private const APP_ID = 'openregister';

	/**
	 * Configuration key switching the record off.
	 *
	 * On by default, because a record nobody enabled is a record nobody has
	 * when the deprecation conversation arrives. The switch exists because the
	 * record costs one indexed write per API call, and an instance under load
	 * that does not need the record should be able to stop paying for it.
	 *
	 * @var string
	 */
	public const ENABLED_KEY = 'api_call_record_enabled';

	/**
	 * Path segments that are always identifiers, whatever they look like.
	 *
	 * @var string
	 */
	private const IDENTIFIER_PATTERN = '/^(?:[0-9]+|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}|[0-9a-f]{24,})$/i';

	/**
	 * Constructor.
	 *
	 * @param ApiCallRecordMapper $mapper Writes the record.
	 * @param IUserSession $userSession Names the caller.
	 * @param IAppConfig $appConfig Reads the switch.
	 * @param LoggerInterface $logger Records a write that did not happen.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ApiCallRecordMapper $mapper,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Whether the record is switched on.
	 *
	 * @return bool True when calls are recorded.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function isEnabled(): bool {
		try {
			return $this->appConfig->getValueBool(self::APP_ID, self::ENABLED_KEY, true);
		} catch (Throwable) {
			return false;
		}

	}//end isEnabled()

	/**
	 * The caller making the current request.
	 *
	 * @return string The Nextcloud uid, or the empty string for anonymous.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public function principal(): string {
		try {
			$user = $this->userSession->getUser();
			if ($user !== null) {
				return $user->getUID();
			}
		} catch (Throwable) {
			return '';
		}

		return '';

	}//end principal()

	/**
	 * Record one call.
	 *
	 * @param string $principal The caller.
	 * @param string $path The request path.
	 * @param string $method The HTTP method.
	 * @param string $apiVersion The contract version that served it.
	 * @param DateTime|null $moment When it happened; defaults to now.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function record(
		string $principal,
		string $path,
		string $method,
		string $apiVersion,
		?DateTime $moment = null,
	): void {
		if ($this->isEnabled() === false) {
			return;
		}

		try {
			$this->mapper->count(
				principal: $principal,
				route: self::routeOf(path: $path),
				method: strtoupper(substr($method, 0, 10)),
				apiVersion: $apiVersion,
				moment: ($moment ?? new DateTime()),
			);
		} catch (Throwable $e) {
			$this->logger->debug(
				'OpenRegister: could not record an API call.',
				['exception' => $e]
			);
		}

	}//end record()

	/**
	 * Collapse an expanded path back to the route it came from.
	 *
	 * @param string $path The request path.
	 *
	 * @return string The route pattern.
	 *
	 * @spec openspec/changes/api-as-a-versioned-surface/specs/api-surface-governance/spec.md
	 */
	public static function routeOf(string $path): string {
		$withoutQuery = strtok($path, '?');
		if ($withoutQuery === false) {
			$withoutQuery = $path;
		}

		// `/index.php/apps/openregister/api/...` and `/apps/openregister/api/...`
		// are the same endpoint reached two ways, and two rows for one route is
		// a report an administrator has to add up by hand.
		$normalised = preg_replace('#^/index\.php#', '', $withoutQuery);
		if (is_string($normalised) === false) {
			$normalised = $withoutQuery;
		}

		$segments = explode('/', trim($normalised, '/'));
		$collapsed = [];
		foreach ($segments as $segment) {
			if ($segment !== '' && preg_match(self::IDENTIFIER_PATTERN, $segment) === 1) {
				$collapsed[] = '{id}';
				continue;
			}

			$collapsed[] = $segment;
		}

		$route = ('/' . implode('/', $collapsed));

		return substr($route, 0, 255);

	}//end routeOf()
}//end class
