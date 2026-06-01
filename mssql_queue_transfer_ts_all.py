import argparse
from dataclasses import dataclass
from datetime import datetime


MYSQL_CONFIG = {
    "host": "10.10.202.156",
    "user": "nr",
    "password": "P@ssw0rd",
    "database": "queue",
}

MYSQL_TARGET_TABLE = "transfer_data_from_mssql"
MYSQL_CREATED_AT_COLUMN = "create_at"

MSSQL_BASE_CONNECTION = (
    "DRIVER={{ODBC Driver 18 for SQL Server}};"
    "SERVER=10.10.202.225;DATABASE={database};"
    "UID=SA;PWD=P@ssw0rd!#;"
    "Encrypt=yes;TrustServerCertificate=yes;"
)

NR_QUERY = """
WITH DateAndWarehouseFilter AS (
    SELECT CAST(DATEADD(day, -15, GETDATE()) AS DATE) AS TargetStartDate,
           '05 TS' AS TargetWarehouseCode
)
SELECT
    c.SHIPFLAG, ch.LName AS branch, c.docno, c.DOCDATE, c.CUSTNAME,
    cu.lname AS user_lname, dg.lname AS dg_lname, cd.code AS cd_code,
    cs.id AS sub_id, cd.name AS cd_name, ct.lname AS ct_lname, cb.LName AS brand,
    cw.code, cw.lname, cl.code AS location_code, cl.lname AS location,
    cl.SNAME, cun.LName AS LName_unit, CAST(cs.QTY AS DECIMAL(8,2)) AS qty,
    c.REMARK, cs.UNITPRICE, cs.NETAMOUNT, d5.code AS dim5_code, d5.LName AS dim5_name
FROM cssale c
LEFT JOIN CSSALESUB cs ON c.DOCNO = cs.DOCNO
LEFT JOIN CSUSER cu ON cu.id = c.SALESMAN
LEFT JOIN CSDOCGROUP dg ON dg.id = c.DOCGROUP
LEFT JOIN CSPRODUCT cd ON cd.id = cs.PRODUCTID
LEFT JOIN CSPDBRAND cb ON cb.ID = cd.BRAND
LEFT JOIN CSCATEGORY ct ON ct.id = cd.CATEGORY
LEFT JOIN CSWAREHOUSE cw ON cw.id = cs.WHID
LEFT JOIN CSLOCATION cl ON cl.id = cs.LOCID
LEFT JOIN CSUNIT cun ON cun.id = cs.UNITID
LEFT JOIN CSBRANCH ch ON ch.ID = c.BRANCH
LEFT JOIN CSDIM5 d5 ON d5.ID = cd.DIM5
INNER JOIN DateAndWarehouseFilter dwf ON c.DOCDATE >= dwf.TargetStartDate AND cw.code = dwf.TargetWarehouseCode
WHERE c.docno NOT LIKE 'HCR%'

UNION ALL

SELECT
    c.SHIPFLAG, ch.LName AS branch, c.docno, c.DOCDATE, c.CUSTNAME,
    cu.lname AS user_lname, dg.lname AS dg_lname, cd.code AS cd_code,
    cs.id AS sub_id, cd.name AS cd_name, ct.lname AS ct_lname, cb.LName AS brand,
    cw.code, cw.lname, cl.code AS location_code, cl.lname AS location,
    cl.SNAME, cun.LName AS LName_unit, CAST(cs.QTY AS DECIMAL(8,2)) AS qty,
    c.REMARK, cs.UNITPRICE, cs.NETAMOUNT, d5.code AS dim5_code, d5.LName AS dim5_name
FROM csgd c
LEFT JOIN CSGDSUB cs ON c.DOCNO = cs.DOCNO
LEFT JOIN CSUSER cu ON cu.id = c.SALESMAN
LEFT JOIN CSDOCGROUP dg ON dg.id = c.DOCGROUP
LEFT JOIN CSPRODUCT cd ON cd.id = cs.PRODUCTID
LEFT JOIN CSPDBRAND cb ON cb.ID = cd.BRAND
LEFT JOIN CSCATEGORY ct ON ct.id = cd.CATEGORY
LEFT JOIN CSWAREHOUSE cw ON cw.id = cs.WHID
LEFT JOIN CSLOCATION cl ON cl.id = cs.LOCID
LEFT JOIN CSUNIT cun ON cun.id = cs.UNITID
LEFT JOIN CSBRANCH ch ON ch.id = c.BRANCH
LEFT JOIN CSDIM5 d5 ON d5.ID = cd.DIM5
INNER JOIN DateAndWarehouseFilter dwf ON c.DOCDATE >= dwf.TargetStartDate AND cw.code = dwf.TargetWarehouseCode
WHERE c.docno NOT LIKE 'HCR%'

UNION ALL

SELECT
    '0' AS SHIPFLAG, ch.LName AS branch, c.docno, c.DOCDATE, c.CUSTNAME,
    cu.lname AS user_lname, dg.lname AS dg_lname, cd.code AS cd_code,
    cs.id AS sub_id, cd.name AS cd_name, ct.lname AS ct_lname, cb.LName AS brand,
    cw.code, cw.lname, cl.code AS location_code, cl.lname AS location,
    cl.SNAME, cun.LName AS LName_unit, CAST(cs.QTY AS DECIMAL(8,2)) AS qty,
    c.REMARK, '0' AS UNITPRICE, '0' AS NETAMOUNT, d5.code AS dim5_code, d5.LName AS dim5_name
FROM CSSTKTF c
LEFT JOIN CSSTKTFSUB cs ON c.DOCNO = cs.DOCNO
LEFT JOIN CSUSER cu ON cu.id = c.userid
LEFT JOIN CSDOCGROUP dg ON dg.id = c.DOCGROUP
LEFT JOIN CSPRODUCT cd ON cd.id = cs.PRODUCTID
LEFT JOIN CSPDBRAND cb ON cb.ID = cd.BRAND
LEFT JOIN CSCATEGORY ct ON ct.id = cd.CATEGORY
LEFT JOIN CSWAREHOUSE cw ON cw.id = cs.WHID
LEFT JOIN CSLOCATION cl ON cl.id = cs.LOCID
LEFT JOIN CSUNIT cun ON cun.id = cs.UNITID
LEFT JOIN CSBRANCH ch ON ch.id = c.BRANCH
LEFT JOIN CSDIM5 d5 ON d5.ID = cd.DIM5
INNER JOIN DateAndWarehouseFilter dwf ON c.DOCDATE >= dwf.TargetStartDate AND cw.code = dwf.TargetWarehouseCode

UNION ALL

SELECT
    '0' AS SHIPFLAG, ch.LName AS branch, c.docno, c.DOCDATE, '-' AS CUSTNAME,
    cu.lname AS user_lname, dg.lname AS dg_lname, cd.code AS cd_code,
    cs.id AS sub_id, cd.name AS cd_name, ct.lname AS ct_lname, cb.LName AS brand,
    cw.code, cw.lname, cl.code AS location_code, cl.lname AS location,
    cl.SNAME, cun.LName AS LName_unit, CAST(cs.QTY AS DECIMAL(8,2)) AS qty,
    c.REMARK, '0' AS UNITPRICE, '0' AS NETAMOUNT, d5.code AS dim5_code, d5.LName AS dim5_name
FROM CSSTKOUT c
LEFT JOIN CSSTKOUTSUB cs ON c.DOCNO = cs.DOCNO
LEFT JOIN CSUSER cu ON cu.id = c.userid
LEFT JOIN CSDOCGROUP dg ON dg.id = c.DOCGROUP
LEFT JOIN CSPRODUCT cd ON cd.id = cs.PRODUCTID
LEFT JOIN CSPDBRAND cb ON cb.ID = cd.BRAND
LEFT JOIN CSCATEGORY ct ON ct.id = cd.CATEGORY
LEFT JOIN CSWAREHOUSE cw ON cw.id = cs.WHID
LEFT JOIN CSLOCATION cl ON cl.id = cs.LOCID
LEFT JOIN CSUNIT cun ON cun.id = cs.UNITID
LEFT JOIN CSBRANCH ch ON ch.id = c.BRANCH
LEFT JOIN CSDIM5 d5 ON d5.ID = cd.DIM5
INNER JOIN DateAndWarehouseFilter dwf ON c.DOCDATE >= dwf.TargetStartDate AND cw.code = dwf.TargetWarehouseCode

UNION ALL

SELECT
    '0' AS SHIPFLAG, ch.lname AS branch, l.docno, l.DOCDATE, l.CUSTNAME,
    cu.lname AS user_lname, dg.lname AS dg_lname, cd.code AS cd_code,
    le.id AS sub_id, cd.name AS cd_name, ct.lname AS ct_lname, cb.LName AS brand,
    cw.code, cw.lname, cl.code AS location_code, cl.lname AS location,
    cl.SNAME, cun.LName AS LName_unit, CAST(le.QTY AS DECIMAL(8,2)) AS qty,
    l.REMARK, le.UNITPRICE, le.NETAMOUNT, d5.code AS dim5_code, d5.LName AS dim5_name
FROM CSSALERET l
LEFT JOIN CSSALERETSUB le ON l.DOCNO = le.DOCNO
LEFT JOIN CSUSER cu ON cu.id = l.SALESMAN
LEFT JOIN CSDOCGROUP dg ON dg.id = l.DOCGROUP
LEFT JOIN CSPRODUCT cd ON cd.id = le.PRODUCTID
LEFT JOIN CSPDBRAND cb ON cb.ID = cd.BRAND
LEFT JOIN CSCATEGORY ct ON ct.id = cd.CATEGORY
LEFT JOIN CSWAREHOUSE cw ON cw.id = le.WHID
LEFT JOIN CSLOCATION cl ON cl.id = le.LOCID
LEFT JOIN CSUNIT cun ON cun.id = le.UNITID
LEFT JOIN CSBRANCH ch ON ch.ID = l.BRANCH
LEFT JOIN CSDIM5 d5 ON d5.ID = cd.DIM5
INNER JOIN DateAndWarehouseFilter dwf ON l.DOCDATE >= dwf.TargetStartDate AND cw.code = dwf.TargetWarehouseCode

ORDER BY DOCDATE, docno;
"""

