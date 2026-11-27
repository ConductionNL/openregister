<?php

/**
 * Lists an organisation's export keys without reading a key.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Exchange
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;

/**
 * The "Export keys" list of the admin settings: id, fingerprint and creation
 * date of each of one organisation's export keys, newest first.
 *
 * It reads the credential objects only, never the stored secret: the
 * fingerprint is the one {@see ExportKeyService} wrote into the credential's
 * name when it minted the key, so listing is not a use of the key and writes
 * no audit row. Credentials of another provider or another organisation are
 * left out here as well as by the caller's organisation gate.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
 */
class ExportKeyDirectory {

	/**
	 * The credential broker's register, restated so this file names no Integrations class.
	 *
	 * @var string
	 */
	private const CREDENTIAL_REGISTER = 'credential-broker';

	/**
	 * The credential broker's schema, restated for the same reason.
	 *
	 * @var string
	 */
	private const CREDENTIAL_SCHEMA = 'brokeredcredential';

	/**
	 * The credential name an export key is minted under, with its fingerprint.
	 *
	 * @var string
	 */
	private const NAME_PATTERN = '/^Export key ([0-9a-f]{16})$/';

	/**
	 * Constructor.
	 *
	 * @param ObjectOperationsInterface $objectService Lists the export-key credentials.
	 */
	public function __construct(
		private readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()

	/**
	 * The organisation's export keys, newest first.
	 *
	 * @param string $organisation The organisation UUID, already gated by the caller.
	 *
	 * @return array<int, array{id: string, fingerprint: string|null, createdAt: string|null}> One row per key.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-export-keys-are-kept-in-the-credential-broker
	 */
	public function list(string $organisation): array {
		if (trim($organisation) === '') {
			return [];
		}

		$this->objectService->setRegister(self::CREDENTIAL_REGISTER)->setSchema(self::CREDENTIAL_SCHEMA);
		$objects = $this->objectService->findAll(
			config: ['filters' => ['provider' => BrokeredKeys::EXPORT_PROVIDER]],
			_rbac: false,
			_multitenancy: false
		);

		$keys = [];
		foreach ($objects as $object) {
			if ($object instanceof ObjectEntity === false) {
				continue;
			}

			$data = ($object->getObject() ?? []);
			if (($data['provider'] ?? null) !== BrokeredKeys::EXPORT_PROVIDER || ($data['organisation'] ?? null) !== $organisation) {
				continue;
			}

			$createdAt = null;
			if (is_string($data['createdAt'] ?? null) === true && $data['createdAt'] !== '') {
				$createdAt = $data['createdAt'];
			}

			$keys[] = [
				'id' => (string) $object->getUuid(),
				'fingerprint' => self::fingerprintOf(name: $data['name'] ?? null),
				'createdAt' => $createdAt,
			];
		}

		// ATOM timestamps sort as strings; a key that does not say when it was made sorts last.
		usort(
			$keys,
			static fn (array $left, array $right): int => strcmp((string) $right['createdAt'], (string) $left['createdAt'])
		);

		return $keys;
	}//end list()

	/**
	 * The fingerprint an export key's credential name carries.
	 *
	 * @param mixed $name The credential's name.
	 *
	 * @return string|null The 16 hex characters, or null for a name this service did not write.
	 */
	private static function fingerprintOf(mixed $name): ?string {
		if (is_string($name) === false || preg_match(self::NAME_PATTERN, $name, $match) !== 1) {
			return null;
		}

		return $match[1];
	}//end fingerprintOf()
}//end class
