# Single Sign-On (SSO) Setup for Nextcloud ExApps

This guide explains how to configure centralized authentication using Keycloak for Nextcloud and all Common Ground ExApps (OpenKlant, OpenZaak, OpenTalk, Valtimo).

## Architecture

```
┌─────────────────────────────────────────────────────────┐
│                      Keycloak                           │
│              (Central Identity Provider)                │
│              http://localhost:8081                      │
└──────────┬──────────┬──────────┬──────────┬────────────┘
           │          │          │          │
     ┌─────▼───┐ ┌────▼────┐ ┌───▼────┐ ┌───▼────┐ ┌──────▼─────┐
     │Nextcloud│ │OpenZaak │ │OpenKlant│ │Valtimo │ │OpenTalk   │
     │(OIDC)   │ │(OIDC)   │ │(OIDC)   │ │(OIDC)  │ │(OIDC)     │
     └─────────┘ └─────────┘ └─────────┘ └────────┘ └───────────┘
```

## Quick Start

### 1. Start the SSO Infrastructure

```bash
cd /path/to/openregister

# Start with SSO (Keycloak + Redis + PostgreSQL with all databases)
docker-compose -f docker-compose.yml -f docker-compose.sso.yml up -d
```

### 2. Wait for Keycloak to Start

Keycloak takes about 60 seconds to start. Check status:

```bash
curl http://localhost:8081/health/ready
```

### 3. Configure Nextcloud OIDC

```bash
./scripts/setup-keycloak-sso.sh
```

### 4. Register ExApps with Keycloak

```bash
# OpenKlant
occ app_api:app:register openklant docker_local \
  --info-xml=/var/www/html/custom_apps/openklant/appinfo/info.xml \
  --env=DB_HOST=openregister-postgres \
  --env=DB_NAME=openklant \
  --env=DB_USER=nextcloud \
  --env=DB_PASSWORD='!ChangeMe!' \
  --env=KEYCLOAK_URL=http://openregister-keycloak:8080 \
  --env=KEYCLOAK_REALM=commonground \
  --env=KEYCLOAK_CLIENT_ID=openklant \
  --env=KEYCLOAK_CLIENT_SECRET=openklant-secret-change-me

# OpenZaak
occ app_api:app:register openzaak docker_local \
  --info-xml=/var/www/html/custom_apps/openzaak/appinfo/info.xml \
  --env=DB_HOST=openregister-postgres \
  --env=DB_NAME=openzaak \
  --env=DB_USER=nextcloud \
  --env=DB_PASSWORD='!ChangeMe!' \
  --env=CACHE_DEFAULT=openregister-redis:6379/0 \
  --env=KEYCLOAK_URL=http://openregister-keycloak:8080 \
  --env=KEYCLOAK_REALM=commonground \
  --env=KEYCLOAK_CLIENT_ID=openzaak \
  --env=KEYCLOAK_CLIENT_SECRET=openzaak-secret-change-me

# OpenTalk
occ app_api:app:register opentalk docker_local \
  --info-xml=/var/www/html/custom_apps/opentalk/appinfo/info.xml \
  --env=OPENTALK_CTRL_DATABASE__URL=postgres://nextcloud:!ChangeMe!@openregister-postgres:5432/opentalk \
  --env=OPENTALK_CTRL_REDIS__URL=redis://openregister-redis:6379/ \
  --env=KEYCLOAK_URL=http://openregister-keycloak:8080 \
  --env=KEYCLOAK_REALM=commonground \
  --env=KEYCLOAK_CLIENT_ID=opentalk \
  --env=KEYCLOAK_CLIENT_SECRET=opentalk-secret-change-me

# Valtimo
occ app_api:app:register valtimo docker_local \
  --info-xml=/var/www/html/custom_apps/valtimo/appinfo/info.xml \
  --env=SPRING_DATASOURCE_URL=jdbc:postgresql://openregister-postgres:5432/valtimo \
  --env=SPRING_DATASOURCE_USERNAME=nextcloud \
  --env=SPRING_DATASOURCE_PASSWORD='!ChangeMe!' \
  --env=KEYCLOAK_URL=http://openregister-keycloak:8080 \
  --env=KEYCLOAK_REALM=commonground \
  --env=KEYCLOAK_CLIENT_ID=valtimo \
  --env=KEYCLOAK_CLIENT_SECRET=valtimo-secret-change-me
```

## Keycloak Configuration

### Default Users

The `commonground` realm includes these default users:

| Username | Password | Roles |
|----------|----------|-------|
| admin | admin | admin, user, case-manager |
| user | user | user |
| casemanager | casemanager | user, case-manager |

### Clients

Pre-configured OIDC clients:

| Client ID | Secret | Purpose |
|-----------|--------|---------|
| nextcloud | nextcloud-secret-change-me | Nextcloud SSO |
| openklant | openklant-secret-change-me | OpenKlant API |
| openzaak | openzaak-secret-change-me | OpenZaak ZGW APIs |
| opentalk | opentalk-secret-change-me | OpenTalk video conferencing |
| valtimo | valtimo-secret-change-me | Valtimo BPM |

### Access Keycloak Admin Console

- URL: http://localhost:8081/admin
- Username: admin
- Password: admin

## Customizing the Realm

To modify the realm configuration:

1. Edit `docker/keycloak/commonground-realm.json`
2. Restart Keycloak:
   ```bash
   docker-compose -f docker-compose.yml -f docker-compose.sso.yml restart keycloak
   ```

Or use the Keycloak admin console to make changes, then export:

```bash
docker exec openregister-keycloak /opt/keycloak/bin/kc.sh export \
  --dir /tmp/export --realm commonground
docker cp openregister-keycloak:/tmp/export/commonground-realm.json \
  docker/keycloak/commonground-realm.json
```

## Troubleshooting

### Keycloak not starting

Check logs:
```bash
docker logs openregister-keycloak
```

### OIDC login not working

1. Verify Keycloak is accessible from Nextcloud container:
   ```bash
   docker exec nextcloud curl -s http://openregister-keycloak:8080/health/ready
   ```

2. Check user_oidc configuration:
   ```bash
   docker exec nextcloud php occ user_oidc:provider:list
   ```

3. Verify client secret matches in both Keycloak and Nextcloud

### ExApp not authenticating

1. Check ExApp logs:
   ```bash
   docker logs nc_app_openklant
   ```

2. Verify environment variables:
   ```bash
   docker inspect nc_app_openklant --format '{{range .Config.Env}}{{println .}}{{end}}' | grep KEYCLOAK
   ```

## Security Notes

**For Production:**

1. Change all default passwords and secrets
2. Enable HTTPS for Keycloak
3. Use a proper SSL certificate
4. Set `KC_HOSTNAME` to your actual domain
5. Consider using a separate database for Keycloak
