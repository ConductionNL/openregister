<?php

/**
 * Golden record of the sidecar writes TranslationProjectionService::project() makes.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\RegisterSchemaMappers;
use OCA\OpenRegister\Service\TranslationProjectionService;
use OCA\OpenRegister\Service\TranslationStores;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\OpenRegister\Service\TranslationProjectionService
 * @uses \OCA\OpenRegister\Service\Object\PropertySourceLanguage
 * @uses \OCA\OpenRegister\Db\ObjectEntity
 * @uses \OCA\OpenRegister\Db\Register
 * @uses \OCA\OpenRegister\Db\Schema
 * @uses \OCA\OpenRegister\Db\Translation
 *
 * @spec openspec/specs/register-i18n/spec.md
 */
class TranslationProjectionGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/translation-projection-golden.json';

	public static function cases(): array {
		$cases = [
			'no uuid' => ['uuid' => '', 'data' => ['title' => 'x']],
			'no schema ref' => ['schema' => '', 'data' => ['title' => 'x']],
			'schema not found' => ['schemaMissing' => true, 'data' => ['title' => 'x']],
			'no translatable properties purges stale rows' => ['translatable' => [], 'existing' => [['title', 'nl'], ['body', 'en']]],
			'language keyed' => ['data' => ['title' => ['nl' => 'Hallo', 'en' => 'Hello', '' => 'skip', 'de' => '', 'fr' => null, 'es' => 5, 'it' => ['a' => 1]]]],
			'legacy string uses schema source language' => ['data' => ['title' => 'Bonjour']],
			'legacy string uses register default' => ['data' => ['body' => 'Tekst']],
			'legacy string without register' => ['register' => '', 'data' => ['body' => 'Tekst']],
			'object override of source language' => ['data' => ['body' => 'Text', '_translationMeta' => ['body' => ['sourceLanguage' => 'en']]]],
			'empty string skipped' => ['data' => ['title' => '']],
			'list value has no languages' => ['data' => ['title' => ['Hallo', 'Hello'], 'body' => ['nl' => 'B']]],
			'non-translatable property ignored' => ['data' => ['other' => 'x', 'title' => ['nl' => 'T']]],
			'stale rows deleted, kept rows kept' => ['data' => ['title' => ['nl' => 'T']], 'existing' => [['title', 'nl'], ['title', 'en'], ['body', 'nl']]],
			'delete failure is swallowed' => ['data' => [], 'existing' => [['title', 'nl'], ['body', 'en']], 'deleteThrows' => true],
			'no translatable properties, delete failure swallowed' => ['translatable' => [], 'existing' => [['title', 'nl'], ['body', 'en']], 'deleteThrows' => true],
			'signed-in translator' => ['user' => 'alice', 'data' => ['title' => ['en' => 'Hi']]],
			'upsert failure is logged' => ['data' => ['title' => ['en' => 'Hi']], 'upsertThrows' => true],
			'register lookup fails falls back to nl' => ['registerThrows' => true, 'data' => ['body' => 'Tekst', 'title' => 'Titre']],
			'register without languages' => ['registerLanguages' => [], 'data' => ['body' => 'Tekst']],
			'scalar language values' => ['data' => ['title' => ['nl' => true, 'en' => false, 'de' => 1.5, '7' => 'numeric key', 'fr' => 0], 'body' => 42]],
			'both properties legacy and keyed' => ['user' => 'bob', 'data' => ['title' => 'Titre', 'body' => ['en' => 'B', 'nl' => 'C']], 'existing' => [['body', 'de']]],
		];

		$out = [];
		foreach ($cases as $name => $case) {
			$out[$name] = [$case];
		}

		return $out;
	}//end cases()

	#[DataProvider('cases')]
	public function testProjectionMatchesGolden(array $case): void {
		$log = [];
		$schema = new Schema();
		$schema->setProperties([
			'title' => ['type' => 'string', 'translatable' => true, 'sourceLanguage' => 'fr'],
			'body' => ['type' => 'string', 'translatable' => true],
			'other' => ['type' => 'string'],
		]);
		$register = new Register();
		$register->setLanguages($case['registerLanguages'] ?? ['de', 'en']);

		$translationMapper = $this->createMock(TranslationMapper::class);
		$translationMapper->method('upsert')->willReturnCallback(
			function (...$args) use (&$log, $case) {
				if (($case['upsertThrows'] ?? false) === true) {
					throw new \RuntimeException('db down');
				}

				$log[] = ['upsert' => $args];
				return new Translation();
			}
		);
		$translationMapper->method('findByObject')->willReturnCallback(
			function () use ($case) {
				return array_map(
					function (array $pair): Translation {
						$row = new Translation();
						$row->setProperty($pair[0]);
						$row->setLanguage($pair[1]);
						return $row;
					},
					($case['existing'] ?? [])
				);
			}
		);
		$translationMapper->method('delete')->willReturnCallback(
			function (Translation $row) use (&$log, $case) {
				$log[] = ['delete' => $row->getProperty() . '|' . $row->getLanguage()];
				if (($case['deleteThrows'] ?? false) === true) {
					throw new \RuntimeException('gone');
				}

				return $row;
			}
		);

		$handler = $this->createMock(TranslationHandler::class);
		$handler->method('getTranslatableProperties')->willReturn($case['translatable'] ?? ['title', 'body']);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		if (($case['schemaMissing'] ?? false) === true) {
			$schemaMapper->method('find')->willThrowException(new \RuntimeException('no schema'));
		} else {
			$schemaMapper->method('find')->willReturn($schema);
		}

		$registerMapper = $this->createMock(RegisterMapper::class);
		if (($case['registerThrows'] ?? false) === true) {
			$registerMapper->method('find')->willThrowException(new \RuntimeException('no register'));
		} else {
			$registerMapper->method('find')->willReturn($register);
		}
		$session = $this->createMock(IUserSession::class);
		if (isset($case['user']) === true) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($case['user']);
			$session->method('getUser')->willReturn($user);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message) use (&$log) {
			$log[] = ['warning' => $message];
		});

		$object = new ObjectEntity();
		$object->setUuid($case['uuid'] ?? 'u-1');
		$object->setSchema($case['schema'] ?? '2');
		$object->setRegister($case['register'] ?? '1');
		$object->setObject($case['data'] ?? []);

		$service = new TranslationProjectionService(
			new TranslationStores($translationMapper, $handler),
			new RegisterSchemaMappers($registerMapper, $schemaMapper),
			$session,
			$logger
		);
		$service->project($object);

		$name = $this->dataName();
		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		$log = json_decode((string)json_encode($log), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $log;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $log);
	}//end testProjectionMatchesGolden()
}//end class
