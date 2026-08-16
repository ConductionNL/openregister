#!/bin/bash
# seed-pim.sh — Seed contacts and calendar events into Nextcloud via DAV APIs
#
# Usage: bash seed-pim.sh [NC_URL] [NC_USER] [NC_PASS]
# Defaults: http://localhost:8080 admin admin
#
# Creates test contacts (CardDAV) and calendar events (CalDAV) for development.

NC_URL="${1:-http://localhost:8080}"
NC_USER="${2:-admin}"
NC_PASS="${3:-admin}"

DAV_URL="$NC_URL/remote.php/dav"

echo "=== Seeding Nextcloud PIM data ==="
echo "URL: $NC_URL, User: $NC_USER"
echo ""

# Helper: create a contact via CardDAV
create_contact() {
    local uid="$1"
    local vcard="$2"

    local status
    status=$(curl -s -o /dev/null -w "%{http_code}" \
        -u "$NC_USER:$NC_PASS" \
        -X PUT \
        -H "Content-Type: text/vcard; charset=utf-8" \
        -d "$vcard" \
        "$DAV_URL/addressbooks/users/$NC_USER/contacts/$uid.vcf")

    if [ "$status" = "201" ] || [ "$status" = "204" ]; then
        echo "  Created contact: $uid"
    else
        echo "  Contact $uid: HTTP $status (may already exist)"
    fi
}

# Helper: create a calendar event via CalDAV
create_event() {
    local uid="$1"
    local ical="$2"

    local status
    status=$(curl -s -o /dev/null -w "%{http_code}" \
        -u "$NC_USER:$NC_PASS" \
        -X PUT \
        -H "Content-Type: text/calendar; charset=utf-8" \
        -d "$ical" \
        "$DAV_URL/calendars/$NC_USER/personal/$uid.ics")

    if [ "$status" = "201" ] || [ "$status" = "204" ]; then
        echo "  Created event: $uid"
    else
        echo "  Event $uid: HTTP $status (may already exist)"
    fi
}

echo "--- Creating contacts ---"

create_contact "jan-de-vries" "BEGIN:VCARD
VERSION:3.0
UID:jan-de-vries
FN:Jan de Vries
N:de Vries;Jan;;;
EMAIL;TYPE=HOME:burger@test.local
TEL;TYPE=CELL:+31612345678
ADR;TYPE=HOME:;;Kerkstraat 42;Tilburg;;5038 AB;Nederland
NOTE:Burger - Aanvraag omgevingsvergunning dakkapel (ZK-2026-0142)
CATEGORIES:Burger,Vergunningen
END:VCARD"

create_contact "priya-ganpat" "BEGIN:VCARD
VERSION:3.0
UID:priya-ganpat
FN:Priya Ganpat
N:Ganpat;Priya;;;
EMAIL;TYPE=HOME:burger@test.local
TEL;TYPE=CELL:+31687654321
ADR;TYPE=HOME:;;Wilhelminastraat 17;Tilburg;;5041 ED;Nederland
NOTE:Burger - Kapvergunning aanvraag (ZK-2026-0034). ZZP developer.
CATEGORIES:Burger,Vergunningen
END:VCARD"

create_contact "fatima-el-amrani" "BEGIN:VCARD
VERSION:3.0
UID:fatima-el-amrani
FN:Fatima El-Amrani
N:El-Amrani;Fatima;;;
ORG:Gemeente Tilburg;Afdeling Vergunningen
TITLE:Behandelaar Vergunningen
EMAIL;TYPE=WORK:behandelaar@test.local
TEL;TYPE=WORK:+31135497200
ADR;TYPE=WORK:;;Stadhuisplein 130;Tilburg;;5038 TC;Nederland
CATEGORIES:Medewerker,Vergunningen
END:VCARD"

create_contact "noor-yilmaz" "BEGIN:VCARD
VERSION:3.0
UID:noor-yilmaz
FN:Noor Yilmaz
N:Yilmaz;Noor;;;
ORG:Gemeente Tilburg;Afdeling Vergunningen
TITLE:Coordinator / Functioneel Beheerder
EMAIL;TYPE=WORK:coordinator@test.local
TEL;TYPE=WORK:+31135497201
ADR;TYPE=WORK:;;Stadhuisplein 130;Tilburg;;5038 TC;Nederland
NOTE:CISO achtergrond. Verantwoordelijk voor IT-koppelingen en planning.
CATEGORIES:Medewerker,Coordinator
END:VCARD"

create_contact "mark-visser" "BEGIN:VCARD
VERSION:3.0
UID:mark-visser
FN:Mark Visser
N:Visser;Mark;;;
ORG:Conduction B.V.
TITLE:Directeur / Lead Developer
EMAIL;TYPE=WORK:leverancier@test.local
TEL;TYPE=WORK:+31854011580
URL:https://conduction.nl
NOTE:Leverancier IT-systeem migratie. Offerte REF-2026-Q1-087.
CATEGORIES:Leverancier,IT
END:VCARD"

create_contact "annemarie-de-vries" "BEGIN:VCARD
VERSION:3.0
UID:annemarie-de-vries
FN:Annemarie de Vries
N:de Vries;Annemarie;;;
ORG:VNG Realisatie
TITLE:Standaarden Architect
EMAIL;TYPE=WORK:annemarie@vng-test.local
TEL;TYPE=WORK:+31703738393
NOTE:VNG contactpersoon voor Common Ground standaarden en ZGW APIs.
CATEGORIES:VNG,Standaarden
END:VCARD"

echo ""
echo "--- Creating calendar events ---"

