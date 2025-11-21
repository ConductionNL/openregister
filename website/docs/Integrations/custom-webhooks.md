# Custom Webhooks

This guide explains how to build custom webhook consumers for OpenRegister events. Use this if you want to integrate OpenRegister with your own applications or services.

## Overview

OpenRegister uses Nextcloud's `webhook_listeners` app to dispatch HTTP requests when events occur. You can create custom webhook endpoints in any programming language to receive and process these events.

## Prerequisites

- Understanding of HTTP webhooks
- Programming language of your choice
- Web server or serverless function endpoint
- Nextcloud instance with OpenRegister and `webhook_listeners` app

## Webhook Request Structure

### HTTP Method

Webhooks are sent as HTTP POST requests by default, but you can configure GET, PUT, or other methods when registering the webhook.

### Headers

```http
POST /your-webhook-endpoint HTTP/1.1
Host: your-domain.com
Content-Type: application/json
User-Agent: Nextcloud-Webhook
```

### Payload Structure

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
        "title": "Example Object",
        "description": "Object description",
        "custom_field": "value"
      },
      "organisation": "org-uuid",
      "created": "2024-01-15T10:30:00+00:00",
      "updated": "2024-01-15T10:30:00+00:00"
    }
  }
}
```

For update events:

```json
{
  "event": "OCA\\OpenRegister\\Event\\ObjectUpdatedEvent",
  "data": {
    "newObject": { /* updated object */ },
    "oldObject": { /* previous state */ }
  }
}
```

## Implementation Examples

### Python (Flask)

```python
from flask import Flask, request, jsonify
import logging

app = Flask(__name__)
logging.basicConfig(level=logging.INFO)

@app.route('/openregister-webhook', methods=['POST'])
def handle_webhook():
    '''Handle OpenRegister webhook events.'''
    try:
        # Parse incoming webhook payload.
        payload = request.json
        event_type = payload.get('event', '')
        data = payload.get('data', {})
        
        # Log event.
        logging.info(f"Received event: {event_type}")
        
        # Process based on event type.
        if 'ObjectCreatedEvent' in event_type:
            result = process_object_created(data)
        elif 'ObjectUpdatedEvent' in event_type:
            result = process_object_updated(data)
        elif 'ObjectDeletedEvent' in event_type:
            result = process_object_deleted(data)
        else:
            result = {'status': 'ignored', 'event': event_type}
        
        return jsonify(result), 200
        
    except Exception as e:
        logging.error(f"Error processing webhook: {str(e)}")
        return jsonify({'error': str(e)}), 500

def process_object_created(data):
    '''Process object creation event.'''
    object_data = data.get('object', {})
    
    # Your custom logic here.
    print(f"New object: {object_data.get('uuid')}")
    print(f"Register: {object_data.get('register')}")
    print(f"Data: {object_data.get('data')}")
    
    # Example: Save to database, send notification, etc.
    
    return {'status': 'success', 'processed': object_data.get('uuid')}

def process_object_updated(data):
    '''Process object update event.'''
    new_object = data.get('newObject', {})
    old_object = data.get('oldObject', {})
    
    # Detect changes.
    changes = detect_changes(old_object, new_object)
    
    print(f"Object {new_object.get('uuid')} updated")
    print(f"Changes: {changes}")
    
    return {'status': 'success', 'changes': len(changes)}

def process_object_deleted(data):
    '''Process object deletion event.'''
    object_data = data.get('object', {})
    
    print(f"Object deleted: {object_data.get('uuid')}")
    
    # Cleanup logic here.
    
    return {'status': 'success', 'deleted': object_data.get('uuid')}

def detect_changes(old_obj, new_obj):
    '''Detect changes between old and new object states.'''
    changes = []
    
    old_data = old_obj.get('data', {})
    new_data = new_obj.get('data', {})
    
    # Compare data fields.
    for key in new_data:
        if key not in old_data or old_data[key] != new_data[key]:
            changes.append({
                'field': key,
                'old_value': old_data.get(key),
                'new_value': new_data[key]
            })
    
    return changes

if __name__ == '__main__':
    app.run(host='0.0.0.0', port=5000)
```

### Node.js (Express)

```javascript
const express = require('express');
const app = express();

app.use(express.json());

