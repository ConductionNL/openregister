<?php

/**
 * The one-use key that carries an encrypted export or load into its background job.
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
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Exchange;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\Credential\CredentialBrokerService;
use Throwable;

/**
 * A background job never sees a passphrase (design D-3).
 *
 * The request that starts an encrypted export or load derives the key at once
 * and stores it here as a one-use organisation credential named for the job;
 * the job's argument holds only the credential id. The job reads the key back
 * through the broker inside {@see withKey()}, which deletes it in a `finally`
 * whether the work succeeded or threw. {@see sweep()} deletes any job key
 * older than 24 hours, for a job that died before it ran.
 *
 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
 */
class OneUseJobKey {

	/**
	 * How long an unused job key may live.
	 *
	 * @var string
	 */
	public const MAX_AGE = 'PT24H';

	/**
	 * Constructor.
	 *
	 * @param BrokeredKeys              $keys          Stores, reads and removes the keys in the broker.
	 * @param ObjectOperationsInterface $objectService Lists the job keys for the sweep.
	 */
	public function __construct(
		private readonly BrokeredKeys $keys,
		private readonly ObjectOperationsInterface $objectService,
	) {
	}//end __construct()

	/**
	 * Store a derived key for one job and return the credential id the job argument carries.
	 *
	 * @param string $jobName      What the job is, for the credential name (never a secret).
	 * @param string $key          The 32-byte derived key.
	 * @param string $organisation The organisation the job runs for.
	 * @param string $owner        The administrator who started it.
	 *
	 * @return string The credential UUID; the only thing the job argument may hold.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public function store(string $jobName, #[\SensitiveParameter] string $key, string $organisation, string $owner): string {
		return $this->keys->put(
			record: new BrokeredKeyRecord(
				provider: BrokeredKeys::JOB_PROVIDER,
				name: 'Job key ' . $jobName,
				organisation: $organisation,
				owner: $owner
			),
			key: $key
		);
	}//end store()

	/**
	 * Run the job's work with its key, and delete the key afterwards whatever happened.
	 *
	 * @param string   $credentialId The credential id from the job argument.
	 * @param string   $organisation The organisation the job runs for.
	 * @param callable $work         Receives the 32-byte key; its return value is returned.
	 *
	 * @return mixed What the work returned.
	 *
	 * @throws ExportKeyException When the credential is gone or is not a job key; nothing is deleted.
	 * @throws \OCA\OpenRegister\Service\Credential\CredentialAccessDeniedException When the broker refuses the job's organisation; nothing is deleted.
	 * @throws Throwable Whatever the work throws, after the key was deleted.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public function withKey(string $credentialId, string $organisation, callable $work): mixed {
		// The provider and the broker guard are checked before the `finally` is armed: a job
		// argument naming an export key, or another organisation's job key, is refused and
		// leaves that credential alone. Only an admitted job key is ever deleted here.
		$key = $this->keys->get(keyId: $credentialId, provider: BrokeredKeys::JOB_PROVIDER, organisation: $organisation);
		try {
			return $work($key);
		} finally {
			$this->keys->remove(keyId: $credentialId);
		}
	}//end withKey()

	/**
	 * Delete every job key created more than 24 hours before now.
	 *
	 * @param DateTimeInterface $now The current time.
	 *
	 * @return int How many job keys were deleted.
	 *
	 * @spec openspec/changes/exchange-encrypted-instance-export/specs/data-import-export/spec.md#requirement-an-instance-set-can-be-encrypted-with-a-passphrase-or-an-export-key
	 */
	public function sweep(DateTimeInterface $now): int {
		$cutoff = DateTimeImmutable::createFromInterface($now)->sub(new DateInterval(self::MAX_AGE));
		$swept  = 0;
		foreach ($this->jobKeys() as $credential) {
			$createdAt = self::createdAt(data: $credential->getObject());
			if ($createdAt === null || $createdAt > $cutoff) {
				continue;
			}

			if ($this->keys->remove(keyId: (string) $credential->getUuid()) === true) {
				$swept++;
			}
		}

		return $swept;
	}//end sweep()

	/**
	 * Every credential object of the job-key provider.
	 *
	 * @return array<int, ObjectEntity> The job key credentials.
	 */
	private function jobKeys(): array {
		$this->objectService->setRegister(CredentialBrokerService::REGISTER)->setSchema(CredentialBrokerService::SCHEMA);
		$objects = $this->objectService->findAll(
			config: ['filters' => ['provider' => BrokeredKeys::JOB_PROVIDER]],
			_rbac: false,
			_multitenancy: false
		);

		return array_values(
			array_filter(
				$objects,
				static fn ($object): bool => $object instanceof ObjectEntity
					&& ($object->getObject()['provider'] ?? null) === BrokeredKeys::JOB_PROVIDER
			)
		);
	}//end jobKeys()

	/**
	 * When a credential was minted, or null when it does not say.
	 *
	 * @param array<string, mixed>|null $data The credential's data.
	 *
	 * @return DateTimeImmutable|null The creation time.
	 */
	private static function createdAt(?array $data): ?DateTimeImmutable {
		$value = $data['createdAt'] ?? null;
		if (is_string($value) === false || $value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end createdAt()
}//end class
