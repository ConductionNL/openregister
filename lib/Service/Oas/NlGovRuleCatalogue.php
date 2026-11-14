<?php

/**
 * The rules of the NLGov REST API Design Rules, pinned to one ruleset version.
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

/**
 * One place that owns every NLGov rule: its id, title, how it can be checked,
 * and the reason when Open Register breaks it on purpose.
 *
 * A deviation is declared here and nowhere else, so no setting can turn a
 * failing rule into a passing one. Moving to a later ruleset is a code change
 * with a test.
 *
 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
 */
final class NlGovRuleCatalogue {

	/**
	 * The ruleset version the catalogue is pinned to.
	 *
	 * @var string
	 */
	public const RULESET_VERSION = '2.2.1';

	/**
	 * Checkable from the OpenAPI document.
	 *
	 * @var string
	 */
	public const KIND_DOCUMENT = 'document';

	/**
	 * Checkable only from a live response.
	 *
	 * @var string
	 */
	public const KIND_RESPONSE = 'response';

	/**
	 * A design guideline no machine can decide.
	 *
	 * @var string
	 */
	public const KIND_FUNCTIONAL = 'functional';

	/**
	 * A module of the ruleset: geospatial, signing or encryption.
	 *
	 * @var string
	 */
	public const KIND_MODULE = 'module';

	/**
	 * Standard HTTP methods an operation may use (/core/http-methods).
	 *
	 * The rule names GET, POST, PUT, PATCH and DELETE; HEAD and OPTIONS are
	 * standard RFC 9110 methods the rule's note allows, so documenting them is
	 * not a violation.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

	/**
	 * Response status codes the generator treats as standard (/core/http-response-code).
	 *
	 * The rule is functional: a whitelist cannot decide whether a code is
	 * semantically right, so a code outside it is a warning, not a failure.
	 *
	 * @var list<string>
	 */
	public const ALLOWED_STATUS_CODES = ['200', '201', '204', '400', '401', '403', '404', '422', '500', 'default'];

