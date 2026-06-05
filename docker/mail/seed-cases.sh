#!/bin/bash
# seed-cases.sh — Seed Procest cases that match the emails from seed-mail.sh
#
# Usage:
#   bash seed-cases.sh [NEXTCLOUD_URL] [USER] [PASSWORD] [REGISTER_ID] [CASE_TYPE_SCHEMA] [CASE_SCHEMA]
# Defaults:
#   http://localhost:8080 admin admin
#
# ID resolution order:
#   1) CLI args 4-6
#   2) env vars REGISTER_ID / CASE_TYPE_SCHEMA / CASE_SCHEMA
#   3) Auto-discovery via API by identifier/title
#
# Script is idempotent: if an object with the same identifier exists, it reuses it.

set -euo pipefail

NC_URL="${1:-http://localhost:8080}"
NC_USER="${2:-admin}"
NC_PASS="${3:-admin}"
REGISTER_ID="${4:-${REGISTER_ID:-}}"
CASE_TYPE_SCHEMA="${5:-${CASE_TYPE_SCHEMA:-}}"
CASE_SCHEMA="${6:-${CASE_SCHEMA:-}}"

api_get() {
	local path="$1"
	curl -sS -u "${NC_USER}:${NC_PASS}" \
		-H "OCS-APIREQUEST: true" \
		"${NC_URL}/index.php${path}"
}

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
	python3 -c "import sys, json; print((json.loads(sys.stdin.read()) or {}).get('id', ''))"
}

first_result_id_for_identifier() {
	local identifier="$1"
	python3 -c '
import json, sys
identifier = sys.argv[1]
payload = json.loads(sys.stdin.read() or "{}")
results = payload.get("results", payload if isinstance(payload, list) else [])
for row in results:
	if str(row.get("identifier", "")) == identifier:
		print(row.get("id", ""))
		break
' "${identifier}"
}

discover_register_id() {
	api_get "/apps/openregister/api/registers?_limit=200" | python3 -c '
import json, sys
payload = json.loads(sys.stdin.read() or "{}")
results = payload.get("results", payload if isinstance(payload, list) else [])
for reg in results:
	identifier = str(reg.get("identifier", "")).lower()
	title = str(reg.get("title", "")).lower()
	if identifier == "procest" or title == "procest":
		print(reg.get("id", ""))
		break
'
}

discover_schema_id() {
	local needle="$1"
	api_get "/apps/openregister/api/schemas?_limit=300" | python3 -c '
import json, sys
needle = sys.argv[1].lower()
payload = json.loads(sys.stdin.read() or "{}")
results = payload.get("results", payload if isinstance(payload, list) else [])
for schema in results:
	identifier = str(schema.get("identifier", "")).lower()
	title = str(schema.get("title", "")).lower()
	if needle in (identifier, title):
		print(schema.get("id", ""))
		break
' "${needle}"
}

ensure_object() {
	local schema="$1"
	local identifier="$2"
	local payload="$3"
	local existing
	existing="$(api_get "/apps/openregister/api/objects/${REGISTER_ID}/${schema}?_search=${identifier}&_limit=50" | first_result_id_for_identifier "${identifier}")"
	if [[ -n "${existing}" ]]; then
		echo "${existing}"
		return
	fi
	api_post "${schema}" "${payload}" | extract_id
}

if [[ -z "${REGISTER_ID}" ]]; then
	REGISTER_ID="$(discover_register_id)"
fi
if [[ -z "${CASE_TYPE_SCHEMA}" ]]; then
	CASE_TYPE_SCHEMA="$(discover_schema_id "caseType")"
fi
if [[ -z "${CASE_SCHEMA}" ]]; then
	CASE_SCHEMA="$(discover_schema_id "case")"
fi

if [[ -z "${REGISTER_ID}" || -z "${CASE_TYPE_SCHEMA}" || -z "${CASE_SCHEMA}" ]]; then
	echo "Could not resolve register/schema IDs automatically."
	echo "Pass explicit IDs: seed-cases.sh <url> <user> <pass> <registerId> <caseTypeSchemaId> <caseSchemaId>"
	exit 1
fi

echo "=== Seeding Procest cases ==="
echo "Target: ${NC_URL} (user: ${NC_USER})"
echo "Register=${REGISTER_ID} caseTypeSchema=${CASE_TYPE_SCHEMA} caseSchema=${CASE_SCHEMA}"
echo ""

echo "--- Ensuring caseTypes ---"

OMG_ID="$(ensure_object "${CASE_TYPE_SCHEMA}" "OMG-001" '{
	"title": "Omgevingsvergunning",
	"description": "Aanvraag omgevingsvergunning voor bouw, verbouw of gebruikswijziging",
	"identifier": "OMG-001"
}')"
echo "  Ready caseType: Omgevingsvergunning (${OMG_ID})"

KAP_ID="$(ensure_object "${CASE_TYPE_SCHEMA}" "KAP-001" '{
	"title": "Kapvergunning",
	"description": "Aanvraag vergunning voor het kappen van bomen",
	"identifier": "KAP-001"
}')"
echo "  Ready caseType: Kapvergunning (${KAP_ID})"

echo ""
echo "--- Ensuring cases (zaken) ---"

CASE1_ID="$(ensure_object "${CASE_SCHEMA}" "ZK-2026-0142" "$(cat <<JSON
{
	"title": "Omgevingsvergunning dakkapel Kerkstraat 42",
	"description": "Aanvraag omgevingsvergunning voor het plaatsen van een dakkapel op het adres Kerkstraat 42, 5038 AB Tilburg. Ingediend door Jan de Vries (BSN 123456789). Welstandsadvies ingepland voor woensdag 26 maart 14:00.",
	"identifier": "ZK-2026-0142",
	"caseType": "${OMG_ID}",
	"startDate": "2026-03-17",
	"plannedEndDate": "2026-05-12"
}
JSON
)")"
echo "  Ready case: ZK-2026-0142 (${CASE1_ID})"

CASE2_ID="$(ensure_object "${CASE_SCHEMA}" "ZK-2026-0034" "$(cat <<JSON
{
	"title": "Kapvergunning Wilhelminastraat 17",
	"description": "Aanvraag kapvergunning ingediend door Priya Ganpat op 3 februari 2026. KLACHT: burger wacht al 6 weken op reactie, twee keer gebeld zonder resultaat. Boomdeskundige heeft positief advies gegeven. Besluit uiterlijk 28 maart.",
	"identifier": "ZK-2026-0034",
	"caseType": "${KAP_ID}",
	"startDate": "2026-02-03",
	"plannedEndDate": "2026-03-28"
}
JSON
)")"
echo "  Ready case: ZK-2026-0034 (${CASE2_ID})"

echo ""
echo "=== Procest cases ready ==="
