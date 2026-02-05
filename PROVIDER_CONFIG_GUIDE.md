# AI Provider Configuration Guide

This guide explains how to add and configure AI providers in the AgentHive admin interface.

## Supported Providers

The system supports the following AI providers:

### Built-in Providers

1. **Local (LM Studio)** - `local`
   - URL: `http://127.0.0.1:1234/v1`
   - No API key required
   - Best for local development and testing
   - Default provider

2. **Ollama** - `ollama`
   - URL: `http://127.0.0.1:11434/v1`
   - No API key required
   - Open-source LLM runtime
   - Good for self-hosted deployments

3. **OpenAI** - `openai`
   - URL: `https://api.openai.com/v1`
   - **Requires API key** (starts with `sk-`)
   - Highest quality, most capable models
   - Usage-based pricing

4. **OpenRouter** - `openrouter`
   - URL: `https://openrouter.ai/api/v1`
   - **Requires API key** (starts with `sk-or-`)
   - Unified interface for multiple providers
   - Model routing and fallback support
   - See: https://openrouter.ai/docs

5. **Anthropic (Claude)** - `anthropic`
   - URL: `https://api.anthropic.com/v1`
   - **Requires API key** (starts with `sk-ant-`)
   - High-quality Claude models
   - Strong reasoning and analysis capabilities

6. **Custom Provider** - `custom`
   - URL: User-defined
   - Optional API key
   - For any OpenAI-compatible API
   - Examples:
     - Groq: `https://api.groq.com/openai/v1`
     - Together.ai: `https://api.together.xyz/v1`
     - LocalAI: `http://localhost:8080/v1`

## Configuration in Admin UI

### Adding a Provider

1. Visit `/admin/admin_AI_Setup.php`
2. Click **+ Add New Connection**
3. Select a provider from the dropdown
4. **For custom providers:**
   - Select "Custom Provider"
   - Enter your provider's API URL in the "Base URL" field
   - Optionally enter an API key if required

### Form Fields

- **Provider** (Required)
  - Choose from preset or custom options
  - Auto-fills the base URL for presets

- **Base URL** (Required)
  - API endpoint URL for the provider
  - Must start with `http://` or `https://`
  - Auto-filled for preset providers
  - User-editable for custom providers

- **API Key** (Conditional)
  - Required for: OpenAI, OpenRouter, Anthropic
  - Optional for: Local, Ollama, Custom
  - Stored securely in the database
  - Never exposed in logs or exports

- **Timeout** (Optional)
  - Default: 120 seconds
  - Applies to model queries and API calls
  - Recommended: 10-300 seconds

## Model Selection

After entering provider details:

1. Click **Test Connection & Load Models**
2. System will query available models from the provider
3. Select a model from the dropdown
4. Optionally customize the connection name
5. Click **Save Connection**

## Provider-Specific Notes

### OpenAI
- Models: `gpt-4o`, `gpt-4-turbo`, `gpt-4o-mini`, etc.
- Requires valid API key
- Supports function calling and vision features

### OpenRouter
- Access to 100+ models (OpenAI, Anthropic, Meta, etc.)
- Single API key for multiple providers
- Automatic model routing and fallback
- Supports streaming and function calling

### Anthropic
- Models: `claude-3-5-sonnet`, `claude-3-opus`, `claude-3-haiku`
- Strong at reasoning, analysis, and long-context tasks
- Requires valid Anthropic API key

### Custom Provider
- **Compatible with:** Any OpenAI-compatible API
- **Examples:**
  - Local models via LocalAI, vLLM, text-generation-webui
  - Alternative endpoints like Groq, Together.ai
  - Self-hosted LLM deployments

## Backend Configuration

Providers are resolved using `ai_settings_resolve_for_provider()` in `/web/html/lib/ai_bootstrap.php`:

```php
// Environment variables (if set, override database settings)
OPENAI_BASE_URL      // Override any provider's base URL
OPENAI_API_KEY       // API key (supports all providers)
OPENAI_MODEL         // Model selection
LLM_BASE_URL         // Alternative base URL
LLM_API_KEY          // Alternative API key

// Database settings (per-connection)
provider    // openai, ollama, local, openrouter, anthropic, custom
base_url    // Provider's API endpoint
api_key     // Authentication token
model       // Selected model identifier
```

## Validation Rules

- **URL Format:** Must start with `http://` or `https://`
- **API Key Requirements:**
  - OpenAI: Mandatory
  - OpenRouter: Mandatory
  - Anthropic: Mandatory
  - Local/Ollama/Custom: Optional
- **Model:** Required for all providers

## Saved Connections

All saved connections are displayed below the form:
- Active connection marked with green indicator
- Connection details: Name, ID, created date
- Actions: Set as active, edit, delete
- Use the **Copy** button to save connection ID for scripting

## Security Considerations

1. **API Keys** are stored in `/web/private/db/codewalker_settings.db`
2. **Never commit** the database file to version control
3. **Environment variables** take precedence over database settings
4. **Keys are never logged** in debug output or audit trails
5. Use strong API keys and rotate regularly
6. Consider using rate limiting for self-hosted deployments

## Troubleshooting

### "Connection failed - Unable to list models"
- Verify the base URL is correct
- Check if API key is required and provided
- Ensure the provider is accessible from your network
- Check firewall/proxy settings

### "Base URL must start with http:// or https://"
- Verify URL format
- Remove trailing slashes from custom URLs
- Use the full URL including `/v1` path for OpenAI-compatible APIs

### "API key is required"
- Verify the provider requires a key (OpenAI, OpenRouter, Anthropic)
- Check that the key is non-empty and valid

### Custom provider not responding
- Test the endpoint with `curl`:
  ```bash
  curl -X POST https://your-url/v1/models \
    -H "Authorization: Bearer your-key" \
    -H "Content-Type: application/json"
  ```
- Verify CORS headers if calling from browser
- Check provider documentation for API endpoint paths

## Examples

### Using OpenRouter
```
Provider: OpenRouter
URL: https://openrouter.ai/api/v1
API Key: sk-or-XXXXXXXXXXXX
Model: openai/gpt-4-turbo
```

### Using Anthropic
```
Provider: Anthropic
URL: https://api.anthropic.com/v1
API Key: sk-ant-XXXXXXXXXXXX
Model: claude-3-5-sonnet-20241022
```

### Using Custom Provider (LocalAI)
```
Provider: Custom Provider
URL: http://localhost:8080/v1
API Key: (leave blank)
Model: gpt-3.5-turbo
```
