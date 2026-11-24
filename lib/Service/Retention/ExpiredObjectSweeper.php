<?php

/**
 * ExpiredObjectSweeper: deletes or anonymises the expired objects of opted-in schemas.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Retention
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Retention;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * For every schema that declares `x-openregister.expiry`, deletes or
 * anonymises each object whose expiry has passed, skips objects under an
 * active legal hold, writes one audit entry per object and one log line per
 * schema. A dry run counts what a run would do and changes nothing.
 */
class ExpiredObjectSweeper {

	public const AUDIT_DELETED = 'expiry.deleted';

	public const AUDIT_ANONYMISED = 'expiry.anonymised';

	public const CONFIG_ENABLED = 'expiry_sweep_enabled';

	public const CONFIG_BATCH = 'expiry_sweep_batch';

	public const DEFAULT_BATCH = 500;

	/**
	 * Constructor.
	 *
	 * @param ExpiredObjectStoreInterface $store     Where the objects live.
	 * @param RetentionServiceInterface   $retention The legal-hold check.
	 * @param IAppConfig                  $appConfig The app configuration.
	 * @param LoggerInterface             $logger    The logger.
	 */
	public function __construct(
		private readonly ExpiredObjectStoreInterface $store,
		private readonly RetentionServiceInterface $retention,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether the daily sweep is switched on (default on).
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function isEnabled(): bool {
		return $this->appConfig->getValueBool('openregister', self::CONFIG_ENABLED, true);
	}//end isEnabled()

	/**
	 * How many objects one run handles per schema (default 500, at least 1).
	 *
	 * @return int
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function batchSize(): int {
		return max(1, $this->appConfig->getValueInt('openregister', self::CONFIG_BATCH, self::DEFAULT_BATCH));
	}//end batchSize()

	/**
	 * Run the sweep, or with $dryRun count what it would do.
	 *
	 * @param DateTimeImmutable $now    The moment of the run.
	 * @param bool              $dryRun Count only, change nothing.
	 *
	 * @return array<int, array{register: string, schema: string, deleted: int, anonymised: int, held: int, failed: int, left: int}>
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag)
	 *
	 * @spec openspec/changes/expired-object-sweep/specs/expired-object-sweep/spec.md
	 */
	public function sweep(DateTimeImmutable $now, bool $dryRun=false): array {
		$reports = [];
		foreach ($this->store->optedInTables() as $table) {
			$report    = $this->sweepTable(register: $table['register'], schema: $table['schema'], now: $now, dryRun: $dryRun);
			$reports[] = $report;
			if ($dryRun === false) {
				$this->logger->info(
					vsprintf(
						'[ExpiredObjectSweep] %s/%s deleted=%d anonymised=%d held=%d failed=%d left=%d',
						array_values($report)
					),
					['app' => 'openregister'] + $report
				);
			}
		}

		return $reports;
	}//end sweep()

	/**
	 * Sweep one table.
	 *
	 * @param Register          $register The register.
	 * @param Schema            $schema   The schema.
	 * @param DateTimeImmutable $now      The moment of the run.
	 * @param bool              $dryRun   Count only.
	 *
	 * @return array{register: string, schema: string, deleted: int, anonymised: int, held: int, failed: int, left: int}
	 */
	private function sweepTable(Register $register, Schema $schema, DateTimeImmutable $now, bool $dryRun): array {
		$report      = [
			'register' => (string)($register->getSlug() ?? $register->getId()),
			'schema' => (string)($schema->getSlug() ?? $schema->getId()),
			'deleted' => 0,
			'anonymised' => 0,
			'held' => 0,
			'failed' => 0,
			'left' => 0,
		];
		$declaration = ExpiryDeclaration::of(schema: $schema);
		if ($declaration === null) {
			return $report;
		}

		$total = $this->store->countExpired(register: $register, schema: $schema, now: $now);
		$batch = $this->store->expired(register: $register, schema: $schema, now: $now, limit: $this->batchSize());
		foreach ($batch as $object) {
			$outcome = $this->handle(table: [$register, $schema], declaration: $declaration, object: $object, dryRun: $dryRun);
			$report[$outcome]++;
		}

		$report['left'] = max(0, $total - count($batch));

		return $report;
	}//end sweepTable()

	/**
	 * Handle one expired object and say what happened: deleted, anonymised, held or failed.
	 *
	 * @param array{0: Register, 1: Schema} $table       The register and schema.
	 * @param ExpiryDeclaration             $declaration The schema's expiry.
	 * @param ObjectEntity                  $object      The object.
	 * @param bool                          $dryRun      Count only.
	 *
	 * @return string
	 */
	private function handle(array $table, ExpiryDeclaration $declaration, ObjectEntity $object, bool $dryRun): string {
		if ($this->retention->hasActiveLegalHold(object: $object) === true) {
			return 'held';
		}

		$data   = ($object->getObject() ?? []);
		$action = $declaration->actionFor(data: $data);
		if ($dryRun === true && $action === ExpiryDeclaration::ACTION_DELETE) {
			return 'deleted';
		}

		if ($dryRun === true) {
			return 'anonymised';
		}

		try {
			return $this->apply(table: $table, declaration: $declaration, object: $object, action: $action);
		} catch (Throwable $error) {
			$this->logger->error(
				'[ExpiredObjectSweep] '.$action.' failed for object '.(string)$object->getUuid().': '.$error->getMessage(),
				['app' => 'openregister', 'exception' => $error]
			);
			return 'failed';
		}
	}//end handle()

	/**
	 * Delete or anonymise one object and write its audit entry.
	 *
	 * @param array{0: Register, 1: Schema} $table       The register and schema.
	 * @param ExpiryDeclaration             $declaration The schema's expiry.
	 * @param ObjectEntity                  $object      The object.
	 * @param string                        $action      delete or anonymise.
	 *
	 * @return string deleted, anonymised or failed.
	 */
	private function apply(array $table, ExpiryDeclaration $declaration, ObjectEntity $object, string $action): string {
		[$register, $schema] = $table;
		$expires             = $object->getExpires()?->format(DATE_ATOM);
		if ($action === ExpiryDeclaration::ACTION_DELETE) {
			if ($this->store->delete(register: $register, schema: $schema, object: $object) === false) {
				return 'failed';
			}

			$this->store->audit(object: $object, action: self::AUDIT_DELETED, context: ['expires' => $expires]);
			return 'deleted';
		}

		$this->store->anonymise(register: $register, schema: $schema, object: $object, data: $declaration->anonymised(data: ($object->getObject() ?? [])));
		$this->store->audit(object: $object, action: self::AUDIT_ANONYMISED, context: ['expires' => $expires, 'kept' => $declaration->keep]);

		return 'anonymised';
	}//end apply()
}//end class
