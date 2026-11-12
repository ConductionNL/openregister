<?php

/**
 * OpenRegister e-Depot Transfer Settings
 *
 * The transport configuration an e-Depot transfer is sent with, read from the
 * app settings with each credential's fail mode stated.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Edepot
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Edepot;

use OCA\OpenRegister\Service\Edepot\Transport\RestApiTransport;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Reads the e-Depot transport configuration.
 *
 * Each credential-bearing key has its own `read*()` that states its fail
 * mode, rather than being folded into the array literal with a bare empty
 * default. An empty default is not neutral here: the REST transport used to
 * answer an unset `edepot_auth_type` by sending NO authentication headers, so
 * an unconfigured instance shipped its archival SIP packages — the records
 * themselves — unauthenticated, and nothing said so. `''` now means NOT
 * CONFIGURED, both transports refuse to send in that state, and the missing
 * value is logged. The configuration still CARRIES the empty values: the
 * settings screen has to render the unconfigured form.
 *
 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
 */
final class TransferSettings {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig The app configuration.
	 * @param LoggerInterface $logger    Logger.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The transport configuration.
	 *
	 * @return array<string,mixed> The transport configuration.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-support-configurable-e-depot-endpoint-settings
	 */
	public function getTransportConfig(): array {
		$transport   = $this->value(key: 'edepot_transport', default: 'rest_api');
		$credentials = $this->credentials();
		$sftp        = $this->sftpConfig(keyPath: $this->readSftpKeyPath());

		return array_merge(
			['endpointUrl' => $this->value(key: 'edepot_endpoint_url')],
			$credentials,
			$this->archiveConfig(transport: $transport),
			$sftp,
			[
				'sourceId' => $this->value(key: 'edepot_openconnector_source_id'),
				'baseUrl' => $this->value(key: 'edepot_openconnector_base_url'),
			]
		);
	}//end getTransportConfig()

	/**
	 * The configured target archive, or '' when unset.
	 *
	 * @return string The target archive.
	 *
	 * @spec openspec/specs/edepot-transfer/spec.md#requirement-the-system-must-log-all-transfer-actions-in-the-audit-trail
	 */
	public function targetArchive(): string {
		return $this->value(key: 'edepot_target_archive');
	}//end targetArchive()

	/**
	 * The REST credentials: the authentication type and both secrets.
	 *
	 * @return array{authenticationType: string, apiKey: string, bearerToken: string} The credentials.
	 */
	private function credentials(): array {
		$authType = $this->readAuthType();

		return [
			'authenticationType' => $authType,
			'apiKey' => $this->readApiKey(authType: $authType),
			'bearerToken' => $this->readBearerToken(authType: $authType),
		];
	}//end credentials()

	/**
	 * Where a package goes and in which profile.
	 *
	 * @param string $transport The configured transport.
	 *
	 * @return array{targetArchive: string, sipProfile: string, transport: string} The archive part of the configuration.
	 */
	private function archiveConfig(string $transport): array {
		return [
			'targetArchive' => $this->targetArchive(),
			'sipProfile' => $this->value(key: 'edepot_sip_profile', default: 'default'),
			'transport' => $transport,
		];
	}//end archiveConfig()

	/**
	 * The SFTP settings.
	 *
	 * @param string $keyPath The configured private-key path.
	 *
	 * @return array<string, string> The SFTP part of the configuration.
	 */
	private function sftpConfig(string $keyPath): array {
		return [
			'host' => $this->value(key: 'edepot_sftp_host'),
			'port' => $this->value(key: 'edepot_sftp_port', default: '22'),
			'username' => $this->value(key: 'edepot_sftp_username'),
			'password' => $this->value(key: 'edepot_sftp_password'),
			'keyPath' => $keyPath,
			'remotePath' => $this->value(key: 'edepot_sftp_remote_path', default: '/'),
			'hostKeyFingerprint' => $this->value(key: 'edepot_sftp_host_key_fingerprint'),
		];
	}//end sftpConfig()

	/**
	 * Read `edepot_auth_type`, stating its fail mode.
	 *
	 * `''` means NOT CONFIGURED — never "configured for no authentication".
	 * `RestApiTransport::validateConfig()` refuses to send in that state.
	 *
	 * @return string The configured authentication type, or '' when unset.
	 */
	private function readAuthType(): string {
		$authType = $this->value(key: 'edepot_auth_type');
		if ($authType === '') {
			$this->warn(message: 'edepot_auth_type is not configured; the REST transport refuses to send a SIP package without one');
		}

		return $authType;
	}//end readAuthType()

	/**
	 * Read `edepot_api_key`, stating its fail mode.
	 *
	 * Only warns when `api_key` is the SELECTED authentication type — an
	 * oauth2 or certificate instance is expected to leave this empty.
	 *
	 * @param string $authType The configured authentication type.
	 *
	 * @return string The configured API key, or '' when unset.
	 */
	private function readApiKey(string $authType): string {
		$apiKey = $this->value(key: 'edepot_api_key');
		if ($apiKey === '' && $authType === RestApiTransport::AUTH_API_KEY) {
			$this->warn(message: 'edepot_auth_type is api_key but edepot_api_key is empty; transfers are refused');
		}

		return $apiKey;
	}//end readApiKey()

	/**
	 * Read `edepot_bearer_token`, stating its fail mode.
	 *
	 * Only warns when `oauth2` is the SELECTED authentication type.
	 *
	 * @param string $authType The configured authentication type.
	 *
	 * @return string The configured bearer token, or '' when unset.
	 */
	private function readBearerToken(string $authType): string {
		$bearerToken = $this->value(key: 'edepot_bearer_token');
		if ($bearerToken === '' && $authType === RestApiTransport::AUTH_OAUTH2) {
			$this->warn(message: 'edepot_auth_type is oauth2 but edepot_bearer_token is empty; transfers are refused');
		}

		return $bearerToken;
	}//end readBearerToken()

	/**
	 * Read `edepot_sftp_key_path`, stating its fail mode.
	 *
	 * SFTP accepts either a key path or a password, so this only warns when
	 * BOTH are unset — the state in which `SftpTransport::validateConfig()`
	 * refuses to connect.
	 *
	 * @return string The configured private-key path, or '' when unset.
	 */
	private function readSftpKeyPath(): string {
		$sftpKeyPath = $this->value(key: 'edepot_sftp_key_path');
		if ($sftpKeyPath === '' && $this->value(key: 'edepot_sftp_password') === '') {
			$this->warn(message: 'neither edepot_sftp_key_path nor edepot_sftp_password is configured; SFTP transfers are refused');
		}

		return $sftpKeyPath;
	}//end readSftpKeyPath()

	/**
	 * One app setting.
	 *
	 * @param string $key     The setting key.
	 * @param string $default The value when unset.
	 *
	 * @return string The value.
	 */
	private function value(string $key, string $default = ''): string {
		return $this->appConfig->getValueString('openregister', $key, $default);
	}//end value()

	/**
	 * Log a missing setting.
	 *
	 * @param string $message What is missing and what that refuses.
	 *
	 * @return void
	 */
	private function warn(string $message): void {
		$this->logger->warning(
			message: '[EdepotTransferService] '.$message,
			context: ['file' => __FILE__, 'line' => __LINE__]
		);
	}//end warn()
}//end class
