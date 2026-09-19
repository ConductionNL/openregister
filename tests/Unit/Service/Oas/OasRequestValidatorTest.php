<?php

/**
 * Unit tests for OasRequestValidator.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.app
 */

declare(strict_types=1);

namespace Unit\Service\Oas;

use OCA\OpenRegister\Service\Oas\OasRequestValidator;
use PHPUnit\Framework\TestCase;

class OasRequestValidatorTest extends TestCase {

	private OasRequestValidator $validator;

	protected function setUp(): void {
		parent::setUp();
		$this->validator = new OasRequestValidator();

	}//end setUp()

	public function testValidBodyAgainstObjectSchemaReturnsEmptyErrors(): void {
		$schema = [
			'type' => 'object',
			'required' => ['title'],
			'properties' => [
				'title' => ['type' => 'string'],
				'age' => ['type' => 'integer', 'minimum' => 0],
			],
		];
		$body = ['title' => 'Hello', 'age' => 30];

		$this->assertSame([], $this->validator->validate(body: $body, schema: $schema));
		$this->assertTrue($this->validator->isValid(body: $body, schema: $schema));

	}//end testValidBodyAgainstObjectSchemaReturnsEmptyErrors()

	public function testMissingRequiredFieldYieldsError(): void {
		$schema = [
			'type' => 'object',
			'required' => ['title'],
			'properties' => ['title' => ['type' => 'string']],
		];

		$errors = $this->validator->validate(body: [], schema: $schema);
		$this->assertNotEmpty($errors);
		$this->assertFalse($this->validator->isValid(body: [], schema: $schema));

	}//end testMissingRequiredFieldYieldsError()

	public function testWrongTypeYieldsError(): void {
		$schema = [
			'type' => 'object',
			'properties' => ['age' => ['type' => 'integer']],
		];

		$errors = $this->validator->validate(body: ['age' => 'not-a-number'], schema: $schema);
		$this->assertNotEmpty($errors);

	}//end testWrongTypeYieldsError()

	public function testEnumViolationYieldsError(): void {
		$schema = [
			'type' => 'object',
			'properties' => [
				'status' => [
					'type' => 'string',
					'enum' => ['open', 'closed'],
				],
			],
		];

		$errors = $this->validator->validate(body: ['status' => 'archived'], schema: $schema);
		$this->assertNotEmpty($errors);

	}//end testEnumViolationYieldsError()

	public function testEachErrorHasPathAndMessage(): void {
		$schema = [
			'type' => 'object',
			'required' => ['title'],
			'properties' => ['title' => ['type' => 'string']],
		];

		$errors = $this->validator->validate(body: [], schema: $schema);
		foreach ($errors as $err) {
			$this->assertArrayHasKey('path', $err);
			$this->assertArrayHasKey('message', $err);
			$this->assertIsString($err['path']);
			$this->assertIsString($err['message']);
		}

	}//end testEachErrorHasPathAndMessage()

	/**
	 * Build a minimal OpenAPI 3.1 document around a list of parameters.
	 *
	 * @param array $parameters The operation's parameters.
	 *
	 * @return array The document.
	 */
	private function oasDocument(array $parameters): array {
		return [
			'openapi' => '3.1.0',
			'info' => ['title' => 'Probe', 'version' => '1.0.0'],
			'paths' => [
				'/items/{id}' => [
					'get' => [
						'parameters' => $parameters,
						'responses' => ['200' => ['description' => 'OK']],
					],
				],
			],
		];

	}//end oasDocument()

	/**
	 * Load the vendored OpenAPI 3.1 meta-schema that OasService validates against.
	 *
	 * @return array The decoded meta-schema.
	 */
	private function oasMetaSchema(): array {
		$path = __DIR__ . '/../../../../lib/Service/Resources/meta/openapi-3.1.0.json';
		$meta = json_decode((string)file_get_contents($path), true);
		$this->assertIsArray($meta, 'the vendored meta-schema MUST decode');
		return $meta;

	}//end oasMetaSchema()