PO_QUERY_TEMPLATE = """
WITH DateAndWarehouseFilter AS (
    SELECT CAST(DATEADD(day, -15, GETDATE()) AS DATE) AS TargetStartDate
)
SELECT
    '0' AS SHIPFLAG, ch.LName AS branch, c.docno, c.DOCDATE, c.VENDORNAME AS CUSTNAME,
    cu.lname AS user_lname, dg.lname AS dg_lname, cd.code AS cd_code,
    cs.id AS sub_id, cd.name AS cd_name, ct.lname AS ct_lname, cb.LName AS brand,
    cw.code, cw.lname, d5.code AS location_code, d5.LName AS location,
    cl.SNAME, cun.LName AS LName_unit, CAST(cs.QTY AS DECIMAL(8,2)) AS qty,
    c.REMARK, cs.UNITPRICE, cs.NETAMOUNT, d5.code AS dim5_code, d5.LName AS dim5_name
FROM CSPO c
LEFT JOIN CSPOSUB cs ON c.DOCNO = cs.DOCNO
LEFT JOIN CSUSER cu ON cu.id = c.userid
LEFT JOIN CSDOCGROUP dg ON dg.id = c.DOCGROUP
LEFT JOIN CSPRODUCT cd ON cd.id = cs.PRODUCTID
LEFT JOIN CSPDBRAND cb ON cb.ID = cd.BRAND
LEFT JOIN CSCATEGORY ct ON ct.id = cd.CATEGORY
LEFT JOIN CSWAREHOUSE cw ON cw.id = cs.WHID
LEFT JOIN CSLOCATION cl ON cl.id = cs.LOCID
LEFT JOIN CSUNIT cun ON cun.id = cs.UNITID
LEFT JOIN CSBRANCH ch ON ch.ID = c.BRANCH
LEFT JOIN CSDIM5 d5 ON d5.ID = cd.DIM5
INNER JOIN DateAndWarehouseFilter dwf ON c.DOCDATE >= dwf.TargetStartDate
WHERE cd.DIM5 <> '-1' AND c.VENDORNAME = '{vendor_name}'

ORDER BY c.DOCDATE, c.docno;
"""