app.post('/openregister-webhook', async (req, res) => {
  try {
    const { event, data } = req.body;
    
    console.log(`Received event: ${event}`);
    
    let result;
    
    if (event.includes('ObjectCreatedEvent')) {
      result = await processObjectCreated(data);
    } else if (event.includes('ObjectUpdatedEvent')) {
      result = await processObjectUpdated(data);
    } else if (event.includes('ObjectDeletedEvent')) {
      result = await processObjectDeleted(data);
    } else {
      result = { status: 'ignored', event };
    }
    
    res.json(result);
  } catch (error) {
    console.error('Error processing webhook:', error);
    res.status(500).json({ error: error.message });
  }
});

async function processObjectCreated(data) {
  const object = data.object || {};
  
  console.log(`New object: ${object.uuid}`);
  console.log(`Register: ${object.register}`);
  console.log(`Data:`, object.data);
  
  // Your custom logic here.
  // - Save to database.
  // - Send notification.
  // - Trigger workflow.
  
  return { status: 'success', processed: object.uuid };
}

async function processObjectUpdated(data) {
  const newObject = data.newObject || {};
  const oldObject = data.oldObject || {};
  
  const changes = detectChanges(oldObject, newObject);
  
  console.log(`Object ${newObject.uuid} updated`);
  console.log(`Changes:`, changes);
  
  return { status: 'success', changes: changes.length };
}

async function processObjectDeleted(data) {
  const object = data.object || {};
  
  console.log(`Object deleted: ${object.uuid}`);
  
  // Cleanup logic here.
  
  return { status: 'success', deleted: object.uuid };
}

function detectChanges(oldObj, newObj) {
  const changes = [];
  const oldData = oldObj.data || {};
  const newData = newObj.data || {};
  
  for (const key in newData) {
    if (!(key in oldData) || oldData[key] !== newData[key]) {
      changes.push({
        field: key,
        oldValue: oldData[key],
        newValue: newData[key]
      });
    }
  }
  
  return changes;
}

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Webhook server listening on port ${PORT}`);
});
```

### PHP

```php
<?php
/**
 * OpenRegister Custom Webhook Handler
 */

// Get the raw POST body.
$payload = json_decode(file_get_contents('php://input'), true);

// Log incoming webhook.
error_log('Received OpenRegister webhook: ' . json_encode($payload));

try {
    $event = $payload['event'] ?? 'unknown';
    $data = $payload['data'] ?? [];
    
    // Process based on event type.
    if (strpos($event, 'ObjectCreatedEvent') !== false) {
        $result = processObjectCreated($data);
    } elseif (strpos($event, 'ObjectUpdatedEvent') !== false) {
        $result = processObjectUpdated($data);
    } elseif (strpos($event, 'ObjectDeletedEvent') !== false) {
        $result = processObjectDeleted($data);
    } else {
        $result = ['status' => 'ignored', 'event' => $event];
    }
    
    // Return JSON response.
    header('Content-Type: application/json');
    echo json_encode($result);
    http_response_code(200);
    
} catch (Exception $e) {
    error_log('Error processing webhook: ' . $e->getMessage());
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    http_response_code(500);
}

function processObjectCreated(array $data): array
{
    $object = $data['object'] ?? [];
    
    error_log('New object: ' . ($object['uuid'] ?? 'unknown'));
    error_log('Register: ' . ($object['register'] ?? 'unknown'));
    
    // Your custom logic here.
    // - Save to database.
    // - Send notification.
    // - Trigger workflow.
    
    return [
        'status' => 'success',
        'processed' => $object['uuid'] ?? null
    ];
}

function processObjectUpdated(array $data): array
{
    $newObject = $data['newObject'] ?? [];
    $oldObject = $data['oldObject'] ?? [];
    
    $changes = detectChanges($oldObject, $newObject);
    
    error_log('Object updated: ' . ($newObject['uuid'] ?? 'unknown'));
    error_log('Changes: ' . count($changes));
    
    return [
        'status' => 'success',
        'changes' => count($changes)
    ];
}

function processObjectDeleted(array $data): array
{
    $object = $data['object'] ?? [];
    
    error_log('Object deleted: ' . ($object['uuid'] ?? 'unknown'));
    
    // Cleanup logic here.
    
    return [
        'status' => 'success',
        'deleted' => $object['uuid'] ?? null
    ];
}

function detectChanges(array $oldObj, array $newObj): array
{
    $changes = [];
    $oldData = $oldObj['data'] ?? [];
    $newData = $newObj['data'] ?? [];
    
    foreach ($newData as $key => $value) {
        if (!isset($oldData[$key]) || $oldData[$key] !== $value) {
            $changes[] = [
                'field' => $key,
                'old_value' => $oldData[$key] ?? null,
                'new_value' => $value
            ];
        }
    }
    
    return $changes;
}
```

