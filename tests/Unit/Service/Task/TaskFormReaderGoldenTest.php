<?php

/**
 * Golden test for TaskFormReader.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Task
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/specs/flow-task-forms/spec.md#requirement-a-task-form-is-a-declaration-of-existing-fields-not-a-new-form-definition
 */

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service\Task;

use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Lifecycle\TransitionEngine;
use OCA\OpenRegister\Service\Task\TaskFormReader;
use OCA\OpenRegister\Service\Task\TaskFormSources;
use OCP\App\IAppManager;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * Golden test for TaskFormReader::fromRecord(), fromConfig() and validate().
 *
 * Each record is read, and when it reads, validated with the Forms app
 * installed and absent. The declaration (or the refusal message) is compared
 * with fixtures/task-form-reader-golden.json. Set UPDATE_GOLDEN=1 to rewrite
 * the fixture.
 *
 * @covers \OCA\OpenRegister\Service\Task\TaskFormReader
 * @uses   \OCA\OpenRegister\Service\Task\TaskForm
 * @uses   \OCA\OpenRegister\Service\Form\FormDestinationValidator
 * @uses   \OCA\OpenRegister\Service\Form\FormFieldRules
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class TaskFormReaderGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/task-form-reader-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function cases(): array {
		$native = static fn (array $extra): array => [['kind' => 'fields', 'schema' => 'case'] + $extra];
		return [
			'empty' => [[]],
			'checklist only' => [['requireChecklist' => 'yes']],
			'checklist off string' => [['requireChecklist' => 'off', 'kind' => '  ']],
			'orphaned schema' => [['schema' => 'case']],
			'orphaned empty values' => [['schema' => '', 'action' => null, 'fields' => [], 'formId' => '']],
			'orphaned fields' => [['fields' => 'a']],
			'orphaned zero form id' => [['formId' => 0]],
			'unknown kind' => [['kind' => 'wizard']],
			'kind padded' => [['kind' => ' external ', 'formId' => '7']],
			'external' => [['kind' => 'external', 'formId' => 12, 'requireChecklist' => true, 'fields' => 'ignored']],
			'external without id' => [['kind' => 'external']],
			'external bad id' => [['kind' => 'external', 'formId' => 'abc']],
			'external zero id' => [['kind' => 'external', 'formId' => '0']],
			'external float id' => [['kind' => 'external', 'formId' => '3.9']],
			'native action' => $native(['action' => ' approve ']),
			'native unknown action' => $native(['action' => 'nope']),
			'native action and fields' => $native(['action' => 'approve', 'fields' => ['reason']]),
			'native nothing' => $native(['action' => ' ', 'fields' => []]),
			'native fields objects' => $native(['fields' => [['field' => ' reason ', 'required' => 'true'], ['field' => 'note']]]),
			'native fields names' => $native(['fields' => ['reason*', ' note * ', '', 'other']]),
			'native fields string' => $native(['fields' => 'reason*, note,,locked']),
			'native star only' => $native(['fields' => ['*']]),
			'native fields mixed' => $native(['fields' => [['field' => 'reason'], 5, true, 1.5]]),
			'native field unnamed' => $native(['fields' => [['required' => true]]]),
			'native field nested list' => $native(['fields' => [['reason']]]),
			'native field object entry' => $native(['fields' => [new \stdClass()]]),
			'native field null entry' => $native(['fields' => [null]]),
			'native fields scalar' => $native(['fields' => 7]),
			'native duplicate' => $native(['fields' => ['reason', ['field' => 'reason', 'required' => true]]]),
			'native duplicate after star' => $native(['fields' => 'reason, reason*']),
			'native hidden field' => $native(['fields' => ['hidden']]),
			'native read-only field' => $native(['fields' => ['reason', 'locked']]),
			'native undeclared field' => $native(['fields' => ['ghost']]),
			'native no schema' => [['kind' => 'fields', 'fields' => ['reason']]],
			'native missing schema' => [['kind' => 'fields', 'schema' => 'missing', 'fields' => ['reason']]],
		];
	}//end cases()

	/**
	 * Compare the reading and validation of one record with the recording.
	 *
	 * @param array<string, mixed> $record The stored declaration.
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testMatchesGolden(array $record): void {
		$name   = $this->dataName();
		$actual = [];
		foreach (['forms installed' => true, 'forms absent' => false] as $label => $formsInstalled) {
			$reader = $this->reader(formsInstalled: $formsInstalled);
			try {
				$form = $reader->fromRecord($record);
				$actual[$label]['form'] = $form->toArray();
				$actual[$label]['validate'] = $this->validation(reader: $reader, form: $form);
			} catch (UnexpectedValueException $e) {
				$actual[$label]['refused'] = $e->getMessage();
			}
		}

		$config = [
			'formKind' => ($record['kind'] ?? null),
			'formSchema' => ($record['schema'] ?? null),
			'formAction' => ($record['action'] ?? null),
			'formFields' => ($record['fields'] ?? null),
			'formId' => ($record['formId'] ?? null),
			'formRequireChecklist' => ($record['requireChecklist'] ?? null),
		];
		try {
			$actual['fromConfig'] = $this->reader(formsInstalled: true)->fromConfig($config)->toArray();
		} catch (UnexpectedValueException $e) {
			$actual['fromConfig'] = $e->getMessage();
		}

		$golden = [];
		if (is_file(self::FIXTURE) === true) {
			$golden = json_decode((string)file_get_contents(self::FIXTURE), true);
		}

		if (getenv('UPDATE_GOLDEN') === '1') {
			$golden[$name] = $actual;
			ksort($golden);
			file_put_contents(self::FIXTURE, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
			$this->addToAssertionCount(1);
			return;
		}

		$this->assertArrayHasKey($name, $golden, 'No golden recording for this case.');
		$this->assertSame($golden[$name], $actual);
	}//end testMatchesGolden()

	/**
	 * The refusal validate() throws for the form, or "ok".
	 *
	 * @param TaskFormReader                         $reader The reader.
	 * @param \OCA\OpenRegister\Service\Task\TaskForm $form   The declaration.
	 *
	 * @return string The message.
	 */
	private function validation(TaskFormReader $reader, \OCA\OpenRegister\Service\Task\TaskForm $form): string {
		try {
			$reader->validate($form);
		} catch (UnexpectedValueException $e) {
			return $e->getMessage();
		}

		return 'ok';
	}//end validation()

	/**
	 * A reader over a `case` schema whose `approve` action asks for reason and note.
	 *
	 * @param bool $formsInstalled Whether the Forms app is installed.
	 *
	 * @return TaskFormReader The reader.
	 */
	private function reader(bool $formsInstalled): TaskFormReader {
		$schema = new Schema();
		$schema->setSlug('case');
		$schema->setProperties(
			[
				'reason' => ['type' => 'string'],
				'note' => ['type' => 'string'],
				'locked' => ['type' => 'string', 'readOnly' => true],
				'hidden' => ['type' => 'string', 'visible' => false],
			]
		);
		$schemas = $this->createMock(SchemaMapper::class);
		$schemas->method('find')->willReturnCallback(
			static function (string $reference) use ($schema): Schema {
				if ($reference !== 'case') {
					throw new DoesNotExistException('none');
				}

				return $schema;
			}
		);
		$engine = $this->createMock(TransitionEngine::class);
		$engine->method('declaredInputs')->willReturnCallback(
			static function (Schema $schema, string $action): ?array {
				if ($action !== 'approve') {
					return null;
				}

				return [['field' => 'reason', 'required' => true], ['field' => 'hidden', 'required' => false]];
			}
		);
		$apps = $this->createMock(IAppManager::class);
		$apps->method('isInstalled')->willReturn($formsInstalled);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(
			static function (string $text, array $parameters = []): string {
				if ($parameters === []) {
					return $text;
				}

				return vsprintf($text, $parameters);
			}
		);

		return new TaskFormReader(sources: new TaskFormSources(schemas: $schemas, engine: $engine), apps: $apps, l10n: $l10n);
	}//end reader()
}//end class
