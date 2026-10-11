"""Validate import SQL against real MariaDB; no SQL Server connections are made."""
import ast
import json
import os
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get('QUEUE_TEST_PHP', 'C:/xampp/php/php.exe')
PORT = int(os.environ.get('QUEUE_TEST_MYSQL_PORT', '3307'))


class DeletedImportTest(unittest.TestCase):
    def test_archived_identities_are_excluded_in_all_four_imports(self):
        for file in sorted(ROOT.glob('mssql_queue_transfer_*_all.py')):
            with self.subTest(file=file.name):
                tree = ast.parse(file.read_text(encoding='utf-8-sig'))
                # Execute the real SQL builder without loading credentials or external drivers.
                builder = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'build_insert_sql')
                namespace = {'MYSQL_TARGET_TABLE': 'transfer_data_from_mssql', 'MYSQL_CREATED_AT_COLUMN': 'create_at'}
                exec(compile(ast.Module(body=[builder], type_ignores=[]), str(file), 'exec'), namespace)
                self.assertIn('exclude_deleted', namespace['build_insert_sql'].__code__.co_varnames, 'import can recreate archived rows')
                columns = ['docno', 'sub_id', 'cd_code', 'qty']
                sql = namespace['build_insert_sql'](columns, exclude_deleted=True).replace('%s', '?')
                legacy = namespace['build_insert_sql'](columns)
                # The fixture uses temporary tables in the mysql system schema, never app data.
                source = r'''
                    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
                    $c = new mysqli('127.0.0.1','root','','mysql',__PORT__);
                    $c->set_charset('utf8mb4');
                    $c->query("CREATE TEMPORARY TABLE transfer_data_from_mssql (docno VARCHAR(50),sub_id VARCHAR(50),cd_code VARCHAR(50),qty INT,PRIMARY KEY(docno,sub_id,cd_code)) ENGINE=InnoDB");
                    $c->query("CREATE TEMPORARY TABLE deleted_transfer_items (docno VARCHAR(50),sub_id VARCHAR(50),cd_code VARCHAR(50)) ENGINE=InnoDB");
                    $c->query("INSERT INTO deleted_transfer_items VALUES ('DOC','1','SKU')");
                    $sql = json_decode(base64_decode('__SQL_BASE64__'), true);
                    $stmt=$c->prepare($sql);
                    foreach ([['DOC','1','SKU','7','DOC','1','SKU'], ['DOC','2','SKU','8','DOC','2','SKU'], ['DOC','2','SKU','9','DOC','2','SKU']] as $params) {
                        $stmt->bind_param('sssssss', ...$params); $stmt->execute();
                    }
                    $rows=$c->query('SELECT docno,sub_id,qty FROM transfer_data_from_mssql ORDER BY sub_id')->fetch_all(MYSQLI_ASSOC);
                    echo json_encode($rows);
                '''
                import base64
                source = source.replace('__PORT__', str(PORT)).replace('__SQL_BASE64__', base64.b64encode(json.dumps(sql).encode()).decode())
                result = subprocess.run([PHP, '-r', source], capture_output=True, text=True)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertEqual(json.loads(result.stdout), [{'docno':'DOC', 'sub_id':'2', 'qty':'9'}])
                self.assertIn('VALUES (', legacy, 'databases without an archive keep the existing import behavior')


if __name__ == '__main__':
    unittest.main()