### Go

```go
package main

import (
    "encoding/json"
    "fmt"
    "log"
    "net/http"
)

type WebhookPayload struct {
    Event string                 `json:"event"`
    Data  map[string]interface{} `json:"data"`
}

type WebhookResponse struct {
    Status  string `json:"status"`
    Message string `json:"message"`
}

func handleWebhook(w http.ResponseWriter, r *http.Request) {
    // Parse incoming webhook payload.
    var payload WebhookPayload
    err := json.NewDecoder(r.Body).Decode(&payload)
    if err != nil {
        log.Printf("Error decoding payload: %v", err)
        http.Error(w, err.Error(), http.StatusBadRequest)
        return
    }
    
    log.Printf("Received event: %s", payload.Event)
    
    var response WebhookResponse
    
    // Process based on event type.
    switch {
    case contains(payload.Event, "ObjectCreatedEvent"):
        response = processObjectCreated(payload.Data)
    case contains(payload.Event, "ObjectUpdatedEvent"):
        response = processObjectUpdated(payload.Data)
    case contains(payload.Event, "ObjectDeletedEvent"):
        response = processObjectDeleted(payload.Data)
    default:
        response = WebhookResponse{
            Status:  "ignored",
            Message: "Event type not handled",
        }
    }
    
    // Return JSON response.
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(response)
}

func processObjectCreated(data map[string]interface{}) WebhookResponse {
    object := data["object"].(map[string]interface{})
    
    uuid := object["uuid"].(string)
    register := object["register"].(string)
    
    log.Printf("New object: %s in register: %s", uuid, register)
    
    // Your custom logic here.
    
    return WebhookResponse{
        Status:  "success",
        Message: fmt.Sprintf("Processed object %s", uuid),
    }
}

func processObjectUpdated(data map[string]interface{}) WebhookResponse {
    newObject := data["newObject"].(map[string]interface{})
    uuid := newObject["uuid"].(string)
    
    log.Printf("Object updated: %s", uuid)
    
    return WebhookResponse{
        Status:  "success",
        Message: fmt.Sprintf("Processed update for %s", uuid),
    }
}

func processObjectDeleted(data map[string]interface{}) WebhookResponse {
    object := data["object"].(map[string]interface{})
    uuid := object["uuid"].(string)
    
    log.Printf("Object deleted: %s", uuid)
    
    return WebhookResponse{
        Status:  "success",
        Message: fmt.Sprintf("Processed deletion of %s", uuid),
    }
}

func contains(str, substr string) bool {
    return len(str) >= len(substr) && (str == substr || len(str) > len(substr) && 
        (str[0:len(substr)] == substr || str[len(str)-len(substr):] == substr))
}

func main() {
    http.HandleFunc("/openregister-webhook", handleWebhook)
    
    port := ":8080"
    log.Printf("Webhook server listening on port %s", port)
    log.Fatal(http.ListenAndServe(port, nil))
}
```

## Registering Your Webhook

Once your endpoint is ready, register it with Nextcloud:

```bash
curl -X POST http://<nextcloud-host>/ocs/v2.php/apps/webhook_listeners/api/v1/webhooks \
  -H "OCS-APIRequest: true" \
  -u "admin:admin" \
  -H "Content-Type: application/json" \
  -d '{
    "httpMethod": "POST",
    "uri": "https://your-domain.com/openregister-webhook",
    "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
    "eventFilter": []
  }'
```

## Security Best Practices

### 1. Use HTTPS

Always use HTTPS endpoints to protect data in transit:

```bash
# Good.
"uri": "https://your-domain.com/webhook"

# Bad.
"uri": "http://your-domain.com/webhook"
```

### 2. Implement Authentication

Add authentication to your webhook endpoint:

**Shared Secret (HMAC)**:

```python
import hmac
import hashlib

SECRET_KEY = 'your-secret-key'

@app.route('/webhook', methods=['POST'])
def webhook():
    # Verify signature.
    signature = request.headers.get('X-Webhook-Signature')
    payload = request.get_data()
    
    expected_signature = hmac.new(
        SECRET_KEY.encode(),
        payload,
        hashlib.sha256
    ).hexdigest()
    
    if not hmac.compare_digest(signature, expected_signature):
        return jsonify({'error': 'Invalid signature'}), 403
    
    # Process webhook.
    ...
```

**API Key**:

```javascript
app.post('/webhook', (req, res) => {
  const apiKey = req.headers['x-api-key'];
  
  if (apiKey !== process.env.WEBHOOK_API_KEY) {
    return res.status(403).json({ error: 'Invalid API key' });
  }
  
  // Process webhook.
  ...
});
```

