# Storix Documentation

**Live portal:** [https://shamimwhois.github.io/storix/](https://shamimwhois.github.io/storix/)

**Portal source:** [index.html](index.html)

The interactive documentation portal uses Tailwind CSS v4 with compiled production CSS and Alpine.js bundled with esbuild. GitHub Actions builds and deploys the static site to GitHub Pages whenever documentation changes are pushed to `main`.

## Build locally

```bash
cd docs
npm install
npm run build
```

Build output:
- `assets/app.css` — compiled and minified Tailwind CSS.
- `assets/app.js` — bundled and minified Alpine.js application.

## Detailed specifications

- [Product Requirements](01-product-requirements.md)
- [System Architecture](02-system-architecture.md)
- [Database Design](03-database-design.md)
- [Package Structure](04-package-structure.md)
- [Core PHP API and REST API](05-core-api-and-rest-api.md)
- [Transfer Engine](06-transfer-engine.md)
- [Storage Drivers](07-storage-drivers.md)
- [Security Design](08-security-design.md)
- [Testing Strategy](09-testing-strategy.md)
- [Implementation Roadmap](10-implementation-roadmap.md)
- [AI Coding Instructions](11-ai-coding-instructions.md)
- [Configuration and Operations](12-configuration-and-operations.md)
- [Acceptance Checklist](13-acceptance-checklist.md)
- [E02 Contracts & API](15-e02-contracts-and-api.md)
- [E03 Persistence Layer](16-e03-persistence.md)
- [E04 Local Storage Driver](17-e04-local-driver.md)
- [E05 Basic Upload](18-e05-basic-upload.md)
- [Architecture Decisions](DECISIONS.md)

> Documentation describes the intended engineering design. Feature availability must be confirmed from implementation, tests, and release notes.
