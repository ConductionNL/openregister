#!/usr/bin/env bash
#
# Seed the accounts the CI Playwright subset needs.
#
# SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
# SPDX-License-Identifier: EUPL-1.2
#
# WHY THIS IS A SCRIPT AND NOT AN INLINE COMMAND.
#
# The shared quality workflow runs the seed through
# `eval ${{ inputs.playwright-seed-command }}` — UNQUOTED. The substitution is
# textual, so the OUTER bash parses whatever the value expands to, and any shell
# metacharacter in it is parsed at the wrong level:
#
#   `( … ) && ( … )`  -> syntax error near unexpected token `OC_PASS=…`,
#                        and the seed silently never ran at all.
#   `sh -c '… ; …'`   -> no error, but the `;` still split at the OUTER level, so
#                        half the command ran inside `sh -c` and half did not.
#
# A single `bash <path>` word has no metacharacters, so eval cannot mis-parse it,
# and every shell operator lives here where it is parsed exactly once.
#
# Invoked from `server/`, which is why the occ path is relative to that.

set -euo pipefail

PASSWORD='E2e-Share-Pass-123'

# Two accounts, and both non-admin. Sharing an object to yourself proves nothing,
# and task 4.0 of object-level-sharing-and-private-scope is specifically that an
# OWNER who is not an administrator can set their own object's scope.
for uid in e2e-owner e2e-other; do
	# Idempotent: the accounts persist across re-runs on the same instance, so an
	# "already exists" is success, not failure. The specs assert each account can
	# actually authenticate rather than trusting this, so a seed that genuinely
	# failed still fails the run — loudly, and naming the account.
	if OC_PASS="$PASSWORD" php occ user:add --password-from-env "$uid"; then
		echo "seeded $uid"
	else
		echo "$uid already present (or could not be created) — the specs will verify"
	fi
done

# A group with a member, for the GROUP grant path.
#
# Not decoration: a group grant is the case that was silently broken. The tab
# posted `shareType: 0|1`, a key ObjectSharingController::createShare() does not
# read, so it fell through to the 'user' default and picking "Group" created a
# USER grant to a uid spelled like the group. A user grant worked by coincidence,
# which is why nothing caught it (nextcloud-vue#591).
#
# The membership is what makes the assertion meaningful: access is checked as
# e2e-other, who gains it only through the group.
GROUP='e2e-grantees'

if php occ group:add "$GROUP"; then
	echo "seeded group $GROUP"
else
	echo "group $GROUP already present (or could not be created) — the specs will verify"
fi

if php occ group:adduser "$GROUP" e2e-other; then
	echo "added e2e-other to $GROUP"
else
	echo "e2e-other already in $GROUP (or could not be added) — the specs will verify"
fi

echo 'e2e seed complete'