@dataclass(frozen=True)
class TransferJob:
    name: str
    database: str
    query: str

    @property
    def mssql_connection_string(self):
        return MSSQL_BASE_CONNECTION.format(database=self.database)


TRANSFER_JOBS = {
    "nr": TransferJob("nr", "NRVAT", NR_QUERY),
    "nk": TransferJob(
        "nk",
        "NKVAT",
        PO_QUERY_TEMPLATE.format(vendor_name="บริษัท ยะลานำรุ่ง จำกัด (สาขา 3)"),
    ),
    "nrt": TransferJob(
        "nrt",
        "NRTVAT",
        PO_QUERY_TEMPLATE.format(vendor_name="บริษัท ยะลานำรุ่ง จำกัด (สาขา 3)"),
    ),
    "nrtkvat": TransferJob(
        "nrtkvat",
        "NRTKVAT",
        PO_QUERY_TEMPLATE.format(vendor_name="บริษัท ยะลานำรุ่ง จำกัด (สาขา 3)"),
    ),
}


def normalize_row_data(row):
    row_data = list(row)
    for index, value in enumerate(row_data):
        if isinstance(value, datetime):
            row_data[index] = value.strftime("%Y-%m-%d %H:%M:%S")
        elif isinstance(value, float):
            row_data[index] = str(value)
        elif value is None:
            row_data[index] = None
    return row_data


