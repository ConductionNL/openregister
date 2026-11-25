<?php

/**
 * OpenRegister NLGov response probe
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

use OCA\OpenRegister\Kernel\Contracts\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use OCP\IRequest;
use Throwable;

/**
 * Checks the rules a document cannot show by reading the instance's own responses.
 *
 * Two requests against the register's own API, never more than
 * {@see self::MAX_REQUESTS}, each bounded by {@see self::TIMEOUT_SECONDS} and
 * never following a redirect: a collection read for the version header, the
 * security headers and the offsets of date-time values, and a read of a
 * record that cannot exist for the error's content type. The caller's
 * Authorization header is passed on, so the probe reads what the caller may
 * read and nothing more. The result is kept per register in the app config.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
class NlGovResponseProbe {

	/**
	 * The most requests one probe sends.
	 *
	 * @var int
	 */
	public const MAX_REQUESTS = 5;

	/**
	 * The timeout of one request, in seconds.
	 *
	 * @var int
	 */
	public const TIMEOUT_SECONDS = 10;

	/**
	 * The record id no register holds.
	 *
	 * @var string
	 */
	public const NIL_UUID = '00000000-0000-0000-0000-000000000000';

	/**
	 * The app config key prefix of a stored probe.
	 *
	 * @var string
	 */
	public const CONFIG_PREFIX = 'nlgov_probe_';

	/**
	 * The security headers the ruleset makes mandatory, with the value a header must contain when one is set.
	 *
	 * @var array<string, string|null>
	 */
	public const SECURITY_HEADERS = [
		'Cache-Control' => null,
		'Content-Security-Policy' => null,
		'Strict-Transport-Security' => null,
		'X-Content-Type-Options' => 'nosniff',
		'X-Frame-Options' => null,
	];

	/**
	 * Requests sent by the probe running now.
	 *
	 * @var int
	 */
	private int $sent = 0;

	/**
	 * Constructor.
	 *
	 * @param IClientService $clients The HTTP client factory.
	 * @param IAppConfig     $config  Where the last probe of a register is kept.
	 * @param ITimeFactory   $time    The clock the probe is stamped with.
	 * @param IRequest|null  $request The caller's request, whose Authorization header is passed on; null outside a request.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function __construct(
		private readonly IClientService $clients,
		private readonly IAppConfig $config,
		private readonly ITimeFactory $time,
		private readonly ?IRequest $request = null,
	) {
	}//end __construct()

	/**
	 * Probe a register's responses, keep the result and return it.
	 *
	 * @param string               $registerId The register.
	 * @param array<string, mixed> $oas        The register's generated document, which names its server and paths.
	 *
	 * @return array{probedAt: string, requests: int, rules: array<string, array{result: string, findings: list<array{path: string, message: string}>}>}
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function probe(string $registerId, array $oas): array {
		$this->sent = 0;
		$server     = rtrim((string) ($oas['servers'][0]['url'] ?? ''), '/');
		$targets    = self::targets(oas: $oas);

		$rules = [];
		if ($targets['collection'] !== null) {
			$rules += $this->checkCollection(url: $server.$targets['collection'], path: $targets['collection']);
		}

		if ($targets['item'] !== null) {
			$rules += $this->checkError(url: $server.$targets['item'], path: $targets['item']);
		}

		foreach (['/core/version-header', '/core/transport/security-headers', '/core/date-time/timezone'] as $ruleId) {
			$rules[$ruleId] ??= ['result' => 'fail', 'findings' => [['path' => 'paths', 'message' => 'No collection path to probe in the document.']]];
		}

		$result = [
			'probedAt' => $this->time->getDateTime('now', new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
			'requests' => $this->sent,
			'rules' => $rules,
		];
		$this->config->setValueString(AppIdentity::APP_ID, self::CONFIG_PREFIX.$registerId, (string) json_encode($result));

		return $result;
	}//end probe()

	/**
	 * The last probe of a register, or null when none was kept.
	 *
	 * @param string $registerId The register.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function lastResult(string $registerId): ?array {
		$stored = $this->config->getValueString(AppIdentity::APP_ID, self::CONFIG_PREFIX.$registerId, '');
		if ($stored === '') {
			return null;
		}

		$decoded = json_decode($stored, true);
		if (is_array($decoded) === false) {
			return null;
		}

		return $decoded;
	}//end lastResult()

	/**
	 * Whether a version header value is a full semantic version.
	 *
	 * @param string $value The header value.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public static function isFullVersion(string $value): bool {
		return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/', trim($value)) === 1;
	}//end isFullVersion()

	/**
	 * The paths a probe reads: the first GET without a path parameter, and the first GET with exactly one, filled with the nil uuid.
	 *
	 * @param array<string, mixed> $oas The document.
	 *
	 * @return array{collection: string|null, item: string|null}
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public static function targets(array $oas): array {
		$targets = ['collection' => null, 'item' => null];
		foreach (($oas['paths'] ?? []) as $path => $operations) {
			if (is_string($path) === false || is_array($operations) === false || isset($operations['get']) === false) {
				continue;
			}

			$parameters = preg_match_all('/\{[^}]+\}/', $path);
			if ($parameters === 0 && $targets['collection'] === null) {
				$targets['collection'] = $path;
			}

			if ($parameters === 1 && $targets['item'] === null) {
				$targets['item'] = (string) preg_replace('/\{[^}]+\}/', self::NIL_UUID, $path);
			}
		}

		return $targets;
	}//end targets()

	/**
	 * Read a collection and judge its version header, security headers and date-time offsets.
	 *
	 * @param string $url  The absolute URL.
	 * @param string $path The path, as findings name it.
	 *
	 * @return array<string, array{result: string, findings: list<array{path: string, message: string}>}>
	 */
	private function checkCollection(string $url, string $path): array {
		$response = $this->send(url: $url);
		if (is_string($response) === true) {
			$unreadable = ['result' => 'fail', 'findings' => [['path' => $path, 'message' => $response]]];
			return ['/core/version-header' => $unreadable, '/core/transport/security-headers' => $unreadable, '/core/date-time/timezone' => $unreadable];
		}

		return [
			'/core/version-header' => self::verdict(findings: self::versionFindings(response: $response, path: $path)),
			'/core/transport/security-headers' => self::verdict(findings: self::securityFindings(response: $response, path: $path)),
			'/core/date-time/timezone' => self::verdict(findings: self::timezoneFindings(body: (string) $response->getBody(), path: $path)),
		];
	}//end checkCollection()

	/**
	 * Read a record that cannot exist and judge the error's content type.
	 *
	 * @param string $url  The absolute URL.
	 * @param string $path The path, as findings name it.
	 *
	 * @return array<string, array{result: string, findings: list<array{path: string, message: string}>}>
	 */
	private function checkError(string $url, string $path): array {
		$response = $this->send(url: $url);
		if (is_string($response) === true) {
			return ['/core/error-handling/problem-details' => ['result' => 'fail', 'findings' => [['path' => $path, 'message' => $response]]]];
		}

		$findings = [];
		$type     = strtolower($response->getHeader('Content-Type'));
		if ($response->getStatusCode() >= 400 && str_starts_with($type, 'application/problem+json') === false) {
			$message    = sprintf('A %d error is served as "%s", not application/problem+json.', $response->getStatusCode(), $type);
			$findings[] = ['path' => $path, 'message' => $message];
		}

		return ['/core/error-handling/problem-details' => self::verdict(findings: $findings)];
	}//end checkError()

	/**
	 * Send one bounded GET, or say why none could be read.
	 *
	 * @param string $url The absolute URL.
	 *
	 * @return IResponse|string The response, or the reason there is none.
	 */
	private function send(string $url): IResponse|string {
		if ($this->sent >= self::MAX_REQUESTS) {
			return 'The probe sends at most '.self::MAX_REQUESTS.' requests.';
		}

		$this->sent++;
		$headers = ['Accept' => 'application/json', 'OCS-APIRequest' => 'true'];
		$auth    = (string) ($this->request?->getHeader('Authorization') ?? '');
		if ($auth !== '') {
			$headers['Authorization'] = $auth;
		}

		try {
			return $this->clients->newClient()->get(
				$url,
				[
					'headers' => $headers,
					'timeout' => self::TIMEOUT_SECONDS,
					'allow_redirects' => false,
					'http_errors' => false,
					'nextcloud' => ['allow_local_address' => true],
				]
			);
		} catch (Throwable $e) {
			return 'The response could not be read: '.$e->getMessage();
		}
	}//end send()

	/**
	 * What is wrong with the version header.
	 *
	 * @param IResponse $response The response.
	 * @param string    $path     The path read.
	 *
	 * @return list<array{path: string, message: string}>
	 */
	private static function versionFindings(IResponse $response, string $path): array {
		$value = $response->getHeader('API-Version');
		if ($value === '') {
			return [['path' => $path, 'message' => 'The response carries no API-Version header.']];
		}

		if (self::isFullVersion(value: $value) === false) {
			return [['path' => $path, 'message' => sprintf('API-Version is "%s", not a full major.minor.patch version.', $value)]];
		}

		return [];
	}//end versionFindings()

	/**
	 * Which mandatory security headers are missing or wrong.
	 *
	 * @param IResponse $response The response.
	 * @param string    $path     The path read.
	 *
	 * @return list<array{path: string, message: string}>
	 */
	private static function securityFindings(IResponse $response, string $path): array {
		$findings = [];
		foreach (self::SECURITY_HEADERS as $name => $expected) {
			$value = $response->getHeader($name);
			if ($value === '') {
				$findings[] = ['path' => $path, 'message' => sprintf('The response carries no %s header.', $name)];
				continue;
			}

			if ($expected !== null && stripos($value, $expected) === false) {
				$findings[] = ['path' => $path, 'message' => sprintf('%s is "%s", expected "%s".', $name, $value, $expected)];
			}
		}

		return $findings;
	}//end securityFindings()

	/**
	 * Which date-time values in a JSON body carry no time zone offset.
	 *
	 * @param string $body The response body.
	 * @param string $path The path read.
	 *
	 * @return list<array{path: string, message: string}>
	 */
	private static function timezoneFindings(string $body, string $path): array {
		$decoded = json_decode($body, true);
		if (is_array($decoded) === false) {
			return [];
		}

		$naked = [];
		array_walk_recursive(
			$decoded,
			static function (mixed $value) use (&$naked): void {
				if (is_string($value) === true
					&& preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/', $value) === 1
				) {
					$naked[] = $value;
				}
			}
		);

		if ($naked === []) {
			return [];
		}

		return [[
			'path' => $path,
			'message' => sprintf('%d date-time values carry no time zone offset, for example "%s".', count($naked), $naked[0]),
		]];
	}//end timezoneFindings()

	/**
	 * A rule's verdict from its findings.
	 *
	 * @param list<array{path: string, message: string}> $findings The findings.
	 *
	 * @return array{result: string, findings: list<array{path: string, message: string}>}
	 */
	private static function verdict(array $findings): array {
		$result = 'fail';
		if ($findings === []) {
			$result = 'pass';
		}

		return ['result' => $result, 'findings' => $findings];
	}//end verdict()
}//end class
