# Amsterdam Weather Webhook - Performance Report

## Overview

Successfully created and deployed an n8n workflow that returns Amsterdam weather data in JSON format via webhook.

## Workflow Details

**Workflow Name:** Amsterdam Weather Webhook  
**Workflow ID:** `q1gQao78IoU5XGES`  
**Status:** Active ✅  
**Template Location:** `n8n-templates/amsterdam-weather-webhook.json`

## Webhook URL

```
http://localhost:5678/webhook/amsterdam-weather
```

## Workflow Architecture

The workflow consists of 3 nodes that execute sequentially:

1. **Webhook Trigger Node**
   - Type: `n8n-nodes-base.webhook`
   - HTTP Method: GET
   - Path: `amsterdam-weather`
   - Response Mode: `lastNode` (returns the output of the last node)

2. **HTTP Request Node**
   - Type: `n8n-nodes-base.httpRequest`
   - Method: GET
   - URL: `https://wttr.in/Amsterdam?format=j1`
   - Purpose: Fetches weather data from wttr.in API

3. **Code Node (JavaScript)**
   - Type: `n8n-nodes-base.code`
   - Purpose: Transforms raw weather data into clean JSON format
   - Extracts: Location, temperature, weather conditions, humidity, wind, pressure, visibility, UV index, cloud cover

## Performance Metrics

### Response Time Analysis

| Metric | Value | Notes |
|--------|-------|-------|
| **Average Total Time** | 7-8 seconds | Varies based on external API |
| **DNS Lookup** | ~0.0005s | Very fast |
| **TCP Connect** | ~0.0006s | Very fast |
| **Time to First Byte** | ~7.4s | Main bottleneck (external API) |
| **Range** | 4-23 seconds | Depends on wttr.in API load |

### Performance Notes

- The majority of response time (>99%) is spent waiting for the wttr.in weather API
- Internal n8n processing (webhook trigger + data transformation) takes < 10ms
- Response time is highly dependent on external API availability and performance
- For production use, consider:
  - Adding caching layer
  - Implementing timeout handling
  - Adding retry logic
  - Using a more reliable weather API with SLA guarantees

## Response Format

```json
{
  'location': {
    'city': 'Amsterdam',
    'country': 'Netherlands',
    'region': 'North Holland'
  },
  'current': {
    'temperature_celsius': '2',
    'temperature_fahrenheit': '36',
    'feels_like_celsius': '-1',
    'feels_like_fahrenheit': '31',
    'weather_description': 'Clear',
    'humidity': '87',
    'wind_speed_kmph': '10',
    'wind_direction': 'ENE',
    'pressure_mb': '1031',
    'visibility_km': '10',
    'uv_index': '0',
    'cloudcover': '0'
  },
  'timestamp': '2025-12-28T17:05:04.381Z'
}
```

## Usage Examples

### cURL

```bash
curl http://localhost:5678/webhook/amsterdam-weather
```

### With timing information

```bash
curl -w '\nTotal Time: %{time_total}s\n' http://localhost:5678/webhook/amsterdam-weather
```

### JavaScript (fetch)

```javascript
fetch('http://localhost:5678/webhook/amsterdam-weather')
  .then(response => response.json())
  .then(data => console.log(data));
```

### Python

```python
import requests

response = requests.get('http://localhost:5678/webhook/amsterdam-weather')
weather = response.json()
print(f'Temperature: {weather["current"]["temperature_celsius"]}°C')
```

## Template Installation

The workflow template has been saved to:
```
n8n-templates/amsterdam-weather-webhook.json
```

To install in a new n8n instance:

1. Open n8n web interface
2. Click 'Add workflow' or '+' button
3. Click the three-dot menu (⋮) → 'Import from File'
4. Select `amsterdam-weather-webhook.json`
5. Click 'Save' and toggle to 'Active'

## Use Cases

This template demonstrates:

- ✅ **Webhook triggers** - How to set up GET request webhooks
- ✅ **External API calls** - Making HTTP requests to third-party APIs
- ✅ **Data transformation** - Using JavaScript to transform API responses
- ✅ **JSON responses** - Returning structured data from webhooks
- ✅ **Error handling** - n8n's built-in error handling for failed nodes

## Learning Points

1. **Response Mode:** Using `lastNode` in webhook configuration automatically returns the output of the final node
2. **No Respond to Webhook Node Needed:** When using `lastNode` mode, a separate 'Respond to Webhook' node is not required (and will cause errors)
3. **HTTP Request Node:** Requires `method` parameter in typeVersion 4.2+
4. **Code Node:** Can access input data via `$input.first().json` and return transformed data directly

## Known Issues

- Response time varies significantly (4-23s) depending on wttr.in API performance
- No caching implemented - each request hits the external API
- No timeout handling - may hang if external API is unresponsive
- No retry logic for failed API calls

## Future Improvements

- [ ] Add response caching (e.g., cache for 5 minutes)
- [ ] Implement timeout handling
- [ ] Add retry logic for failed requests
- [ ] Support for multiple cities via query parameters
- [ ] Error response formatting
- [ ] Add rate limiting
- [ ] Health check endpoint

## Files Modified/Created

1. ✅ Created: `n8n-templates/amsterdam-weather-webhook.json`
2. ✅ Updated: `n8n-templates/README.md` (added example template section)
3. ✅ Active workflow in n8n (ID: q1gQao78IoU5XGES)

## Verification

```bash
# Check workflow status
curl -s 'http://localhost:5678/api/v1/workflows/q1gQao78IoU5XGES' \
  -H 'X-N8N-API-KEY: <your-api-key>' | jq '{id, name, active}'

# Test webhook
curl -s 'http://localhost:5678/webhook/amsterdam-weather' | jq .

# Check execution history
curl -s 'http://localhost:5678/api/v1/executions?workflowId=q1gQao78IoU5XGES&limit=5' \
  -H 'X-N8N-API-KEY: <your-api-key>' | jq '.data[] | {id, status, mode}'
```

## Conclusion

Successfully created a working example n8n webhook workflow that:
- ✅ Triggers via HTTP GET request
- ✅ Calls external weather API
- ✅ Transforms and returns JSON data
- ✅ Executes through all 3 nodes successfully
- ✅ Saved as reusable template in `n8n-templates/`
- ✅ Documented in README.md

**Average Response Time:** ~7-8 seconds (limited by external API)

---

*Created: 2025-12-28*  
*n8n Version: 1.120.4*  
*OpenRegister App: apps-extra/openregister*

