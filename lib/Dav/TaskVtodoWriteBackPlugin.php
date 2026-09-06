<?php

/**
 * The in-band write-back hook: a Sabre server plugin that refuses an
 * unauthorized calendar edit BEFORE the client records it.
 *
 * Declared in appinfo/info.xml under `<sabre><plugins><plugin>` and loaded
 * by apps/dav's PluginManager. It acts only on VTODOs carrying
 * `X-OPENREGISTER-TASK`; every other calendar object passes untouched. What
 * it does with a projected VTODO is hand the document to the ONE gate: an
 * accepted verb advances the engine and the stored document becomes the
 * engine's fresh rendering; a refusal becomes a DAV 403, so there is no
 * window in which the user's client believes the task is done
 * (flow-task-inbox-projections, design D-6).
 *
 * Creating a VTODO that carries a task identity through DAV is refused: only
 * the projector creates projected VTODOs, so a VTODO carrying an engine task
 * identity always corresponds to a task the engine authorized into
 * existence. Deleting one is refused too, with the reason: a task is not
 * cancelled by removing the reminder of it.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Dav
 * @package  OCA\OpenRegister\Dav
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Dav;

use OCA\OpenRegister\Exception\TaskAccessDeniedException;
use OCA\OpenRegister\Exception\TaskConflictException;
use OCA\OpenRegister\Exception\TaskValidationException;
use OCA\OpenRegister\Service\Task\TaskVtodoWriteBackGate;
use OCP\AppFramework\Db\DoesNotExistException;
use Psr\Log\LoggerInterface;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\ICollection;
use Sabre\DAV\INode;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Throwable;

/**
 * Sabre plugin: refuse in-band, or store the engine's rendering.
 *
 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
 */
class TaskVtodoWriteBackPlugin extends ServerPlugin {

	/**
	 * The DAV path prefix calendar objects live under.
	 */
	private const CALENDAR_PREFIX = 'calendars/';

	/**
	 * The principal prefix a user's DAV identity carries.
	 */
	private const PRINCIPAL_PREFIX = 'principals/users/';

	/**
	 * The Sabre server, once initialised.
	 *
	 * @var Server|null
	 */
	private ?Server $server = null;

	/**
	 * Constructor.
	 *
	 * @param TaskVtodoWriteBackGate $gate The one gate.
	 * @param LoggerInterface $logger Failure reporting.
	 */
	public function __construct(
		private readonly TaskVtodoWriteBackGate $gate,
		private readonly LoggerInterface $logger,
	) {

	}//end __construct()

	/**
	 * Subscribe to the write hooks.
	 *
	 * Priority 90: after Sabre's own CalDAV validation (which runs at 100
	 * and normalises the document), before the write reaches the backend.
	 *
	 * @param Server $server The Sabre server.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function initialize(Server $server): void {
		$this->server = $server;
		$server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 90);
		$server->on('beforeCreateFile', [$this, 'beforeCreateFile'], 90);
		$server->on('beforeUnbind', [$this, 'beforeUnbind'], 90);
	}//end initialize()

	/**
	 * The plugin's name, for Sabre's plugin registry.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function getPluginName(): string {
		return 'openregister-task-write-back';
	}//end getPluginName()

	/**
	 * An existing calendar object is being overwritten.
	 *
	 * @param string $path The DAV path.
	 * @param INode $node The node being written.
	 * @param resource|string|null $data The incoming body, by reference.
	 * @param bool $modified Set to true when the body is replaced, by reference.
	 *
	 * @return void
	 *
	 * @throws Forbidden When the gate refuses.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The node is Sabre's hook signature.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	public function beforeWriteContent(string $path, INode $node, &$data, &$modified): void {
		if ($this->isCalendarPath(path: $path) === false) {
			return;
		}

		$body = $this->bodyOf(data: $data);
		if ($body === null || $this->gate->isProjected(calendarData: $body) === false) {
			return;
		}

		$replacement = $this->guard(
			call: fn (): ?string => $this->gate->handleWrite(calendarData: $body, actor: $this->actor())
		);

		if ($replacement !== null) {
			$data = $replacement;
			$modified = true;
		}
	}//end beforeWriteContent()

	/**
	 * A new calendar object is being created.
	 *
	 * @param string $path The DAV path.
	 * @param resource|string|null $data The incoming body, by reference.
	 * @param ICollection $parent The calendar.
	 * @param bool $modified Unused: a refusal replaces nothing.
	 *
	 * @return void
	 *
	 * @throws Forbidden When the body forges a task identity.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The parent and flag are Sabre's hook signature.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
	 */
	public function beforeCreateFile(string $path, &$data, ICollection $parent, &$modified): void {
		if ($this->isCalendarPath(path: $path) === false) {
			return;
		}

		$body = $this->bodyOf(data: $data);
		if ($body === null || $this->gate->isProjected(calendarData: $body) === false) {
			return;
		}

		$this->logger->warning(
			'[TaskVtodoWriteBackPlugin] Refused a calendar object that forges an engine task identity.',
			['path' => $path, 'actor' => $this->actor()]
		);

		throw new Forbidden('A calendar entry cannot create an engine task. Only OpenRegister writes task entries into a calendar.');
	}//end beforeCreateFile()

