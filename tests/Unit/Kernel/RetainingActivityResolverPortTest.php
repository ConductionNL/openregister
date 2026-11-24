<?php

/**
 * The retention clocks resolve a processing activity through a Core port; the Compliance mapper answers it.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Tests\Unit\Kernel
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Kernel;

use DateTime;
use DateTimeImmutable;
use OCA\OpenRegister\Db\ComplianceMapperBindings;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapper;
use OCA\OpenRegister\Db\VerwerkingsactiviteitMapperInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RetainingActivityInterface;
use OCA\OpenRegister\Kernel\Contracts\Core\RetainingActivityResolverInterface;
use OCA\OpenRegister\Service\Deletion\RetentionClockService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionNamedType;

/**
 * The port is bound to the mapper that already answered, and the clocks read through it alone.
 *
 * @covers \OCA\OpenRegister\Service\Deletion\RetentionClockService
 */
final class RetainingActivityResolverPortTest extends TestCase {

	public function testComplianceBindsThePortToTheProcessingActivityMapper(): void {
		$this->assertSame(VerwerkingsactiviteitMapper::class, ComplianceMapperBindings::BINDINGS[RetainingActivityResolverInterface::class]);
		$this->assertSame(VerwerkingsactiviteitMapper::class, ComplianceMapperBindings::BINDINGS[VerwerkingsactiviteitMapperInterface::class]);
	}

	public function testTheMapperInterfaceIsThePort(): void {
		$this->assertTrue(is_subclass_of(VerwerkingsactiviteitMapperInterface::class, RetainingActivityResolverInterface::class));
		$this->assertTrue(is_subclass_of(VerwerkingsactiviteitMapper::class, RetainingActivityResolverInterface::class));
	}

	public function testTheClocksTypeAgainstThePort(): void {
		$type = (new ReflectionClass(RetentionClockService::class))->getConstructor()?->getParameters()[0]->getType();
		$this->assertInstanceOf(ReflectionNamedType::class, $type);
		$this->assertSame(RetainingActivityResolverInterface::class, $type->getName());
	}

	public function testAnyPortImplementationDrivesTheAvgClock(): void {
		$resolver = new class implements RetainingActivityResolverInterface {
			/**
			 * The references asked for.
			 *
			 * @var string[]
			 */
			public array $asked = [];

			public function resolveReference(string $reference): ?RetainingActivityInterface {
				$this->asked[] = $reference;
				return new class implements RetainingActivityInterface {
					public function getUuid(): string {
						return 'activity-9';
					}

					public function getRetentionPeriod(): string {
						return 'P1Y';
					}
				};
			}
		};

		$object = new ObjectEntity();
		$object->setUuid('zaak-1');
		$object->setUpdated(new DateTime('2026-01-01T00:00:00+00:00'));
		$object->setRetention([]);
		$object->setProcessingActivityId('activity-9');

		$clocks = (new RetentionClockService($resolver, new NullLogger()))->clocksFor($object, new DateTimeImmutable('2026-06-01T00:00:00+00:00'));

		$this->assertSame(['activity-9'], $resolver->asked);
		$this->assertSame('2027-01-01T00:00:00+00:00', $clocks['avg']['date']);
		$this->assertStringContainsString('activity-9', $clocks['avg']['rule']);
	}
}//end class
