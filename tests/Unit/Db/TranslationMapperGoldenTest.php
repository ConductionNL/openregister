<?php

/**
 * Golden record of TranslationMapper: the SQL each method builds, the parameters it binds, and what it returns.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCP\AppFramework\Db\Entity;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\OpenRegister\Db\TranslationMapper
 * @uses \OCA\OpenRegister\Db\Translation
 *
 * @spec openspec/changes/i18n-source-of-truth/tasks.md#phase-3
 */
class TranslationMapperGoldenTest extends TestCase {
	use SqlLogQueryBuilderTrait;

	private const FIXTURE = __DIR__ . '/fixtures/translation-mapper-golden.json';

	/**
	 * A stored translation row as the database returns it.
	 *
	 * @param string|null $status Its status.
	 *
	 * @return array<string, mixed> The row.
	 */
	private static function row(?string $status): array {
		return [
			'id' => 9,
			'object_uuid' => 'obj-1',
			'property' => 'title',
			'language' => 'en',
			'value' => 'Old',
			'status' => $status,
			'translator' => 'bob',
			'updated' => null,
			'source_language' => 'nl',
		];
	}//end row()

	/**
	 * The cases: which method, its arguments, the statement results and the query rows.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$cases = [
			'upsert new slot, defaults' => ['upsert', ['obj-1', 'title', 'en', 'Hello'], [], [[]]],
			'upsert new slot, every option' => ['upsert', ['obj-1', 'title', 'de', 'Hallo', 'approved', 'alice', 'en'], [], [[]]],
			'upsert new slot, empty source language and null value' => ['upsert', ['obj-1', 'title', 'fr', null, null, null, ''], [], [[]]],
			'upsert existing slot keeps status' => ['upsert', ['obj-1', 'title', 'en', 'New'], [], [[self::row('approved')]]],
			'upsert existing slot without status gets draft' => ['upsert', ['obj-1', 'title', 'en', 'New'], [], [[self::row(null)]]],
			'upsert existing slot overrides status' => ['upsert', ['obj-1', 'title', 'en', 'New', 'outdated', 'carol', 'de'], [], [[self::row('approved')]]],
			'findOne found' => ['findOne', ['obj-1', 'title', 'en'], [], [[self::row('draft')]]],
			'findOne missing' => ['findOne', ['obj-1', 'title', 'en'], [], [[]]],
			'findByObject' => ['findByObject', ['obj-1'], [], [[self::row('draft'), self::row('approved')]]],
			'deleteByObject' => ['deleteByObject', ['obj-1'], [4], []],
			'completeness' => ['getCompletenessByObject', ['obj-1'], [], [[['language' => 'en', 'count' => '3'], ['language' => 'de', 'count' => 1]]]],
			'completeness empty' => ['getCompletenessByObject', ['obj-1'], [], [[]]],
			'search defaults' => ['search', [], [], [[self::row('draft')]]],
			'search every filter' => ['search', ['50%_off\\x', 'de', 'approved', 'obj-1', 20, 'nl', true], [], [[]]],
			'search empty strings are ignored' => ['search', ['', '', '', '', 0, '', false], [], [[]]],
			'search limit is capped' => ['search', [null, 'en', null, null, 5000], [], [[]]],
			'search negative limit and outdated only' => ['search', [null, null, null, null, -3, null, true], [], [[]]],
			'markDerivedOutdated' => ['markDerivedOutdated', ['obj-1', 'title', 'nl'], [2], []],
			'backfill without registers' => ['backfillSourceLanguage', [[]], [7], []],
			'backfill per register' => ['backfillSourceLanguage', [[5 => 'de', '12' => '', 'r-x' => null], 25], [3, 4, 5, 6], []],
			'backfill falls back when bounded update fails' => ['backfillSourceLanguage', [[5 => 'en'], 0], [new \RuntimeException('no limit on update'), 8, 1], []],
			'count missing source language' => ['countMissingSourceLanguage', [], [], [[['pending' => '11']]]],
			'count missing source language without row' => ['countMissingSourceLanguage', [], [], [[]]],
			'dominant source language' => ['getDominantSourceLanguage', ['obj-1'], [], [[['source_language' => 'de', 'cnt' => 4]]]],
			'dominant source language without rows' => ['getDominantSourceLanguage', ['obj-1'], [], [[]]],
			'dominant source language null column' => ['getDominantSourceLanguage', ['obj-1'], [], [[['source_language' => null, 'cnt' => 4]]]],
			'dominant source language empty column' => ['getDominantSourceLanguage', ['obj-1'], [], [[['source_language' => '', 'cnt' => 4]]]],
			'missing language without properties' => ['findObjectsMissingLanguage', ['en', [], ['a']], [], []],
			'missing language without candidates' => ['findObjectsMissingLanguage', ['en', ['title'], []], [], []],
			'missing language' => [
				'findObjectsMissingLanguage',
				['en', ['title', 'body'], ['a', 'b', 'c', 'a']],
				[],
				[[['object_uuid' => 'a', 'property' => 'title'], ['object_uuid' => 'a', 'property' => 'body'], ['object_uuid' => 'b', 'property' => 'title'], ['object_uuid' => 'x', 'property' => 'body']]],
			],
		];

		$out = [];
		foreach ($cases as $name => [$method, $args, $statements, $queries]) {
			$out[$name] = [['method' => $method, 'args' => $args, 'statements' => $statements, 'queries' => $queries]];
		}

		return $out;
	}//end cases()

	/**
	 * A mapper whose insert/update record the entity instead of writing it.
	 *
	 * @param IDBConnection $db The connection.
	 *
	 * @return TranslationMapper The mapper.
	 */
	private function mapper(IDBConnection $db): TranslationMapper {
		$log = function (string $line): void {
			$this->sqlLog[] = $line;
		};

		return new class ($db, $log) extends TranslationMapper {
			/**
			 * @param IDBConnection $db The connection.
			 * @param \Closure $log Receives a log line.
			 */
			public function __construct(IDBConnection $db, private readonly \Closure $log) {
				parent::__construct($db);
			}

			public function insert(Entity $entity): Entity {
				($this->log)('insert(entity)');
				return $entity;
			}

			public function update(Entity $entity): Entity {
				($this->log)('update(entity)');
				return $entity;
			}
		};
	}//end mapper()

