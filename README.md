# Clareo Altee Job Sync

WordPress plugin that pulls job offers from the Altee XML feed and publishes them in HireZoot.

Feed: https://career.altee.com/xml/clareo

## Build the zip for upload

From the parent folder (`WP-Clareo-Altee-Plugin`), in PowerShell:

```powershell
Remove-Item -Force "clareo-altee-sync.zip"
tar -a -c -f "clareo-altee-sync.zip" "clareo-altee-sync"
```

The zip must contain:

```
clareo-altee-sync/clareo-altee-job-sync.php
clareo-altee-sync/README.md
```

The folder is named `clareo-altee-sync` so it does not collide with a broken `clareo-altee-job-sync` install. Leave the old plugin deactivated if you cannot delete it.

## Install

1. In WordPress go to **Extensions → Ajouter → Téléverser une extension**.
2. Upload `clareo-altee-sync.zip`.
3. Activate **Clareo Altee Job Sync**.
4. Open **Postes ouverts → Altee Sync**.
5. Click **Synchroniser maintenant**.

**Test mode is on.** Only the first job in the XML (`job[0]`) is imported. To import the full feed, set `CLAREO_ALTEE_TEST_FIRST_ONLY` to `false` in `clareo-altee-job-sync.php`.

After the first sync, jobs refresh automatically every hour.

## Update (replace the zip)

WordPress will not overwrite a plugin folder that already exists. Remove the current install first:

1. Go to **Extensions**.
2. **Désactiver** Clareo Altee Job Sync.
3. **Supprimer** Clareo Altee Job Sync.
4. **Extensions → Ajouter → Téléverser une extension** and upload the new `clareo-altee-sync.zip`.
5. Activate the plugin.
6. Open **Postes ouverts → Altee Sync** and click **Synchroniser maintenant**.

If **Supprimer** fails or the plugin comes back after refresh, the files are still on the server. Delete `wp-content/plugins/clareo-altee-sync/` in the hosting file manager, then upload the zip again.

Imported jobs are kept. Deleting the plugin does not delete HireZoot listings.

## What it does

- Creates or updates HireZoot jobs from the Altee feed.
- Only manages jobs it imported (identified by the Altee reference number). Hand-posted jobs are left alone.
- Expires imported jobs that disappear from the feed.
- On imported jobs, **Postuler** sends candidates to the Altee career page (new tab). Existing Clareo application forms on old posts are unchanged.

## Field mapping

| HireZoot | Altee | Notes |
|---|---|---|
| Title | `{title} – {team}` | Example: `Secrétaire dentaire – Institut Dentaire Grande Allée` |
| Content | `html_description` | First paragraph bold. Section titles bold. *Complice de ton mieux-être* / *Complice de ton succès* underlined, with bullets under each. `#Clareo1` removed. |
| Apply | `url` | `/emplois/details/{id}` becomes `/emplois/apply/{id}#Titre du poste` (é stays é, spaces become `%20`). |
| Titre du poste (`job-category`) | Job title | Matched to existing HireZoot tags (Dentiste, Hygiéniste dentaire, Assistant(e) dentaire, …). Unmatched titles are left empty. |
| Ville ou région (`job-location`) | City | Creates the city if it does not exist. |
| Clinique (`clinique`) | Team | Creates the clinic if it does not exist. |
| Statut ou type (`job-type`) | `jobtype` | See below. |
| Type de pratique (`type-de-pratique`) | — | Left empty. |

### Statut ou type

| Altee `jobtype` | HireZoot |
|---|---|
| *(empty)* | Permanent |
| Full-Time | Temps plein |
| Part-Time | Temps partiel |
| Temporary | Temporaire |
| Contractor | Contrateur |

Add **Contrateur** under HireZoot → **Spécifications du poste** → Statut ou type if it is not already in the list.

## After the first sync

Imported jobs sit next to the old hand-posted ones. Check a few imported listings, then trash the duplicates you no longer need.

## Requirements

- HireZoot (WP Job Openings) active
- PHP with SimpleXML
- The site must get traffic (or a real server cron) so WordPress hourly cron can run
