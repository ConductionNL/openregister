<?php

/**
 * A node type that offers links from its own run-log entries.
 *
 * When a run is inspected, the entry a node wrote often points at something
 * elsewhere: an openconnector call has the source or the contract it used, an
 * agent step has the session it created. Only the app that ships the node knows
 * what those are or where they live, so the app that ships the node declares
 * them.
 *
 * DERIVED, NEVER STORED
 * ---------------------
 * The engine hands the log entry back and asks for links NOW, at display time.
 * An href frozen into a log at write time is a link that rots when the target
 * moves or the app's routes change — and a run log is kept for months, per the
 * flow's own `retentionDays`. Deriving also means a log written before an app
 * had a detail page gains the link the moment the page exists.
 *
 * The entry is the only input. A node MUST NOT need the run, the flow or a
 * database round-trip to answer: this is called once per entry while a modal is
 * rendering, and a query per row is how a log with fifty steps becomes fifty
 * queries.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\OpenRegister\Service\Flow
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://OpenRegister.app
 *
 * @spec openspec/specs/flow-engine/spec.md#requirement-a-node-type-declares-its-own-form-and-its-own-run-log-actions
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Flow;

/**
 * Declares the links a node's run-log entries earn.
 */
interface IFlowNodeLogActions
{
    /**
     * The links this log entry earns.
     *
     * `$entry` IS UNTRUSTED. `FlowController::logActions()` is `#[NoAdminRequired]`
     * and passes the caller's POST body through verbatim — it is not read back from
     * a stored run log, and OpenRegister cannot check it, because only the node that
     * wrote the entry knows what its fields mean. An implementer that resolves an id
     * out of `$entry` (a call log, a source, an agent session) MUST authorise that
     * read for the current user before returning a link to it. Returning a link to a
     * record the caller may not see is an IDOR whichever app the link points into,
     * and an href alone already discloses that the record exists.
     *
     * Each entry:
     *
     *   label string Translated — it is a link an operator reads.
     *   href  string Where it goes. Opened in a NEW TAB by the editor, which
     *                holds unsaved state: navigating away in place would throw
     *                away an author's in-progress flow to show them a record
     *                they wanted to glance at.
     *   icon  string Optional.
     *
     * An entry with nothing to point at MUST return an empty array rather than
     * a link to a list page. A link that goes somewhere unrelated is worse than
     * no link: it is followed once, and thereafter none of them are trusted.
     *
     * @param array<string, mixed> $entry One entry from the run's log.
     *
     * @return array<int, array{label: string, href: string, icon?: string}> The links.
     */
    public function logActions(array $entry): array;
}//end interface