### 3. Validate Payload

Always validate incoming payloads:

```python
def validate_payload(payload):
    '''Validate webhook payload structure.'''
    if not isinstance(payload, dict):
        raise ValueError('Payload must be a dictionary')
    
    if 'event' not in payload:
        raise ValueError('Missing event field')
    
    if 'data' not in payload:
        raise ValueError('Missing data field')
    
    return True
```

### 4. IP Whitelisting

Restrict access to your webhook endpoint to your Nextcloud server IP:

```nginx
# Nginx example.
location /openregister-webhook {
    allow 192.168.1.10;  # Your Nextcloud server IP.
    deny all;
    proxy_pass http://localhost:5000;
}
```

### 5. Rate Limiting

Implement rate limiting to prevent abuse:

```python
from flask_limiter import Limiter
from flask_limiter.util import get_remote_address

limiter = Limiter(
    app,
    key_func=get_remote_address,
    default_limits=["100 per minute"]
)

@app.route('/webhook', methods=['POST'])
@limiter.limit("50 per minute")
def webhook():
    ...
```

## Error Handling

### Implement Retry Logic

Nextcloud `webhook_listeners` implements automatic retries with exponential backoff. Ensure your endpoint:

1. Returns appropriate HTTP status codes:
   - `200-299`: Success (no retry)
   - `400-499`: Client error (no retry)
   - `500-599`: Server error (will retry)

2. Is idempotent (can handle duplicate requests)

### Example Error Handling

```python
@app.route('/webhook', methods=['POST'])
def webhook():
    try:
        payload = request.json
        
        # Validate payload.
        validate_payload(payload)
        
        # Process webhook.
        result = process_webhook(payload)
        
        return jsonify(result), 200
        
    except ValueError as e:
        # Client error - don't retry.
        logging.error(f"Validation error: {str(e)}")
        return jsonify({'error': str(e)}), 400
        
    except Exception as e:
        # Server error - will retry.
        logging.error(f"Processing error: {str(e)}")
        return jsonify({'error': 'Internal server error'}), 500
```

## Testing Your Webhook

### Manual Testing with curl

```bash
curl -X POST https://your-domain.com/openregister-webhook \
  -H "Content-Type: application/json" \
  -d '{
    "event": "OCA\\OpenRegister\\Event\\ObjectCreatedEvent",
    "data": {
      "object": {
        "id": 1,
        "uuid": "test-uuid",
        "register": "test-register",
        "schema": "test-schema",
        "data": {"title": "Test Object"},
        "created": "2024-01-15T10:30:00+00:00",
        "updated": "2024-01-15T10:30:00+00:00"
      }
    }
  }'
```

### Unit Testing

```python
import unittest
from your_webhook_handler import app

class TestWebhook(unittest.TestCase):
    def setUp(self):
        self.client = app.test_client()
    
    def test_object_created(self):
        payload = {
            'event': 'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
            'data': {
                'object': {
                    'uuid': 'test-uuid',
                    'register': 'test',
                    'schema': 'test',
                    'data': {'title': 'Test'}
                }
            }
        }
        
        response = self.client.post('/webhook', json=payload)
        self.assertEqual(response.status_code, 200)
        self.assertIn('success', response.json['status'])
```

## Logging and Monitoring

### Structured Logging

```python
import logging
import json

logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)

def log_webhook_event(event_type, uuid, status):
    logging.info(json.dumps({
        'type': 'webhook_event',
        'event': event_type,
        'uuid': uuid,
        'status': status,
        'timestamp': datetime.now().isoformat()
    }))
```

### Monitoring

- Track webhook delivery success rate
- Monitor processing time
- Alert on failed webhooks
- Log all errors with context

## Troubleshooting

### Webhook Not Received

1. Verify endpoint is accessible
2. Check firewall rules
3. Test with curl
4. Review Nextcloud logs

### Payload Parsing Errors

1. Verify Content-Type header
2. Check JSON syntax
3. Validate payload structure
4. Test with sample data

### Authentication Failures

1. Verify credentials/tokens
2. Check authentication headers
3. Test authentication separately

## Further Reading

- [Webhooks Feature Documentation](../Features/webhooks.md)
- [Events API Reference](../api/events-reference.md)
- [n8n Integration Guide](./n8n.md)
- [Windmill Integration Guide](./windmill.md)

## Support

For issues and questions:
- OpenRegister GitHub Issues
- Nextcloud Documentation
- Community Forums

