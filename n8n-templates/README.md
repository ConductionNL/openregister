# n8n Workflow Templates for OpenRegister

This directory contains ready-to-use n8n workflow templates for integrating OpenRegister with external systems via Nextcloud webhooks.

## Requirements

- Nextcloud 28+ with OpenRegister app installed
- n8n instance (self-hosted or cloud)
- Nextcloud `webhook_listeners` app enabled

## Available Templates

### 1. openregister-object-sync.json
**Description:** Sync OpenRegister objects to an external system whenever they are created or updated.

**Use Cases:**
- Keep external databases synchronized with OpenRegister data
- Trigger external processes when objects change
- Archive object data to external storage

**Events:** `ObjectCreatedEvent`, `ObjectUpdatedEvent`

---

### 2. openregister-to-database.json
**Description:** Write OpenRegister objects directly to an external database with transformation logic.

**Use Cases:**
- Data warehousing
- Analytics platforms
- External reporting systems

**Events:** `ObjectCreatedEvent`, `ObjectUpdatedEvent`

---

### 3. openregister-bidirectional-sync.json
**Description:** Two-way synchronization between OpenRegister and an external system.

**Use Cases:**
- Sync with CRM systems
- Integration with ERP platforms
- Multi-system data consistency

**Events:** `ObjectCreatedEvent`, `ObjectUpdatedEvent`, `ObjectDeletedEvent`

---

### 4. openregister-schema-notifications.json
**Description:** Send notifications when schemas are created, updated, or deleted.

**Use Cases:**
- Schema change alerts
- Team collaboration notifications
- Automated documentation updates

**Events:** `SchemaCreatedEvent`, `SchemaUpdatedEvent`, `SchemaDeletedEvent`

---

## How to Use These Templates

### Step 1: Enable Nextcloud webhook_listeners

```bash
docker exec -u 33 <nextcloud-container> php occ app:enable webhook_listeners
```

### Step 2: Register Webhooks in Nextcloud

Register a webhook for the events you want to listen to. For example, to listen to `ObjectCreatedEvent`:

```bash
curl -X POST http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "admin:admin" \
  -H "Content-Type: application/json" \
  -d '{
    "httpMethod": "POST",
    "uri": "https://<n8n-host>/webhook/<webhook-path>",
    "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
    "eventFilter": []
  }'
```

Replace:
- `<nextcloud-host>` with your Nextcloud hostname
- `<n8n-host>` with your n8n hostname
- `<webhook-path>` with the webhook path from the imported workflow

### Step 3: Import Template into n8n

1. Open n8n web interface
2. Click "Add workflow" or use the "+" button
3. Click the three-dot menu (⋮) in the top right
4. Select "Import from File"
5. Choose one of the JSON templates from this directory
6. Click "Import"

### Step 4: Configure Credentials

After importing, configure the following in n8n:

1. **Webhook Node:**
   - Copy the webhook URL from the node
   - Use this URL when registering the webhook in Nextcloud

2. **HTTP Request Nodes:**
   - Add HTTP Basic Auth credentials for OpenRegister API
   - Username: `admin` (or your Nextcloud admin user)
   - Password: Your Nextcloud admin password
   - Base URL: `http://<nextcloud-container>/apps/openregister/api`

3. **Database/External Service Nodes:**
   - Configure credentials for your external systems

### Step 5: Activate Workflow

1. Click "Save" in n8n
2. Toggle the workflow to "Active"
3. Test by creating/updating an object in OpenRegister

---

## Available OpenRegister Events

| Event | Description | Payload Getter |
|-------|-------------|----------------|
| `ObjectCreatedEvent` | When an object is created | `getObject()` |
| `ObjectUpdatedEvent` | When an object is updated | `getNewObject()`, `getOldObject()` |
| `ObjectDeletedEvent` | When an object is deleted | `getObject()` |
| `ObjectLockedEvent` | When an object is locked | `getObject()` |
| `ObjectUnlockedEvent` | When an object is unlocked | `getObject()` |
| `RegisterCreatedEvent` | When a register is created | `getRegister()` |
| `RegisterUpdatedEvent` | When a register is updated | `getNewRegister()`, `getOldRegister()` |
| `RegisterDeletedEvent` | When a register is deleted | `getRegister()` |
| `SchemaCreatedEvent` | When a schema is created | `getSchema()` |
| `SchemaUpdatedEvent` | When a schema is updated | `getNewSchema()`, `getOldSchema()` |
| `SchemaDeletedEvent` | When a schema is deleted | `getSchema()` |
| `ApplicationCreatedEvent` | When an application is created | `getApplication()` |
| `ApplicationUpdatedEvent` | When an application is updated | `getNewApplication()`, `getOldApplication()` |
| `ApplicationDeletedEvent` | When an application is deleted | `getApplication()` |

See the [Events Documentation](../website/docs/Features/events.md) for a complete list.

---

## Webhook Event Payload Structure

When Nextcloud dispatches a webhook, it sends a JSON payload with the following structure:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
  "data": {
    "object": {
      "id": 123,
      "uuid": "550e8400-e29b-41d4-a716-446655440000",
      "register": "my-register",
      "schema": "my-schema",
      "data": {
        "field1": "value1",
        "field2": "value2"
      },
      "created": "2024-01-15T10:30:00+00:00",
      "updated": "2024-01-15T10:30:00+00:00"
    }
  }
}
```

---

## Troubleshooting

### Webhook not triggering

1. Verify `webhook_listeners` app is enabled
2. Check webhook registration with:

```bash
curl -X GET http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "admin:admin"
```

3. Check Nextcloud logs:

```bash
docker logs -f <nextcloud-container>
```

### n8n workflow errors

1. Check n8n execution logs
2. Verify credentials are correct
3. Test webhook URL manually with curl
4. Ensure OpenRegister API is accessible from n8n

---

## Contributing

Have an idea for a new template? Submit a pull request or open an issue on GitHub.

---

## Support

For issues and questions:
- OpenRegister Documentation: [website/docs](../website/docs)
- n8n Documentation: https://docs.n8n.io
- Nextcloud Webhooks: https://docs.nextcloud.com/server/latest/admin_manual/webhook_listeners

