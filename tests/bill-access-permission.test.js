const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const bootstrap = read('api/_bootstrap.php');
const fetchMyBills = read('api/fetch_my_bills.php');
const getMyBillDetails = read('api/get_my_bill_details.php');
const deleteMyBills = read('api/delete_my_bills.php');
const adminSaveUser = read('api/admin_save_user.php');
const adminGetUsers = read('api/admin_get_users.php');
const setupSql = read('setup_users_and_locations.sql');
const setupEmployeeLogin = read('setup_employee_login.php');
const adminHtml = read('admin.html');
const indexHtml = read('index.html');
const loginHtml = read('login.html');

const checks = [
    {
        name: 'bootstrap exposes can_view_all_bills in user payload',
        ok: bootstrap.includes("'can_view_all_bills'") && bootstrap.includes('function app_can_view_all_bills'),
    },
    {
        name: 'My Bills list uses can_view_all_bills helper instead of role-only admin',
        ok: fetchMyBills.includes('app_can_view_all_bills($user)') && !fetchMyBills.includes("$isAdmin = ($user['role'] ?? '') === 'admin';"),
    },
    {
        name: 'My Bills detail uses can_view_all_bills helper instead of role-only admin',
        ok: getMyBillDetails.includes('app_can_view_all_bills($user)') && !getMyBillDetails.includes("$isAdmin = ($user['role'] ?? '') === 'admin';"),
    },
    {
        name: 'My Bills delete uses can_view_all_bills helper instead of role-only admin',
        ok: deleteMyBills.includes('app_can_view_all_bills($user)') && !deleteMyBills.includes("$isAdmin = ($user['role'] ?? '') === 'admin';"),
    },
    {
        name: 'admin user APIs read and save can_view_all_bills',
        ok: adminGetUsers.includes('can_view_all_bills')
            && adminGetUsers.includes('app_ensure_user_bill_access_column($conn)')
            && adminSaveUser.includes('$canViewAllBills')
            && adminSaveUser.includes('can_view_all_bills')
            && adminSaveUser.includes('app_ensure_user_bill_access_column($conn)'),
    },
    {
        name: 'setup scripts include can_view_all_bills schema',
        ok: setupSql.includes('`can_view_all_bills` TINYINT(1) NOT NULL DEFAULT 0')
            && setupEmployeeLogin.includes('can_view_all_bills')
            && setupEmployeeLogin.includes('foreach (app_db_profiles() as $profile)'),
    },
    {
        name: 'admin UI includes bill access control',
        ok: adminHtml.includes('can_view_all_bills') && adminHtml.includes('ดูบิลทุกพนักงานขาย'),
    },
    {
        name: 'admin page access remains role-admin gated',
        ok: adminHtml.includes("result.user.role !== 'admin'"),
    },
    {
        name: 'index and login route all-bill users to My Bills, not admin',
        ok: indexHtml.includes('can_view_all_bills')
            && loginHtml.includes("result.user && result.user.role === 'admin' ? 'admin.html' : 'my_bills.html'"),
    },
];

const failed = checks.filter((check) => !check.ok);

if (failed.length > 0) {
    console.error('Bill access permission checks failed:');
    failed.forEach((check) => console.error(`- ${check.name}`));
    process.exit(1);
}

console.log('bill access permission checks passed');