def build_insert_sql(column_names):
    insert_placeholders = ", ".join(["%s"] * len(column_names))
    update_set_clause = ", ".join(
        f"{col}=VALUES({col})"
        for col in column_names
        if col.lower() not in ["docno", "sub_id", "cd_code", MYSQL_CREATED_AT_COLUMN]
    )

    return f"""
    INSERT INTO {MYSQL_TARGET_TABLE} ({", ".join(column_names)})
    VALUES ({insert_placeholders})
    ON DUPLICATE KEY UPDATE
    {update_set_clause}
    """


def ensure_created_at_column(mysql_cursor):
    mysql_cursor.execute(
        """
        SELECT COUNT(*) AS total
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = %s
          AND COLUMN_NAME = %s
        """,
        (MYSQL_TARGET_TABLE, MYSQL_CREATED_AT_COLUMN),
    )
    exists = mysql_cursor.fetchone()[0] > 0
    if not exists:
        mysql_cursor.execute(
            f"""
            ALTER TABLE {MYSQL_TARGET_TABLE}
            ADD COLUMN {MYSQL_CREATED_AT_COLUMN} DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            AFTER last_update
            """
        )
        print(f"Added column {MYSQL_TARGET_TABLE}.{MYSQL_CREATED_AT_COLUMN}.")
        mysql_cursor.execute(
            f"""
            UPDATE {MYSQL_TARGET_TABLE}
            SET {MYSQL_CREATED_AT_COLUMN} = COALESCE(last_update, {MYSQL_CREATED_AT_COLUMN}, NOW())
            """
        )
        return

    mysql_cursor.execute(
        f"""
        UPDATE {MYSQL_TARGET_TABLE}
        SET {MYSQL_CREATED_AT_COLUMN} = COALESCE(last_update, NOW())
        WHERE {MYSQL_CREATED_AT_COLUMN} IS NULL
           OR {MYSQL_CREATED_AT_COLUMN} = '0000-00-00 00:00:00'
        """
    )


def get_row_key(row):
    doc_no_val = row.docno if hasattr(row, "docno") else "N/A"
    sub_id_val = row.sub_id if hasattr(row, "sub_id") else "N/A"
    cd_code_val = row.cd_code if hasattr(row, "cd_code") else "N/A"
    return doc_no_val, sub_id_val, cd_code_val


