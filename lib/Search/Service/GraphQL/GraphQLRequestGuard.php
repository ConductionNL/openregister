<?php

/**
 * GraphQL request guard
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Search\Service\GraphQL
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://www.OpenRegister.nl
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-cross-register-schema-stitching-must-provide-a-unified-graph
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Search\Service\GraphQL;

use GraphQL\Error\Error;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\SelectionSetNode;
use OCP\IAppConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Refuses a GraphQL request that is over its rate limit or asks for introspection it may not.
 *
 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
 */
final class GraphQLRequestGuard {
	/**
	 * Constructor.
	 *
	 * @param IAppConfig   $appConfig   App configuration
	 * @param IUserSession $userSession User session
	 * @param IRequest     $request     Current request
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly IUserSession $userSession,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * Check if introspection is allowed for the current request.
	 *
	 * @param DocumentNode $document The parsed document
	 *
	 * @return void
	 *
	 * @throws Error If introspection is not allowed
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	public function checkIntrospection(DocumentNode $document): void {
		$setting = $this->appConfig->getValueString(
			'openregister',
			'graphql_introspection',
			'enabled'
		);

		if ($setting === 'enabled' || $this->documentHasIntrospection(document: $document) === false) {
			return;
		}

		if ($setting === 'disabled') {
			throw $this->introspectionError(message: 'Introspection is disabled');
		}

		// Setting is 'authenticated' — check if user is logged in.
		if ($setting === 'authenticated' && $this->userSession->getUser() === null) {
			throw $this->introspectionError(message: 'Introspection requires authentication');
		}

	}//end checkIntrospection()

	/**
	 * Check rate limiting for the current request.
	 *
	 * Uses APCu to track per-user and per-IP request counts.
	 * Progressive delay: 2s → 4s → 8s → ... → 60s max.
	 *
	 * @return void
	 *
	 * @throws Error If rate limited
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	public function checkRateLimit(): void {
		$maxRequests = (int)$this->appConfig->getValueString('openregister', 'graphql_rate_limit', '100');

		$key = $this->rateLimitKey();
		if ($key === null || function_exists('apcu_enabled') === false || apcu_enabled() === false) {
			return;
		}

		$count = apcu_fetch($key);
		if ($count === false) {
			apcu_store($key, 1, 60);
			return;
		}

		apcu_inc($key);
		if ($count >= $maxRequests) {
			throw $this->rateLimitError(count: $count, maxRequests: $maxRequests);
		}

	}//end checkRateLimit()

	/**
	 * Identify the client the rate limit counts against.
	 *
	 * @return string|null The APCu key, or null when no client is identifiable (CLI/test context)
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function rateLimitKey(): ?string {
		$key  = 'openregister_graphql_rate_';
		$user = $this->userSession->getUser();
		if ($user !== null) {
			return $key.'user_'.preg_replace('/[^a-zA-Z0-9_]/', '_', $user->getUID());
		}

		$clientIp = $this->request->getRemoteAddress();
		if (empty($clientIp) === true) {
			return null;
		}

		return $key.'ip_'.preg_replace('/[^a-zA-Z0-9_.]/', '_', $clientIp);
	}//end rateLimitKey()

	/**
	 * Build the rate-limit error with its progressive delay.
	 *
	 * @param int $count       The requests counted in this window
	 * @param int $maxRequests The requests allowed in this window
	 *
	 * @return Error The error
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function rateLimitError(int $count, int $maxRequests): Error {
		$overCount  = ($count - $maxRequests);
		$retryAfter = min(60, (int)pow(2, min($overCount, 5)));

		return new Error(
			"Rate limit exceeded. Retry after $retryAfter seconds.",
			null,
			null,
			[],
			null,
			null,
			[
				'code' => 'RATE_LIMITED',
				'retryAfter' => $retryAfter,
			]
		);
	}//end rateLimitError()

	/**
	 * Build an introspection refusal.
	 *
	 * @param string $message The message
	 *
	 * @return Error The error
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function introspectionError(string $message): Error {
		return new Error(
			$message,
			null,
			null,
			[],
			null,
			null,
			['code' => 'INTROSPECTION_DISABLED']
		);
	}//end introspectionError()

	/**
	 * Check if any operation in a document selects an introspection field.
	 *
	 * @param DocumentNode $document The parsed document
	 *
	 * @return bool True if introspection fields are present
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function documentHasIntrospection(DocumentNode $document): bool {
		foreach ($document->definitions as $definition) {
			if ($definition instanceof OperationDefinitionNode === true
				&& $this->selectionSetHasIntrospection(selectionSet: $definition->selectionSet) === true
			) {
				return true;
			}
		}

		return false;
	}//end documentHasIntrospection()

	/**
	 * Check if a selection set contains introspection fields.
	 *
	 * @param SelectionSetNode $selectionSet The selection set
	 *
	 * @return bool True if introspection fields are present
	 *
	 * @spec openspec/specs/graphql-api/spec.md#requirement-query-complexity-analysis-must-prevent-resource-abuse
	 */
	private function selectionSetHasIntrospection(SelectionSetNode $selectionSet): bool {
		foreach ($selectionSet->selections as $selection) {
			if ($selection instanceof FieldNode === true
				&& in_array($selection->name->value, ['__schema', '__type'], true) === true
			) {
				return true;
			}
		}

		return false;
	}//end selectionSetHasIntrospection()
}//end class
