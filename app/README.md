# briefklar app

Symfony application: upload, local OCR, explanation agent, UI, MCP server.

Not scaffolded yet on purpose: create it with the current Symfony skeleton (see `../docs/tasks/B-briefklar-app.md`), then require the packages via path repositories:

```json
"repositories": [
  { "type": "path", "url": "../packages/pii-shield", "options": { "symlink": true } },
  { "type": "path", "url": "../packages/ris-client", "options": { "symlink": true } }
]
```
