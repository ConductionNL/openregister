<?php

/**
 * OpenRegister ViewService
 *
 * Service class for managing views in the OpenRegister application.
 *
 * This service acts as a facade for view operations,
 * coordinating between ViewMapper and business logic.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */

namespace OCA\OpenRegister\Service;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\SchemaMapper;
use OCA\OpenRegister\Db\View;
use OCA\OpenRegister\Db\ViewMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;

/**
 * ViewService manages views in the OpenRegister application
 *
 * Service class for managing views in the OpenRegister application.
 * This service acts as a facade for view operations, coordinating between
 * ViewMapper and business logic. Handles view CRUD operations, access control,
 * and default view management.
 *
 * @category Service
 * @package  OCA\OpenRegister\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git_id>
 *
 * @link https://www.OpenRegister.app
 */
class ViewService {

	/**
	 * View mapper
	 *
	 * Handles database operations for view entities.
	 *
	 * @var ViewMapper View mapper instance
	 */
	private readonly ViewMapper $viewMapper;

	/**
	 * Logger
	 *
	 * Used for logging view operations and errors.
	 *
	 * @var LoggerInterface Logger instance
	 */
	private readonly LoggerInterface $logger;

	/**
	 * Schema mapper
	 *
	 * Used to resolve the view's schema when validating a presentation
	 * config's groupByField/dateField against real schema properties.
	 *
	 * @var SchemaMapper Schema mapper instance
	 */
	private readonly SchemaMapper $schemaMapper;

	/**
	 * Constructor
	 *
	 * Initializes service with view mapper and logger for view operations.
	 *
	 * @param ViewMapper $viewMapper View mapper for database operations
	 * @param LoggerInterface $logger Logger for error tracking
	 * @param SchemaMapper $schemaMapper Schema mapper for presentation field validation
	 *
	 * @return void
	 */
	public function __construct(
		ViewMapper $viewMapper,
		LoggerInterface $logger,
		SchemaMapper $schemaMapper,
	) {
		// Store dependencies for use in service methods.
		$this->viewMapper = $viewMapper;
		$this->logger = $logger;
		$this->schemaMapper = $schemaMapper;
	}//end __construct()

	/**
	 * Find a view by ID
	 *
	 * Retrieves view by ID and validates user access permissions.
	 * Users can access their own views or public views.
	 *
	 * @param int|string $id The ID of the view to find
	 * @param string $owner The owner user ID for access control
	 *
	 * @return View The found view entity
	 *
	 * @throws \OCP\AppFramework\Db\DoesNotExistException If view not found or access denied
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException If multiple views found (should not happen)
	 * @throws \OCP\DB\Exception If database error occurs
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-8
	 */
	public function find(int|string $id, string $owner): View {
		// Step 1: Find view by ID in database.
		$view = $this->viewMapper->find($id);

		// Step 2: Check if user has access to this view.
		// Users can access their own views or public views.
		if ($view->getOwner() !== $owner && $view->getIsPublic() === false) {
			// Throw exception to prevent unauthorized access.
			throw new DoesNotExistException('View not found or access denied');
		}

		// Step 3: Return view if access is granted.
		return $view;
	}//end find()

	/**
	 * Find all views accessible to a user
	 *
	 * Retrieves all views that the user owns or has access to (public views).
	 * Returns array of view entities sorted by default status and name.
	 *
	 * @param string $owner The owner user ID to find views for
	 *
	 * @return View[] Array of found views accessible to the user
	 *
	 * @psalm-return array<View>
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-8
	 */
	public function findAll(string $owner): array {
		// Retrieve all views accessible to the user (owned or public).
		return $this->viewMapper->findAll(owner: $owner);
	}//end findAll()

