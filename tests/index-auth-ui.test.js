const fs = require('fs');
const path = require('path');

const indexHtml = fs.readFileSync(path.join(__dirname, '..', 'index.html'), 'utf8');

const requiredSnippets = [
    'const API_AUTH_LOGOUT',
    'id="currentUserPanel"',
    'id="currentUserName"',
    'id="logoutBtn"',
    'handleLogout',
    "fetch(API_AUTH_LOGOUT, { method: 'POST', credentials: 'same-origin' })",
    "window.location.href = 'login.html'",
];

const missing = requiredSnippets.filter((snippet) => !indexHtml.includes(snippet));

if (missing.length > 0) {
    console.error('index.html is missing authenticated-user UI/logout snippets:');
    missing.forEach((snippet) => console.error(`- ${snippet}`));
    process.exit(1);
}

console.log('index auth UI checks passed');
