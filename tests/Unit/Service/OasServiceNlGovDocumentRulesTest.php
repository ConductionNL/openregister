<?php

/**
 * Unit tests for the NLGov date and time, error handling and document rules, and the marker.
 *
 * @category Test
 * @package  OCA\OpenRegister\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://www.OpenRegister.app
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */

declare(strict_types=1);

// phpcs:disable CustomSniffs.Functions.NamedParameters.RequireNamedParameters -- PHPUnit assertion helpers use positional args.

namespace OCA\OpenRegister\Tests\Unit\Service;

use OCA\OpenRegister\Db\Register;
use OCA\OpenRegister\Db\RegisterMapper;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Service\Oas\NlGovConformance;
use OCA\OpenRegister\Service\Oas\NlGovFindings;
use OCA\OpenRegister\Service\Oas\NlGovMarker;
use OCA\OpenRegister\Service\Oas\OasIssue;
use OCA\OpenRegister\Service\Oas\OasValidationReport;
use OCA\OpenRegister\Service\OasService;
use OCA\OpenRegister\Service\OasSources;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Pass 6's schema, error and document rules, and the x-nl-api-design-rules marker.
 *
 * @covers \OCA\OpenRegister\Service\Oas\NlGovConformance
 * @covers \OCA\OpenRegister\Service\Oas\NlGovDateTimeRules
 * @covers \OCA\OpenRegister\Service\Oas\NlGovErrorRules
 * @covers \OCA\OpenRegister\Service\Oas\NlGovDocumentInfoRules
 * @covers \OCA\OpenRegister\Service\Oas\NlGovFindings
 * @covers \OCA\OpenRegister\Service\Oas\NlGovMarker
 * @uses   \OCA\OpenRegister\Service\Oas\NlGovDocumentRules
 * @uses   \OCA\OpenRegister\Service\Oas\NlGovRuleCatalogue
 * @uses   \OCA\OpenRegister\Service\Oas\OasIssue
 * @uses   \OCA\OpenRegister\Service\Oas\OasValidationReport
 * @uses   \OCA\OpenRegister\Formats\SemVerFormat
 * @uses   \OCA\OpenRegister\Service\OasService
 * @uses   \OCA\OpenRegister\Service\OasSources
 * @uses   \OCA\OpenRegister\Service\Oas\OasExtendedTypes
 * @uses   \OCA\OpenRegister\Service\Oas\OasPropertySanitizer
 * @uses   \OCA\OpenRegister\Service\Oas\OasReferenceRepair
 * @uses   \OCA\OpenRegister\Service\Oas\OasRbacAnnotator
 * @uses   \OCA\OpenRegister\Db\Register
 * @uses   \OCA\OpenRegister\Db\Schema
 */
class OasServiceNlGovDocumentRulesTest extends TestCase {