def transfer_data_from_mssql_to_mysql(job, dry_run=False):
    import mysql.connector
    import pyodbc

    mssql_conn = None
    mssql_cursor = None
    mysql_conn = None
    mysql_cursor = None

    current_log_date = datetime.now().date().strftime("%Y-%m-%d")
    print(f"\n=== Starting job: {job.name} ({job.database}) ===")
    print(f"--- Starting data transfer process for data from {current_log_date} onwards ---")

    try:
        print("Connecting to SQL Server...")
        mssql_conn = pyodbc.connect(job.mssql_connection_string)
        mssql_cursor = mssql_conn.cursor()
        print("Connected to SQL Server.")

        if not dry_run:
            print("Connecting to MySQL...")
            mysql_conn = mysql.connector.connect(**MYSQL_CONFIG)
            mysql_cursor = mysql_conn.cursor()
            ensure_created_at_column(mysql_cursor)
            mysql_conn.commit()
            print("Connected to MySQL.")

        print("Fetching data from SQL Server...")
        mssql_cursor.execute(job.query)

        rows = mssql_cursor.fetchall()
        column_names = [column[0] for column in mssql_cursor.description]
        print(f"Fetched {len(rows)} rows from SQL Server.")
        print(f"Columns: {', '.join(column_names)}")

        if not rows:
            print("No data to transfer. Skipping.")
            return

        if dry_run:
            print("Dry run enabled. Skipping MySQL UPSERT.")
            return

        insert_sql = build_insert_sql(column_names)
        processed_keys = set()
        skipped_rows_summary = []

        print(f"Starting data UPSERT to MySQL table: {MYSQL_TARGET_TABLE}...")
        for index, row in enumerate(rows):
            current_key = get_row_key(row)

            if current_key in processed_keys:
                skipped_rows_summary.append(f"Row {index + 1} with Key: {current_key}")
                continue
            processed_keys.add(current_key)

            try:
                mysql_cursor.execute(insert_sql, tuple(normalize_row_data(row)))

                if (index + 1) % 100 == 0:
                    mysql_conn.commit()
                    print(f"Processed {index + 1} rows...")

            except mysql.connector.Error as err:
                print(f"  [ERROR] MySQL error on row {index + 1} (Key: {current_key}): {err}")
                print(f"  [ERROR] Problematic row data: {row}")
                continue

            except Exception as err:
                print(f"  [ERROR] An unexpected error occurred for row {index + 1} (Key: {current_key}): {err}")
                print(f"  [ERROR] Problematic row data: {row}")
                continue

        mysql_conn.commit()
        print(f"Data transfer completed successfully! Transferred {len(processed_keys)} unique rows.")

        if skipped_rows_summary:
            print("\n--- Skipped Rows Summary ---")
            print(f"Total skipped rows due to duplicate keys in source: {len(skipped_rows_summary)}")
            for summary in skipped_rows_summary:
                print(f"- {summary}")
        else:
            print("\n--- No rows were skipped. ---")

    except pyodbc.Error as ex:
        sqlstate = ex.args[0]
        print(f"MSSQL Connection Error: {sqlstate}")
        print(f"Message: {ex}")
    except mysql.connector.Error as err:
        print(f"MySQL Connection or Query Error: {err}")
    except Exception as err:
        print(f"An unexpected error occurred: {err}")
    finally:
        if mssql_cursor:
            mssql_cursor.close()
        if mssql_conn:
            mssql_conn.close()
        if mysql_cursor:
            mysql_cursor.close()
        if mysql_conn:
            mysql_conn.close()
        print("Database connections closed.")
        print(f"=== Finished job: {job.name} ({job.database}) ===")


def parse_args():
    parser = argparse.ArgumentParser(description="Transfer queue data from MSSQL databases to MySQL.")
    parser.add_argument(
        "--job",
        choices=["all", *TRANSFER_JOBS.keys()],
        default="all",
        help="Select one transfer job to run. Default: all.",
    )
    parser.add_argument(
        "--dry-run",
        action="store_true",
        help="Fetch data from SQL Server and skip MySQL UPSERT.",
    )
    return parser.parse_args()


if __name__ == "__main__":
    args = parse_args()
    jobs = TRANSFER_JOBS.values() if args.job == "all" else [TRANSFER_JOBS[args.job]]

    for transfer_job in jobs:
        transfer_data_from_mssql_to_mysql(transfer_job, dry_run=args.dry_run)

    print("\n--- Data transfer process finished. ---")