	/**
	 * Create a new view
	 *
	 * Creates a new view entity with specified properties. If view is set as default,
	 * clears any existing default view for the user. Validates and stores view in database.
	 *
	 * @param string $name The name of the view
	 * @param string $description The description of the view
	 * @param string $owner The owner user ID
	 * @param bool $isPublic Whether the view is public (accessible to all users)
	 * @param bool $isDefault Whether the view is the default view for the user
	 * @param array<string, mixed> $query The query parameters (registers, schemas, filters)
	 * @param array|null $presentation Presentation config (viewType + kanban/calendar config); null = table (default)
	 *
	 * @return View The created view entity
	 *
	 * @throws Exception If view creation fails (database error, validation error, etc.)
	 * @throws InvalidArgumentException If the presentation config cannot render (REQ-VIEW-PRES-01)
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-8
	 */
	public function create(
		string $name,
		string $description,
		string $owner,
		bool $isPublic,
		bool $isDefault,
		array $query,
		?array $presentation = null,
	): View {
		try {
			// Step 0: Reject a presentation config that cannot render before touching the DB.
			$this->validatePresentationConfig(presentation: $presentation, query: $query);

			// Step 1: If this view is set as default, clear any existing default for this user.
			// Only one default view per user is allowed.
			if ($isDefault === true) {
				$this->clearDefaultForUser(owner: $owner);
			}

			// Step 2: Create new view entity and set all properties.
			$view = new View();
			$view->setName($name);
			$view->setDescription($description);
			$view->setOwner($owner);
			$view->setIsPublic($isPublic);
			$view->setIsDefault($isDefault);
			$view->setQuery($query);
			$view->setPresentation($presentation);
			$view->setFavoredBy([]);

			// Step 3: Insert view into database and return created entity.
			return $this->viewMapper->insert($view);
		} catch (Exception $e) {
			// Log error for debugging and monitoring.
			$this->logger->error(
				message: '[ViewService] Error creating view: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw $e;
		}//end try
	}//end create()

	/**
	 * Update an existing view.
	 *
	 * @param int|string $id The ID of the view to update
	 * @param string $name The name of the view
	 * @param string $description The description of the view
	 * @param string $owner The owner user ID (for access control)
	 * @param bool $isPublic Whether the view is public
	 * @param bool $isDefault Whether the view is default
	 * @param array $query The query parameters
	 * @param array|null $favoredBy Array of user IDs who favor this view
	 * @param array|null $presentation Presentation config (viewType + kanban/calendar config); null leaves the existing value untouched
	 *
	 * @return View The updated view
	 *
	 * @throws Exception If update fails
	 * @throws InvalidArgumentException If the presentation config cannot render (REQ-VIEW-PRES-01)
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-8
	 */
	public function update(
		int|string $id,
		string $name,
		string $description,
		string $owner,
		bool $isPublic,
		bool $isDefault,
		array $query,
		?array $favoredBy = null,
		?array $presentation = null,
	): View {
		try {
			// Reject a presentation config that cannot render before touching the DB.
			$this->validatePresentationConfig(presentation: $presentation, query: $query);

			$view = $this->find(id: $id, owner: $owner);

			// If this is set as default, schema: unset any existing default for this user.
			if ($isDefault === true && $view->getIsDefault() === false) {
				$this->clearDefaultForUser(owner: $owner);
			}

			$view->setName($name);
			$view->setDescription($description);
			$view->setIsPublic($isPublic);
			$view->setIsDefault($isDefault);
			$view->setQuery($query);

			// Update favoredBy if provided.
			if ($favoredBy !== null) {
				$view->setFavoredBy($favoredBy);
			}

			// Update presentation if provided; a null presentation leaves the
			// existing stored value untouched (mirrors favoredBy's semantics)
			// so a PATCH that doesn't mention presentation can't silently wipe it.
			if ($presentation !== null) {
				$view->setPresentation($presentation);
			}

			return $this->viewMapper->update($view);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ViewService] Error updating view: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw $e;
		}//end try
	}//end update()

