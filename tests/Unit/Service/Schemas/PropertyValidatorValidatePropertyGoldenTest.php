<?php

declare(strict_types=1);

// phpcs:disable PEAR.Commenting.FunctionComment.Missing -- arrange/act/assert PHPUnit conventions.
// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace Unit\Service\Schemas;

use OCA\OpenRegister\Service\Schemas\PropertyValidatorHandler;
use OCA\OpenRegister\Service\Schemas\PropertyVocabularyException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Golden test for PropertyValidatorHandler::validateProperty().
 *
 * Each case is a property definition and a path. The result, or the thrown
 * exception class, message and vocabulary errors, is compared with
 * fixtures/property-validate-golden.json. Set UPDATE_GOLDEN=1 to rewrite the fixture.
 */
class PropertyValidatorValidatePropertyGoldenTest extends TestCase {
	private const FIXTURE = __DIR__ . '/fixtures/property-validate-golden.json';

	/**
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function cases(): array {
		$top = '/field';
		$deep = '/field/properties/inner';
		$rel = ['type' => 'string', '$ref' => 'person'];
		return [
			'plain string' => [['type' => 'string'], $top],
			'unknown key' => [['type' => 'string', 'colour' => 'red', 'shade' => 1], $top],
			'x key and numeric key' => [['type' => 'string', 'x-extra' => 1, 0 => 'a'], $top],
			'localised key' => [['type' => 'string', 'title_nl' => 'Naam'], $top],
			'oneOf valid' => [['oneOf' => ['a' => ['type' => 'string'], 'b' => ['type' => 'integer']]], $top],
			'oneOf invalid member' => [['oneOf' => ['a' => ['type' => 'nope']]], $top],
			'oneOf scalar member' => [['oneOf' => ['a' => 'string']], $top],
			'no type top level' => [['description' => 'x'], $top],
			'no type empty path' => [['description' => 'x'], ''],
			'no type deep' => [['description' => 'x'], $deep],
			'no type with ref' => [['$ref' => 'person'], $top],
			'unknown type' => [['type' => 'text'], $top],
			'union type' => [['type' => ['string', 'null']], $top],
			'numeric string type' => [['type' => 'string ', 'format' => 'x'], $top],
			'known format' => [['type' => 'string', 'format' => 'email'], $top],
			'empty format' => [['type' => 'string', 'format' => ''], $top],
			'unknown format' => [['type' => 'string', 'format' => 'colour'], $top],
			'array format' => [['type' => 'string', 'format' => ['email']], $top],
			'format on integer' => [['type' => 'integer', 'format' => 'colour'], $top],
			'array items valid' => [['type' => 'array', 'items' => ['type' => 'string']], $top],
			'array items invalid' => [['type' => 'array', 'items' => ['type' => 'bogus']], $top],
			'array items ref skipped' => [['type' => 'array', 'items' => ['$ref' => 'x', 'type' => 'bogus']], $top],
			'array items without type' => [['type' => 'array', 'items' => ['description' => 'x']], $top],
			'array no items' => [['type' => 'array'], $top],
			'object nested valid' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string']]], $top],
			'object nested no type' => [['type' => 'object', 'properties' => ['a' => ['description' => 'd']]], $top],
			'object nested invalid' => [['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'format' => 'bad']]], $top],
			'object nested scalar' => [['type' => 'object', 'properties' => ['a' => 5]], $top],
			'properties on string ignored' => [['type' => 'string', 'properties' => ['a' => 5]], $top],
			'minimum not numeric' => [['type' => 'integer', 'minimum' => 'a'], $top],
			'maximum not numeric' => [['type' => 'number', 'maximum' => 'b'], $top],
			'minimum above maximum' => [['type' => 'number', 'minimum' => 5, 'maximum' => 2], $top],
			'minimum equals maximum' => [['type' => 'integer', 'minimum' => 2, 'maximum' => 2], $top],
			'numeric strings bounds' => [['type' => 'integer', 'minimum' => '3', 'maximum' => '10'], $top],
			'bounds on string ignored' => [['type' => 'string', 'minimum' => 'a'], $top],
			'file valid' => [['type' => 'file', 'allowedTypes' => ['image/png'], 'maxSize' => 1000], $top],
			'file bad mime' => [['type' => 'file', 'allowedTypes' => ['png']], $top],
			'file too big' => [['type' => 'file', 'maxSize' => 999999999], $top],
			'enum valid' => [['type' => 'string', 'enum' => ['a']], $top],
			'enum empty' => [['type' => 'string', 'enum' => []], $top],
			'enum scalar' => [['type' => 'string', 'enum' => 'a'], $top],
			'visible bool' => [['type' => 'string', 'visible' => false], $top],
			'visible string' => [['type' => 'string', 'visible' => 'no'], $top],
			'hideOnCollection string' => [['type' => 'string', 'hideOnCollection' => 1], $top],
			'hideOnForm string' => [['type' => 'string', 'hideOnForm' => 'yes'], $top],
			'all three flags valid' => [['type' => 'string', 'visible' => true, 'hideOnCollection' => true, 'hideOnForm' => false], $top],
			'sourceLanguage valid' => [['type' => 'string', 'translatable' => true, 'sourceLanguage' => 'nl-NL'], $top],
			'sourceLanguage without translatable' => [['type' => 'string', 'sourceLanguage' => 'nl'], $top],
			'sourceLanguage null without translatable' => [['type' => 'string', 'sourceLanguage' => null], $top],
			'sourceLanguage empty' => [['type' => 'string', 'translatable' => true, 'sourceLanguage' => ''], $top],
			'sourceLanguage number' => [['type' => 'string', 'translatable' => true, 'sourceLanguage' => 5], $top],
			'sourceLanguage bad tag' => [['type' => 'string', 'translatable' => true, 'sourceLanguage' => 'Dutch'], $top],
			'onDelete without relation' => [['type' => 'string', 'onDelete' => 'CASCADE'], $top],
			'onDelete cascade' => [$rel + ['onDelete' => 'cascade'], $top],
			'onDelete on items ref' => [['type' => 'array', 'items' => ['$ref' => 'p'], 'onDelete' => 'SET_NULL'], $top],
			'onDelete on referenceSchema' => [['type' => 'string', 'referenceSchema' => 'p', 'onDelete' => 'NO_ACTION'], $top],
			'onDelete on items referenceSchema' => [['type' => 'array', 'items' => ['type' => 'string', 'referenceSchema' => 'p'], 'onDelete' => 'RESTRICT'], $top],
			'onDelete unknown' => [$rel + ['onDelete' => 'EXPLODE'], $top],
			'onDelete referenceKey restrict' => [$rel + ['onDelete' => 'RESTRICT', 'referenceKey' => 'code'], $top],
			'onDelete referenceKey cascade' => [$rel + ['onDelete' => 'CASCADE', 'referenceKey' => 'code'], $top],
			'onDelete referenceKey uuid' => [$rel + ['onDelete' => 'CASCADE', 'referenceKey' => 'uuid'], $top],
			'matchType valid' => [['type' => 'string', 'matchType' => 'exact'], $top],
			'matchType unknown' => [['type' => 'string', 'matchType' => 'fuzzyish'], $top],
			'inputControl not string' => [['type' => 'string', 'inputControl' => ['select']], $top],
			'repeating group declared' => [['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]], $top],
			'first failure wins' => [['type' => 'integer', 'minimum' => 'a', 'visible' => 'no', 'enum' => []], $top],
			'flag order without enum' => [['type' => 'string', 'hideOnForm' => 1, 'hideOnCollection' => 1, 'visible' => 1], $top],
			'flag order' => [['type' => 'string', 'hideOnForm' => 1, 'visible' => 1, 'enum' => 'x'], $top],
			'enum null' => [['type' => 'string', 'enum' => null], $top],
			'array items null' => [['type' => 'array', 'items' => null], $top],
			'object properties null' => [['type' => 'object', 'properties' => null], $top],
			'only minimum' => [['type' => 'integer', 'minimum' => 1], $top],
			'only maximum' => [['type' => 'number', 'maximum' => 1.5], $top],
			'both bounds not numeric' => [['type' => 'number', 'minimum' => 'a', 'maximum' => 'b'], $top],
			'null bounds' => [['type' => 'number', 'minimum' => null, 'maximum' => null], $top],
			'sourceLanguage three letters' => [['type' => 'string', 'translatable' => true, 'sourceLanguage' => 'nld'], $top],
			'sourceLanguage subtags' => [['type' => 'string', 'translatable' => true, 'sourceLanguage' => 'zh-Hant-TW'], $top],
			'sourceLanguage translatable string' => [['type' => 'string', 'translatable' => 'yes', 'sourceLanguage' => 'nl'], $top],
			'onDelete null' => [['type' => 'string', 'onDelete' => null], $top],
			'onDelete lowercase referenceKey restrict' => [$rel + ['onDelete' => 'restrict', 'referenceKey' => 'code'], $top],
			'onDelete referenceKey unknown action' => [$rel + ['onDelete' => 'EXPLODE', 'referenceKey' => 'code'], $top],
			'matchType padded upper' => [['type' => 'string', 'matchType' => ' EXACT '], $top],
			'matchType null' => [['type' => 'string', 'matchType' => null], $top],
			'inputControl unknown' => [['type' => 'string', 'inputControl' => 'dial'], $top],
			'file allowedTypes scalar' => [['type' => 'file', 'allowedTypes' => 'image/png'], $top],
			'file allowedTypes non string' => [['type' => 'file', 'allowedTypes' => ['image/png', 5]], $top],
			'file allowedTypes keyed' => [['type' => 'file', 'allowedTypes' => ['a' => 'image/png', 'b' => 'nope']], $top],
			'file maxSize non numeric' => [['type' => 'file', 'maxSize' => 'big'], $top],
			'file maxSize numeric string' => [['type' => 'file', 'maxSize' => '1000'], $top],
			'file maxSize negative' => [['type' => 'file', 'maxSize' => -1], $top],
			'file maxSize at limit' => [['type' => 'file', 'maxSize' => 104857600], $top],
			'file maxSize float' => [['type' => 'file', 'maxSize' => 104857600.9], $top],
			'file allowedTags valid' => [['type' => 'file', 'allowedTags' => ['a', str_repeat('b', 50)]], $top],
			'file allowedTags scalar' => [['type' => 'file', 'allowedTags' => 'a'], $top],
			'file allowedTags non string' => [['type' => 'file', 'allowedTags' => ['a', 1]], $top],
			'file allowedTags blank' => [['type' => 'file', 'allowedTags' => ['a', '  ']], $top],
			'file allowedTags too long' => [['type' => 'file', 'allowedTags' => [str_repeat('c', 51)]], $top],
			'file autoTags valid' => [['type' => 'file', 'autoTags' => ['x']], $top],
			'file autoTags scalar' => [['type' => 'file', 'autoTags' => 3], $top],
			'file autoTags non string' => [['type' => 'file', 'autoTags' => [null]], $top],
			'file autoTags blank' => [['type' => 'file', 'autoTags' => ['k' => '']], $top],
			'file autoTags too long' => [['type' => 'file', 'autoTags' => ['ok', str_repeat('d', 60)]], $top],
			'file every option valid' => [['type' => 'file', 'allowedTypes' => ['application/pdf'], 'maxSize' => 0, 'allowedTags' => ['t'], 'autoTags' => ['u']], $top],
			'file first failure wins' => [['type' => 'file', 'allowedTypes' => [1], 'maxSize' => -5, 'allowedTags' => [''], 'autoTags' => 'x'], $top],
			'file tags before autoTags' => [['type' => 'file', 'autoTags' => 'x', 'allowedTags' => 'y'], $top],
			'file options on string ignored' => [['type' => 'string', 'maxSize' => -1], $top],
		];
	}//end cases()

	/**
	 * @param array<string, mixed> $property
	 */
	#[DataProvider('cases')]
	public function testValidatePropertyMatchesGolden(array $property, string $path): void {
		$name = $this->dataName();
		try {
			$actual = ['result' => (new PropertyValidatorHandler())->validateProperty($property, $path)];
		} catch (PropertyVocabularyException $e) {
			$actual = ['threw' => get_class($e), 'message' => $e->getMessage(), 'errors' => $e->getErrors()];
		} catch (\Throwable $e) {
			$actual = ['threw' => get_class($e), 'message' => $e->getMessage()];
		}

		$actual = json_decode(json_encode($actual), true);

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
	}//end testValidatePropertyMatchesGolden()
}//end class
