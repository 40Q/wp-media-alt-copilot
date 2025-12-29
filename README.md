# Media Alt Suggester

Acorn package that suggests accessible alt text for WordPress media items using AI providers (OpenAI, Anthropic/Claude, etc.). Suggestions can be generated from the attachment edit screen, optionally auto-filled on upload, and stored as meta.

## Installation (local package)

1. Ensure `composer.json` includes the path repository and requirement (see project root diff):
   ```json
   "repositories": [
     { "type": "path", "url": "packages/*/*", "options": { "symlink": true } }
   ],
   "require": {
     "40q/media-alt-suggester": "*"
   }
   ```
2. Install or refresh autoloaders:
   ```bash
   composer update 40q/media-alt-suggester
   ```
3. Activate **40Q Autonomy AI Hub** (required) and **40Q Media Alt Suggester** in wp-admin → Plugins.
4. Publish config if you want per-project overrides:
   ```bash
   wp acorn vendor:publish --tag=media-alt-config
   ```

## Configuration
- Env-first: if env vars exist they win; otherwise values can be edited via the admin screen.

Environment variables:
```
MEDIA_ALT_PROVIDER=openai         # default provider key
MEDIA_ALT_AUTOSUGGEST_ON_UPLOAD=false
MEDIA_ALT_AUTO_FILL_EMPTY_ALT=false

OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o-mini
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-3-5-sonnet-latest
```

Config (`config/media-alt.php`) allows per-provider endpoints, models, and token budgets. `prompt.max_words` and `prompt.tone` control prompt guidance.

Admin screen: settings live under Autonomy AI → Alt Text Copilot (hub required; standalone menu is not shown).

## Usage

- In WP Admin > Media > Edit, the **AI Alt Suggestion** controls sit directly under **Alternative Text**:
  - Click **Generate suggestion** to fetch text from the selected provider.
  - Review the suggestion (follows the W3C alt decision tree) and click **Use suggestion for alt text** to copy into the native field.
  - If the image is purely decorative, leave the alt field empty as recommended.
- Optional: set `MEDIA_ALT_AUTOSUGGEST_ON_UPLOAD=true` to auto-prime `_ai_alt_suggestion` when a new media item is uploaded (skips if alt text exists). Enable `MEDIA_ALT_AUTO_FILL_EMPTY_ALT=true` to write directly to the alt meta when empty.
- REST endpoint for tooling: `POST /wp-json/media-alt-suggester/v1/suggest/{attachmentId}` with `provider` payload, requires `upload_files` capability.

## Extending

- Add new providers by extending `BaseClient` and registering the driver name in `AiClientFactory`.
- Hook `media_alt_suggester_generation_failed` to capture failures or send logs.
- Vision support (optional, disabled by default): set `MEDIA_ALT_VISION=true` and choose `MEDIA_ALT_VISION_MODE=auto|url|base64`. Auto tries the URL first and falls back to base64 within `MEDIA_ALT_MAX_BASE64_BYTES` (default ~1.5MB). Base64 is useful for local/private media; URL is leaner for production.
- Advanced (opt-in): if local URLs aren’t public, you can set `MEDIA_ALT_PUBLIC_HOST` (and `MEDIA_ALT_PUBLIC_SCHEME`) to rewrite to a public host, and `MEDIA_ALT_CHECK_URL=true` to HEAD-check before use. Leave these unset/false unless you need them.
