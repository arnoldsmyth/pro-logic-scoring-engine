#!/bin/bash
# v4 — runs queries from master (modern compat level; the restored TMS db is
# compat-80-era so STRING_AGG etc. fail inside it), fully qualifies TMS objects,
# strips sqlcmd headers structurally. Writes pipe-delimited CSVs to extracted/.
set -o pipefail

SA_PASSWORD='TaiLocal!2026'
CONTAINER=tai-sql
OUT_DIR="$(cd "$(dirname "$0")" && pwd)/extracted"
mkdir -p "$OUT_DIR"
rm -f "$OUT_DIR"/*.csv "$OUT_DIR"/*.txt 2>/dev/null

BASE="docker exec $CONTAINER /opt/mssql-tools18/bin/sqlcmd -C -S localhost -U sa -P $SA_PASSWORD -d master"

echo "== Connectivity check =="
if ! $BASE -Q "SET NOCOUNT ON; SELECT 1;" >/dev/null 2>&1; then
  echo "ERROR: cannot reach SQL Server."; docker ps -a --filter name=$CONTAINER; exit 1
fi
echo "Connected OK. TMS compat level: $($BASE -Q "SET NOCOUNT ON; SELECT compatibility_level FROM sys.databases WHERE name='TMS';" 2>/dev/null | grep -o '[0-9]\+' | head -1)"

# Find sqlcmd flag combo that returns 300-char nvarchar(max) untruncated.
PROBE_Q="SET NOCOUNT ON; SELECT CAST(REPLICATE('x',300) AS nvarchar(max)) AS [ ];"
MODE="__none__"
for COMBO in "-h -1 -y 0" "-y 0" "-h -1 -W" "-W" ""; do
  CNT=$($BASE $COMBO -Q "$PROBE_Q" 2>/dev/null | tr -d ' \r' | awk '/^x+$/ && length($0)>=300 {c++} END{print c+0}')
  if [ "${CNT:-0}" -ge 1 ]; then MODE="$COMBO"; break; fi
done
if [ "$MODE" = "__none__" ]; then
  echo "ERROR: no working sqlcmd output mode. Send Claude this:"; $BASE -? 2>&1 | head -30; exit 1
fi
echo "Using sqlcmd flags: '${MODE:-<default>}'"

# Every query aliases its single output column AS [ ] so the header line becomes
# whitespace; sed removes trailing spaces, blank lines, and dash separator lines.
SQL() { $BASE $MODE -Q "$1" 2>/dev/null | sed -e 's/[[:space:]]*$//' -e '/^-\{3,\}$/d' -e '/^$/d'; }

echo "== 1/3 Table inventory =="
{ echo "table,rows"
  SQL "SET NOCOUNT ON; SELECT t.name + ',' + CAST(SUM(p.rows) AS varchar(20)) AS [ ] FROM TMS.sys.tables t JOIN TMS.sys.partitions p ON t.object_id=p.object_id AND p.index_id IN (0,1) GROUP BY t.name ORDER BY t.name;"
} > "$OUT_DIR/_table_rowcounts.csv"
echo "  $(wc -l < "$OUT_DIR/_table_rowcounts.csv") lines"

echo "== 2/3 Exporting config tables =="
TABLES=$(SQL "SET NOCOUNT ON;
SELECT t.name AS [ ] FROM TMS.sys.tables t JOIN TMS.sys.partitions p ON t.object_id=p.object_id AND p.index_id IN (0,1)
GROUP BY t.name
HAVING SUM(p.rows) BETWEEN 1 AND 200000
   AND (t.name LIKE '%Rule%' OR t.name LIKE '%Matrix%' OR t.name LIKE 'Tool%'
     OR t.name LIKE 'Package%' OR t.name LIKE 'Profile%' OR t.name LIKE 'Insight%'
     OR t.name LIKE 'Question%' OR t.name LIKE 'Archetype%' OR t.name LIKE '%Weight%'
     OR t.name LIKE '%Boost%' OR t.name LIKE 'Framework%' OR t.name LIKE 'Lookup%'
     OR t.name IN ('MCS','PRO','SessionOutStringType','Language','WebServiceUsers','ApiPartner',
                   'DeliveryType','ReportTemplate','WebEmail','WebEmailType','InsightCategoryType',
                   'VersionControl','DeliveryVersionControlProject'))
   AND t.name NOT LIKE '%ScaleValue' AND t.name NOT LIKE '%Status' AND t.name NOT LIKE 'Debug%'
ORDER BY t.name;")

FAILED=0
for T in $TABLES; do
  [[ "$T" =~ ^[A-Za-z0-9_]+$ ]] || continue   # skip any header/separator junk
  SELLIST=$(SQL "SET NOCOUNT ON; SELECT STRING_AGG(CAST('ISNULL(REPLACE(REPLACE(REPLACE(TRY_CONVERT(nvarchar(max),[' + name + ']),CHAR(13),'' ''),CHAR(10),'' ''),''|'',''!''),''NULL'')' AS nvarchar(max)), ' + ''|'' + ') WITHIN GROUP (ORDER BY column_id) AS [ ] FROM TMS.sys.columns WHERE object_id=OBJECT_ID('TMS.dbo.$T');")
  if [ -z "$SELLIST" ] || [ "$SELLIST" = "NULL" ]; then echo "  skipped $T (no column list)"; FAILED=$((FAILED+1)); continue; fi
  SQL "SET NOCOUNT ON; SELECT STRING_AGG(name,'|') WITHIN GROUP (ORDER BY column_id) AS [ ] FROM TMS.sys.columns WHERE object_id=OBJECT_ID('TMS.dbo.$T');" > "$OUT_DIR/$T.csv"
  if SQL "SET NOCOUNT ON; SELECT $SELLIST AS [ ] FROM TMS.dbo.[$T];" >> "$OUT_DIR/$T.csv"; then
    echo "  exported $T ($(($(wc -l < "$OUT_DIR/$T.csv")-1)) rows)"
  else
    echo "  FAILED $T"; rm -f "$OUT_DIR/$T.csv"; FAILED=$((FAILED+1))
  fi
done

echo "== 3/3 Golden-master candidates =="
{ echo "SessionKey|SessionID|ScoreDate|WebServiceUserID"
  SQL "SET NOCOUNT ON;
SELECT TOP 25 CAST(SessionKey AS varchar(20))+'|'+CAST(SessionID AS varchar(20))+'|'+CONVERT(varchar(30),ScoreDate,121)+'|'+ISNULL(CAST(WebServiceUserID AS varchar(10)),'NULL') AS [ ]
FROM TMS.dbo.Session WHERE ScoreDate IS NOT NULL AND ScoreDate < '9000-01-01' ORDER BY ScoreDate DESC;"
} > "$OUT_DIR/_recent_scored_sessions.txt"

echo "DONE. $(ls "$OUT_DIR" | wc -l | tr -d ' ') files in restore-db/extracted/ ($FAILED failures)"
