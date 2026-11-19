<?php

/**
 * The Core read contracts of the audit trail row and the processing activity
 * are implemented by their Compliance entities and serve the stored columns.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/deletion-audit-trail/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel\Contracts;

use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\Verwerkingsactiviteit;
use OCA\OpenRegister\Kernel\Contracts\Core\AuditRecordInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RetainingActivityInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @coversNothing
 */
final class CoreReadContractsTest extends TestCase {

	/**
	 * Each contract with the entity that implements it.
	 *
	 * @return array<string, array{class-string, class-string}>
	 */
	public static function contracts(): array {
		return [
			'audit record'       => [AuditRecordInterface::class, AuditTrail::class],
			'retaining activity' => [RetainingActivityInterface::class, Verwerkingsactiviteit::class],
		];
	}//end contracts()

	/**
	 * The entity implements the contract, and every accessor the contract lists is a column accessor of the entity, with the same type.
	 *
	 * @param class-string $contract The contract.
	 * @param class-string $entity   The entity.
	 *
	 * @return void
	 */
	#[DataProvider('contracts')]
	public function testTheEntityServesEveryAccessorOfTheContract(string $contract, string $entity): void {
		$this->assertTrue(is_subclass_of($entity, $contract), $entity . ' implements ' . $contract);

		$listed = self::methodTags(class: $contract);
		$this->assertNotSame([], $listed);
		$this->assertSame([], (new ReflectionClass($contract))->getMethods(), 'the contract lists accessors as tags only');

		$columns = self::methodTags(class: $entity);
		foreach ($listed as $method => $type) {
			$this->assertSame($type, $columns[$method] ?? null, $entity . ' has no column accessor ' . $method . '(): ' . $type);
		}
	}//end testTheEntityServesEveryAccessorOfTheContract()

	/**
	 * The audit record reports who acted.
	 *
	 * @return void
	 */
	public function testTheAuditRecordReportsTheActor(): void {
		$record = new AuditTrail();
		$record->setUser('alice');

		$this->assertSame('alice', self::actorOf(record: $record));
	}//end testTheAuditRecordReportsTheActor()

	/**
	 * The retaining activity reports its uuid and retention period.
	 *
	 * @return void
	 */
	public function testTheRetainingActivityReportsItsPeriod(): void {
		$activity = new Verwerkingsactiviteit();
		$activity->setUuid('va-1');
		$activity->setRetentionPeriod('P7Y');

		$this->assertSame(['va-1', 'P7Y'], self::periodOf(activity: $activity));
	}//end testTheRetainingActivityReportsItsPeriod()

	/**
	 * Read the actor through the contract.
	 *
	 * @param AuditRecordInterface $record The record.
	 *
	 * @return string|null
	 */
	private static function actorOf(AuditRecordInterface $record): ?string {
		return $record->getUser();
	}//end actorOf()

	/**
	 * Read the uuid and period through the contract.
	 *
	 * @param RetainingActivityInterface $activity The activity.
	 *
	 * @return array{0: string|null, 1: string|null}
	 */
	private static function periodOf(RetainingActivityInterface $activity): array {
		return [$activity->getUuid(), $activity->getRetentionPeriod()];
	}//end periodOf()

	/**
	 * The `@method` tags of a class docblock, as method name to return type.
	 *
	 * @param class-string $class The class or interface.
	 *
	 * @return array<string, string>
	 */
	private static function methodTags(string $class): array {
		$doc = (string)(new ReflectionClass($class))->getDocComment();
		preg_match_all('/@method\s+(\S+)\s+(\w+)\(\)/', $doc, $matches, PREG_SET_ORDER);

		$tags = [];
		foreach ($matches as $match) {
			$tags[$match[2]] = $match[1];
		}

		return $tags;
	}//end methodTags()
}//end class
