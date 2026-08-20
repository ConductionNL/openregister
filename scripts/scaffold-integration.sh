#!/usr/bin/env bash
#
# scaffold-integration.sh <id> [<Label>]
#
# Generates the skeleton for a new integration leaf change under
# openspec/changes/integration-<id>/. Part of the pluggable
# integration registry umbrella (see
# docs/Integrations/pluggable-integration-registry.md).
#
# What it creates:
#   openspec/changes/integration-<id>/proposal.md
#   openspec/changes/integration-<id>/tasks.md
#   openspec/changes/integration-<id>/hydra.json   (depends_on the umbrella)
#   openspec/changes/integration-<id>/stubs/<Id>Provider.php   (PHP provider stub)
#   openspec/changes/integration-<id>/stubs/<id>.js            (JS registration stub)
#
# The stubs/ files are starting points — move the PHP provider into
# the appropriate lib/Service/Integration/ location (or the consuming
# app), and the JS registration into the consuming app's bootstrap.
#
# Usage:
#   scripts/scaffold-integration.sh contacts
#   scripts/scaffold-integration.sh contacts "Contacts"

set -euo pipefail

if [ "${1:-}" = "" ]; then
  echo "usage: $0 <id> [<Label>]" >&2
  echo "  e.g. $0 contacts \"Contacts\"" >&2
  exit 2
fi

ID="$1"
# Validate the id: kebab-case identifier (matches the registry's
# isValidIntegrationId() and the PHP provider ids).
if ! printf '%s' "$ID" | grep -qE '^[a-z][a-z0-9-]*$'; then
  echo "error: id must be a lowercase kebab-case identifier (got: '$ID')" >&2
  exit 2
fi

# PascalCase for the PHP class name: contacts -> Contacts, audit-trail -> AuditTrail
PASCAL="$(printf '%s' "$ID" | awk -F- '{ for (i=1;i<=NF;i++) printf "%s%s", toupper(substr($i,1,1)), substr($i,2) }')"
LABEL="${2:-$PASCAL}"

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DIR="$ROOT/openspec/changes/integration-$ID"
STUBS="$DIR/stubs"

if [ -e "$DIR" ]; then
  echo "error: $DIR already exists — refusing to overwrite" >&2
  exit 1
fi

mkdir -p "$STUBS"

cat > "$DIR/proposal.md" <<EOF
# Integration: $LABEL

## Why

<!-- One paragraph: what does the "$LABEL" integration give object owners,
     and why is it a registry leaf rather than core? -->

## What