	/**
	 * A document that passes every document rule but the URI version deviation and /core/publish-openapi.
	 *
	 * @return array<string, mixed> The document.
	 */
	private function cleanDocument(): array {
		return [
			'openapi' => '3.1.0',
			'info' => [
				'version' => '1.0.0',
				'contact' => ['name' => 'Team', 'url' => 'https://example.org', 'email' => 'team@example.org'],
			],
			'servers' => [['url' => 'https://host/apps/openregister/api']],
			'paths' => [
				'/objects/{register}/zaken' => [
					'post' => [
						'responses' => [
							'201' => ['description' => 'Created'],
							'400' => ['$ref' => '#/components/responses/Problem'],
							'500' => ['$ref' => '#/components/responses/Problem'],
						],
					],
					'get' => ['responses' => ['200' => ['description' => 'OK']]],
				],
			],
			'components' => [
				'responses' => [
					'Problem' => [
						'description' => 'Problem',
						'content' => ['application/problem+json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
					],
				],
				'schemas' => [
					'Zaak' => [
						'type' => 'object',
						'properties' => [
							'startdatum' => ['type' => 'string', 'format' => 'date', 'example' => '2026-11-14'],
							'gewijzigd' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-11-14T10:00:00+01:00'],
							'tijd' => ['type' => 'string', 'format' => 'time', 'default' => '10:00:00Z'],
							'naam' => ['type' => 'string', 'format' => 'name'],
						],
					],
				],
			],
		];
	}//end cleanDocument()

	/**
	 * The findings of one document by rule, as [path, message].
	 *
	 * @param array<string, mixed> $oas The document.
	 *
	 * @return array<string, list<array{0: string, 1: string}>> The findings.
	 */
	private function findings(array $oas): array {
		$report = new OasValidationReport();
		(new NlGovConformance())->check(report: $report, oas: $oas);

		$this->assertSame([], $report->getErrors());
		$found = [];
		foreach ($report->getWarnings() as $issue) {
			$this->assertSame(OasValidationReport::CODE_NLGOV_RULE, $issue['code']);
			$found[$issue['rule']][] = [$issue['path'], $issue['message']];
		}

		return $found;
	}//end findings()

	/**
	 * A clean document has only the declared URI version deviation and the publication finding, which names both locations.
	 *
	 * @return void
	 */
	public function testACleanDocumentHasOnlyThePublicationFinding(): void {
		$found = $this->findings(oas: $this->cleanDocument());

		$this->assertSame(['/core/uri-version', '/core/publish-openapi'], array_keys($found));
		$this->assertSame(
			[[
				'servers.0.url',
				'Violates NLGov /core/publish-openapi: The document is served as JSON at https://host/apps/openregister/api/registers/{id}/oas'
				.' and https://host/apps/openregister/api/registers/oas, not at https://host/apps/openregister/api/openapi.json.',
			]],
			$found['/core/publish-openapi']
		);
	}//end testACleanDocumentHasOnlyThePublicationFinding()

	/**
	 * A version that is not semantic fails /core/semver, naming the path and the value.
	 *
	 * @return void
	 */
	public function testAVersionThatIsNotSemanticFails(): void {
		$oas = $this->cleanDocument();
		$oas['info']['version'] = '1.0';

		$this->assertSame(
			[['info.version', 'Violates NLGov /core/semver: "1.0" is not a semantic version (MAJOR.MINOR.PATCH).']],
			$this->findings(oas: $oas)['/core/semver']
		);
	}//end testAVersionThatIsNotSemanticFails()

	/**
	 * The OpenAPI version, the contact details and plain-HTTP servers each fail their rule.
	 *
	 * @return void
	 */
	public function testDocumentInfoRules(): void {
		$oas = $this->cleanDocument();
		$oas['openapi'] = '2.0';
		$oas['info']['contact'] = ['name' => 'Team', 'url' => ' '];
		$oas['servers'][] = ['url' => 'http://localhost/api'];

		$found = $this->findings(oas: $oas);

		$this->assertSame([['openapi', 'Violates NLGov /core/doc-openapi: The document declares OpenAPI "2.0", not 3.x.']], $found['/core/doc-openapi']);
		$this->assertSame(
			[['info.contact', 'Violates NLGov /core/doc-openapi-contact: The contact details have no url, email.']],
			$found['/core/doc-openapi-contact']
		);
		$this->assertSame(
			[['servers.1.url', 'Violates NLGov /core/transport/tls: The server URL "http://localhost/api" does not use TLS (https).']],
			$found['/core/transport/tls']
		);
	}//end testDocumentInfoRules()

	/**
	 * Non-RFC formats, a format on a non-string, a bad example and a date with a time each fail, nested ones included.
	 *
	 * @return void
	 */
	public function testDateTimeRules(): void {
		$oas = $this->cleanDocument();
		$oas['components']['schemas']['Zaak']['properties'] += [
			'aangemaakt' => ['type' => 'string', 'format' => 'date_time'],
			'jaar' => ['type' => 'integer', 'format' => 'date'],
			'eind' => ['type' => 'string', 'format' => 'date-time', 'example' => '2026-11-14 10:00'],
			'besluit' => ['type' => 'object', 'properties' => ['datum' => ['type' => 'string', 'format' => 'date', 'default' => '2026-11-14T00:00:00Z']]],
			'termijnen' => ['type' => 'array', 'items' => ['allOf' => [['type' => ['string', 'null'], 'format' => 'timestamp']]]],
		];

		$found = $this->findings(oas: $oas);
		$base = 'components.schemas.Zaak.properties.';

		$this->assertSame(
			[
				[
					$base.'aangemaakt.format',
					'Violates NLGov /core/date-time/format: The format "date_time" is not an RFC 3339 format (date, date-time or time).',
				],
				[$base.'jaar.type', 'Violates NLGov /core/date-time/format: The format "date" is declared on type "integer", not on a string.'],
				[$base.'eind.example', 'Violates NLGov /core/date-time/format: The value "2026-11-14 10:00" is not an RFC 3339 date-time.'],
				[
					$base.'termijnen.items.allOf.0.format',
					'Violates NLGov /core/date-time/format: The format "timestamp" is not an RFC 3339 format (date, date-time or time).',
				],
			],
			$found['/core/date-time/format']
		);
		$this->assertSame(
			[[
				$base.'besluit.properties.datum.default',
				'Violates NLGov /core/date-time/date-omit-time-portion: The date "2026-11-14T00:00:00Z" carries a time portion.',
			]],
			$found['/core/date-time/date-omit-time-portion']
		);
	}//end testDateTimeRules()

	/**
	 * Every error response without a problem details body, and every write without a 400, make one finding per rule.
	 *
	 * @return void
	 */
	public function testErrorRulesWriteOneFindingPerRule(): void {
		$oas = $this->cleanDocument();
		$plain = ['description' => 'Error', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
		$oas['paths']['/objects/{register}/zaken']['get']['responses']['404'] = $plain;
		$oas['paths']['/objects/{register}/zaken/{id}'] = [
			'put' => ['responses' => ['200' => [], '403' => $plain, '5XX' => ['$ref' => '#/components/responses/Missing']]],
			'patch' => ['responses' => ['200' => []]],
			'delete' => ['responses' => ['204' => [], '404' => $plain]],
			'x-internal' => ['responses' => ['500' => $plain]],
		];

		$found = $this->findings(oas: $oas);

		$this->assertSame(
			[[
				'paths./objects/{register}/zaken.get.responses.404',
				'Violates NLGov /core/error-handling/problem-details: 4 error responses do not declare application/problem+json with the Error schema'
				.' (paths./objects/{register}/zaken.get.responses.404, paths./objects/{register}/zaken/{id}.put.responses.403,'
				.' paths./objects/{register}/zaken/{id}.put.responses.5XX, ...).',
			]],
			$found['/core/error-handling/problem-details']
		);
		$this->assertSame(
			[[
				'paths./objects/{register}/zaken/{id}.put.responses',
				'Violates NLGov /core/error-handling/invalid-input: 2 POST, PUT or PATCH operations declare no 400 response'
				.' (paths./objects/{register}/zaken/{id}.put.responses, paths./objects/{register}/zaken/{id}.patch.responses).',
			]],
			$found['/core/error-handling/invalid-input']
		);
	}//end testErrorRulesWriteOneFindingPerRule()

	/**
	 * The marker puts document rules under pass, fail or deviation from their findings, functional ones under manual.
	 *
	 * @return void
	 */
	public function testTheMarkerReadsTheFindings(): void {
		$report = new OasValidationReport();
		$findings = new NlGovFindings(report: $report);
		$findings->add(ruleId: '/core/semver', path: 'info.version', message: 'x');
		$findings->add(ruleId: '/core/uri-version', path: 'servers.0.url', message: 'x');
		$findings->add(ruleId: '/core/query-keys-camel-case', path: 'a', message: 'x');
		$findings->addViolation(ruleId: '/core/query-keys-camel-case', path: 'b', message: 'x');
		$report->addError(issue: new OasIssue(path: 'paths./a.trace', message: 'x', code: OasValidationReport::CODE_INVALID_HTTP_METHOD));

		$marker = (new NlGovConformance())->marker(report: $report);

		$this->assertSame('2.2.1', $marker['version']);
		$this->assertSame(['/core/query-keys-camel-case', '/core/http-methods', '/core/semver'], $marker['fail']);
		$this->assertSame(['/core/uri-version'], $marker['deviation']);
		$this->assertContains('/core/no-trailing-slash', $marker['pass']);
		$this->assertCount(14, array_merge($marker['pass'], $marker['fail'], $marker['deviation']));
		$this->assertCount(15, $marker['manual']);
		$this->assertContains('/core/http-response-code', $marker['manual']);
		$this->assertNotContains('/core/version-header', array_merge(...array_values(array_slice($marker, 1))));
		$this->assertSame('x-nl-api-design-rules', NlGovMarker::KEY);
	}//end testTheMarkerReadsTheFindings()

	/**
	 * A generated document carries the marker, the same on two generations, inside the time budget for 20 schemas.
	 *
	 * @return void
	 */
	public function testTheGeneratedDocumentCarriesTheMarkerWithinBudget(): void {
		$schemas = [];
		for ($id = 1; $id <= 20; $id++) {
			$schemas[] = $this->entity(
				entity: new Schema(),
				id: $id,
				fields: [
					'title' => 'Schema '.$id,
					'slug' => 'schema-'.$id,
					'properties' => [
						'naam' => ['type' => 'string'],
						'datum' => ['type' => 'string', 'format' => 'date'],
						'gewijzigd' => ['type' => 'string', 'format' => 'date-time'],
						'aantal' => ['type' => 'integer'],
					],
				]
			);
		}

		$register = $this->entity(
			entity: new Register(),
			id: 1,
			fields: ['title' => 'Zaken', 'version' => '1.2.0', 'slug' => 'zaken', 'schemas' => range(1, 20)]
		);
		$registerMapper = $this->createMock(RegisterMapper::class);
		$registerMapper->method('find')->willReturn($register);
		$schemaMapper = $this->createMock(SchemaMapper::class);
		$schemaMapper->method('findMultiple')->willReturn($schemas);
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getAbsoluteURL')->willReturnCallback(fn (string $path): string => 'https://nc.example'.$path);
		$urlGenerator->method('getBaseUrl')->willReturn('https://nc.example');
		$service = new OasService(new OasSources($registerMapper, $schemaMapper, $urlGenerator));

		$start = microtime(true);
		$oas = $service->createOas('1');
		$elapsed = microtime(true) - $start;

		$marker = $oas['x-nl-api-design-rules'];
		$this->assertLessThan(2.0, $elapsed);
		$this->assertSame('2.2.1', $marker['version']);
		$this->assertContains('/core/http-methods', $marker['pass']);
		$this->assertContains('/core/semver', $marker['pass']);
		$this->assertContains('/core/date-time/format', $marker['pass']);
		$this->assertContains('/core/uri-version', $marker['deviation']);
		$this->assertContains('/core/publish-openapi', $marker['fail']);
		$this->assertSame($marker, $service->createOas('1')['x-nl-api-design-rules']);
	}//end testTheGeneratedDocumentCarriesTheMarkerWithinBudget()

	/**
	 * An entity with its fields and id set.
	 *
	 * @param object               $entity The entity.
	 * @param integer              $id     The id.
	 * @param array<string, mixed> $fields The fields.
	 *
	 * @return object The entity.
	 */
	private function entity(object $entity, int $id, array $fields): object {
		foreach ($fields as $name => $value) {
			(new \ReflectionProperty($entity, $name))->setValue($entity, $value);
		}

		(new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

		return $entity;
	}//end entity()
}//end class