	/**
	 * Every rule: id => [title, kind, deviation reason or null].
	 *
	 * @var array<string, array{0: string, 1: string, 2: string|null}>
	 */
	private const RULES = [
		'/core/no-trailing-slash' => ['Leave off trailing slashes from URIs', self::KIND_DOCUMENT, null],
		'/core/path-segments-kebab-case' => ['Use kebab-case in path segments', self::KIND_DOCUMENT, null],
		'/core/query-keys-camel-case' => [
			'Use camelCase for query keys',
			self::KIND_DOCUMENT,
			'The underscore prefix marks a reserved query key (_limit, _page, _extend, ...) and every client sends them; '
			.'renaming them breaks the contract.',
		],
		'/core/date-time/format' => ['Use RFC 3339 date and time formats', self::KIND_DOCUMENT, null],
		'/core/date-time/date-omit-time-portion' => ['Omit the time portion of a date', self::KIND_DOCUMENT, null],
		'/core/http-methods' => ['Only apply standard HTTP methods', self::KIND_DOCUMENT, null],
		'/core/error-handling/problem-details' => ['Use problem details for errors', self::KIND_DOCUMENT, null],
		'/core/error-handling/invalid-input' => ['Use 400 for invalid input', self::KIND_DOCUMENT, null],
		'/core/doc-openapi' => ['Use OpenAPI Specification for documentation', self::KIND_DOCUMENT, null],
		'/core/doc-openapi-contact' => ['Include contact details in the OpenAPI document', self::KIND_DOCUMENT, null],
		'/core/publish-openapi' => ['Publish the OpenAPI document at a standard location in JSON', self::KIND_DOCUMENT, null],
		'/core/uri-version' => [
			'Include the major version number in the URI',
			self::KIND_DOCUMENT,
			'The URL pattern /index.php/apps/{app}/api/{resource} is fixed for every app (hydra ADR-002); '
			.'the version is negotiated by the API-Version header instead.',
		],
		'/core/semver' => ['Adhere to the Semantic Versioning model', self::KIND_DOCUMENT, null],
		'/core/transport/tls' => ['Secure connections using TLS', self::KIND_DOCUMENT, null],
		'/core/version-header' => ['Return the full version number in a response header', self::KIND_RESPONSE, null],
		'/core/transport/security-headers' => ['Use mandatory security headers in API responses', self::KIND_RESPONSE, null],
		'/core/date-time/timezone' => ['Return date and time values with a time zone offset', self::KIND_RESPONSE, null],
		'/core/naming-resources' => ['Use nouns to name resources', self::KIND_FUNCTIONAL, null],
		'/core/naming-collections' => ['Use plural nouns to name collection resources', self::KIND_FUNCTIONAL, null],
		'/core/interface-language' => [
			'Define interfaces in Dutch unless there is an official English glossary',
			self::KIND_FUNCTIONAL,
			null,
		],
		'/core/hide-implementation' => ['Hide irrelevant implementation details', self::KIND_FUNCTIONAL, null],
		'/core/http-safety' => ['Adhere to HTTP safety and idempotency semantics', self::KIND_FUNCTIONAL, null],
		'/core/http-response-code' => ['Use appropriate HTTP status codes', self::KIND_FUNCTIONAL, null],
		'/core/stateless' => ['Do not maintain session state on the server', self::KIND_FUNCTIONAL, null],
		'/core/nested-child' => ['Use nested URIs for child resources', self::KIND_FUNCTIONAL, null],
		'/core/resource-operations' => ['Model resource operations as a sub-resource or dedicated resource', self::KIND_FUNCTIONAL, null],
		'/core/error-handling/all-errors' => ['Return all errors of a request at once', self::KIND_FUNCTIONAL, null],
		'/core/doc-language' => [
			'Publish documentation in Dutch unless there is existing documentation in English',
			self::KIND_FUNCTIONAL,
			null,
		],
		'/core/deprecation-schedule' => ['Include a deprecation schedule when publishing API changes', self::KIND_FUNCTIONAL, null],
		'/core/transition-period' => ['Schedule a fixed transition period for a new major API version', self::KIND_FUNCTIONAL, null],
		'/core/transport/cors' => ['Use CORS to control access', self::KIND_FUNCTIONAL, null],
		'/core/transport/no-sensitive-uris' => ['No sensitive information in URIs', self::KIND_FUNCTIONAL, null],
		'/core/modules/geospatial' => ['Geospatial module', self::KIND_MODULE, null],
		'/core/modules/signing' => ['Signing module', self::KIND_MODULE, null],
		'/core/modules/encryption' => ['Encryption module', self::KIND_MODULE, null],
	];

	/**
	 * Every rule of the ruleset, in catalogue order.
	 *
	 * @return list<array{id: string, title: string, kind: string, deviation: string|null}> The rules.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public static function rules(): array {
		$rules = [];
		foreach (self::RULES as $id => [$title, $kind, $deviation]) {
			$rules[] = ['id' => $id, 'title' => $title, 'kind' => $kind, 'deviation' => $deviation];
		}

		return $rules;
	}//end rules()

	/**
	 * One rule by its id.
	 *
	 * @param string $ruleId The rule id, for example `/core/no-trailing-slash`.
	 *
	 * @return array{id: string, title: string, kind: string, deviation: string|null}|null The rule, or null when the ruleset has no such rule.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public static function rule(string $ruleId): ?array {
		if (isset(self::RULES[$ruleId]) === false) {
			return null;
		}

		[$title, $kind, $deviation] = self::RULES[$ruleId];

		return ['id' => $ruleId, 'title' => $title, 'kind' => $kind, 'deviation' => $deviation];
	}//end rule()

	/**
	 * The reason Open Register breaks a rule on purpose, or null when it does not.
	 *
	 * @param string $ruleId The rule id.
	 *
	 * @return string|null The reason.
	 *
	 * @spec openspec/changes/api-nl-design-rules-conformance/specs/oas-validation/spec.md
	 */
	public static function deviation(string $ruleId): ?string {
		return (self::rule(ruleId: $ruleId)['deviation'] ?? null);
	}//end deviation()
}//end class
