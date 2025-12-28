# Windmill Integration

Integrate OpenRegister with Windmill to create powerful automation workflows. Windmill is an open-source workflow engine that has native integration with Nextcloud.

## Overview

Windmill is a developer-focused workflow automation platform that allows you to build workflows using Python, TypeScript, Go, and Bash. With OpenRegister's webhook integration, you can:

- Create automated workflows triggered by OpenRegister events
- Build complex data processing pipelines
- Integrate with hundreds of external services
- Use version control for your workflows

## Prerequisites

- Nextcloud 28+ with OpenRegister installed
- Windmill integration configured in Nextcloud
- Nextcloud `webhook_listeners` app enabled
- Admin access to both Nextcloud and Windmill

## Nextcloud Windmill Integration

Nextcloud has official support for Windmill workflows. Refer to the [Nextcloud Windmill Documentation](https://docs.nextcloud.com/server/latest/admin_manual/windmill_workflows/) for setup instructions.

### Enabling Windmill in Nextcloud

1. Install the Windmill app from Nextcloud App Store (if available)
2. Configure Windmill as an External App in Nextcloud settings
3. Set the Windmill instance URL
4. Configure authentication tokens

## Quick Start

### Step 1: Enable webhook_listeners in Nextcloud

```bash
docker exec -u 33 <nextcloud-container> php occ app:enable webhook_listeners
```

### Step 2: Create a Windmill Webhook Script

Create a new script in Windmill to handle webhook requests:

**Python Example**:

```python
# Windmill webhook handler for OpenRegister events.
import wmill
from typing import Any, Dict

def main(body: Dict[str, Any]) -> Dict[str, Any]:
    '''
    Handle OpenRegister webhook events.
    
    Args:
        body: The webhook payload from OpenRegister
        
    Returns:
        Response dictionary
    '''
    event_type = body.get('event', 'unknown')
    data = body.get('data', {})
    
    # Process based on event type.
    if 'ObjectCreatedEvent' in event_type:
        return handle_object_created(data)
    elif 'ObjectUpdatedEvent' in event_type:
        return handle_object_updated(data)
    elif 'SchemaCreatedEvent' in event_type:
        return handle_schema_created(data)
    else:
        return {'status': 'ignored', 'event': event_type}

def handle_object_created(data: Dict[str, Any]) -> Dict[str, Any]:
    '''Handle object creation event.'''
    object_data = data.get('object', {})
    
    # Example: Log the new object.
    print(f"New object created: {object_data.get('uuid')}")
    print(f"Register: {object_data.get('register')}")
    print(f"Schema: {object_data.get('schema')}")
    
    # Add your custom logic here.
    # - Send notification.
    # - Update external system.
    # - Process data.
    
    return {
        'status': 'success',
        'message': f"Processed object {object_data.get('uuid')}"
    }

def handle_object_updated(data: Dict[str, Any]) -> Dict[str, Any]:
    '''Handle object update event.'''
    new_object = data.get('newObject', {})
    old_object = data.get('oldObject', {})
    
    print(f"Object updated: {new_object.get('uuid')}")
    print(f"Changes detected in object")
    
    return {
        'status': 'success',
        'message': f"Processed update for {new_object.get('uuid')}"
    }

def handle_schema_created(data: Dict[str, Any]) -> Dict[str, Any]:
    '''Handle schema creation event.'''
    schema = data.get('schema', {})
    
    print(f"New schema created: {schema.get('name')}")
    print(f"Version: {schema.get('version')}")
    
    return {
        'status': 'success',
        'message': f"Processed schema {schema.get('name')}"
    }
```

**TypeScript Example**:

```typescript
// Windmill webhook handler for OpenRegister events.
type WebhookPayload = {
  event: string;
  data: any;
};

export async function main(body: WebhookPayload) {
  const eventType = body.event || 'unknown';
  const data = body.data || {};

  // Process based on event type.
  if (eventType.includes('ObjectCreatedEvent')) {
    return await handleObjectCreated(data);
  } else if (eventType.includes('ObjectUpdatedEvent')) {
    return await handleObjectUpdated(data);
  } else if (eventType.includes('SchemaCreatedEvent')) {
    return await handleSchemaCreated(data);
  }

  return { status: 'ignored', event: eventType };
}

async function handleObjectCreated(data: any) {
  const object = data.object || {};
  
  console.log(`New object created: ${object.uuid}`);
  console.log(`Register: ${object.register}`);
  console.log(`Schema: ${object.schema}`);
  
  // Add your custom logic here.
  
  return {
    status: 'success',
    message: `Processed object ${object.uuid}`
  };
}

async function handleObjectUpdated(data: any) {
  const newObject = data.newObject || {};
  const oldObject = data.oldObject || {};
  
  console.log(`Object updated: ${newObject.uuid}`);
  
  return {
    status: 'success',
    message: `Processed update for ${newObject.uuid}`
  };
}

async function handleSchemaCreated(data: any) {
  const schema = data.schema || {};
  
  console.log(`New schema created: ${schema.name}`);
  console.log(`Version: ${schema.version}`);
  
  return {
    status: 'success',
    message: `Processed schema ${schema.name}`
  };
}
```

### Step 3: Deploy Script and Get Webhook URL

1. Save and deploy your script in Windmill
2. Get the webhook URL for your script (usually `https://windmill.your-domain.com/api/w/your-workspace/jobs/run/p/your-script-path`)
3. Copy this URL for the next step

### Step 4: Register Webhook in Nextcloud

```bash
curl -X POST http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "admin:admin" \
  -H "Content-Type: application/json" \
  -d '{
    "httpMethod": "POST",
    "uri": "https://windmill.your-domain.com/api/w/your-workspace/jobs/run/p/your-script-path",
    "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
    "eventFilter": []
  }'
```

### Step 5: Test the Integration

1. Create a new object in OpenRegister
2. Check Windmill execution logs
3. Verify the webhook was received and processed

## Common Use Cases

### 1. Sync Objects to PostgreSQL

```python
import wmill
from typing import Any, Dict

def main(body: Dict[str, Any]) -> Dict[str, Any]:
    '''Sync OpenRegister objects to PostgreSQL.'''
    object_data = body.get('data', {}).get('object', {})
    
    # Get PostgreSQL connection from Windmill resources.
    db = wmill.get_resource('u/admin/postgres_openregister')
    
    # Insert or update object in database.
    query = '''
    INSERT INTO openregister_objects (uuid, register, schema, data, created_at, updated_at)
    VALUES ($1, $2, $3, $4, $5, $6)
    ON CONFLICT (uuid) DO UPDATE SET
        data = EXCLUDED.data,
        updated_at = EXCLUDED.updated_at
    '''
    
    wmill.execute_query(
        db,
        query,
        [
            object_data.get('uuid'),
            object_data.get('register'),
            object_data.get('schema'),
            object_data.get('data'),
            object_data.get('created'),
            object_data.get('updated')
        ]
    )
    
    return {'status': 'success', 'uuid': object_data.get('uuid')}
```

### 2. Send Slack Notification on Schema Changes

```python
import wmill
from typing import Any, Dict

def main(body: Dict[str, Any]) -> Dict[str, Any]:
    '''Send Slack notification when schema changes.'''
    event = body.get('event', '')
    schema = body.get('data', {}).get('schema', {})
    
    # Determine event type.
    if 'Created' in event:
        message = f"🆕 New schema created: *{schema.get('name')}*"
    elif 'Updated' in event:
        message = f"✏️ Schema updated: *{schema.get('name')}*"
    elif 'Deleted' in event:
        message = f"🗑️ Schema deleted: *{schema.get('name')}*"
    else:
        return {'status': 'ignored'}
    
    # Get Slack webhook from Windmill resources.
    slack_webhook = wmill.get_resource('u/admin/slack_webhook')
    
    # Send notification.
    wmill.post(
        slack_webhook['url'],
        json={
            'text': message,
            'channel': '#openregister-notifications'
        }
    )
    
    return {'status': 'success', 'message': message}
```

### 3. Complex Data Pipeline with Multiple Steps

Create a Windmill flow with multiple steps:

**Step 1: Receive Webhook**
```python
def main(body: dict) -> dict:
    '''Extract object data from webhook.'''
    return body.get('data', {}).get('object', {})
```

**Step 2: Transform Data**
```python
def main(object_data: dict) -> dict:
    '''Transform object data.'''
    return {
        'uuid': object_data.get('uuid'),
        'title': object_data.get('data', {}).get('title', 'Untitled'),
        'metadata': {
            'register': object_data.get('register'),
            'schema': object_data.get('schema'),
            'created': object_data.get('created')
        }
    }
```

**Step 3: Send to External API**
```python
import wmill

def main(transformed_data: dict) -> dict:
    '''Send transformed data to external API.'''
    api_config = wmill.get_resource('u/admin/external_api')
    
    response = wmill.post(
        f"{api_config['base_url']}/objects",
        json=transformed_data,
        headers={'Authorization': f"Bearer {api_config['token']}"}
    )
    
    return {'status': 'success', 'response': response}
```

## Working with OpenRegister API

### Get Object Details in Windmill

```python
import wmill
from typing import Any, Dict

def main(object_uuid: str) -> Dict[str, Any]:
    '''Fetch full object details from OpenRegister API.'''
    
    # Get Nextcloud credentials from Windmill resources.
    nextcloud = wmill.get_resource('u/admin/nextcloud_api')
    
    # Call OpenRegister API.
    response = wmill.get(
        f"{nextcloud['base_url']}/apps/openregister/api/objects/{object_uuid}",
        auth=(nextcloud['username'], nextcloud['password'])
    )
    
    return response.json()
```

### Create Object from Windmill

```python
import wmill
from typing import Any, Dict

def main(register: str, schema: str, data: Dict[str, Any]) -> Dict[str, Any]:
    '''Create new object in OpenRegister.'''
    
    # Get Nextcloud credentials.
    nextcloud = wmill.get_resource('u/admin/nextcloud_api')
    
    # Create object.
    response = wmill.post(
        f"{nextcloud['base_url']}/apps/openregister/api/objects",
        json={
            'register': register,
            'schema': schema,
            'data': data
        },
        auth=(nextcloud['username'], nextcloud['password'])
    )
    
    return response.json()
```

## Advanced Patterns

### Conditional Workflows

Use Windmill's branching to create conditional workflows:

```python
def main(body: dict) -> str:
    '''Determine workflow path based on event data.'''
    object_data = body.get('data', {}).get('object', {})
    register = object_data.get('register')
    
    if register == 'critical-data':
        return 'process_immediately'
    elif register == 'analytics':
        return 'queue_for_batch'
    else:
        return 'standard_processing'
```

Then create separate flows for each path.

### Error Handling and Retries

```python
import wmill
from typing import Any, Dict

def main(body: Dict[str, Any], retry_count: int = 0) -> Dict[str, Any]:
    '''Process with retry logic.'''
    max_retries = 3
    
    try:
        # Process webhook.
        result = process_webhook(body)
        return result
    except Exception as e:
        if retry_count < max_retries:
            # Schedule retry with exponential backoff.
            delay = 2 ** retry_count * 60  # 1min, 2min, 4min.
            wmill.schedule_job(
                'u/admin/handle_openregister_webhook',
                {'body': body, 'retry_count': retry_count + 1},
                schedule=f"in {delay} seconds"
            )
            return {'status': 'retry_scheduled', 'attempt': retry_count + 1}
        else:
            # Max retries reached, log error.
            wmill.log_error(f"Failed after {max_retries} retries: {str(e)}")
            return {'status': 'failed', 'error': str(e)}

def process_webhook(body: Dict[str, Any]) -> Dict[str, Any]:
    '''Actual webhook processing logic.'''
    # Implementation here.
    pass
```

### Batch Processing

```python
import wmill
from typing import Any, Dict, List

def main() -> Dict[str, Any]:
    '''Batch process OpenRegister objects.'''
    
    # Get Nextcloud credentials.
    nextcloud = wmill.get_resource('u/admin/nextcloud_api')
    
    # Fetch objects that need processing.
    response = wmill.get(
        f"{nextcloud['base_url']}/apps/openregister/api/objects",
        params={'_limit': 100, 'register': 'to-process'},
        auth=(nextcloud['username'], nextcloud['password'])
    )
    
    objects = response.json()
    results = []
    
    # Process each object.
    for obj in objects:
        result = process_object(obj)
        results.append(result)
    
    return {
        'status': 'success',
        'processed': len(results),
        'results': results
    }

def process_object(obj: Dict[str, Any]) -> Dict[str, Any]:
    '''Process individual object.'''
    # Implementation here.
    pass
```

## Troubleshooting

### Webhook Not Triggering Windmill Script

1. **Verify webhook registration**:
   ```bash
   curl -X GET http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
     -H "OCS-APIRequest: true" \
     -u "admin:admin"
   ```

2. **Check Windmill script deployment**:
   - Ensure script is deployed
   - Verify webhook URL is correct
   - Check script permissions

3. **Review execution logs**:
   - Check Windmill execution history
   - Look for errors in script logs
   - Verify payload is being received

### Authentication Errors

1. **Verify Windmill authentication**:
   - Check Windmill API token
   - Verify webhook URL includes authentication
   - Test endpoint with curl

2. **OpenRegister API authentication**:
   - Store credentials in Windmill resources
   - Use correct username/password
   - Check user permissions

### Script Execution Failures

1. **Check script syntax**:
   - Validate Python/TypeScript syntax
   - Test script locally if possible
   - Review type annotations

2. **Verify dependencies**:
   - Ensure required libraries are available
   - Check import statements
   - Test with sample data

## Best Practices

1. **Use Windmill Resources**: Store credentials and configuration in Windmill resources, not in code
2. **Version Control**: Use Windmill's Git sync to version control your scripts
3. **Error Handling**: Always implement proper error handling and logging
4. **Testing**: Test scripts with sample payloads before deploying
5. **Monitoring**: Regularly review execution logs and set up alerts
6. **Documentation**: Document your workflows and scripts
7. **Security**: Use minimal permissions and secure credentials storage

## Performance Considerations

- **Async Processing**: Use Windmill's async capabilities for long-running tasks
- **Batch Operations**: Process multiple objects together when possible
- **Caching**: Cache frequently accessed data in Windmill state
- **Rate Limiting**: Implement rate limiting when calling external APIs

## Further Reading

- [Webhooks Feature Documentation](../Features/webhooks.md)
- [Events API Reference](../api/events-reference.md)
- [Nextcloud Windmill Documentation](https://docs.nextcloud.com/server/latest/admin_manual/windmill_workflows/)
- [Windmill Documentation](https://docs.windmill.dev)
- [OpenRegister API Documentation](../api/objects.md)

## Support

For issues specific to:
- **Windmill scripts**: Windmill documentation or community
- **OpenRegister integration**: OpenRegister GitHub issues
- **Nextcloud integration**: Nextcloud documentation

