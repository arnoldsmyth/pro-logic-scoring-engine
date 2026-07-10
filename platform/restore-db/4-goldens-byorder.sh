#!/bin/bash
# Extracts golden masters for specific PRO-D order IDs (matched against the
# externalid "{order_id}::{token}" inside stored register requests).
# Usage: bash 4-goldens-byorder.sh            (uses the built-in French list)
#        bash 4-goldens-byorder.sh 123 456    (explicit order ids)
set -o pipefail

ORDERS="${@:-14995 14994 14984 14983 14982 14981 13945 13944 13943 13942 13941 13940 13939 13938 11644 11526 11525 11524 11523 11522 10962 10859 10386 10355 9815}"

SA_PASSWORD='TaiLocal!2026'
CONTAINER=tai-sql
OUT_DIR="$(cd "$(dirname "$0")" && pwd)/goldens"
mkdir -p "$OUT_DIR"

BASE="docker exec $CONTAINER /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P $SA_PASSWORD -d master"
$BASE -Q "SET NOCOUNT ON; SELECT 1;" >/dev/null 2>&1 || { echo "ERROR: cannot reach SQL Server (docker start tai-sql)"; exit 1; }

PROBE_Q="SET NOCOUNT ON; SELECT CAST(REPLICATE('x',300) AS nvarchar(max)) AS [ ];"
MODE="__none__"
for COMBO in "-h -1 -y 0" "-y 0" "-h -1 -W" "-W" ""; do
  CNT=$($BASE $COMBO -Q "$PROBE_Q" 2>/dev/null | tr -d ' \r' | awk '/^x+$/ && length($0)>=300 {c++} END{print c+0}')
  if [ "${CNT:-0}" -ge 1 ]; then MODE="$COMBO"; break; fi
done
[ "$MODE" = "__none__" ] && { echo "ERROR: no working sqlcmd mode."; exit 1; }
SQL() { $BASE $MODE -Q "$1" 2>/dev/null | sed -e 's/[[:space:]]*$//' -e '/^-\{3,\}$/d' -e '/^$/d'; }

[ -f "$OUT_DIR/_index.csv" ] || echo "SessionKey|Gender|LanguageKey|ScoreDate" > "$OUT_DIR/_index.csv"
[ -f "$OUT_DIR/_orders.csv" ] || echo "OrderID|SessionKey|SessionID|Status" > "$OUT_DIR/_orders.csv"

extract_session() {
  local K=$1
  local D="$OUT_DIR/$K"; mkdir -p "$D"
  SQL "SET NOCOUNT ON; SELECT CAST(s.SessionKey AS varchar(20))+'|'+ISNULL(RTRIM(s.Gender),'?')+'|'+ISNULL(CAST(r.LanguageKey AS varchar(10)),'?')+'|'+ISNULL(CONVERT(varchar(30),s.ScoreDate,121),'not-scored') AS [ ] FROM TMS.dbo.Session s LEFT JOIN TMS.dbo.SessionResults r ON r.SessionKey=s.SessionKey WHERE s.SessionKey=$K;" >> "$OUT_DIR/_index.csv"
  SQL "SET NOCOUNT ON; SELECT TOP 1 Request AS [ ] FROM TMS.dbo.WebServiceRequestQueue WHERE SessionKey=$K AND [Transaction]='register' ORDER BY WebServiceRequestQueueID DESC;" > "$D/request.json"
  SQL "SET NOCOUNT ON; SELECT TOP 1 ISNULL(Response,'') AS [ ] FROM TMS.dbo.WebServiceRequestQueue WHERE SessionKey=$K AND [Transaction]='register' ORDER BY WebServiceRequestQueueID DESC;" > "$D/register_response.json"
  SQL "SET NOCOUNT ON; SELECT ISNULL(JSONResultsKeys,'') AS [ ] FROM TMS.dbo.SessionResults WHERE SessionKey=$K;" > "$D/results_keys.json"
  SQL "SET NOCOUNT ON; SELECT ISNULL(JSONResultsStrings,'') AS [ ] FROM TMS.dbo.SessionResults WHERE SessionKey=$K;" > "$D/results_strings.json"
  { echo "TypeKey|Sequence|ArcheTypeDetailKey|InsightDetailKey|String"
    SQL "SET NOCOUNT ON; SELECT CAST(SessionOutStringTypeKey AS varchar(10))+'|'+ISNULL(CAST(Sequence AS varchar(10)),'')+'|'+ISNULL(CAST(ArcheTypeDetailKey AS varchar(10)),'')+'|'+ISNULL(CAST(InsightDetailKey AS varchar(10)),'')+'|'+ISNULL(REPLACE(REPLACE(REPLACE(String,CHAR(13),' '),CHAR(10),' '),'|','!'),'') AS [ ] FROM TMS.dbo.SessionOutString WHERE SessionKey=$K ORDER BY SessionOutStringTypeKey, Sequence;"
  } > "$D/outstrings.csv"
  echo "  session $K: request $(wc -c < "$D/request.json" | tr -d ' ')B, keys $(wc -c < "$D/results_keys.json" | tr -d ' ')B, strings $(wc -c < "$D/results_strings.json" | tr -d ' ')B, outstrings $(($(wc -l < "$D/outstrings.csv")-1)) rows"
}

FOUND=0; MISSING=""
for O in $ORDERS; do
  ROW=$(SQL "SET NOCOUNT ON; SELECT TOP 1 ISNULL(CAST(SessionKey AS varchar(20)),'?')+'|'+ISNULL(CAST(SessionID AS varchar(20)),'?')+'|'+CAST(Status AS varchar(10)) AS [ ] FROM TMS.dbo.WebServiceRequestQueue WHERE [Transaction]='register' AND Request LIKE '%\"externalid\":\"$O::%' ORDER BY WebServiceRequestQueueID DESC;")
  if [ -z "$ROW" ]; then
    echo "order $O: NOT FOUND in request queue"; MISSING="$MISSING $O"
    echo "$O|not-found||" >> "$OUT_DIR/_orders.csv"; continue
  fi
  K="${ROW%%|*}"
  echo "order $O -> session $ROW"
  echo "$O|$ROW" >> "$OUT_DIR/_orders.csv"
  if [[ "$K" =~ ^[0-9]+$ ]]; then extract_session "$K"; FOUND=$((FOUND+1)); else echo "  (no SessionKey — registration likely failed; see _orders.csv)"; fi
done

# De-dupe index
{ head -1 "$OUT_DIR/_index.csv"; tail -n +2 "$OUT_DIR/_index.csv" | sort -t'|' -k1,1n -u; } > "$OUT_DIR/_index.tmp" && mv "$OUT_DIR/_index.tmp" "$OUT_DIR/_index.csv"

echo "DONE. Extracted $FOUND sessions.${MISSING:+ Missing orders:$MISSING}"
