<?php

/**
 * Names a recent breaking schema change on that schema's object responses.
 *
 * @category Middleware
 * @package  OCA\OpenRegister\Middleware
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
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-clients-see-a-breaking-change-on-the-schemas-responses
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Middleware;

use DateTime;
use OCA\OpenRegister\Controller\ObjectsController;
use OCA\OpenRegister\Db\SchemaChangelog;
use OCA\OpenRegister\Db\SchemaChangelogMapperInterface;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Throwable;

/**
 * For 30 days after a schema's latest breaking change, a successful object
 * read or list of that schema carries:
 *
 * - `OpenRegister-Schema-Changed: version="<v>", at="<ISO date>", breaking`
 * - `Link: </index.php/apps/openregister/api/schemas/<id>/changelog>; rel="describedby"`
 *
 * The lookup is memoised per schema for the request, so a list answers with
 * one lookup however many objects it holds. A failing lookup adds nothing.
 *
 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-clients-see-a-breaking-change-on-the-schemas-responses
 */
class SchemaChangeHeaderMiddleware extends Middleware {

	/**
	 * The header.
	 */
	public const HEADER = 'OpenRegister-Schema-Changed';

	/**
	 * How long a breaking change is announced, in days.
	 */
	public const DAYS = 30;

	/**
	 * The object routes that carry the header.
	 *
	 * @var array<int, string>
	 */
	private const METHODS = ['index', 'show'];

	/**
	 * The latest breaking entry per schema parameter, for this request.
	 *
	 * @var array<string, SchemaChangelog|null>
	 */
	private array $memo = [];

	/**
	 * Constructor.
	 *
	 * @param IRequest                       $request   The request, for its schema parameter.
	 * @param SchemaMapperInterface          $schemas   Resolves the schema parameter.
	 * @param SchemaChangelogMapperInterface $changelog The changelog.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-clients-see-a-breaking-change-on-the-schemas-responses
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly SchemaMapperInterface $schemas,
		private readonly SchemaChangelogMapperInterface $changelog,
	) {
	}//end __construct()

	/**
	 * Add the headers to a successful object read or list of a recently broken schema.
	 *
	 * @param mixed    $controller The controller.
	 * @param string   $methodName The action.
	 * @param Response $response   The response.
	 *
	 * @return Response The response.
	 *
	 * @spec openspec/changes/schema-breaking-change-notice/specs/schema-migration/spec.md#requirement-clients-see-a-breaking-change-on-the-schemas-responses
	 */
	public function afterController($controller, $methodName, Response $response): Response {
		if (($controller instanceof ObjectsController) === false
			|| in_array($methodName, self::METHODS, true) === false
			|| $response->getStatus() >= 300
		) {
			return $response;
		}

		$entry = $this->latestBreaking(schema: (string)$this->request->getParam('schema', ''));
		if ($entry === null) {
			return $response;
		}

		$response->addHeader(
			self::HEADER,
			sprintf('version="%s", at="%s", breaking', (string)$entry->getVersion(), $entry->getCreated()?->format(DATE_ATOM) ?? '')
		);
		$response->addHeader(
			'Link',
			sprintf('</index.php/apps/openregister/api/schemas/%d/changelog>; rel="describedby"', (int)$entry->getSchemaId())
		);

		return $response;
	}//end afterController()

	/**
	 * The schema's breaking entry of the last 30 days, memoised for the request.
	 *
	 * @param string $schema The schema parameter: id, uuid or slug.
	 *
	 * @return SchemaChangelog|null The entry.
	 */
	private function latestBreaking(string $schema): ?SchemaChangelog {
		if ($schema === '') {
			return null;
		}

		if (array_key_exists($schema, $this->memo) === false) {
			$this->memo[$schema] = $this->lookUp(schema: $schema);
		}

		return $this->memo[$schema];
	}//end latestBreaking()

	/**
	 * Look the entry up; a schema that does not resolve has none.
	 *
	 * @param string $schema The schema parameter.
	 *
	 * @return SchemaChangelog|null The entry.
	 */
	private function lookUp(string $schema): ?SchemaChangelog {
		try {
			$schemaId = (int)$this->schemas->find(id: $schema, _rbac: false, _multitenancy: false)->getId();

			return $this->changelog->findLatestBreakingSince(
				schemaId: $schemaId,
				since: new DateTime(sprintf('-%d days', self::DAYS))
			);
		} catch (Throwable $e) {
			return null;
		}
	}//end lookUp()
}//end class