	/**
	 * A textbook-valid OAS 3.1 document with parameters MUST pass the meta-schema.
	 *
	 * Every OAS report with parameters used to carry 14 false errors: opis
	 * bound the parameter's `schema: {$dynamicRef: "#meta"}` to the document
	 * ROOT (demanding `openapi` inside every parameter schema), and injected
	 * `default` values into the data so `unevaluatedProperties` then refused
	 * the `allowEmptyValue` it had just added itself.
	 *
	 * @return void
	 */
	public function testValidOasDocumentWithParametersPassesTheMetaSchema(): void {
		$document = $this->oasDocument(
			[
				['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
				['name' => '_extend', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string'], 'example' => 'a,b'],
			]
		);

		$errors = $this->validator->validate(body: $document, schema: $this->oasMetaSchema());

		$this->assertSame([], $errors, 'a valid OAS 3.1 document MUST report zero errors; got: ' . json_encode($errors));

	}//end testValidOasDocumentWithParametersPassesTheMetaSchema()

	/**
	 * The meta-schema check MUST still catch a genuinely broken parameter.
	 *
	 * Guards the fix from the other side: a validator that accepts everything
	 * would also pass the test above.
	 *
	 * @return void
	 */
	public function testOasParameterWithoutNameIsStillRejected(): void {
		$document = $this->oasDocument(
			[
				['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
				['in' => 'query', 'schema' => ['type' => 'string']],
			]
		);

		$errors = $this->validator->validate(body: $document, schema: $this->oasMetaSchema());
		$messages = array_column($errors, 'message');

		$this->assertContains('The required properties (name) are missing', $messages);

	}//end testOasParameterWithoutNameIsStillRejected()

	/**
	 * A `$dynamicRef` MUST bind to the `$dynamicAnchor` its schema declares.
	 *
	 * @return void
	 */
	public function testDynamicRefBindsToItsDeclaredAnchorNotTheRoot(): void {
		$schema = [
			'$schema' => 'https://json-schema.org/draft/2020-12/schema',
			'$id' => 'https://example.test/tree',
			'type' => 'object',
			'required' => ['kind'],
			'properties' => ['child' => ['$dynamicRef' => '#node']],
			'$defs' => ['node' => ['$dynamicAnchor' => 'node', 'type' => ['object', 'boolean']]],
		];

		// `child` is a plain object: valid against $defs/node, invalid against
		// the root (which requires `kind`).
		$this->assertSame([], $this->validator->validate(body: ['kind' => 'a', 'child' => ['x' => 1]], schema: $schema));
		$this->assertNotEmpty($this->validator->validate(body: ['kind' => 'a', 'child' => 'leaf'], schema: $schema));

	}//end testDynamicRefBindsToItsDeclaredAnchorNotTheRoot()

	/**
	 * A `default` MUST NOT be written into the data being validated.
	 *
	 * @return void
	 */
	public function testDefaultsAreNotInjectedIntoTheValidatedData(): void {
		$schema = [
			'type' => 'object',
			'properties' => ['in' => ['type' => 'string']],
			'if' => ['properties' => ['in' => ['const' => 'query']], 'required' => ['in']],
			'then' => ['properties' => ['allowEmptyValue' => ['default' => false, 'type' => 'boolean']]],
			'unevaluatedProperties' => false,
		];

		$this->assertSame([], $this->validator->validate(body: ['in' => 'query'], schema: $schema));

	}//end testDefaultsAreNotInjectedIntoTheValidatedData()

	/**
	 * Error messages MUST be filled in, never raw `{keyword}` templates.
	 *
	 * @return void
	 */
	public function testErrorMessagesCarryNoUnfilledPlaceholders(): void {
		$schema = [
			'type' => 'object',
			'required' => ['title'],
			'properties' => ['title' => ['type' => 'string'], 'age' => ['type' => 'integer']],
		];

		// Not `[]`: an empty PHP array encodes as a JSON list, which fails on
		// type before `required` is ever reached.
		$errors = array_merge(
			$this->validator->validate(body: ['age' => 3], schema: $schema),
			$this->validator->validate(body: ['title' => 'x', 'age' => 'old'], schema: $schema)
		);

		$this->assertNotEmpty($errors);
		foreach ($errors as $error) {
			$this->assertDoesNotMatchRegularExpression('/\{[a-z]+\}/i', $error['message'], 'unfilled placeholder in: ' . $error['message']);
		}

		$this->assertContains('The required properties (title) are missing', array_column($errors, 'message'));

	}//end testErrorMessagesCarryNoUnfilledPlaceholders()
}//end class
