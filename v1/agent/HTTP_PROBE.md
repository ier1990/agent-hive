# http_probe Tool

A deterministic, first-class tool for probing URLs and monitoring endpoint health.

## Usage

### Via Tool Name
```bash
curl -X POST http://127.0.0.1/v1/agent/ \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "http_probe",
    "params": {
      "url": "https://example.com",
      "method": "HEAD",
      "timeout": 10
    }
  }'
```

### Via Intent Matching
```bash
curl -X POST http://127.0.0.1/v1/agent/ \
  -H "Content-Type: application/json" \
  -d '{
    "intent": "probe this URL",
    "params": {"url": "https://example.com"}
  }'
```

## Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `url` | string | required | Full URL to probe (must be valid http/https) |
| `method` | string | HEAD | HTTP method: HEAD (default), GET, POST |
| `timeout` | number | 10 | Timeout in seconds (1-60) |

## Response

On success (HTTP 2xx):
```json
{
  "ok": true,
  "tool": "http_probe",
  "source": "db",
  "result": {
    "ok": true,
    "url": "https://example.com",
    "final_url": "https://example.com/",
    "method": "HEAD",
    "status_code": 200,
    "content_type": "text/html; charset=utf-8",
    "content_length": 12345,
    "redirects": 1,
    "timing": {
      "total_ms": 245.5,
      "connect_ms": 52.3,
      "firstbyte_ms": 240.1
    },
    "snippet_hash": "9e08b93a7840a76148f1492bcff53708",
    "snippet_size": 500
  },
  "duration_ms": 250
}
```

## Response Fields

| Field | Description |
|-------|-------------|
| `status_code` | HTTP response code (200, 404, 503, etc.) |
| `final_url` | URL after following redirects |
| `redirects` | Number of redirects followed |
| `content_type` | Response Content-Type header |
| `content_length` | Size of response body in bytes |
| `snippet_hash` | MD5 hash of first 500 bytes (for change detection) |
| `snippet_size` | Actual size of snippet extracted |
| `timing.total_ms` | Total request time |
| `timing.connect_ms` | Time to establish connection |
| `timing.firstbyte_ms` | Time to first response byte |

## Use Cases

- **Health checks**: Monitor if endpoints are up
- **Availability tracking**: Track status codes over time
- **Redirect detection**: Find final URL after redirects
- **Performance monitoring**: Track connection and response times
- **Content change detection**: Use snippet_hash to detect changes

## Examples

### Check if server is up
```bash
curl -X POST http://127.0.0.1/v1/agent/ \
  -H "Content-Type: application/json" \
  -d '{"tool": "http_probe", "params": {"url": "https://api.example.com"}}'
```

### Follow redirects and get final URL
```bash
curl -X POST http://127.0.0.1/v1/agent/ \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "http_probe",
    "params": {"url": "https://bit.ly/example"}
  }'
```

### Get full response with GET method
```bash
curl -X POST http://127.0.0.1/v1/agent/ \
  -H "Content-Type: application/json" \
  -d '{
    "tool": "http_probe",
    "params": {
      "url": "https://api.example.com/status",
      "method": "GET",
      "timeout": 20
    }
  }'
```

## Logging

All executions are logged to the `tool_runs` table:
- **tool_name**: "http_probe"
- **input_hash**: MD5 of parameters
- **success**: 1 if status_code 2xx, 0 otherwise
- **duration_ms**: Time in milliseconds
- **client_ip**: Requester IP address
- **created_at**: Timestamp

Query execution history:
```bash
sqlite3 /web/private/db/agent_tools.db \
  "SELECT url, status_code, duration_ms FROM tool_runs WHERE tool_name = 'http_probe';"
```

## Implementation

- **Language**: PHP
- **Location**: `/web/private/db/agent_tools.db` (stored procedure)
- **Keywords**: http, url, probe, test, request, status, check, availability, monitoring
- **Approval Status**: Pre-approved
