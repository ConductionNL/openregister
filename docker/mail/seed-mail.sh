#!/bin/bash
# seed-mail.sh — Send test emails to Greenmail for development/testing
#
# Usage: bash seed-mail.sh [SMTP_HOST] [SMTP_PORT]
# Defaults: localhost 3025
#
# Greenmail auto-creates accounts on first email received.
# After seeding, configure Nextcloud Mail app with:
#   IMAP: greenmail:3143 (or localhost:3143 from host)
#   SMTP: greenmail:3025 (or localhost:3025 from host)
#   User: <email address>, Password: <email address>

SMTP_HOST="${1:-localhost}"
SMTP_PORT="${2:-3025}"

send_email() {
    local from="$1"
    local to="$2"
    local subject="$3"
    local body="$4"
    local date="$5"
    local cc="${6:-}"
    local message_id="${7:-$(uuidgen)@test.local}"

    local cc_header=""
    if [ -n "$cc" ]; then
        cc_header="Cc: $cc"$'\r\n'
    fi

    local email_data="From: $from\r\nTo: $to\r\n${cc_header}Subject: $subject\r\nDate: $date\r\nMessage-ID: <$message_id>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$body"

    # Use Python for reliable SMTP sending (available in most environments)
    python3 -c "
import smtplib
from email.mime.text import MIMEText
from email.mime.multipart import MIMEMultipart
import sys

msg = MIMEMultipart()
msg['From'] = '''$from'''
msg['To'] = '''$to'''
msg['Subject'] = '''$subject'''
msg['Date'] = '''$date'''
msg['Message-ID'] = '''<$message_id>'''
cc = '''$cc'''
if cc:
    msg['Cc'] = cc

msg.attach(MIMEText('''$body''', 'plain', 'utf-8'))

try:
    with smtplib.SMTP('$SMTP_HOST', $SMTP_PORT) as server:
        recipients = ['$to']
        if cc:
            recipients.extend([r.strip() for r in cc.split(',')])
        server.sendmail('$from', recipients, msg.as_string())
    print(f'  Sent: {msg[\"Subject\"]} -> {msg[\"To\"]}')
except Exception as e:
    print(f'  FAILED: {e}', file=sys.stderr)
    sys.exit(1)
"
}

echo "=== Seeding Greenmail with test emails ==="
echo "SMTP: $SMTP_HOST:$SMTP_PORT"
echo ""

# Test accounts (auto-created by Greenmail on first email):
# - admin@test.local       (system admin)
# - behandelaar@test.local (case handler / civil servant)
# - coordinator@test.local (team coordinator)
# - burger@test.local      (citizen)
# - leverancier@test.local (supplier/vendor)

echo "--- Case management emails (procest/pipelinq relevant) ---"

send_email \
    "burger@test.local" \
    "behandelaar@test.local" \
    "Aanvraag omgevingsvergunning - Kerkstraat 42" \
    "Geachte heer/mevrouw,

Hierbij dien ik een aanvraag in voor een omgevingsvergunning voor het plaatsen van een dakkapel op het adres Kerkstraat 42, 5038 AB Tilburg.

De benodigde documenten (bouwtekeningen en situatieschets) stuur ik als bijlage mee.

Met vriendelijke groet,
Jan de Vries
Burger BSN: 123456789" \
    "Mon, 17 Mar 2026 09:15:00 +0100"

send_email \
    "behandelaar@test.local" \
    "burger@test.local" \
    "RE: Aanvraag omgevingsvergunning - Kerkstraat 42 - Ontvangstbevestiging" \
    "Geachte heer De Vries,

Wij hebben uw aanvraag voor een omgevingsvergunning ontvangen. Uw aanvraag is geregistreerd onder zaaknummer ZK-2026-0142.

De behandeltermijn is 8 weken. U ontvangt binnen 2 weken bericht over de voortgang.

Met vriendelijke groet,
Fatima El-Amrani
Afdeling Vergunningen
Gemeente Tilburg" \
    "Mon, 17 Mar 2026 14:30:00 +0100" \
    "" \
    "reply-zk2026-0142@test.local"

send_email \
    "behandelaar@test.local" \
    "coordinator@test.local" \
    "Adviesaanvraag welstandscommissie - ZK-2026-0142" \
    "Hoi Noor,

Kun je het advies van de welstandscommissie inplannen voor de aanvraag ZK-2026-0142 (dakkapel Kerkstraat 42)?

De bouwtekeningen zitten in het dossier. Graag voor volgende week woensdag.

Groet,
Fatima" \
    "Tue, 18 Mar 2026 10:00:00 +0100"

send_email \
    "coordinator@test.local" \
    "behandelaar@test.local" \
    "RE: Adviesaanvraag welstandscommissie - ZK-2026-0142" \
    "Fatima,

Welstandscommissie is ingepland voor woensdag 26 maart om 14:00.
Ik heb het dossier doorgestuurd naar de commissieleden.

Positief advies verwacht gezien eerdere vergelijkbare aanvragen in die straat.

Groet,
Noor Yilmaz" \
    "Tue, 18 Mar 2026 15:45:00 +0100"

send_email \
    "leverancier@test.local" \
    "coordinator@test.local" \
    "Offerte IT-systeem migratie - REF-2026-Q1-087" \
    "Beste Noor,

In navolging van ons gesprek hierbij onze offerte voor de migratie van het zaaksysteem naar Nextcloud/OpenRegister.

Samenvatting:
- Fase 1: Data migratie (4 weken) - EUR 24.000
- Fase 2: Integratie Procest/Pipelinq (6 weken) - EUR 36.000
- Fase 3: Training en acceptatie (2 weken) - EUR 8.000

Totaal: EUR 68.000 excl. BTW

