# MFS Organisations

**Version 1.0.0** · FreeScout module by [Managed FreeScout](https://managedfreescout.com) (StackPros)

Group your FreeScout customers into organisations. Link e-mail addresses to an organisation by domain
(everyone at acme.com) or by hand, and every new conversation from those addresses is placed under that
organisation automatically.

- An organisation page with details, domains, addresses, departments and all its conversations
- A coloured badge in the conversation list and on Kanban cards; click it for all conversations of that organisation
- An organisation block next to every conversation, with an optional alert note
- Search, Kanban and Workflows filters and conditions on organisation
- A REST API and webhooks (with FreeScout's official API & Webhooks module)

User manual: [MANUAL.md](MANUAL.md) · online: https://managedfreescout.com/support/mfsorganisations/
API and webhooks: [API.md](API.md) · online: https://managedfreescout.com/support/mfsorganisations-api/

## Requirements

- FreeScout 1.8 or newer
- An active MFS Organisations licence (yearly subscription, one FreeScout installation, all agents)
- Outgoing HTTPS from the FreeScout server to our licence server
- Optional: the official Kanban, Workflows and API & Webhooks modules

## Installation

1. Download `mfsorganisations.zip` from the [latest release](https://github.com/ManagedFreeScout/mfsorganisations/releases/latest).
2. Unzip it into FreeScout's `Modules/` folder, so you get `Modules/MFSOrganisations/`.
3. In FreeScout: **Manage → Modules**, activate **MFS Organisations**. The module creates its own tables
   and changes none of FreeScout's tables.
4. **Manage → Settings → MFS Organisations**: enter your licence key and click **Activate licence**.

Updates arrive through **Manage → Modules → Update now** (FreeScout reads `version.txt` on `main` and
the `mfsorganisations.zip` asset of the latest GitHub release).

## Licence

- The module asks the Managed FreeScout hub (`hub_url`, default `https://app.managedfreescout.com`)
  with only the licence key and this installation's domain:
  `POST {hub_url}/modules/mfsorganisations/license/{activate|validate|deactivate}`.
  The module holds no StackPros credentials.
- The result is stored in the shared `modules_licenses` table (row `module_alias = mfsorganisations`).
- Re-checked every 6 hours by FreeScout's scheduler. Without a valid licence the organisations stay in
  the database, but the module's screens, badges, API and webhooks are off.
- `.env` overrides (testing only): `MFSORGANISATIONS_HUB_URL`, `MFSORGANISATIONS_BUY_URL`.

## Changelog

| Version | Date | Changes |
| --- | --- | --- |
| 1.0.0 | 2026-11-07 | First release: organisations with domains, addresses and departments; badges in the list and on Kanban; organisation block with alert note; search, Kanban and Workflows support; create an organisation from a conversation; REST API and five organisation webhooks. |