	/**
	 * A calendar object is being deleted.
	 *
	 * @param string $path The DAV path.
	 *
	 * @return void
	 *
	 * @throws Forbidden When the object is a projected VTODO.
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/object-interactions/spec.md#requirement-tasks-on-objects-via-caldav-vtodo
	 */
	public function beforeUnbind(string $path): void {
		if ($this->isCalendarPath(path: $path) === false || $this->server === null) {
			return;
		}

		try {
			$node = $this->server->tree->getNodeForPath($path);
		} catch (Throwable) {
			return;
		}

		if (method_exists($node, 'get') === false) {
			return;
		}

		$body = $this->bodyOf(data: $node->get());
		if ($body === null || $this->gate->isProjected(calendarData: $body) === false) {
			return;
		}

		throw new Forbidden('This entry is an OpenRegister task. Complete or cancel the task instead of deleting its calendar entry.');
	}//end beforeUnbind()

	/**
	 * Run the gate, translating every refusal into a DAV 403 that names the reason.
	 *
	 * @param callable(): ?string $call The gate call.
	 *
	 * @return string|null The gate's replacement document, when any.
	 *
	 * @throws Forbidden On every refusal, including an unknown task and an unexpected failure (fail closed).
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-an-unauthorized-write-back-is-undone-and-explained
	 */
	private function guard(callable $call): ?string {
		try {
			return $call();
		} catch (TaskAccessDeniedException | TaskConflictException | TaskValidationException $refused) {
			throw new Forbidden('OpenRegister refused this change: ' . $refused->getMessage(), 0, $refused);
		} catch (DoesNotExistException $missing) {
			throw new Forbidden('OpenRegister refused this change: the entry names a task that does not exist.', 0, $missing);
		} catch (Throwable $failure) {
			$this->logger->error(
				'[TaskVtodoWriteBackPlugin] Gate failure; the write is refused rather than applied unchecked: ' . $failure->getMessage(),
				['exception' => $failure]
			);
			throw new Forbidden('OpenRegister could not verify this change, so it was not applied.', 0, $failure);
		}//end try
	}//end guard()

	/**
	 * The acting uid, from the authenticated DAV principal.
	 *
	 * @return string|null The uid, or null when there is none (which the gate denies).
	 *
	 * @spec openspec/changes/flow-task-inbox-projections/specs/flow-task-projections/spec.md#requirement-ticking-off-the-vtodo-completes-the-engine-task-through-authorization
	 */
	private function actor(): ?string {
		if ($this->server === null) {
			return null;
		}

		try {
			$auth = $this->server->getPlugin('auth');
			if ($auth === null || method_exists($auth, 'getCurrentPrincipal') === false) {
				return null;
			}

			$principal = (string)$auth->getCurrentPrincipal();
		} catch (Throwable) {
			return null;
		}

		if (str_starts_with($principal, self::PRINCIPAL_PREFIX) === false) {
			return null;
		}

		$uid = substr($principal, strlen(self::PRINCIPAL_PREFIX));
		if ($uid === '') {
			return null;
		}

		return $uid;
	}//end actor()

	/**
	 * The body as a string, whatever Sabre handed over.
	 *
	 * @param mixed $data A string, a stream, or null.
	 *
	 * @return string|null The body, or null when unreadable.
	 */
	private function bodyOf(mixed $data): ?string {
		if (is_string($data) === true) {
			return $data;
		}

		if (is_resource($data) === true) {
			$contents = stream_get_contents($data);
			rewind($data);
			if ($contents === false) {
				return null;
			}

			return $contents;
		}

		return null;
	}//end bodyOf()

	/**
	 * Whether a DAV path addresses a calendar object.
	 *
	 * @param string $path The DAV path.
	 *
	 * @return bool True under `calendars/`.
	 */
	private function isCalendarPath(string $path): bool {
		return str_starts_with(ltrim($path, '/'), self::CALENDAR_PREFIX);
	}//end isCalendarPath()
}//end class