De offerte is 30 dagen geldig. Graag hoor ik uw reactie.

Met vriendelijke groet,
Mark Visser
Conduction B.V." \
    "Wed, 19 Mar 2026 08:30:00 +0100"

send_email \
    "coordinator@test.local" \
    "admin@test.local" \
    "FW: Offerte IT-systeem migratie - ter goedkeuring" \
    "Admin,

Hierbij de offerte van Conduction voor de zaaksysteem migratie. Past binnen het budget dat in de begroting is opgenomen.

Graag je akkoord zodat we het contract kunnen opstellen.

Noor" \
    "Wed, 19 Mar 2026 11:00:00 +0100" \
    "behandelaar@test.local"

echo ""
echo "--- Workflow/notification emails ---"

send_email \
    "admin@test.local" \
    "behandelaar@test.local" \
    "Herinnering: 3 zaken naderen deadline" \
    "Beste Fatima,

De volgende zaken naderen hun behandeldeadline:

1. ZK-2026-0098 - Evenementenvergunning Koningsdag (deadline: 25 maart)
2. ZK-2026-0115 - Bezwaarschrift WOZ-waarde (deadline: 28 maart)
3. ZK-2026-0142 - Omgevingsvergunning dakkapel (deadline: 12 mei)

Verzoek om de status bij te werken in het zaaksysteem.

Systeem notificatie - Niet beantwoorden" \
    "Thu, 20 Mar 2026 07:00:00 +0100"

send_email \
    "burger@test.local" \
    "admin@test.local" \
    "Klacht: geen reactie op mijn aanvraag sinds 6 weken" \
    "Geacht college,

Op 3 februari heb ik een aanvraag ingediend voor een kapvergunning (referentie ZK-2026-0034). Sindsdien heb ik geen enkele reactie ontvangen ondanks twee keer bellen.

Ik verzoek u dringend om mij binnen 5 werkdagen te informeren over de status.

Met vriendelijke groet,
Priya Ganpat
Wilhelminastraat 17, Tilburg" \
    "Thu, 20 Mar 2026 16:20:00 +0100"

send_email \
    "admin@test.local" \
    "coordinator@test.local" \
    "URGENT: Klacht kapvergunning ZK-2026-0034 - direct oppakken" \
    "Noor,

Bijgevoegd een klacht over ZK-2026-0034 (kapvergunning Ganpat).
De burger wacht al 6 weken. Dit moet morgen opgepakt worden.

Wie is de behandelaar? Graag terugkoppeling voor 12:00.

Admin" \
    "Fri, 21 Mar 2026 08:00:00 +0100" \
    "behandelaar@test.local"

send_email \
    "behandelaar@test.local" \
    "burger@test.local" \
    "Status update: Uw aanvraag kapvergunning ZK-2026-0034" \
    "Geachte mevrouw Ganpat,

Excuses voor het uitblijven van een reactie op uw aanvraag kapvergunning.

Uw aanvraag is in behandeling. De boomdeskundige heeft een positief advies gegeven. Het besluit wordt uiterlijk 28 maart genomen.

U kunt de voortgang ook volgen via het zaakportaal op https://gemeente.nl/mijnzaken.

Met vriendelijke groet,
Fatima El-Amrani
Gemeente Tilburg" \
    "Fri, 21 Mar 2026 11:30:00 +0100"

echo ""
echo "--- Internal coordination emails ---"

send_email \
    "coordinator@test.local" \
    "behandelaar@test.local" \
    "Weekplanning team Vergunningen - week 13" \
    "Team,

Planning voor volgende week:

Maandag: Sprint review Q1 (09:30-10:30, vergaderzaal 3)
Dinsdag: Geen vergaderingen - focus dag
Woensdag: Welstandscommissie (14:00-16:00)
Donderdag: Overleg met IT over nieuwe koppelingen (10:00-11:00)
Vrijdag: Retrospective (15:00-16:00)

Openstaande zaken per persoon:
- Fatima: 12 zaken (3 urgent)
- Ahmed: 8 zaken (1 urgent)
- Lisa: 10 zaken (2 urgent)

Fijn weekend!
Noor" \
    "Fri, 21 Mar 2026 16:00:00 +0100" \
    "admin@test.local"

send_email \
    "leverancier@test.local" \
    "behandelaar@test.local" \
    "Technische documentatie API-koppeling OpenRegister" \
    "Beste Fatima,

Zoals besproken hierbij de technische documentatie voor de API-koppeling tussen jullie zaaksysteem en OpenRegister.

De koppeling verloopt via:
- REST API endpoints voor zaak-objecten
- Webhook notificaties voor statuswijzigingen
- CalDAV voor taak-synchronisatie
- CardDAV voor contactpersonen

We hebben een testomgeving ingericht op https://test.conduction.nl waar jullie de koppeling kunnen testen.

Laat weten als er vragen zijn.

Groet,
Mark Visser
Conduction B.V." \
    "Sat, 22 Mar 2026 10:00:00 +0100" \
    "coordinator@test.local"

echo ""
echo "=== Mail seeding complete ==="
echo ""
echo "Accounts created (login = email address, password = email address):"
echo "  - admin@test.local"
echo "  - behandelaar@test.local"
echo "  - coordinator@test.local"
echo "  - burger@test.local"
echo "  - leverancier@test.local"
echo ""
echo "Configure Nextcloud Mail app:"
echo "  IMAP Host: greenmail (from container) or localhost (from host)"
echo "  IMAP Port: 3143"
echo "  SMTP Host: greenmail (from container) or localhost (from host)"
echo "  SMTP Port: 3025"
echo "  Security: None"
echo "  User: <email address>"
echo "  Password: <email address>"
