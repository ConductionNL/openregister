<?php

/**
 * DeclaredProviderApprovals — reads the administrator decisions on declared providers.
 *
 * Decisions are `credentialProviderApproval` objects in the credential-broker
 * register, so the audit trail carries who decided, when and on what digest. This
 * class only READS them, cached per request; a read that fails yields no decision,
 * which leaves a novel declaration pending and therefore denied.
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
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Credential;

use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read side of the declared-provider approval records.
 *
 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-approval-decisions-are-recorded-in-the-audit-trail-with-the-deciding-user
 */
class DeclaredProviderApprovals {

	/**
	 * Schema slug of the approval records, in the credential-broker register.
	 *
	 * @var string
	 */
	public const SCHEMA = 'credentialproviderapproval';

	/**
	 * Decisions per provider identifier, read once per request.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $cache = [];

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService Reads the approval objects.
	 * @param LoggerInterface           $logger        Logs a failed read, secret-free.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The decisions recorded for one provider identifier, as stored.
	 *
	 * @param string $identifier The `<appId>:<localId>` identifier.
	 *
	 * @return array<int, array<string, mixed>> The decisions; empty when none, or when the read failed.
	 *
	 * @spec openspec/changes/app-declared-credential-providers/specs/app-declared-credential-providers/spec.md#requirement-a-novel-declaration-is-unusable-until-an-administrator-approves-it
	 */
	public function decisionsFor(string $identifier): array {
		if (array_key_exists($identifier, $this->cache) === true) {
			return $this->cache[$identifier];
		}

		$this->cache[$identifier] = $this->read(identifier: $identifier);
		return $this->cache[$identifier];
	}//end decisionsFor()

	/**
	 * Read the decisions from the register.
	 *
	 * @param string $identifier The provider identifier.
	 *
	 * @return array<int, array<string, mixed>> The decisions whose `providerIdentifier` is exactly the identifier.
	 */
	private function read(string $identifier): array {
		try {
			$result = $this->objectService->searchObjectsBySlug(
				registerSlug: CredentialBrokerService::REGISTER,
				schemaSlug: self::SCHEMA,
				filters: ['providerIdentifier' => $identifier],
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->warning('[DeclaredProviderApprovals] approval records could not be read for "' . $identifier . '"');
			return [];
		}

		if (is_array($result) === false) {
			return [];
		}

		$decisions = [];
		foreach ($result as $object) {
			$data = $object->getObject();
			if (is_array($data) === true && ($data['providerIdentifier'] ?? null) === $identifier) {
				$decisions[] = $data;
			}
		}

		return $decisions;
	}//end read()
}//end class
