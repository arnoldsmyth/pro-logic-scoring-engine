# Restoring the TMS database locally (Mac)

Goal: bring the 7.3GB `TMS202602260457.bak` to life in a local Docker SQL Server so we can extract the scoring rule/norm data and golden-master test cases. Nothing leaves your machine.

## One-time setup

1. Install **Docker Desktop for Mac**: https://www.docker.com/products/docker-desktop/
   - On Apple Silicon: open Docker Desktop → Settings → General → make sure **"Use Rosetta for x86_64/amd64 emulation"** is ON (SQL Server has no ARM build).
   - Give it resources: Settings → Resources → at least 4GB memory.
2. Make sure you have **~60GB free disk** and the `.bak` file is fully downloaded (in Finder, right-click `original-source/TMS202602260457.bak` → *Make Available Offline* if it shows a cloud icon).

## Run

Open Terminal and paste:

```bash
bash "$HOME/Library/CloudStorage/Dropbox/2025 Fresh Start/Development/platform/restore-db/1-restore.sh"
```

The restore of 7.3GB takes roughly 10–40 minutes (emulation is slow). When it finishes:

```bash
bash "$HOME/Library/CloudStorage/Dropbox/2025 Fresh Start/Development/platform/restore-db/2-extract.sh"
```

This writes the scoring config tables as CSVs into `restore-db/extracted/`. Then tell Claude it's done — the CSVs are readable from the project folder.

## Useful afterwards

- Stop the DB (keeps data): `docker stop tai-sql` · start again: `docker start tai-sql`
- Connect with a GUI if curious: Azure Data Studio → `localhost,1433`, user `sa`, password `TaiLocal!2026`
- Delete everything when done: `docker rm -f tai-sql && docker volume rm tai-sql-data`

Note: `TaiLocal!2026` is a throwaway local-only password; fine to leave as is.
