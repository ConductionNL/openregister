<?php

/**
 * Finds the export key an unattended copy is encrypted with.
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
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\CopySealerInterface;
use OCA\OpenRegister\Kernel\Contracts\ExportKeySealersInterface;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The sealer of an organisation's newest usable export key (design D-6).
 *
 * The candidates are the organisation's export-key credentials, newest
 * first: a rotation creates a new key and keeps the old one for loading old
 * sets, so the newest is the one new copies use. Each candidate is read back
 * through {@see ExportKeyService::use()}, which runs the broker guard and
 * writes the `export.key-used` audit row; a candidate the broker refuses is
 * passed over, and with none left the answer is null.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
 */
class ExportKeySealers implements ExportKeySealersInterface {

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
	 * Constructor.
	 *
	 * @param ExportKeyService          $exportKeys    Reads a key back through the broker and audits the use.
	 * @param ObjectOperationsInterface $objectService Lists the export-key credentials.
	 * @param EncryptedSetWriter        $writer        Writes the encrypted set.
	 * @param LoggerInterface           $logger        Records a passed-over candidate, never its key.
	 */
	public function __construct(
		private readonly ExportKeyService $exportKeys,
		private readonly ObjectOperationsInterface $objectService,
		private readonly EncryptedSetWriter $writer,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The sealer of the organisation's newest usable export key.
	 *
	 * @param string $organisation The organisation UUID.
	 *
	 * @return CopySealerInterface|null The sealer, or null when the organisation has no usable export key.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-the-copy-before-a-destruction-can-be-required-to-be-encrypted
	 */
	public function sealerFor(string $organisation): ?CopySealerInterface {
		if (trim($organisation) === '') {
			return null;
		}

		foreach ($this->candidates(organisation: $organisation) as $keyId) {
			try {
				$key = $this->exportKeys->use(keyId: $keyId, organisation: $organisation);
			} catch (Throwable $e) {
				$this->logger->warning(
					'[ExportKeySealers] An export key was passed over for an encrypted copy',
					['keyId' => $keyId, 'organisation' => $organisation, 'reason' => $e::class]
				);
				continue;
			}

			return new ExportKeySealer(key: $key, writer: $this->writer);
		}

		return null;
	}//end sealerFor()

	/**
	 * The organisation's export-key credential ids, newest first.
	 *
	 * @param string $organisation The organisation UUID.
	 *
	 * @return array<int, string> The credential UUIDs.
	 */
	private function candidates(string $organisation): array {
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

			$keys[] = ['id' => (string) $object->getUuid(), 'createdAt' => (string) ($data['createdAt'] ?? '')];
		}

		// ATOM timestamps sort as strings; a key that does not say when it was made sorts last.
		usort($keys, static fn (array $left, array $right): int => strcmp($right['createdAt'], $left['createdAt']));

		return array_column($keys, 'id');
	}//end candidates()
}//end class
