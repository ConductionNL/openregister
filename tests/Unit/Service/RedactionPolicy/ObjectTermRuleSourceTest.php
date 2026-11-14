<?php

/**
 * Unit tests for ObjectTermRuleSource: the termRule objects read through the object service.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/redaction-policy-as-data/specs/redaction-policy/spec.md#REQ-RPD-001
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Kernel\Contracts\ObjectOperationsInterface;
use OCA\OpenRegister\Service\RedactionPolicy\ObjectTermRuleSource;
use OCA\OpenRegister\Service\RedactionPolicy\PolicyIndexCache;
use OCA\OpenRegister\Service\RedactionPolicy\TermRuleMatcher;
use OCA\OpenRegister\Service\RedactionPolicy\TextFolder;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Service\RedactionPolicy\ObjectTermRuleSource
 * @covers \OCA\OpenRegister\Service\RedactionPolicy\PolicyIndexCache
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Service\RedactionPolicy\PolicyIndex
 * @uses   \OCA\OpenRegister\Service\RedactionPolicy\PolicyMatch
 * @uses   \OCA\OpenRegister\Service\RedactionPolicy\TermRule
 * @uses   \OCA\OpenRegister\Service\RedactionPolicy\TermRuleMatcher
 * @uses   \OCA\OpenRegister\Service\RedactionPolicy\TextFolder
 */
final class ObjectTermRuleSourceTest extends TestCase {

	private static function entity(string $uuid, array $data, ?string $slug=null): ObjectEntity {
		$entity = new ObjectEntity();
		$entity->setUuid($uuid);
		$entity->setSlug($slug);
		$entity->setObject($data);
		return $entity;
	}

	private function cacheOver(ObjectTermRuleSource $source): PolicyIndexCache {
		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable('2026-11-13T12:00:00Z'));
		return new PolicyIndexCache($source, $clock);
	}

	public function testItReadsTheTermRuleSchemaPageByPage(): void {
		$full = [];
		for ($i = 0; $i < 500; $i++) {
			$full[] = ['@self' => ['uuid' => 'u' . $i], 'list' => 'never'];
		}

		$configs = [];
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->exactly(2))->method('findAll')->willReturnCallback(
			function (array $config) use (&$configs, $full): array {
				$configs[] = $config;
				return count($configs) === 1 ? $full : [['@self' => ['uuid' => 'last'], 'list' => 'always']];
			}
		);

		$rows = (new ObjectTermRuleSource($objects))->termRuleObjects();

		$this->assertCount(501, $rows);
		$this->assertSame('last', $rows[500]['@self']['uuid']);
		$filters = ['register' => 'redaction-policy', 'schema' => 'termRule'];
		$this->assertSame([['filters' => $filters, 'limit' => 500, 'offset' => 0], ['filters' => $filters, 'limit' => 500, 'offset' => 500]], $configs);
	}

	public function testEntitiesArriveWithTheirSelfAndAnythingElseIsSkipped(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->method('findAll')->willReturn(
			[
				self::entity('11111111-1111-1111-1111-111111111111', ['list' => 'always', 'layer' => 'base'], 'woo-bsn'),
				'not an object',
				null,
			]
		);

		$rows = (new ObjectTermRuleSource($objects))->termRuleObjects();

		$this->assertCount(1, $rows);
		$this->assertSame('always', $rows[0]['list']);
		$this->assertSame('base', $rows[0]['layer']);
		$this->assertSame('woo-bsn', $rows[0]['@self']['slug']);
	}

	public function testAStoredRuleMatchesThroughTheIndex(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->once())->method('findAll')->willReturn(
			[
				self::entity('22222222-2222-2222-2222-222222222222', ['list' => 'always', 'matchRules' => [['type' => 'exact', 'value' => 'Jan Jansen']]]),
			]
		);
		$cache = $this->cacheOver(new ObjectTermRuleSource($objects));

		$match = (new TermRuleMatcher(new TextFolder()))->match($cache->index(), 'Jan Jansen', 'PERSON');

		$this->assertNotNull($match);
		$this->assertSame('always', $match->rule->list);
		$this->assertSame('22222222-2222-2222-2222-222222222222', $match->rule->uuid);
		$this->assertNull((new TermRuleMatcher(new TextFolder()))->match($cache->index(), 'Piet Jansen', 'PERSON'));
	}

	public function testAFailedReadIsNotAnEmptyPolicyAndIsNotKept(): void {
		$objects = $this->createMock(ObjectOperationsInterface::class);
		$objects->expects($this->exactly(2))->method('findAll')->willReturnOnConsecutiveCalls(
			$this->throwException(new RuntimeException('database gone')),
			[]
		);
		$cache = $this->cacheOver(new ObjectTermRuleSource($objects));

		try {
			$cache->index();
			$this->fail('A failed read answered an index');
		} catch (RuntimeException $failure) {
			$this->assertSame('database gone', $failure->getMessage());
		}

		$this->assertFalse($cache->isBuilt());
		$cache->index();
		$this->assertTrue($cache->isBuilt());
		$cache->invalidate();
		$this->assertFalse($cache->isBuilt());
	}
}//end class