A new \`IntegrationProvider\` (\`id: $ID\`) plus its \`@conduction/nextcloud-vue\`
sidebar tab and widget, registered on \`window.OCA.OpenRegister.integrations\`.
Storage strategy: <!-- magic-column | link-table | external | query-time -->.

## Leaf plan

Depends on the \`pluggable-integration-registry\` umbrella (the contract +
registry + parity gate). This change stays within the ADR-028 15-task cap.
EOF

cat > "$DIR/tasks.md" <<EOF
# Tasks: Integration — $LABEL

## Backend

- [ ] Create \`${PASCAL}Provider\` extending \`AbstractIntegrationProvider\` (id \`$ID\`, label \`$LABEL\`, group, requiredApp, storage strategy, \`isEnabled()\`, \`list()\` and any of get/create/update/delete needed)
- [ ] Register the provider at app bootstrap via \`IntegrationRegistry::addProvider()\`
- [ ] (external only) declare \`getOpenConnectorSource()\` + \`authRequirements()\`; verify \`ExternalIntegrationRouter\` routing + \`probe()\`
- [ ] Unit test: \`${PASCAL}ProviderTest\` — happy path, error handling, edge case

## Frontend (\`@conduction/nextcloud-vue\` or the consuming app)

- [ ] \`Cn${PASCAL}Tab.vue\` — sidebar tab (props: register/schema/objectId/apiBase)
- [ ] \`Cn${PASCAL}Card.vue\` — widget (receives \`:surface\`); MVP may shell the tab's data
- [ ] Register on \`window.OCA.OpenRegister.integrations\` with \`tab\` + \`widget\` (REQUIRED) and \`referenceType: '$ID'\`
- [ ] Component test: mount + render the tab and the widget
- [ ] \`npm run check:integration-parity\` passes

## Docs

- [ ] Add a short section to \`docs/Integrations/$ID.md\` (or extend the developer guide)
EOF

cat > "$DIR/hydra.json" <<EOF
{
  "schema": "spec-driven",
  "depends_on": ["pluggable-integration-registry"]
}
EOF

cat > "$STUBS/${PASCAL}Provider.php" <<EOF
<?php

declare(strict_types=1);

namespace OCA\\OpenRegister\\Service\\Integration\\BuiltinProviders;

use OCA\\OpenRegister\\Service\\Integration\\AbstractIntegrationProvider;

/**
 * ${PASCAL}Provider — "$LABEL" integration (scaffolded stub).
 *
 * Move this into the appropriate location, fill in the data-access
 * methods, and register it at app bootstrap via
 * IntegrationRegistry::addProvider().
 *
 * @license EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */
final class ${PASCAL}Provider extends AbstractIntegrationProvider
{
    public function getId(): string
    {
        return '$ID';
    }//end getId()

    public function getLabel(): string
    {
        // TODO: localise via \$this->l10n->t('$LABEL') once a constructor is wired.
        return '$LABEL';
    }//end getLabel()

    public function getIcon(): string
    {
        // TODO: pick an MDI icon name.
        return 'Puzzle';
    }//end getIcon()

    public function getGroup(): ?string
    {
        // TODO: one of core|comms|docs|workflow|external.
        return null;
    }//end getGroup()

    public function getRequiredApp(): ?string
    {
        // TODO: the Nextcloud app id this integration needs, or null.
        return null;
    }//end getRequiredApp()

    public function getStorageStrategy(): string
    {
        // TODO: magic-column | link-table | external | query-time.
        return 'link-table';
    }//end getStorageStrategy()

    public function isEnabled(): bool
    {
        // TODO: check the required app / configuration.
        return true;
    }//end isEnabled()

    public function list(string \$register, string \$schema, string \$objectId, array \$filters = []): array
    {
        // TODO: return the linked entities for this object.
        return [];
    }//end list()

}//end class
EOF

cat > "$STUBS/${ID}.js" <<EOF
// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

/**
 * Built-in registration stub for the "$LABEL" integration ($ID).
 *
 * Move this into the consuming app's bootstrap (or, for a registry
 * built-in, into @conduction/nextcloud-vue's src/integrations/builtin/).
 * Both \`tab\` and \`widget\` are REQUIRED (AD-11/AD-13).
 */

import { translate as t } from '@nextcloud/l10n'
// import Cn${PASCAL}Tab from '.../Cn${PASCAL}Tab.vue'
// import Cn${PASCAL}Card from '.../Cn${PASCAL}Card.vue'

export const ${ID//-/}Integration = {
	id: '$ID',
	label: t('nextcloud-vue', '$LABEL'),
	icon: 'Puzzle',                 // TODO: MDI name
	requiredApp: null,              // TODO: NC app id or null
	order: 100,
	group: null,                    // TODO: core|comms|docs|workflow|external
	referenceType: '$ID',
	// tab: Cn${PASCAL}Tab,         // REQUIRED
	// widget: Cn${PASCAL}Card,     // REQUIRED — receives :surface
	defaultSize: { w: 3, h: 3 },
}

// To register at bootstrap:
//   window.OCA.OpenRegister.integrations.register(${ID//-/}Integration)
EOF

echo "✓ scaffolded openspec/changes/integration-$ID/"
echo "  - proposal.md, tasks.md, hydra.json (depends_on: pluggable-integration-registry)"
echo "  - stubs/${PASCAL}Provider.php, stubs/${ID}.js"
echo ""
echo "Next: flesh out the stubs, move them into place, and run"
echo "  openspec validate integration-$ID --strict"
