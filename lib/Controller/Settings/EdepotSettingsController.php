<?php

/**
 * OpenRegister e-Depot Settings Controller
 *
 * Handles e-Depot endpoint configuration and connection testing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Controller
 * @package  OCA\OpenRegister\Controller\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Controller\Settings;

use OCA\OpenRegister\AppInfo\Application;
use OCA\OpenRegister\Controller\Support\EdepotSettingsControllerServices;
use OCA\OpenRegister\Service\Connection\ConnectionReporterInterface;
use OCA\OpenRegister\Service\Edepot\EdepotTransferServiceInterface;
use OCA\OpenRegister\Service\Edepot\Transport\OpenConnectorTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\SftpTransportInterface;
use OCA\OpenRegister\Service\Edepot\Transport\TransportInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Controller for e-Depot settings management.
 *
 * Provides endpoints for configuring the e-Depot endpoint, transport protocol,
 * authentication, and SIP profile. Also supports connection testing.
 *
 * @psalm-suppress UnusedClass
 */
class EdepotSettingsController extends Controller {
	/**
	 * The settings answered as '***' when they hold a value.
	 *
	 * @var list<string>
	 */
	private const SECRET_FIELDS = ['apiKey', 'bearerToken', 'password'];

	/**
	 * The request parameter of each setting and the config key it is saved under, in write order.
	 *
	 * @var array<string, string>
	 */
	private const CONFIG_KEYS = [
		'endpointUrl' => 'edepot_endpoint_url',
		'authenticationType' => 'edepot_auth_type',
		'apiKey' => 'edepot_api_key',
		'bearerToken' => 'edepot_bearer_token',
		'targetArchive' => 'edepot_target_archive',
		'sipProfile' => 'edepot_sip_profile',
		'transport' => 'edepot_transport',
		'host' => 'edepot_sftp_host',
		'port' => 'edepot_sftp_port',
		'username' => 'edepot_sftp_username',
		'password' => 'edepot_sftp_password',
		'keyPath' => 'edepot_sftp_key_path',
		'remotePath' => 'edepot_sftp_remote_path',
		'hostKeyFingerprint' => 'edepot_sftp_host_key_fingerprint',
		'sourceId' => 'edepot_openconnector_source_id',
		'baseUrl' => 'edepot_openconnector_base_url',
		'packageFormat' => 'edepot_package_format',
	];

	/**
	 * The SIP output formats a connection can choose; the first is the default.
	 *
	 * @var list<string>
	 */
	private const PACKAGE_FORMATS = ['zip', 'bagit'];

	/**
	 * The app configuration.
	 *
	 * @var IAppConfig
	 */
	private readonly IAppConfig $appConfig;

	/**
	 * The transfer service.
	 *
	 * @var EdepotTransferServiceInterface
	 */
	private readonly EdepotTransferServiceInterface $transferService;

	/**
	 * SFTP transport.
	 *
	 * @var SftpTransportInterface
	 */
	private readonly SftpTransportInterface $sftpTransport;

	/**
	 * REST API transport.
	 *
	 * @var RestApiTransportInterface
	 */
	private readonly RestApiTransportInterface $restTransport;

	/**
	 * OpenConnector transport.
	 *
	 * @var OpenConnectorTransportInterface
	 */
	private readonly OpenConnectorTransportInterface $ocTransport;

	/**
	 * Reports connection test outcomes to integriq's connection registry.
	 *
	 * @var ConnectionReporterInterface
	 */
	private readonly ConnectionReporterInterface $connectionReporter;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param EdepotSettingsControllerServices $services The edepot settings controller services.
	 */
	public function __construct(
		IRequest $request,
		EdepotSettingsControllerServices $services,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
		$this->appConfig = $services->platform->appConfig;
		$this->transferService = $services->domain->transferService;
		$this->sftpTransport = $services->domain->sftpTransport;
		$this->restTransport = $services->domain->restTransport;
		$this->ocTransport = $services->domain->ocTransport;
		$this->connectionReporter = $services->platform->connectionReporter;
	}//end __construct()

