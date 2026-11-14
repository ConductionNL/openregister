<?php

/**
 * DestructionDryRun: what the next destruction run would list, and what it holds back by inheritance.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Archival
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Service\Archival;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Db\MagicMapper\MagicObjectFinderInterface;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Service\Archival\DestructionDryRun;
use OCA\OpenRegister\Service\DisposalSweep;
use OCA\OpenRegister\Service\RetentionServiceInterface;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[CoversClass(DestructionDryRun::class)]
#[UsesClass(DisposalSweep::class)]
#[UsesClass(ObjectEntity::class)]
class DestructionDryRunTest extends TestCase {

	/**
	 * Objects the retention scan visits, in order.
	 *
	 * @var ObjectEntity[]
	 */
	private array $scanned = [];

	/**
	 * The refusal per object uuid; absent means eligible.
	 *
	 * @var array<string, string>
	 */
	private array $refusals = [];

	/**
	 * Ancestors the finder can read, by uuid.
	 *
	 * @var array<string, ObjectEntity>
	 */
	private array $ancestors = [];

	/**
	 * The sweeps the rule was asked with.
	 *
	 * @var DisposalSweep[]
	 */
	private array $sweeps = [];

	private function dryRun(): DestructionDryRun {
		$retention = $this->createMock(RetentionServiceInterface::class);
		$retention->method('getObjectsOnPendingDestructionLists')->willReturn(['already-listed']);
		$retention->method('scanObjectsWithRetention')->willReturnCallback(
			function (callable $accept): array {
				$matches = [];
				foreach ($this->scanned as $object) {
					if ($accept($object) === true) {
						$matches[] = $object;
					}
				}

				return ['objects' => $matches, 'scanned' => count($this->scanned), 'truncated' => false];
			}
		);
		$retention->method('destructionRefusalIn')->willReturnCallback(
			function (ObjectEntity $object, DisposalSweep $sweep): ?string {
				$this->sweeps[] = $sweep;
				return ($this->refusals[(string) $object->getUuid()] ?? null);
			}
		);

		$finder = $this->createMock(MagicObjectFinderInterface::class);
		$finder->method('findAcrossAllSources')->willReturnCallback(
			function (string|int $identifier, bool $includeDeleted=false, bool $_rbac=true, bool $_multitenancy=true): array {
				$this->assertFalse($_rbac);
				$this->assertFalse($_multitenancy);
				if (isset($this->ancestors[(string) $identifier]) === false) {
					throw new DoesNotExistException('gone');
				}

				return ['object' => $this->ancestors[(string) $identifier], 'register' => null, 'schema' => null];
			}
		);

		return new DestructionDryRun($retention, $finder, $this->createMock(LoggerInterface::class));
	}

	private function object(string $uuid, string $name, ?string $appraisal=null): ObjectEntity {
		$object = new ObjectEntity();
		$object->setUuid($uuid);
		$object->setName($name);
		$object->setSchema('7');
		if ($appraisal !== null) {
			$object->setRetention(['archiefnominatie' => $appraisal]);
		}

		return $object;
	}

	public function testThreePublicationsUnderAHotspotAreHeldBackWithTheSubjectNamed(): void {
		$this->ancestors['subject-1'] = $this->object('subject-1', 'Stikstof', 'blijvend_bewaren');
		foreach (['pub-1', 'pub-2', 'pub-3'] as $uuid) {
			$this->scanned[]         = $this->object($uuid, 'Besluit '.$uuid);
			$this->refusals[$uuid]   = 'inherited_permanent_appraisal:subject-1';
		}

		$this->scanned[] = $this->object('pub-4', 'Besluit pub-4');

		$result = $this->dryRun()->run();

		$this->assertSame([['uuid' => 'pub-4', 'name' => 'Besluit pub-4', 'schema' => '7']], $result['eligible']);
		$this->assertSame(['pub-1', 'pub-2', 'pub-3'], array_column($result['heldBack'], 'uuid'));
		$this->assertSame('inherited_permanent_appraisal', $result['heldBack'][0]['reason']);
		$this->assertSame(
			['uuid' => 'subject-1', 'name' => 'Stikstof', 'appraisal' => 'blijvend_bewaren', 'property' => null],
			$result['heldBack'][2]['ancestor']
		);
		$this->assertSame(4, $result['scanned']);
		$this->assertFalse($result['truncated']);
	}

	public function testAnUnresolvedAncestorIsReportedWithItsProperty(): void {
		$this->scanned[]          = $this->object('pub-1', 'Besluit');
		$this->refusals['pub-1']  = 'unresolved_ancestor:subjects:gone-uuid';

		$result = $this->dryRun()->run();

		$this->assertSame([], $result['eligible']);
		$this->assertSame('unresolved_ancestor', $result['heldBack'][0]['reason']);
		$this->assertSame(
			['uuid' => 'gone-uuid', 'name' => null, 'appraisal' => null, 'property' => 'subjects'],
			$result['heldBack'][0]['ancestor']
		);
	}

	public function testOtherRefusalsAreNeitherListedNorHeldBack(): void {
		$this->scanned[]         = $this->object('not-due', 'Not due');
		$this->refusals['not-due'] = 'not_due';
		$this->scanned[]         = $this->object('on-hold', 'On hold');
		$this->refusals['on-hold'] = 'legal_hold';

		$result = $this->dryRun()->run();

		$this->assertSame([], $result['eligible']);
		$this->assertSame([], $result['heldBack']);
		$this->assertSame(['already-listed'], $this->sweeps[0]->excludeUuids);
	}

	public function testAnAncestorThatVanishedSinceTheRuleRanIsStillReportedByUuid(): void {
		$this->scanned[]         = $this->object('pub-1', 'Besluit');
		$this->refusals['pub-1'] = 'inherited_permanent_appraisal:subject-gone';

		$result = $this->dryRun()->run();

		$this->assertSame(
			['uuid' => 'subject-gone', 'name' => null, 'appraisal' => null, 'property' => null],
			$result['heldBack'][0]['ancestor']
		);
	}

	public function testOnlyInheritedReasonsCount(): void {
		$this->assertTrue(DestructionDryRun::isInherited('inherited_permanent_appraisal:x'));
		$this->assertTrue(DestructionDryRun::isInherited('unresolved_ancestor:p:x'));
		$this->assertFalse(DestructionDryRun::isInherited('inherited_permanent_appraisal'));
		$this->assertFalse(DestructionDryRun::isInherited('legal_hold'));
	}
}
