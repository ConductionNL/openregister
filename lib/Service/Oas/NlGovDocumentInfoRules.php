<?php

/**
 * The NLGov rules about the OpenAPI document itself: its version, contact, location and servers.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Oas
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Oas;

use OCA\OpenRegister\Formats\FormatFactory;

/**
 * Checks /core/doc-openapi, /core/doc-openapi-contact, /core/semver,
 * /core/publish-openapi and /core/transport/tls on a generated document.
 *
 * /core/publish-openapi always has a finding: Open Register serves the
 * document at `/registers/{id}/oas` and `/registers/oas` under its API, not at
 * the rule's `openapi.json`, and the finding names both side by side.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovDocumentInfoRules {

	/**
	 * The contact fields the rule asks for.
	 *
	 * @var list<string>
	 */
	private const CONTACT_FIELDS = ['name', 'url', 'email'];

	/**
	 * Constructor.
	 *
	 * @param NlGovFindings $findings Where the findings go.
	 */
	public function __construct(
		private readonly NlGovFindings $findings,
	) {
	}//end __construct()

	/**
	 * Check a document.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public function check(array $oas): void {
		$this->checkOpenApiVersion(oas: $oas);
		$this->checkContact(oas: $oas);
		$this->checkSemver(oas: $oas);
		$this->checkPublication(oas: $oas);
		$this->checkTls(oas: $oas);
	}//end check()

	/**
	 * /core/doc-openapi: the document is OpenAPI 3.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 */
	private function checkOpenApiVersion(array $oas): void {
		$version = ($oas['openapi'] ?? null);
		if (is_string($version) === true && str_starts_with($version, '3.') === true) {
			return;
		}

		$this->findings->add(
			ruleId: '/core/doc-openapi',
			path: 'openapi',
			message: sprintf('The document declares OpenAPI "%s", not 3.x.', self::text(value: $version))
		);
	}//end checkOpenApiVersion()

	/**
	 * /core/doc-openapi-contact: info.contact has a name, a URL and an email address.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 */
	private function checkContact(array $oas): void {
		$missing = [];
		foreach (self::CONTACT_FIELDS as $field) {
			$value = ($oas['info']['contact'][$field] ?? null);
			if (is_string($value) === false || trim($value) === '') {
				$missing[] = $field;
			}
		}

		if ($missing === []) {
			return;
		}

		$this->findings->add(
			ruleId: '/core/doc-openapi-contact',
			path: 'info.contact',
			message: sprintf('The contact details have no %s.', implode(', ', $missing))
		);
	}//end checkContact()

	/**
	 * /core/semver: info.version is a semantic version.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 */
	private function checkSemver(array $oas): void {
		$version = ($oas['info']['version'] ?? null);
		if ((new FormatFactory())->isSemanticVersion(value: $version) === true) {
			return;
		}

		$this->findings->add(
			ruleId: '/core/semver',
			path: 'info.version',
			message: sprintf('"%s" is not a semantic version (MAJOR.MINOR.PATCH).', self::text(value: $version))
		);
	}//end checkSemver()

	/**
	 * /core/publish-openapi: where the document is served, beside where the rule expects it.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 */
	private function checkPublication(array $oas): void {
		$server = rtrim((string)($oas['servers'][0]['url'] ?? ''), '/');
		$this->findings->add(
			ruleId: '/core/publish-openapi',
			path: 'servers.0.url',
			message: sprintf(
				'The document is served as JSON at %1$s/registers/{id}/oas and %1$s/registers/oas, not at %1$s/openapi.json.',
				$server
			)
		);
	}//end checkPublication()

	/**
	 * /core/transport/tls: every server URL is https.
	 *
	 * @param array<string, mixed> $oas The OpenAPI document.
	 *
	 * @return void
	 */
	private function checkTls(array $oas): void {
		foreach (self::listOf(value: ($oas['servers'] ?? null)) as $index => $server) {
			$url = ($server['url'] ?? null);
			if (is_string($url) === true && str_starts_with(strtolower($url), 'https://') === false) {
				$this->findings->add(
					ruleId: '/core/transport/tls',
					path: 'servers.'.$index.'.url',
					message: sprintf('The server URL "%s" does not use TLS (https).', $url)
				);
			}
		}
	}//end checkTls()

	/**
	 * The value when it is an array, else an empty array.
	 *
	 * @param mixed $value The value.
	 *
	 * @return array<mixed> The array.
	 */
	private static function listOf(mixed $value): array {
		if (is_array($value) === false) {
			return [];
		}

		return $value;
	}//end listOf()

	/**
	 * A scalar as text, anything else as an empty string.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string The text.
	 */
	private static function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return (string)$value;
	}//end text()
}//end class