	/**
	 * Get e-Depot settings.
	 *
	 * @NoCSRFRequired
	 *
	 * @return JSONResponse The current e-Depot configuration.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function getEdepotSettings(): JSONResponse {
		try {
			$config = $this->maskedSecrets(config: $this->transferService->getTransportConfig());
			$config['availableProfiles'] = $this->transferService->getAvailableProfiles();
			$config['packageFormat'] = $this->packageFormat();
			$config['availablePackageFormats'] = self::PACKAGE_FORMATS;

			return new JSONResponse(data: $config);
		} catch (\Exception $e) {
			return $this->failure(e: $e);
		}
	}//end getEdepotSettings()

	/**
	 * Mask the secret settings that hold a value.
	 *
	 * @param array<string, mixed> $config The transport configuration.
	 *
	 * @return array<string, mixed> The configuration with secrets as '***'.
	 */
	private function maskedSecrets(array $config): array {
		foreach (self::SECRET_FIELDS as $field) {
			if (empty($config[$field]) === false) {
				$config[$field] = '***';
			}
		}

		return $config;
	}//end maskedSecrets()

	/**
	 * Update e-Depot settings.
	 *
	 * @return JSONResponse The update result.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function updateEdepotSettings(): JSONResponse {
		try {
			$params = $this->request->getParams();
			$invalid = $this->invalidProfile(sipProfile: ($params['sipProfile'] ?? 'default'));
			if ($invalid !== null) {
				return $invalid;
			}

			$invalid = $this->invalidPackageFormat(params: $params);
			if ($invalid !== null) {
				return $invalid;
			}

			$this->storeSettings(params: $params);
			return $this->settingsSaved(testResult: $this->requestedConnectionTest(params: $params));
		} catch (\Exception $e) {
			return $this->failure(e: $e);
		}
	}//end updateEdepotSettings()

	/**
	 * The answer for saved settings, with the connection test outcome when one ran.
	 *
	 * @param bool|null $testResult Whether the transport answered, or null when no test was asked.
	 *
	 * @return JSONResponse The 200 answer.
	 */
	private function settingsSaved(?bool $testResult): JSONResponse {
		$response = ['success' => true];
		if ($testResult !== null) {
			$response['connectionTest'] = $testResult;
		}

		return new JSONResponse(data: $response);
	}//end settingsSaved()

	/**
	 * The 400 answer for an unknown SIP profile, if it is one.
	 *
	 * @param mixed $sipProfile The requested SIP profile.
	 *
	 * @return JSONResponse|null The rejection, or null when the profile is known.
	 */
	private function invalidProfile(mixed $sipProfile): ?JSONResponse {
		if ($this->transferService->isValidProfile($sipProfile) === true) {
			return null;
		}

		$available = implode(', ', array_keys($this->transferService->getAvailableProfiles()));
		return new JSONResponse(
			data: ['error' => "Invalid SIP profile '{$sipProfile}'. Available: {$available}"],
			statusCode: 400
		);
	}//end invalidProfile()

	/**
	 * The configured SIP output format, `zip` when unset or unknown.
	 *
	 * @return string `zip` or `bagit`.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
	 */
	private function packageFormat(): string {
		$format = $this->appConfig->getValueString('openregister', self::CONFIG_KEYS['packageFormat'], self::PACKAGE_FORMATS[0]);
		if (in_array($format, self::PACKAGE_FORMATS, true) === false) {
			return self::PACKAGE_FORMATS[0];
		}

		return $format;
	}//end packageFormat()

	/**
	 * The 400 answer for an unknown SIP output format, if the request names one.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return JSONResponse|null The rejection, or null when no format or a known one is asked.
	 *
	 * @spec openspec/changes/archival-transfer-hardening/specs/edepot-bagit-output/spec.md
	 */
	private function invalidPackageFormat(array $params): ?JSONResponse {
		if (isset($params['packageFormat']) === false
			|| in_array($params['packageFormat'], self::PACKAGE_FORMATS, true) === true
		) {
			return null;
		}

		$requested = gettype($params['packageFormat']);
		if (is_scalar($params['packageFormat']) === true) {
			$requested = (string)$params['packageFormat'];
		}

		$available = implode(', ', self::PACKAGE_FORMATS);
		return new JSONResponse(
			data: ['error' => "Invalid package format '{$requested}'. Available: {$available}"],
			statusCode: 400
		);
	}//end invalidPackageFormat()

