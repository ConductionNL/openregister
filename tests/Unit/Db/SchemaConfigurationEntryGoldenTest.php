<?php

/**
 * Golden for the per-key validation of a schema's configuration.
 *
 * Records what Schema::setConfiguration() keeps, which keys it reports as
 * dropped, and what it throws, for valid and invalid values of every key it
 * knows, unknown keys and annotation keys. Re-record with UPDATE_GOLDEN=1.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit mock helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Db;

use OCA\OpenRegister\Db\Schema;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Configuration validation outcomes, recorded per input.
 *
 * @covers \OCA\OpenRegister\Db\Schema
 * @uses   \OCA\OpenRegister\Service\Calendar\ObjectDateDeclaration
 * @uses \OCA\OpenRegister\Db\Schema\AnnotationVocabulary
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationEntryRules
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValidator
 * @uses \OCA\OpenRegister\Db\Schema\ConfigurationValueRules
 * @uses \OCA\OpenRegister\Db\Schema\LinkedTypesRule
 * @uses \OCA\OpenRegister\Db\Schema\VocabularyEntries
 * @uses \OCA\OpenRegister\Db\Schema\WriteOnlyPathsDeclaration
 * @uses \OCA\OpenRegister\Service\Calendar\CalendarDateDeclarationCheck
 */
class SchemaConfigurationEntryGoldenTest extends TestCase {

	private const FIXTURE = __DIR__ . '/fixtures/schema-configuration-entry-golden.json';

	/**
	 * Every input saves, drops and throws exactly as recorded.
	 *
	 * @return void
	 */
	public function testConfigurationValidationMatchesGolden(): void {
		$values = [null, true, false, 0, 7, -1, 2.5, '', 'title', '30', [], ['a', 'b'], ['x' => 1], [['role' => 'owner']]];
		$keys = [
			'objectNameField', 'objectSlugField', 'allowFiles', 'autoShare', 'draftsListed', 'orphanCleanup',
			'allowedTags', 'linkedTypes', 'mailObjectTemplate', 'draftLifetimeDays', 'linkRoles', 'partyKinds',
			Schema::WRITEONLY_PATHS_ANNOTATION, 'calendarProvider', 'unique', 'autoPublish', 'importSource',
			'x-openregister-lifecycle', 'x-openregister-lifecycl', 'x-openregisterlifecycle', 'somethingElse', 12,
		];

		$actual = [];
		foreach ($keys as $key) {
			foreach ($values as $index => $value) {
				$actual["{$key} #{$index}"] = $this->outcome(configuration: [$key => $value]);
			}
		}

		$actual['calendarProvider valid'] = $this->outcome(
			configuration: ['calendarProvider' => ['enabled' => true, 'dtstart' => 'start', 'titleTemplate' => '{{ title }}']]
		);
		$actual['calendarProvider no dtstart'] = $this->outcome(configuration: ['calendarProvider' => ['enabled' => true]]);
		$actual['writeonly path'] = $this->outcome(configuration: [Schema::WRITEONLY_PATHS_ANNOTATION => ['title.secret']]);
		$actual['mixed'] = $this->outcome(
			configuration: [
				'objectNameField' => 'title',
				'draftLifetimeDays' => 'never',
				'allowFiles' => 'yes',
				'unique' => ['title'],
				'x-openregister-lifecycl' => [],
				'linkRoles' => 'bad',
				'exportable' => true,
				'x-openregister-calculations' => ['a' => 1],
			]
		);

		if (getenv('UPDATE_GOLDEN') === '1') {
			file_put_contents(self::FIXTURE, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$expected = json_decode((string) file_get_contents(self::FIXTURE), true);
		foreach ($expected as $name => $outcome) {
			$this->assertSame($outcome, $actual[$name] ?? null, $name);
		}

		$this->assertSame(array_keys($expected), array_keys($actual));
	}//end testConfigurationValidationMatchesGolden()

	/**
	 * What one configuration save keeps, drops and throws.
	 *
	 * @param array $configuration The configuration.
	 *
	 * @return array The outcome, JSON-round-tripped so it compares like the fixture.
	 */
	private function outcome(array $configuration): array {
		$schema = new Schema();
		$schema->setProperties(['title' => ['type' => 'string']]);
		$outcome = [];
		try {
			$schema->setConfiguration($configuration);
			$outcome['kept'] = $schema->getConfiguration();
		} catch (Throwable $e) {
			$outcome['threw'] = get_class($e) . ': ' . $e->getMessage();
		}

		$outcome['dropped'] = $schema->consumeDroppedAnnotationKeys();

		return json_decode((string) json_encode($outcome), true);
	}//end outcome()
}//end class