	/**
	 * Normalise a returned value for comparison.
	 *
	 * @param mixed $value The returned value.
	 *
	 * @return mixed The normalised value.
	 */
	private static function normalise(mixed $value): mixed {
		if ($value instanceof Translation) {
			return [
				'id' => $value->getId(),
				'objectUuid' => $value->getObjectUuid(),
				'property' => $value->getProperty(),
				'language' => $value->getLanguage(),
				'value' => $value->getValue(),
				'status' => $value->getStatus(),
				'translator' => $value->getTranslator(),
				'sourceLanguage' => $value->getSourceLanguage(),
				'updatedIsSet' => ($value->getUpdated() !== null),
			];
		}

		if (is_array($value) === true) {
			return array_map(self::normalise(...), $value);
		}

		return $value;
	}//end normalise()

	/**
	 * Run one case and return what it produced.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The log and the result.
	 */
	private function runCase(array $case): array {
		$this->sqlLog = [];
		$this->parameterCount = 0;
		$this->statementResults = $case['statements'];
		$this->queryResults = $case['queries'];
		$mapper = $this->mapper($this->recordingConnection());
		$result = $mapper->{$case['method']}(...$case['args']);

		return ['sql' => $this->sqlLog, 'result' => self::normalise($result)];
	}//end run()

	#[DataProvider('cases')]
	public function testMatchesGolden(array $case): void {
		$actual = $this->runCase($case);
		$golden = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame($golden[$this->dataName()], json_decode(json_encode($actual, JSON_THROW_ON_ERROR), true));
	}//end testMatchesGolden()

	public function testFixtureCoversEveryCase(): void {
		$golden = json_decode((string)file_get_contents(self::FIXTURE), true, 512, JSON_THROW_ON_ERROR);
		$this->assertSame(array_keys(self::cases()), array_keys($golden));
	}//end testFixtureCoversEveryCase()
}//end class
