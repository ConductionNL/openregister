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

namespace OCA\OpenRegister\Search\Service;

use Exception;
use InvalidArgumentException;
use OCA\OpenRegister\Db\Schema;
use OCA\OpenRegister\Db\SchemaMapperInterface;
use OCA\OpenRegister\Search\Db\View;
use OCA\OpenRegister\Search\Db\ViewMapperInterface;
use OCA\OpenRegister\Service\Rbac\ViewerReach;
use OCA\OpenRegister\Service\View\ViewAlert;
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
	 * Constructor
	 *
	 * Initializes service with view mapper and logger for view operations.
	 *
	 * @param ViewMapperInterface $viewMapper View mapper for database operations
	 * @param LoggerInterface $logger Logger for error tracking
	 * @param SchemaMapperInterface $schemaMapper Schema mapper for presentation field validation
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ViewMapperInterface $viewMapper,
		private readonly LoggerInterface $logger,
		private readonly SchemaMapperInterface $schemaMapper,
	) {
	}//end __construct()

	/**
	 * A view by id, without judging who asks.
	 *
	 * For a caller that resolves access itself, such as the views controller
	 * through ViewerReachResolver::reaches().
	 *
	 * @param int|string $id The view id.
	 *
	 * @return View
	 *
	 * @throws DoesNotExistException When no view has this id.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function findById(int|string $id): View {
		return $this->viewMapper->find($id);
	}//end findById()

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
	 * Every view this caller may see, each carrying the access they hold.
	 *
	 * The union `view-group-share` adds to `findAll()`: the caller's own views,
	 * the views shared with a group they are in, and the public ones, each with
	 * `@self.access`. `findAll()` is left alone rather than widened, because it
	 * is called from paths that mean "the views this OWNER has" and silently
	 * turning that into "and everything shared with them" would change what
	 * those paths count.
	 *
	 * @param ViewerReach $reach The caller, their groups and whether they administer the instance.
	 *
	 * @return array The views.
	 *
	 * @spec openspec/specs/saved-search-views/spec.md
	 */
	public function findAllFor(ViewerReach $reach): array {
		return $this->viewMapper->findAllFor(reach: $reach);
	}//end findAllFor()

	/**
	 * Create a new view from one edit.
	 *
	 * Validates and stores the view. If the view is set as default, clears any existing default view for the owner first.
	 * Nobody favours a new view, whatever the edit says.
	 *
	 * @param ViewEdit $edit The definition, audience and count alert of the new view
	 *
	 * @return View The created view entity
	 *
	 * @throws Exception If view creation fails (database error, validation error, etc.)
	 * @throws InvalidArgumentException If the presentation config cannot render (REQ-VIEW-PRES-01)
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	public function create(ViewEdit $edit): View {
		try {
			return $this->viewMapper->insert($this->newViewFor(edit: $edit));
		} catch (Exception $e) {
			// Log error for debugging and monitoring.
			$this->logger->error(
				message: '[ViewService] Error creating view: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw $e;
		}
	}//end create()

	/**
	 * Validate an edit and build the unsaved view it creates, clearing the owner's old default when it is one.
	 *
	 * @param ViewEdit $edit The definition, audience and count alert of the new view
	 *
	 * @return View
	 *
	 * @throws InvalidArgumentException If the presentation config cannot render (REQ-VIEW-PRES-01)
	 */
	private function newViewFor(ViewEdit $edit): View {
		// Reject a presentation config that cannot render before touching the DB.
		$this->validatePresentationConfig(presentation: $edit->definition->presentation, query: $edit->definition->query);

		// Only one default view per user is allowed.
		if ($edit->audience->isDefault === true) {
			$this->clearDefaultForUser(owner: $edit->audience->owner);
		}

		$view = $this->newView(definition: $edit->definition, audience: $edit->audience);
		$this->applyAlert(view: $view, alert: $edit->alert);
		return $view;
	}//end newViewFor()

	/**
	 * A new, unsaved view entity carrying a definition and an audience; nobody favours it yet.
	 *
	 * @param ViewDefinition $definition The name, description, query and presentation.
	 * @param ViewAudience   $audience   The owner, public and default flags and the group shares.
	 *
	 * @return View
	 */
	private function newView(ViewDefinition $definition, ViewAudience $audience): View {
		$view = new View();
		$view->setName($definition->name);
		$view->setDescription($definition->description);
		$view->setOwner($audience->owner);
		$view->setIsPublic($audience->isPublic);
		$view->setIsDefault($audience->isDefault);
		$view->setQuery($definition->query);
		$view->setPresentation($definition->presentation);
		$view->setFavoredBy([]);
		$view->setSharedWith(array_values($audience->sharedWith ?? []));
		return $view;
	}//end newView()

	/**
	 * Apply one edit to an existing view the edit's owner may read.
	 *
	 * The name, description, query and the public and default flags are always written; a null
	 * presentation, sharedWith, favoredBy or alert leaves the stored value untouched, so a PATCH
	 * that does not mention it cannot silently wipe it.
	 *
	 * @param int|string $id   The ID of the view to update
	 * @param ViewEdit   $edit The new definition, audience, favourites and count alert
	 *
	 * @return View The updated view
	 *
	 * @throws Exception If update fails
	 * @throws InvalidArgumentException If the presentation config cannot render (REQ-VIEW-PRES-01)
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	public function revise(int|string $id, ViewEdit $edit): View {
		try {
			return $this->viewMapper->update($this->revised(id: $id, edit: $edit));
		} catch (Exception $e) {
			$this->logger->error(
				message: '[ViewService] Error updating view: ' . $e->getMessage(),
				context: ['file' => __FILE__, 'line' => __LINE__]
			);
			throw $e;
		}
	}//end revise()

	/**
	 * Validate an edit, load the view and apply the edit to it, unsaved.
	 *
	 * @param int|string $id   The ID of the view to update
	 * @param ViewEdit   $edit The edit
	 *
	 * @return View
	 *
	 * @throws DoesNotExistException If the view is not there or the owner may not read it
	 * @throws InvalidArgumentException If the presentation config cannot render (REQ-VIEW-PRES-01)
	 */
	private function revised(int|string $id, ViewEdit $edit): View {
		// Reject a presentation config that cannot render before touching the DB.
		$this->validatePresentationConfig(presentation: $edit->definition->presentation, query: $edit->definition->query);

		$view = $this->find(id: $id, owner: $edit->audience->owner);

		// If this is set as default, unset any existing default for this user.
		if ($edit->audience->isDefault === true && $view->getIsDefault() === false) {
			$this->clearDefaultForUser(owner: $edit->audience->owner);
		}

		$this->reviseDefinition(view: $view, definition: $edit->definition);
		$this->reviseAudience(view: $view, edit: $edit);
		$this->applyAlert(view: $view, alert: $edit->alert);
		return $view;
	}//end revised()

	/**
	 * Write a definition onto a stored view; a null presentation leaves the stored one.
	 *
	 * @param View           $view       The stored view.
	 * @param ViewDefinition $definition The new definition.
	 *
	 * @return void
	 */
	private function reviseDefinition(View $view, ViewDefinition $definition): void {
		$view->setName($definition->name);
		$view->setDescription($definition->description);
		$view->setQuery($definition->query);
		if ($definition->presentation !== null) {
			$view->setPresentation($definition->presentation);
		}
	}//end reviseDefinition()

	/**
	 * Write the flags, group shares and favourites of an edit onto a stored view.
	 *
	 * The owner is not written: it only selects the view. A null sharedWith or favoredBy
	 * leaves the stored value; the group shares are validated by the caller.
	 *
	 * @param View     $view The stored view.
	 * @param ViewEdit $edit The edit.
	 *
	 * @return void
	 */
	private function reviseAudience(View $view, ViewEdit $edit): void {
		$view->setIsPublic($edit->audience->isPublic);
		$view->setIsDefault($edit->audience->isDefault);
		if ($edit->audience->sharedWith !== null) {
			$view->setSharedWith(array_values($edit->audience->sharedWith));
		}

		if ($edit->favoredBy !== null) {
			$view->setFavoredBy($edit->favoredBy);
		}
	}//end reviseAudience()


	/**
	 * Delete a view by ID.
	 *
	 * Only the owner or an administrator deletes. `find()` is not the guard
	 * here: it also lets a reader of a public view through.
	 *
	 * @param int|string  $id    The ID of the view to delete
	 * @param ViewerReach $reach The caller and whether they administer the instance
	 *
	 * @return void
	 *
	 * @throws DoesNotExistException When the caller neither owns nor administers the view
	 * @throws Exception If deletion fails
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-b-svc-urn-sec-edepot-view/tasks.md#task-8
	 * @spec openspec/changes/archive/2026-10-30-saved-view-delete-owner-only/tasks.md#task-1
	 */
	public function delete(int|string $id, ViewerReach $reach): void {
		try {
			$view = $this->viewMapper->find($id);
			if ($view->getOwner() !== $reach->userId && $reach->isAdmin === false) {
				throw new DoesNotExistException('View not found or access denied');
			}

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
	 * Store a declared count alert on a view, in its canonical form.
	 *
	 * Null leaves the alert as it was, so a save that does not mention it
	 * cannot drop it. An empty array clears it. A declaration is read through
	 * ViewAlert::parse(), which refuses a malformed one naming its field.
	 *
	 * The alert's state restarts only when the declaration CHANGES. The edit
	 * screen sends the whole view on every save; re-arming a fired alert each
	 * time would page its recipients again about a backlog they already heard
	 * of. A changed line is a different alert, and the old state belongs to
	 * the old one.
	 *
	 * @param View $view The view being saved.
	 * @param array|null $alert The declared alert, `[]` to clear, or null to leave it.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the declared alert does not read.
	 *
	 * @spec openspec/changes/saved-view-count-alert/specs/saved-search-views/spec.md#requirement-a-view-may-declare-a-count-alert
	 */
	private function applyAlert(View $view, ?array $alert): void {
		if ($alert === null) {
			return;
		}

		$canonical = ViewAlert::parse(raw: $alert)?->jsonSerialize();
		if ($canonical === $view->getAlert()) {
			return;
		}

		$view->setAlert($canonical);
		$view->setAlertState(null);
		$view->setAlertEvaluatedAt(null);
	}//end applyAlert()

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

		$check = new PresentationCheck(presentation: $presentation, query: $query);
		$viewType = $presentation['viewType'] ?? 'table';

		match ($viewType) {
			'table' => null,
			'kanban' => $this->assertRequiredField(check: $check, path: 'kanban.groupByField'),
			'calendar' => $this->validateCalendarPresentation(check: $check),
			default => throw new InvalidArgumentException('Unknown presentation viewType: ' . (string)$viewType),
		};
	}//end validatePresentationConfig()

	/**
	 * A calendar presentation must declare a dateField, and may declare an
	 * endDateField, each on the view's schema.
	 *
	 * @param PresentationCheck $check The presentation and the view's query
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the presentation cannot render
	 */
	private function validateCalendarPresentation(PresentationCheck $check): void {
		$this->assertRequiredField(check: $check, path: 'calendar.dateField');

		$endDateField = $check->declared(path: 'calendar.endDateField');
		if ($endDateField === null) {
			return;
		}

		if (is_string($endDateField) === false || $endDateField === '') {
			throw new InvalidArgumentException('calendar.endDateField must be a non-empty string when provided');
		}

		$this->assertFieldExistsOnViewSchema(check: $check, path: 'calendar.endDateField');
	}//end validateCalendarPresentation()

	/**
	 * A required presentation field (kanban.groupByField, calendar.dateField) must be declared and on the view's schema.
	 *
	 * @param PresentationCheck $check The presentation and the view's query
	 * @param string            $path  The field's dotted path, also its label in the error message
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If the field is missing, empty or not on the schema
	 */
	private function assertRequiredField(PresentationCheck $check, string $path): void {
		$field = $check->declared(path: $path);
		if (is_string($field) === false || $field === '') {
			[$viewType] = explode('.', $path, 2);
			throw new InvalidArgumentException('A ' . $viewType . ' presentation requires a non-empty ' . $path);
		}

		$this->assertFieldExistsOnViewSchema(check: $check, path: $path);
	}//end assertRequiredField()

	/**
	 * Assert that the field a presentation declares at a path is a real property on the view's (first) schema.
	 *
	 * Kanban/calendar are single-schema presentations (per design non-goals:
	 * no cross-register kanban), so the first schema referenced by the view's
	 * query is the one validated against.
	 *
	 * @param PresentationCheck $check The presentation and the view's query (holds `schemas`)
	 * @param string            $path  The field's dotted path, also its label in the error message
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException If no schema is configured, the schema can't be
	 *                                  resolved, or the field is not one of its properties
	 *
	 * @spec openspec/specs/saved-search-views/spec.md#requirement-views-persist-a-validated-presentation-config-req-view-pres-01
	 */
	private function assertFieldExistsOnViewSchema(PresentationCheck $check, string $path): void {
		$field = $check->declared(path: $path);
		$properties = $this->viewSchema(check: $check, label: $path)->getProperties();
		if (array_key_exists($field, $properties) === false) {
			throw new InvalidArgumentException(
				'Presentation ' . $path . ' "' . $field . '" is not a property of the view\'s schema'
			);
		}
	}//end assertFieldExistsOnViewSchema()

	/**
	 * The view's (first) schema, resolved.
	 *
	 * @param PresentationCheck $check The presentation and the view's query (holds `schemas`)
	 * @param string            $label Human-readable label for the error message
	 *
	 * @return Schema
	 *
	 * @throws InvalidArgumentException If no schema is configured or the schema can't be resolved
	 */
	private function viewSchema(PresentationCheck $check, string $label): Schema {
		$schemaRef = $check->schemaRef();
		if ($schemaRef === null || $schemaRef === '') {
			throw new InvalidArgumentException(
				'Cannot validate ' . $label . ': the view has no schema configured to validate against'
			);
		}

		try {
			return $this->schemaMapper->find($schemaRef);
		} catch (Exception $e) {
			throw new InvalidArgumentException(
				'Cannot validate ' . $label . ': schema "' . $schemaRef . '" could not be resolved',
				0,
				$e
			);
		}
	}//end viewSchema()
}//end class
