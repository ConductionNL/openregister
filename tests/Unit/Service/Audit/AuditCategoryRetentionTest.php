<?php

/**
 * AuditCategoryRetentionTest
 *
 * Administrative audit rows take their own retention, the setting
 * `audit.administrativeRetention` (REQ-HSA-011); an unparsable period is
 * refused, and domain rows keep the retention they were written with.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Audit
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/history-schema-and-settings-edits-audited/specs/history-schema-and-settings-edits-audited/spec.md#requirement-the-one-trail-separates-administrative-from-domain-history-req-hsa-011
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Audit;

use DateTime;
use InvalidArgumentException;
use OCA\OpenRegister\Db\AuditTrail;
use OCA\OpenRegister\Db\AuditTrail\AuditTrailMapperServices;
use OCA\OpenRegister\Db\AuditTrailMapper;
use OCA\OpenRegister\Kernel\Contracts\AdministrativeRetentionPeriod;
use OCA\OpenRegister\Service\Audit\AdministrativeRetention;
use OCA\OpenRegister\Service\Audit\AuditCategory;
use OCP\IAppConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

#[CoversClass(AdministrativeRetention::class)]
#[CoversClass(AdministrativeRetentionPeriod::class)]
#[UsesClass(AuditTrail::class)]
#[UsesClass(AuditTrailMapper::class)]
#[UsesClass(AuditTrailMapperServices::class)]
#[UsesClass(AuditCategory::class)]
final class AuditCategoryRetentionTest extends TestCase {

	/** @var array<string, string> The stored settings. */
	private array $stored = [];

	/**
	 * Scenario "administrative rows keep their own retention": with P20Y set,
	 * a schema edit expires twenty years on, and an object row keeps its seven.
	 *
	 * @return void
	 */
	public function testAdministrativeRowsTakeTheirOwnRetention(): void {
		$this->stored[AdministrativeRetention::CONFIG_KEY] = 'P20Y';
		$mapper = $this->mapper(retention: new AdministrativeRetention(appConfig: $this->appConfig()));

		$schemaRow = new AuditTrail();
		$schemaRow->setAction('schema.updated');
		$schemaRow->setCreated(new DateTime('2026-11-14 12:00:00'));
		$mapper->stampCategory(auditTrail: $schemaRow);

		$this->assertSame('P20Y', $schemaRow->getRetentionPeriod());
		$this->assertSame('2046-11-14 12:00:00', $schemaRow->getExpires()?->format('Y-m-d H:i:s'));
		$this->assertSame('2026-11-14 12:00:00', $schemaRow->getCreated()?->format('Y-m-d H:i:s'), 'The creation moment is not moved');

		$objectRow = new AuditTrail();
		$objectRow->setAction('update');
		$objectRow->setCreated(new DateTime('2026-11-14 12:00:00'));
		$objectRow->setRetentionPeriod('P7Y');
		$objectRow->setExpires(new DateTime('2033-11-14 12:00:00'));
		$mapper->stampCategory(auditTrail: $objectRow);

		$this->assertSame(AuditCategory::DOMAIN, $objectRow->getCategory());
		$this->assertSame('P7Y', $objectRow->getRetentionPeriod());
		$this->assertSame('2033-11-14 12:00:00', $objectRow->getExpires()?->format('Y-m-d H:i:s'));
	}

	/**
	 * Without a setting, or with an unusable stored one, the default P10Y applies.
	 *
	 * @return void
	 */
	public function testTheDefaultIsTenYears(): void {
		$this->assertSame('P10Y', (new AdministrativeRetention(appConfig: $this->appConfig()))->period());

		$this->stored[AdministrativeRetention::CONFIG_KEY] = 'ten years';
		$this->assertSame('P10Y', (new AdministrativeRetention(appConfig: $this->appConfig()))->period());

		$this->assertSame('P10Y', (new AdministrativeRetention())->period());

		$row = new AuditTrail();
		$row->setAction('organisation.created');
		$row->setCreated(new DateTime('2026-01-31 08:00:00'));
		$this->mapper(retention: null)->stampCategory(auditTrail: $row);
		$this->assertSame('P10Y', $row->getRetentionPeriod());
		$this->assertSame('2036-01-31 08:00:00', $row->getExpires()?->format('Y-m-d H:i:s'));
	}

	/**
	 * An unparsable period is refused and nothing is stored; a valid one is stored trimmed.
	 *
	 * @return void
	 */
	public function testAnUnparsableDurationIsRefused(): void {
		$retention = new AdministrativeRetention(appConfig: $this->appConfig());

		foreach (['', 'ten years', 'P', 'PT', 'P10', '10Y', 'P1Y2X'] as $bad) {
			try {
				$retention->store(period: $bad);
				$this->fail('Stored "' . $bad . '"');
			} catch (InvalidArgumentException $e) {
				$this->assertStringContainsString('ISO 8601 duration', $e->getMessage());
			}
		}

		$this->assertSame([], $this->stored);

		$this->assertSame('P6M', $retention->store(period: ' P6M '));
		$this->assertSame(['audit.administrativeRetention' => 'P6M'], $this->stored);
		$this->assertSame('P6M', $retention->period());
		$this->assertSame(6, AdministrativeRetention::interval(period: 'P6M')->m);
		$this->assertSame(36, AdministrativeRetention::interval(period: 'PT36H')->h);
	}

	/**
	 * An app config over $this->stored.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => ($app === 'openregister' ? ($this->stored[$key] ?? $default) : $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $app, string $key, string $value): bool {
				$this->stored[$key] = $value;
				return true;
			}
		);

		return $config;
	}

	/**
	 * A real mapper whose container holds the given retention, or nothing.
	 *
	 * @param AdministrativeRetention|null $retention The retention service.
	 *
	 * @return AuditTrailMapper
	 */
	private function mapper(?AdministrativeRetention $retention): AuditTrailMapper {
		$container = $this->createMock(ContainerInterface::class);
		if ($retention === null) {
			$container->method('get')->willThrowException(new \RuntimeException('not registered'));
		} else {
			$container->method('get')->with(AdministrativeRetention::class)->willReturn($retention);
		}

		return new AuditTrailMapper(
			$this->createMock(IDBConnection::class),
			new AuditTrailMapperServices($container, $this->createMock(LoggerInterface::class), $this->createMock(IUserSession::class), $this->createMock(IRequest::class))
		);
	}
}
