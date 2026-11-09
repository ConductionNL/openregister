<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

namespace OCA\OpenRegister\Tests\Unit\Service;

use Exception;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Db\TranslationEdit;
use OCA\OpenRegister\Db\TranslationSlot;
use OCA\OpenRegister\Db\Translation;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\TranslationMapper;
use OCA\OpenRegister\Service\BulkTranslationService;
use OCA\OpenRegister\Service\Object\TranslationHandler;
use OCA\OpenRegister\Service\Translation\TranslationProviderInterface;
use OCA\OpenRegister\Service\TranslationStores;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Records the provider, sidecar and log calls and the result of translateObject(), per scenario,
 * against fixtures/bulk-translation-object-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 *
 * @covers \OCA\OpenRegister\Service\BulkTranslationService
 * @uses   \OCA\OpenRegister\Service\ObjectTranslationRequest
 * @uses   \OCA\OpenRegister\Db\ObjectEntity
 * @uses   \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Db\Translation
 * @uses   \OCA\OpenRegister\Service\ObjectTranslationRequest
 */
class BulkTranslationServiceTranslateObjectGoldenTest extends TestCase {

	/**
	 * @var list<array>
	 */
	private array $calls = [];

	/**
	 * Builds the service for one scenario.
	 *
	 * @param array $s Scenario settings.
	 *
	 * @return BulkTranslationService
	 */
	private function service(array $s): BulkTranslationService {
		$provider = $this->createMock(TranslationProviderInterface::class);
		$provider->method('getIdentifier')->willReturn('fake');
		$provider->method('translate')->willReturnCallback(function (string $text, string $from, string $to) {
			$this->calls[] = ['translate', $text, $from, $to];
			if ($text === 'boom') {
				throw new Exception('provider down');
			}
			if ($text === 'blank') {
				return '';
			}
			return strtoupper($text) . '@' . $to;
		});

		$mapper = $this->createMock(TranslationMapper::class);
		$mapper->method('upsert')->willReturnCallback(function (TranslationSlot $slot, TranslationEdit $edit) {
			$args = [$slot->objectUuid, $slot->property, $slot->language, $edit->value, $edit->status, $edit->translator, $edit->sourceLanguage];
			$this->calls[] = ['upsert', $args];
			if (($args['property'] ?? '') === 'sidecarFails') {
				throw new Exception('sidecar down');
			}
			return new Translation();
		});

		$handler = $this->createMock(TranslationHandler::class);
		$handler->method('getTranslatableProperties')->willReturn($s['props']);

		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('find')->willReturnCallback(function () use ($s) {
			if ($s['schema'] === false) {
				throw new Exception('no schema');
			}
			return new Schema();
		});

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function ($message) {
			$this->calls[] = ['warning', $message];
		});

		return new BulkTranslationService($provider, new TranslationStores($mapper, $handler), $schemaMapper, $logger);
	}

	/**
	 * Scenario table.
	 *
	 * @return array<string, array>
	 */
	public static function scenarios(): array {
		$data = [
			'title' => ['nl' => 'hallo', 'en' => ''],
			'body' => 'plain dutch',
			'filled' => ['nl' => 'x', 'en' => 'already'],
			'nonStringTarget' => ['nl' => 'y', 'en' => 5],
			'noSource' => ['de' => 'hallo'],
			'emptySource' => ['nl' => ''],
			'failing' => ['nl' => 'boom'],
			'blank' => ['nl' => 'blank'],
			'sidecarFails' => ['nl' => 'side'],
			'number' => 7,
		];
		$props = array_merge(array_keys($data), ['missing']);
		$base = ['props' => $props, 'schema' => true, 'data' => $data, 'from' => 'nl', 'to' => 'en', 'only' => null];
		return [
			'all properties nl to en' => [$base],
			'whitelist' => [['only' => ['title', 'number', 'unknown']] + $base],
			'from en' => [['from' => 'en', 'to' => 'nl'] + $base],
			'same language' => [['to' => 'nl'] + $base],
			'no translatable properties' => [['props' => []] + $base],
			'schema not resolvable' => [['schema' => false] + $base],
		];
	}

	/**
	 * @dataProvider scenarios
	 */
	public function testTranslateObjectGolden(array $s): void {
		$object = new ObjectEntity();
		$object->setUuid('obj-1');
		$object->setSchema('7');
		$object->setObject($s['data']);

		$result = $this->service($s)->translateObject($object, $s['from'], $s['to'], $s['only']);
		$this->calls[] = ['result', $result];

		$file = __DIR__ . '/fixtures/bulk-translation-object-golden.json';
		$name = $this->dataName();
		$all = is_file($file) === true ? json_decode(file_get_contents($file), true) : [];
		$actual = json_decode(json_encode($this->calls), true);
		if (getenv('UPDATE_GOLDEN') === '1') {
			$all[$name] = $actual;
			file_put_contents($file, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}
		$this->assertSame($all[$name], $actual);
	}
}
