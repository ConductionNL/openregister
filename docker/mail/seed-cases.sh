#!/bin/bash
# seed-cases.sh — Seed Procest cases that match the emails from seed-mail.sh
#
# Usage: bash seed-cases.sh [NEXTCLOUD_URL] [USER] [PASSWORD]
# Defaults: http://localhost:8080 admin admin
#
# Creates OpenRegister objects in the Procest register (id=92):
#   - 2 caseTypes: Omgevingsvergunning, Kapvergunning
#   - 2 cases: ZK-2026-0142 (dakkapel Kerkstraat 42), ZK-2026-0034 (kapvergunning Wilhelminastraat)
#
# These cases are the counterparts of the emails seeded by seed-mail.sh, so the
# OpenRegister mail sidebar can demo linking emails to existing cases.
#
# Prerequisites:
#   - OpenRegister app installed and enabled
#   - Procest app installed and repair step executed (creates register 92 + case schema 204)
#   - Run seed-mail.sh first if you want the matching emails in Greenmail

set -euo pipefail

NC_URL="${1:-http://localhost:8080}"
NC_USER="${2:-admin}"
NC_PASS="${3:-admin}"

REGISTER_ID=92          # Procest register
CASE_TYPE_SCHEMA=197    # caseType schema
CASE_SCHEMA=204         # case schema

api_post() {
    local schema="$1"
    local payload="$2"
    curl -sS -u "${NC_USER}:${NC_PASS}" \
         -X POST "${NC_URL}/index.php/apps/openregister/api/objects/${REGISTER_ID}/${schema}" \
         -H "Content-Type: application/json" \
         -H "OCS-APIREQUEST: true" \
         -d "${payload}"
}

extract_id() {
    python3 -c "import sys, json; print(json.loads(sys.stdin.read())['id'])"
}

echo "=== Seeding Procest cases ==="
echo "Target: ${NC_URL} (user: ${NC_USER})"
echo ""

echo "--- Creating caseTypes ---"

OMG_ID=$(api_post "${CASE_TYPE_SCHEMA}" '{
    "title": "Omgevingsvergunning",
    "description": "Aanvraag omgevingsvergunning voor bouw, verbouw of gebruikswijziging",
    "identifier": "OMG-001"
}' | extract_id)
echo "  Created caseType: Omgevingsvergunning (${OMG_ID})"

KAP_ID=$(api_post "${CASE_TYPE_SCHEMA}" '{
    "title": "Kapvergunning",
    "description": "Aanvraag vergunning voor het kappen van bomen",
    "identifier": "KAP-001"
}' | extract_id)
echo "  Created caseType: Kapvergunning (${KAP_ID})"

echo ""
echo "--- Creating cases (zaken) ---"

# Case 1: matches email #26 (Aanvraag omgevingsvergunning - Kerkstraat 42)
# and email #27 (RE: Adviesaanvraag welstandscommissie - ZK-2026-0142)
CASE1_ID=$(api_post "${CASE_SCHEMA}" "$(cat <<JSON
{
    "title": "Omgevingsvergunning dakkapel Kerkstraat 42",
    "description": "Aanvraag omgevingsvergunning voor het plaatsen van een dakkapel op het adres Kerkstraat 42, 5038 AB Tilburg. Ingediend door Jan de Vries (BSN 123456789). Welstandsadvies ingepland voor woensdag 26 maart 14:00.",
    "identifier": "ZK-2026-0142",
    "caseType": "${OMG_ID}",
    "startDate": "2026-03-17",
    "plannedEndDate": "2026-05-12"
}
JSON
)" | extract_id)
echo "  Created case: ZK-2026-0142 (${CASE1_ID})"

# Case 2: matches email #30 (URGENT: Klacht kapvergunning ZK-2026-0034)
CASE2_ID=$(api_post "${CASE_SCHEMA}" "$(cat <<JSON
{
    "title": "Kapvergunning Wilhelminastraat 17",
    "description": "Aanvraag kapvergunning ingediend door Priya Ganpat op 3 februari 2026. KLACHT: burger wacht al 6 weken op reactie, twee keer gebeld zonder resultaat. Boomdeskundige heeft positief advies gegeven. Besluit uiterlijk 28 maart.",
    "identifier": "ZK-2026-0034",
    "caseType": "${KAP_ID}",
    "startDate": "2026-02-03",
    "plannedEndDate": "2026-03-28"
}
JSON
)" | extract_id)
echo "  Created case: ZK-2026-0034 (${CASE2_ID})"

echo ""
echo "=== Procest cases seeded ==="
echo ""
echo "Open in the UI:"
echo "  ${NC_URL}/index.php/apps/openregister/registers/${REGISTER_ID}"
echo ""
echo "Mail sidebar demo: open an email in ${NC_URL}/index.php/apps/mail/"
echo "and the OpenRegister sidebar should offer these cases as link targets."
echo ""
echo "TODO:"
echo "  - Decidesk meetings (mail #31 Weekplanning) — blocked by OpenRegister"
echo "    ConfigurationService 'dirty table reads' bug; decidesk register not"
echo "    initialized. Fix that, then add meeting seeding here."
echo "  - Pipelinq leads (mail #28 Offerte, #32 Tech docs) — pipelinq register"
echo "    only has agentProfile schema attached; lead/client schemas not wired"
echo "    to register 228 yet."
