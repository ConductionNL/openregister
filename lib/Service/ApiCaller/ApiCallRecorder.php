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
 * @spec openspec/specs/api-surface-governance/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\ApiCaller;

use DateTime;
use OCA\OpenRegister\Db\ApiCallKey;
use OCA\OpenRegister\Db\ApiCallKeys;
use OCA\OpenRegister\Db\ApiCallRecordMapperInterface;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records one API call against its caller.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\ApiCaller
 */
class ApiCallRecorder implements ApiCallRecorderInterface {

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
	 * record costs an indexed write per caller and route (one per API call
	 * without a memory cache), and an instance under load
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
	 * Seconds a key's calls are counted in the cache before they are written.
	 *
	 * The first call of a key writes at once; the calls after it in the window
	 * are added by the first call after the window. The record answers "who
	 * called this in the last month", so a last call up to this late is the
	 * same answer.
	 *
	 * @var int
	 */
	public const FLUSH_SECONDS = 30;

	/**
	 * Counts calls between writes, or null when no memory cache is available.
	 *
	 * @var IMemcache|null
	 */
	private readonly ?IMemcache $buffer;

	/**
	 * Constructor.
	 *
	 * @param ApiCallRecordMapperInterface $mapper Writes the record.
	 * @param IUserSession $userSession Names the caller.
	 * @param IAppConfig $appConfig Reads the switch.
	 * @param LoggerInterface $logger Records a write that did not happen.
	 * @param ICacheFactory|null $cacheFactory Gives the cache that counts calls between writes.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ApiCallRecordMapperInterface $mapper,
		private readonly IUserSession $userSession,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		?ICacheFactory $cacheFactory=null,
	) {
		$this->buffer = self::resolveBuffer(cacheFactory: $cacheFactory);

	}//end __construct()

	/**
	 * Whether the record is switched on.
	 *
	 * @return bool True when calls are recorded.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
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
	 * @spec openspec/specs/api-surface-governance/spec.md
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
	 * Count one observed call against the caller making the current request.
	 *
	 * With a memory cache the call is counted there and written with the
	 * other calls of its key at most once per FLUSH_SECONDS, so a busy page
	 * does not pay a database write per API call. A failure to count is
	 * logged and never fails the call itself.
	 *
	 * @param ApiCallObservation $call The path, method, API version and moment.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md#requirement-every-api-call-records-its-caller-and-a-caller-carries-a-limit-and-an-address-binding-req-avs-003
	 */
	public function recordCall(ApiCallObservation $call): void {
		if ($this->isEnabled() === false) {
			return;
		}

		try {
			$key   = $this->callKey(call: $call);
			$calls = $this->callsToWrite(key: $key);
			if ($calls > 0) {
				$this->mapper->count(key: $key, moment: ($call->moment ?? new DateTime()), calls: $calls);
			}
		} catch (Throwable $e) {
			$this->logger->debug('OpenRegister: could not record an API call.', ['exception' => $e]);
		}

	}//end recordCall()

	/**
	 * How many calls of a key to write now, the current one included.
	 *
	 * One without a buffer. With one, the call is counted in the buffer and
	 * the first call of each FLUSH_SECONDS window takes everything counted so
	 * far; the other calls of the window write nothing. Only what was taken
	 * is subtracted, so a call counted meanwhile waits for the next window.
	 *
	 * @param ApiCallKey $key The counter key.
	 *
	 * @return int The calls to write, zero while the window is open.
	 */
	private function callsToWrite(ApiCallKey $key): int {
		if ($this->buffer === null) {
			return 1;
		}

		$id = hash('sha256', implode("\0", [$key->principal, $key->route, $key->method, $key->apiVersion, $key->client]));
		try {
			$pending = $this->buffer->inc('pending_' . $id);
		} catch (Throwable) {
			return 1;
		}

		if (is_int($pending) === false) {
			return 1;
		}

		try {
			if ($this->buffer->add('window_' . $id, 1, self::FLUSH_SECONDS) === false) {
				return 0;
			}

			$this->buffer->dec('pending_' . $id, $pending);
		} catch (Throwable) {
			return 0;
		}

		return $pending;

	}//end callsToWrite()

	/**
	 * The distributed memory cache that counts calls, or null when there is none.
	 *
	 * @param ICacheFactory|null $cacheFactory The cache factory.
	 *
	 * @return IMemcache|null The cache.
	 */
	private static function resolveBuffer(?ICacheFactory $cacheFactory): ?IMemcache {
		if ($cacheFactory === null) {
			return null;
		}

		try {
			if ($cacheFactory->isAvailable() === false) {
				return null;
			}

			$cache = $cacheFactory->createDistributed('openregister_api_calls');
			if (($cache instanceof IMemcache) === false) {
				return null;
			}

			return $cache;
		} catch (Throwable) {
			return null;
		}

	}//end resolveBuffer()

	/**
	 * The counter key of a call: who, which route, which method, which API version.
	 *
	 * @param ApiCallObservation $call The observed call.
	 *
	 * @return ApiCallKey The key.
	 */
	private function callKey(ApiCallObservation $call): ApiCallKey {
		return ApiCallKeys::of(
			principal: $this->principal(),
			route: self::routeOf(path: $call->path),
			method: strtoupper(substr($call->method, 0, 10)),
			apiVersion: $call->apiVersion,
			client: ApiClientName::fromUserAgent(userAgent: $call->client),
		);

	}//end callKey()

	/**
	 * Collapse an expanded path back to the route it came from.
	 *
	 * @param string $path The request path.
	 *
	 * @return string The route pattern.
	 *
	 * @spec openspec/specs/api-surface-governance/spec.md
	 */
	public static function routeOf(string $path): string {
		$segments = explode('/', trim(self::endpointPath(path: $path), '/'));
		$route    = ('/' . implode('/', self::collapseIdentifiers(segments: $segments)));

		return substr($route, 0, 255);

	}//end routeOf()

	/**
	 * A request path without its query string and without the `/index.php` prefix.
	 *
	 * @param string $path The request path.
	 *
	 * @return string The endpoint path.
	 */
	private static function endpointPath(string $path): string {
		$withoutQuery = strtok($path, '?');
		if ($withoutQuery === false) {
			$withoutQuery = $path;
		}

		// `/index.php/apps/openregister/api/...` and `/apps/openregister/api/...`
		// are the same endpoint reached two ways, and two rows for one route is
		// a report an administrator has to add up by hand.
		$normalised = preg_replace('#^/index\.php#', '', $withoutQuery);
		if (is_string($normalised) === false) {
			return $withoutQuery;
		}

		return $normalised;

	}//end endpointPath()

	/**
	 * The path segments with every identifier replaced by `{id}`.
	 *
	 * @param array<int, string> $segments The path segments.
	 *
	 * @return array<int, string> The route segments.
	 */
	private static function collapseIdentifiers(array $segments): array {
		$collapsed = [];
		foreach ($segments as $segment) {
			if ($segment !== '' && preg_match(self::IDENTIFIER_PATTERN, $segment) === 1) {
				$collapsed[] = '{id}';
				continue;
			}

			$collapsed[] = $segment;
		}

		return $collapsed;

	}//end collapseIdentifiers()
}//end class