	/**
	 * Save every setting the request carries, skipping masked values so secrets are not overwritten with '***'.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return void
	 */
	private function storeSettings(array $params): void {
		foreach (self::CONFIG_KEYS as $paramKey => $configKey) {
			if (isset($params[$paramKey]) === false) {
				continue;
			}

			$value = (string)$params[$paramKey];
			if ($value !== '***') {
				$this->appConfig->setValueString('openregister', $configKey, $value);
			}
		}
	}//end storeSettings()

	/**
	 * Run the connection test when the request asks for it.
	 *
	 * @param array<string, mixed> $params The request parameters.
	 *
	 * @return bool|null Whether the transport answered, or null when no test was asked.
	 */
	private function requestedConnectionTest(array $params): ?bool {
		if (isset($params['testConnection']) === false || $params['testConnection'] !== true) {
			return null;
		}

		$transport = $this->resolveTransport(type: ($params['transport'] ?? 'rest_api'));
		$config = $this->transferService->getTransportConfig();
		$testResult = $transport->testConnection($config);
		$this->reportTest(passed: $testResult, transport: $transport->getName());

		return $testResult;
	}//end requestedConnectionTest()

	/**
	 * Test e-Depot connection.
	 *
	 * @return JSONResponse The connection test result.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function testEdepotConnection(): JSONResponse {
		try {
			$config = $this->transferService->getTransportConfig();
			$transport = $this->resolveTransport(type: ($config['transport'] ?? 'rest_api'));
			$result = $transport->testConnection($config);
			$this->reportTest(passed: $result, transport: $transport->getName());

			return new JSONResponse(
				data: ['success' => $result, 'transport' => $transport->getName(), 'message' => $this->testMessage(passed: $result)]
			);
		} catch (\Exception $e) {
			return $this->testFailed(e: $e);
		}
	}//end testEdepotConnection()

	/**
	 * Describe a connection test outcome.
	 *
	 * @param bool $passed Whether the transport answered.
	 *
	 * @return string The message.
	 */
	private function testMessage(bool $passed): string {
		if ($passed === true) {
			return 'Connection successful';
		}

		return 'Connection failed';
	}//end testMessage()

	/**
	 * Report a connection test that threw and answer it.
	 *
	 * @param \Exception $e The exception.
	 *
	 * @return JSONResponse The 500 answer.
	 */
	private function testFailed(\Exception $e): JSONResponse {
		$this->connectionReporter->report(
			key: 'edepot',
			status: 'error',
			message: 'The e-Depot connection test failed: ' . $e->getMessage()
		);

		return new JSONResponse(data: ['success' => false, 'error' => $e->getMessage()], statusCode: 500);
	}//end testFailed()

	/**
	 * The 500 answer for a settings read or write that threw.
	 *
	 * @param \Exception $e The exception.
	 *
	 * @return JSONResponse The failure.
	 */
	private function failure(\Exception $e): JSONResponse {
		return new JSONResponse(data: ['error' => $e->getMessage()], statusCode: 500);
	}//end failure()

	/**
	 * Tell integriq what an e-Depot connection test found.
	 *
	 * @param bool $passed Whether the transport answered.
	 * @param string $transport The transport name the test used.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/app-connections/spec.md
	 */
	private function reportTest(bool $passed, string $transport): void {
		if ($passed === true) {
			$this->connectionReporter->report(
				key: 'edepot',
				status: 'configured',
				message: 'The connection test passed over ' . $transport . '.'
			);
			return;
		}

		$this->connectionReporter->report(
			key: 'edepot',
			status: 'error',
			message: 'The connection test failed over ' . $transport . '.'
		);
	}//end reportTest()

	/**
	 * Resolve transport implementation by type name.
	 *
	 * @param string $type The transport type.
	 *
	 * @return TransportInterface The transport.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md
	 */
	private function resolveTransport(string $type): TransportInterface {
		switch ($type) {
			case 'sftp':
				return $this->sftpTransport;
			case 'openconnector':
				return $this->ocTransport;
			case 'rest_api':
			default:
				return $this->restTransport;
		}
	}//end resolveTransport()
}//end class