	/**
	 * Delete a view by ID.
	 *
	 * @param int|string $id The ID of the view to delete
	 * @param string $owner The owner user ID (for access control)
	 *
	 * @return void
	 *
	 * @throws Exception If deletion fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-8
	 */
	public function delete(int|string $id, string $owner): void {
		try {
			$view = $this->find(id: $id, owner: $owner);
			$this->viewMapper->delete($view);
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ViewService] Error deleting view: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw $e;
		}
	}//end delete()

	/**
	 * Clear default flag for all views of a user.
	 *
	 * @param string $owner The owner user ID
	 *
	 * @return void
	 */
	private function clearDefaultForUser(string $owner): void {
		$views = $this->viewMapper->findAll($owner);
		foreach ($views as $view) {
			if ($view->getOwner() === $owner && $view->getIsDefault() === true) {
				$view->setIsDefault(false);
				$this->viewMapper->update($view);
			}
		}
	}//end clearDefaultForUser()

	/**
	 * Validate a presentation config against the view's schema.
	 *
	 * A null presentation (or an explicit `viewType: table`) is always valid —
	 * `table` has no field to check. A `kanban` presentation must declare a
	 * `groupByField`, and a `calendar` presentation a `dateField` (with an
	 * optional `endDateField`); each declared field MUST be a real property
	 * on the view's (first configured) schema, or the save is rejected.
	 *
	 * @param array|null $presentation The presentation config to validate, or null
	 * @param array<string, mixed> $query The view's query (used to resolve its schema)
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the presentation cannot render
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	private function validatePresentationConfig(?array $presentation, array $query): void {
		if ($presentation === null) {
			return;
		}

		$viewType = $presentation['viewType'] ?? 'table';

		if ($viewType === 'table') {
			return;
		}

		if ($viewType === 'kanban') {
			$groupByField = $presentation['kanban']['groupByField'] ?? null;
			if (is_string($groupByField) === false || $groupByField === '') {
				throw new InvalidArgumentException('A kanban presentation requires a non-empty kanban.groupByField');
			}

			$this->assertFieldExistsOnViewSchema(field: $groupByField, query: $query, label: 'kanban.groupByField');
			return;
		}

		if ($viewType === 'calendar') {
			$dateField = $presentation['calendar']['dateField'] ?? null;
			if (is_string($dateField) === false || $dateField === '') {
				throw new InvalidArgumentException('A calendar presentation requires a non-empty calendar.dateField');
			}

			$this->assertFieldExistsOnViewSchema(field: $dateField, query: $query, label: 'calendar.dateField');

			$endDateField = $presentation['calendar']['endDateField'] ?? null;
			if ($endDateField !== null) {
				if (is_string($endDateField) === false || $endDateField === '') {
					throw new InvalidArgumentException('calendar.endDateField must be a non-empty string when provided');
				}

				$this->assertFieldExistsOnViewSchema(field: $endDateField, query: $query, label: 'calendar.endDateField');
			}

			return;
		}

		throw new InvalidArgumentException('Unknown presentation viewType: ' . (string)$viewType);
	}//end validatePresentationConfig()

	/**
	 * Assert that a field name is a real property on the view's (first) schema.
	 *
	 * Kanban/calendar are single-schema presentations (per design non-goals:
	 * no cross-register kanban), so the first schema referenced by the view's
	 * query is the one validated against.
	 *
	 * @param string $field The property name to check
	 * @param array<string, mixed> $query The view's query (holds `schemas`)
	 * @param string $label Human-readable label for the error message
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If no schema is configured, the schema can't be
	 *                                  resolved, or the field is not one of its properties
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	private function assertFieldExistsOnViewSchema(string $field, array $query, string $label): void {
		$schemaRef = $query['schemas'][0] ?? null;
		if ($schemaRef === null || $schemaRef === '') {
			throw new InvalidArgumentException(
				'Cannot validate ' . $label . ': the view has no schema configured to validate against'
			);
		}

		try {
			$schema = $this->schemaMapper->find($schemaRef);
		} catch (Exception $e) {
			throw new InvalidArgumentException(
				'Cannot validate ' . $label . ': schema "' . $schemaRef . '" could not be resolved',
				0,
				$e
			);
		}

		$properties = $schema->getProperties();
		if (array_key_exists($field, $properties) === false) {
			throw new InvalidArgumentException(
				'Presentation ' . $label . ' "' . $field . '" is not a property of the view\'s schema'
			);
		}
	}//end assertFieldExistsOnViewSchema()
}//end class
