<?php

/**
 * Unit tests for the term-rule matching core: TermRule, PolicyIndex, PolicyIndexCache, TermRuleMatcher.
 *
 * Ports the cases of filinq's PolicyMatchServiceTest (exact, normalized, bsn,
 * kvk; inactive and out-of-window rules; lowest uuid; prohibition wins) and
 * adds the engine's own: both matching rules recorded, request scope, a local
 * override switching a base rule off, the text search for always terms, and
 * no read of the rules per detected entity.
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

namespace OCA\OpenRegister\Tests\Unit\Service\RedactionPolicy;

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use DateTimeImmutable;
use OCA\OpenRegister\Service\RedactionPolicy\PolicyIndex;
use OCA\OpenRegister\Service\RedactionPolicy\PolicyIndexCache;
use OCA\OpenRegister\Service\RedactionPolicy\PolicyMatch;
use OCA\OpenRegister\Service\RedactionPolicy\TermRule;
use OCA\OpenRegister\Service\RedactionPolicy\TermRuleMatcher;
use OCA\OpenRegister\Service\RedactionPolicy\TermRuleSourceInterface;
use OCA\OpenRegister\Service\RedactionPolicy\TextFolder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

#[CoversClass(TermRuleMatcher::class)]
#[CoversClass(TermRule::class)]
#[CoversClass(PolicyIndex::class)]
#[CoversClass(PolicyIndexCache::class)]
#[CoversClass(PolicyMatch::class)]
#[CoversClass(TextFolder::class)]
final class TermRuleMatcherTest extends TestCase {

	private const NOW = '2026-11-13T12:00:00+00:00';

	private TermRuleMatcher $matcher;

	protected function setUp(): void {
		$this->matcher = new TermRuleMatcher(new TextFolder());
	}

	/**
	 * @param array<string, mixed> $fields
	 *
	 * @return array<string, mixed>
	 */
	private static function rule(string $uuid, string $list, string $type, string $value, array $fields=[]): array {
		return ['@self' => ['uuid' => $uuid], 'list' => $list, 'matchRules' => [['type' => $type, 'value' => $value]]] + $fields;
	}

	/**
	 * @param array<int, array<string, mixed>> $objects
	 */
	private static function index(array $objects): PolicyIndex {
		return PolicyIndex::fromObjects($objects, new DateTimeImmutable(self::NOW));
	}

	public function testExactDoesNotMatchVariants(): void {
		$index = self::index([self::rule('a', 'never', 'exact', 'Gemeente Voorbeeldstad', ['entityType' => 'ORGANIZATION'])]);

		$match = $this->matcher->match($index, 'Gemeente Voorbeeldstad', 'ORGANIZATION');
		$this->assertSame('a', $match->rule->uuid);
		$this->assertSame(PolicyMatch::DECISION_RELEASE, $match->decision());

		$this->assertNull($this->matcher->match($index, 'gemeente voorbeeldstad', 'ORGANIZATION'));
		$this->assertNull($this->matcher->match($index, 'Gemeente Voorbeeldstad ', 'ORGANIZATION'));
		$this->assertNull($this->matcher->match($index, 'Gemeente Voorbeeldstad', 'PERSON'), 'another entity type');
	}

	public function testNormalizedFoldsCaseAndAccents(): void {
		$index = self::index([self::rule('a', 'always', 'normalized', 'beschermde getuige a')]);

		$this->assertSame(PolicyMatch::DECISION_REDACT, $this->matcher->match($index, 'Beschermde Getuige A', 'PERSON')->decision());
		$this->assertNotNull($this->matcher->match($index, ' BESCHERMDE GETUIGE A ', 'OTHER'));

		$accented = self::index([self::rule('b', 'always', 'normalized', 'Zoë Müller')]);
		$this->assertNotNull($this->matcher->match($accented, 'ZOË MÜLLER', 'PERSON'));
		if (class_exists(\Transliterator::class) === true) {
			// Accents fold with intl; without it only case does.
			$this->assertNotNull($this->matcher->match($accented, 'zoe muller', 'PERSON'));
		}

		$this->assertNull($this->matcher->match($accented, 'zoe mulder', 'PERSON'));
	}

	public function testBsnMatchesOnlyAResolvedBsn(): void {
		$index = self::index([self::rule('a', 'always', 'bsn', '123456782')]);

		$this->assertNotNull($this->matcher->match($index, 'whatever', 'BSN', ['bsn' => '123456782']));
		$this->assertNull($this->matcher->match($index, '123456782', 'BSN'), 'the text alone is not a resolved bsn');
		$this->assertNull($this->matcher->match($index, 'x', 'BSN', ['bsn' => '111222333']));

		$any = self::index([self::rule('b', 'always', 'kvk', '*')]);
		$this->assertNotNull($this->matcher->match($any, 'x', 'ORGANIZATION', ['kvk' => '12345678']));
		$this->assertNull($this->matcher->match($any, 'x', 'ORGANIZATION', ['bsn' => '123456782']));
	}

	public function testAlwaysWinsAndBothAreAudited(): void {
		$index = self::index([
			self::rule('0-never', 'never', 'exact', 'P. de Boer'),
			self::rule('z-always', 'always', 'normalized', 'p. de boer', ['bases' => ['5.1.2e']]),
		]);

		$match = $this->matcher->match($index, 'P. de Boer', 'PERSON');

		$this->assertSame('z-always', $match->rule->uuid, 'always wins even with the higher uuid');
		$this->assertSame(PolicyMatch::DECISION_REDACT, $match->decision());
		$this->assertSame(['z-always', '0-never'], $match->matchedUuids());
		$this->assertSame(['5.1.2e'], $match->rule->bases);
	}

	public function testTheLowestUuidIsRecorded(): void {
		$index = self::index([
			self::rule('c', 'always', 'exact', 'X'),
			self::rule('a', 'always', 'normalized', 'x'),
			self::rule('b', 'always', 'exact', 'X'),
		]);

		$match = $this->matcher->match($index, 'X', 'PERSON');

		$this->assertSame('a', $match->rule->uuid);
		$this->assertSame(['a', 'b', 'c'], $match->matchedUuids());
	}

	public function testExpiredAndInactiveRulesAreSkipped(): void {
		$index = self::index([
			self::rule('expired', 'always', 'exact', 'X', ['validUntil' => '2026-11-12T12:00:00+00:00']),
			self::rule('future', 'always', 'exact', 'X', ['validFrom' => '2026-11-14T00:00:00+00:00']),
			self::rule('inactive', 'always', 'exact', 'X', ['active' => false]),
			self::rule('garbled', 'always', 'exact', 'X', ['validFrom' => 'not a date']),
			self::rule('regex', 'always', 'regex', '.*'),
		]);

		$this->assertNull($this->matcher->match($index, 'X', 'PERSON'));

		$open = self::index([self::rule('open', 'always', 'exact', 'X', ['validFrom' => '2026-11-01', 'validUntil' => '2026-12-01'])]);
		$this->assertSame('open', $this->matcher->match($open, 'X', 'PERSON')->rule->uuid);
	}

	public function testARequestScopedRuleAppliesToItsRequestOnly(): void {
		$index = self::index([
			self::rule('a', 'always', 'exact', 'P. de Boer', ['scope' => 'request', 'requestRef' => 'woo-2026-118']),
			self::rule('b', 'always', 'exact', 'P. de Boer', ['scope' => 'request']),
		]);

		$this->assertSame('a', $this->matcher->match($index, 'P. de Boer', 'PERSON', [], 'woo-2026-118')->rule->uuid);
		$this->assertNull($this->matcher->match($index, 'P. de Boer', 'PERSON', [], 'woo-2026-119'));
		$this->assertNull($this->matcher->match($index, 'P. de Boer', 'PERSON'));
	}

	public function testALocalOverrideSwitchesOffABaseRule(): void {
		$base     = ['@self' => ['uuid' => 'a', 'slug' => 'base-x'], 'layer' => 'base'] + self::rule('a', 'always', 'exact', 'X');
		$override = ['@self' => ['uuid' => 'b'], 'layer' => 'local', 'overrides' => 'base-x', 'active' => false];

		$this->assertNotNull($this->matcher->match(self::index([$base]), 'X', 'PERSON'));
		$this->assertNull($this->matcher->match(self::index([$base, $override]), 'X', 'PERSON'));

		// A base object cannot switch another base object off.
		$baseOverride = ['layer' => 'base'] + $override;
		$this->assertNotNull($this->matcher->match(self::index([$base, $baseOverride]), 'X', 'PERSON'));
	}

	public function testAMissedAlwaysTermIsFoundInTheText(): void {
		$index = self::index([
			self::rule('a', 'always', 'normalized', 'beschermde getuige a', ['entityType' => 'PERSON']),
			self::rule('b', 'always', 'exact', 'Zoë'),
			self::rule('c', 'never', 'exact', 'Gemeente'),
			self::rule('d', 'always', 'bsn', '*'),
		]);
		$text = 'De Gemeente sprak met Beschermde Getuige A en zoë; Zoë en Zoëlla.';

		$found = $this->matcher->findAlwaysTerms($index, $text);

		$this->assertSame(
			[
				['rule' => 'a', 'start' => 22, 'length' => 20, 'text' => 'Beschermde Getuige A', 'entityType' => 'PERSON'],
				['rule' => 'b', 'start' => 51, 'length' => 3, 'text' => 'Zoë', 'entityType' => null],
			],
			array_map(static fn (array $f): array => ['rule' => $f['rule']->uuid] + $f, $found)
		);
		$this->assertSame('Beschermde Getuige A', mb_substr($text, 22, 20));
	}

	public function testNoQueryPerEntity(): void {
		$source = $this->createMock(TermRuleSourceInterface::class);
		$source->expects($this->exactly(2))->method('termRuleObjects')->willReturn([self::rule('a', 'always', 'exact', 'X')]);
		$clock = $this->createMock(ClockInterface::class);
		$clock->method('now')->willReturn(new DateTimeImmutable(self::NOW));
		$cache = new PolicyIndexCache($source, $clock);

		for ($entity = 0; $entity < 50; $entity++) {
			$this->assertNotNull($this->matcher->match($cache->index(), 'X', 'PERSON'));
		}

		// A change to a termRule invalidates the index: the next run reads once more.
		$cache->invalidate();
		$this->assertNotNull($this->matcher->match($cache->index(), 'X', 'PERSON'));
		$this->assertSame($cache->index(), $cache->index());
	}
}
