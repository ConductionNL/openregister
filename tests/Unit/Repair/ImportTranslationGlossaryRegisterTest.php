<?php

/**
 * Unit tests for ImportTranslationGlossaryRegister.
 *
 * The step imports the shipped translation glossary descriptor under its own
 * configuration id, the same way every run, so the importer's version gate
 * keeps a second run from adding anything; and the shipped example entries
 * themselves hold no duplicate under the schemas' uniqueness constraints.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/ai-translation-with-a-glossary/specs
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Tests\Unit\Repair;

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit positional assertions.

use OCA\OpenRegister\Repair\ImportTranslationGlossaryRegister;
use OCA\OpenRegister\Repair\RegisterDescriptorImport;
use OCA\OpenRegister\Service\ConfigurationService;
use OCP\App\IAppManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\OpenRegister\Repair\ImportTranslationGlossaryRegister
 * @covers \OCA\OpenRegister\Repair\AbstractRegisterImportStep
 * @uses \OCA\OpenRegister\Repair\RegisterDescriptorImport
 */
class ImportTranslationGlossaryRegisterTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private static function appRoot(): string {
		return (string) realpath(__DIR__.'/../../..');
	}//end appRoot()

	/**
	 * The shipped descriptor, decoded.
	 *
	 * @return array<string, mixed>
	 */
	private static function descriptor(): array {
		return json_decode((string) file_get_contents(self::appRoot().'/lib/Settings/translation_glossary_register.json'), true, 512, JSON_THROW_ON_ERROR);
	}//end descriptor()

	/**
	 * The step over a configuration service and an output.
	 *
	 * @param ConfigurationService $config The configuration service.
	 *
	 * @return ImportTranslationGlossaryRegister
	 */
	private function step(ConfigurationService $config): ImportTranslationGlossaryRegister {
		$apps = $this->createMock(IAppManager::class);
		$apps->method('getAppPath')->with('openregister')->willReturn(self::appRoot());

		return new ImportTranslationGlossaryRegister(
			import: new RegisterDescriptorImport(
				configurationService: $config,
				appManager: $apps,
				logger: $this->createMock(LoggerInterface::class)
			)
		);
	}//end step()

	/**
	 * Two runs import the same descriptor under the same id and version, never forced.
	 *
	 * @return void
	 */
	public function testImportingTwiceSendsTheSameVersionGatedImport(): void {
		$calls  = [];
		$config = $this->createMock(ConfigurationService::class);
		$config->expects($this->exactly(2))->method('importFromApp')->willReturnCallback(
			static function (string $appId, array $data, string $version, bool $force) use (&$calls): array {
				$calls[] = [$appId, $data, $version, $force];
				return [];
			}
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->exactly(2))->method('info')
			->with('Translation-glossary register imported (translation-term + translation-style-guide)');
		$output->expects($this->never())->method('warning');

		$step = $this->step(config: $config);
		$step->run($output);
		$step->run($output);

		$this->assertSame($calls[0], $calls[1]);
		$this->assertSame(['openregister.translation-glossary', self::descriptor(), '1.0.0', false], $calls[0]);
		$this->assertStringContainsString('translation-glossary', $step->getName());
	}//end testImportingTwiceSendsTheSameVersionGatedImport()

	/**
	 * A failing import is reported as a warning, never thrown.
	 *
	 * @return void
	 */
	public function testAFailingImportIsReportedNotThrown(): void {
		$config = $this->createMock(ConfigurationService::class);
		$config->method('importFromApp')->willThrowException(new RuntimeException('database gone'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')
			->with('Translation-glossary register import skipped: database gone');

		$this->step(config: $config)->run($output);
	}//end testAFailingImportIsReportedNotThrown()

	/**
	 * The register holds the two schemas, each refusing a duplicate entry.
	 *
	 * @return void
	 */
	public function testTheRegisterDeclaresBothSchemasWithARefusingUniqueness(): void {
		$components = self::descriptor()['components'];

		$this->assertSame(['translation-term', 'translation-style-guide'], $components['registers']['translation-glossary']['schemas']);
		$this->assertSame(
			[['name' => 'one-entry-per-term', 'properties' => ['term', 'sourceLanguage', 'targetLanguage', 'register'], 'action' => 'refuse']],
			$components['schemas']['translation-term']['configuration']['uniqueConstraints']
		);
		$this->assertSame(
			[['name' => 'one-guide-per-language', 'properties' => ['language'], 'action' => 'refuse']],
			$components['schemas']['translation-style-guide']['configuration']['uniqueConstraints']
		);
		$this->assertSame(2000, $components['schemas']['translation-style-guide']['properties']['instructions']['maxLength']);
		$this->assertSame(['formal', 'informal'], $components['schemas']['translation-style-guide']['properties']['formality']['enum']);
	}//end testTheRegisterDeclaresBothSchemasWithARefusingUniqueness()

	/**
	 * The example entries are marked as examples and hold no duplicate under those constraints.
	 *
	 * @return void
	 */
	public function testTheExampleEntriesAreMarkedAndHoldNoDuplicates(): void {
		$objects = self::descriptor()['components']['objects'];
		$terms   = [];
		$guides  = [];
		foreach ($objects as $object) {
			$self = $object['@self'];
			$this->assertSame('translation-glossary', $self['register']);
			$this->assertStringStartsWith('example-', $self['slug']);

			if ($self['schema'] === 'translation-term') {
				$terms[] = implode('|', [$object['term'], $object['sourceLanguage'], $object['targetLanguage'], $object['register']]);
				$this->assertStringStartsWith('Example seed data', $object['note']);
				$this->assertTrue($object['doNotTranslate'] === true || ($object['translation'] ?? '') !== '', $self['slug'].' needs a translation');
				continue;
			}

			$this->assertSame('translation-style-guide', $self['schema']);
			$guides[] = $object['language'];
			$this->assertStringStartsWith('Example seed data', $object['instructions']);
			$this->assertLessThanOrEqual(2000, mb_strlen($object['instructions']));
		}

		$this->assertGreaterThanOrEqual(3, count($terms));
		$this->assertGreaterThanOrEqual(3, count($guides));
		$this->assertSame($terms, array_values(array_unique($terms)));
		$this->assertSame($guides, array_values(array_unique($guides)));
		$this->assertSame(count($objects), count(array_unique(array_column(array_column($objects, '@self'), 'slug'))));
	}//end testTheExampleEntriesAreMarkedAndHoldNoDuplicates()
}//end class
