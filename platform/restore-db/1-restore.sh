#!/bin/bash
# Restores the TMS SQL Server backup into a local Docker container.
# Run from anywhere:  bash "path/to/restore-db/1-restore.sh"
set -e

SA_PASSWORD='TaiLocal!2026'
CONTAINER=tai-sql
BAK_DIR="$(cd "$(dirname "$0")/../original-source" && pwd)"
BAK_FILE="TMS202602260457.bak"

echo "== Pre-flight checks =="
command -v docker >/dev/null || { echo "ERROR: Docker not found. Install Docker Desktop first: https://www.docker.com/products/docker-desktop/"; exit 1; }
docker info >/dev/null 2>&1 || { echo "ERROR: Docker is installed but not running. Open Docker Desktop and wait for it to start."; exit 1; }

if [ ! -f "$BAK_DIR/$BAK_FILE" ]; then
  echo "ERROR: $BAK_FILE not found in $BAK_DIR"; exit 1
fi
# Dropbox may show the file but keep it cloud-only. Check it's actually on disk.
ACTUAL_SIZE=$(du -k "$BAK_DIR/$BAK_FILE" | cut -f1)
if [ "$ACTUAL_SIZE" -lt 1000000 ]; then
  echo "ERROR: The .bak appears to be cloud-only (Dropbox smart sync)."
  echo "Right-click the file in Finder > 'Make Available Offline', wait for download, re-run."
  exit 1
fi

FREE_GB=$(df -g / | awk 'NR==2 {print $4}')
echo "Free disk: ${FREE_GB}GB (recommend 60+)"
[ "$FREE_GB" -lt 45 ] && echo "WARNING: low disk space — the restored DB may need 20-40GB."

echo "== Starting SQL Server container =="
if ! docker ps -a --format '{{.Names}}' | grep -q "^${CONTAINER}$"; then
  docker run -d --name $CONTAINER \
    --platform linux/amd64 \
    -e ACCEPT_EULA=Y \
    -e MSSQL_SA_PASSWORD="$SA_PASSWORD" \
    -p 1433:1433 \
    -v "$BAK_DIR":/backup:ro \
    -v tai-sql-data:/var/opt/mssql \
    mcr.microsoft.com/mssql/server:2022-latest
else
  docker start $CONTAINER >/dev/null
fi

SQLCMD="docker exec $CONTAINER /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P $SA_PASSWORD"

echo "== Waiting for SQL Server to accept connections (can take a minute on Apple Silicon) =="
for i in $(seq 1 60); do
  if $SQLCMD -Q "SELECT 1" >/dev/null 2>&1; then echo "SQL Server is up."; break; fi
  [ "$i" = "60" ] && { echo "ERROR: SQL Server didn't start. Run: docker logs $CONTAINER"; exit 1; }
  sleep 5
done

echo "== Reading backup file list =="
$SQLCMD -Q "RESTORE FILELISTONLY FROM DISK='/backup/$BAK_FILE'" -s"|" -W | head -20

echo "== Building restore command =="
MOVES=$($SQLCMD -h -1 -W -Q "SET NOCOUNT ON; RESTORE FILELISTONLY FROM DISK='/backup/$BAK_FILE'" -s"|" \
  | awk -F"|" '/\|/ {ext=($3=="L")?".ldf":".mdf"; printf "MOVE '\''%s'\'' TO '\''/var/opt/mssql/data/%s%s'\'', ", $1, $1, ext}')

echo "== Restoring TMS (7.3GB — this will take a while, likely 10-40 min) =="
$SQLCMD -l 0 -t 0 -Q "RESTORE DATABASE TMS FROM DISK='/backup/$BAK_FILE' WITH ${MOVES} REPLACE, STATS=5"

echo "== Verifying =="
$SQLCMD -d TMS -Q "SELECT COUNT(*) AS tables FROM sys.tables; SELECT COUNT(*) AS sessions FROM Session;"
echo "DONE. Now run 2-extract.sh"