create_event "sprint-review-q1" "BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//OpenRegister//Seed//EN
BEGIN:VEVENT
UID:sprint-review-q1
DTSTART:20260323T083000Z
DTEND:20260323T093000Z
SUMMARY:Sprint Review Q1 - Team Vergunningen
DESCRIPTION:Kwartaal review van het team Vergunningen.\\n\\nAgenda:\\n1. Demo nieuwe zaak-koppeling OpenRegister\\n2. Voortgang migratie zaaksysteem\\n3. KPI's en doorlooptijden\\n4. Planning Q2
LOCATION:Vergaderzaal 3 - Stadskantoor
ORGANIZER;CN=Noor Yilmaz:mailto:coordinator@test.local
ATTENDEE;CN=Fatima El-Amrani;PARTSTAT=ACCEPTED:mailto:behandelaar@test.local
ATTENDEE;CN=Admin;PARTSTAT=ACCEPTED:mailto:admin@test.local
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR"

create_event "welstandscommissie-0142" "BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//OpenRegister//Seed//EN
BEGIN:VEVENT
UID:welstandscommissie-0142
DTSTART:20260325T130000Z
DTEND:20260325T150000Z
SUMMARY:Welstandscommissie - o.a. ZK-2026-0142 (dakkapel Kerkstraat 42)
DESCRIPTION:Vergadering welstandscommissie.\\n\\nBelangrijkste dossiers:\\n- ZK-2026-0142: Dakkapel Kerkstraat 42 (positief advies verwacht)\\n- ZK-2026-0155: Uitbouw Dorpsstraat 8\\n- ZK-2026-0163: Gevelbekleding Marktplein 3
LOCATION:Raadzaal - Stadskantoor
ORGANIZER;CN=Noor Yilmaz:mailto:coordinator@test.local
ATTENDEE;CN=Fatima El-Amrani;PARTSTAT=ACCEPTED:mailto:behandelaar@test.local
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR"

create_event "it-koppeling-overleg" "BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//OpenRegister//Seed//EN
BEGIN:VEVENT
UID:it-koppeling-overleg
DTSTART:20260326T090000Z
DTEND:20260326T100000Z
SUMMARY:Overleg IT-koppelingen OpenRegister/Procest/Pipelinq
DESCRIPTION:Technisch overleg over de API-koppelingen:\\n\\n1. Email integratie via Nextcloud Mail\\n2. CalDAV/CardDAV koppelingen\\n3. Deck integratie voor kanban workflow\\n4. Webhook configuratie voor statuswijzigingen\\n\\nVoorbereiding: technische documentatie van Conduction doorlezen.
LOCATION:Online - Nextcloud Talk
ORGANIZER;CN=Noor Yilmaz:mailto:coordinator@test.local
ATTENDEE;CN=Mark Visser;PARTSTAT=TENTATIVE:mailto:leverancier@test.local
ATTENDEE;CN=Fatima El-Amrani;PARTSTAT=ACCEPTED:mailto:behandelaar@test.local
ATTENDEE;CN=Admin;PARTSTAT=NEEDS-ACTION:mailto:admin@test.local
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR"

create_event "deadline-koningsdag" "BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//OpenRegister//Seed//EN
BEGIN:VEVENT
UID:deadline-koningsdag
DTSTART:20260325T000000Z
DTEND:20260325T235959Z
SUMMARY:DEADLINE: ZK-2026-0098 Evenementenvergunning Koningsdag
DESCRIPTION:Uiterste behandeldatum evenementenvergunning Koningsdag.\\nBehandelaar: Fatima El-Amrani\\nStatus: In behandeling
ORGANIZER;CN=Admin:mailto:admin@test.local
ATTENDEE;CN=Fatima El-Amrani;PARTSTAT=ACCEPTED:mailto:behandelaar@test.local
STATUS:CONFIRMED
BEGIN:VALARM
TRIGGER:-P1D
ACTION:DISPLAY
DESCRIPTION:Deadline morgen: Evenementenvergunning Koningsdag
END:VALARM
END:VEVENT
END:VCALENDAR"

create_event "retrospective-q1" "BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//OpenRegister//Seed//EN
BEGIN:VEVENT
UID:retrospective-q1
DTSTART:20260327T140000Z
DTEND:20260327T150000Z
SUMMARY:Retrospective Team Vergunningen - Week 13
DESCRIPTION:Wat ging goed? Wat kan beter?\\n\\nPunten uit vorige retro:\\n- Doorlooptijd bezwaarschriften verbeterd (van 12 naar 9 weken)\\n- Nieuw zaakportaal positief ontvangen door burgers\\n- Klachten over trage e-mail notificaties (actie: migratie naar n8n workflows)
LOCATION:Vergaderzaal 2 - Stadskantoor
ORGANIZER;CN=Noor Yilmaz:mailto:coordinator@test.local
ATTENDEE;CN=Fatima El-Amrani;PARTSTAT=ACCEPTED:mailto:behandelaar@test.local
ATTENDEE;CN=Admin;PARTSTAT=ACCEPTED:mailto:admin@test.local
STATUS:CONFIRMED
END:VEVENT
END:VCALENDAR"

echo ""
echo "=== PIM seeding complete ==="
echo ""
echo "Created:"
echo "  - 6 contacts in default address book"
echo "  - 5 calendar events in personal calendar"
echo ""
echo "All data links to the same case scenarios as seed-mail.sh"
echo "  ZK-2026-0142: Omgevingsvergunning dakkapel (Jan de Vries)"
echo "  ZK-2026-0034: Kapvergunning (Priya Ganpat)"
echo "  ZK-2026-0098: Evenementenvergunning Koningsdag"
echo "  REF-2026-Q1-087: IT migratie offerte (Mark Visser / Conduction)"
