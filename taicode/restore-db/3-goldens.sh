#!/bin/bash
# Extracts golden-master test cases: real register-request JSON (input) +
# result JSONs and output strings (expected output) for a diverse set of
# scored sessions. Output: restore-db/goldens/<SessionKey>/...
# PRIVACY: request JSONs contain real names/emails — keep goldens/ local,
# never commit to a public repo.
set -o pipefail

SA_PASSWORD='TaiLocal!2026'
CONTAINER=tai-sql
OUT_DIR="$(cd "$(dirname "$0")" && pwd)/goldens"
mkdir -p "$OUT_DIR"

BASE="docker exec $CONTAINER /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P $SA_PASSWORD -d master"

if ! $BASE -Q "SET NOCOUNT ON; SELECT 1;" >/dev/null 2>&1; then
  echo "ERROR: cannot reach SQL Server. Is the tai-sql container running? (docker start tai-sql)"; exit 1
fi

PROBE_Q="SET NOCOUNT ON; SELECT CAST(REPLICATE('x',300) AS nvarchar(max)) AS [ ];"
MODE="__none__"
for COMBO in "-h -1 -y 0" "-y 0" "-h -1 -W" "-W" ""; do
  CNT=$($BASE $COMBO -Q "$PROBE_Q" 2>/dev/null | tr -d ' \r' | awk '/^x+$/ && length($0)>=300 {c++} END{print c+0}')
  if [ "${CNT:-0}" -ge 1 ]; then MODE="$COMBO"; break; fi
done
[ "$MODE" = "__none__" ] && { echo "ERROR: no working sqlcmd mode."; exit 1; }

SQL() { $BASE $MODE -Q "$1" 2>/dev/null | sed -e 's/[[:space:]]*$//' -e '/^-\{3,\}$/d' -e '/^$/d'; }

echo "== Selecting a diverse session set =="
# Only PRO-D web-service sessions that have both a register request and stored results.
# Mix: recent, both genders, non-English, plus a random spread of older ones.
KEYS=$(SQL "SET NOCOUNT ON;
WITH eligible AS (
  SELECT s.SessionKey, s.ScoreDate, s.Gender, r.LanguageKey
  FROM TMS.dbo.Session s
  JOIN TMS.dbo.SessionResults r ON r.SessionKey = s.SessionKey
  WHERE s.ScoreDate IS NOT NULL AND s.ScoreDate < '9000-01-01'
    AND EXISTS (SELECT 1 FROM TMS.dbo.WebServiceRequestQueue q
                WHERE q.SessionKey = s.SessionKey AND q.[Transaction] = 'register'
                  AND q.Request IS NOT NULL)
)
SELECT CAST(SessionKey AS varchar(20)) AS [ ] FROM (
  SELECT TOP 12 SessionKey FROM eligible ORDER BY ScoreDate DESC
  UNION SELECT TOP 6 SessionKey FROM eligible WHERE Gender = 'F' ORDER BY ScoreDate DESC
  UNION SELECT TOP 6 SessionKey FROM eligible WHERE LanguageKey <> 1 ORDER BY ScoreDate DESC
  UNION SELECT TOP ${1:-8} SessionKey FROM eligible ORDER BY NEWID()
) u;")

N=$(echo "$KEYS" | wc -w | tr -d ' ')
echo "Selected $N sessions."

[ -f "$OUT_DIR/_index.csv" ] || echo "SessionKey|Gender|LanguageKey|ScoreDate" > "$OUT_DIR/_index.csv"
for K in $KEYS; do
  [[ "$K" =~ ^[0-9]+$ ]] || continue
  D="$OUT_DIR/$K"; mkdir -p "$D"

  SQL "SET NOCOUNT ON; SELECT CAST(s.SessionKey AS varchar(20))+'|'+ISNULL(RTRIM(s.Gender),'?')+'|'+ISNULL(CAST(r.LanguageKey AS varchar(10)),'?')+'|'+CONVERT(varchar(30),s.ScoreDate,121) AS [ ] FROM TMS.dbo.Session s JOIN TMS.dbo.SessionResults r ON r.SessionKey=s.SessionKey WHERE s.SessionKey=$K;" >> "$OUT_DIR/_index.csv"

  SQL "SET NOCOUNT ON; SELECT TOP 1 Request AS [ ] FROM TMS.dbo.WebServiceRequestQueue WHERE SessionKey=$K AND [Transaction]='register' ORDER BY WebServiceRequestQueueID DESC;" > "$D/request.json"

  SQL "SET NOCOUNT ON; SELECT TOP 1 ISNULL(Response,'') AS [ ] FROM TMS.dbo.WebServiceRequestQueue WHERE SessionKey=$K AND [Transaction]='register' ORDER BY WebServiceRequestQueueID DESC;" > "$D/register_response.json"

  SQL "SET NOCOUNT ON; SELECT ISNULL(JSONResultsKeys,'') AS [ ] FROM TMS.dbo.SessionResults WHERE SessionKey=$K;" > "$D/results_keys.json"
  SQL "SET NOCOUNT ON; SELECT ISNULL(JSONResultsStrings,'') AS [ ] FROM TMS.dbo.SessionResults WHERE SessionKey=$K;" > "$D/results_strings.json"

  { echo "TypeKey|Sequence|ArcheTypeDetailKey|InsightDetailKey|String"
    SQL "SET NOCOUNT ON; SELECT CAST(SessionOutStringTypeKey AS varchar(10))+'|'+ISNULL(CAST(Sequence AS varchar(10)),'')+'|'+ISNULL(CAST(ArcheTypeDetailKey AS varchar(10)),'')+'|'+ISNULL(CAST(InsightDetailKey AS varchar(10)),'')+'|'+ISNULL(REPLACE(REPLACE(REPLACE(String,CHAR(13),' '),CHAR(10),' '),'|','!'),'') AS [ ] FROM TMS.dbo.SessionOutString WHERE SessionKey=$K ORDER BY SessionOutStringTypeKey, Sequence;"
  } > "$D/outstrings.csv"

  echo "  session $K: request $(wc -c < "$D/request.json" | tr -d ' ')B, keys $(wc -c < "$D/results_keys.json" | tr -d ' ')B, strings $(wc -c < "$D/results_strings.json" | tr -d ' ')B, outstrings $(($(wc -l < "$D/outstrings.csv")-1)) rows"
done

# De-dupe index (safe across repeat runs)
{ head -1 "$OUT_DIR/_index.csv"; tail -n +2 "$OUT_DIR/_index.csv" | sort -t'|' -k1,1n -u; } > "$OUT_DIR/_index.tmp" && mv "$OUT_DIR/_index.tmp" "$OUT_DIR/_index.csv"
echo "DONE. $(($(wc -l < "$OUT_DIR/_index.csv")-1)) total sessions in restore-db/goldens/"
