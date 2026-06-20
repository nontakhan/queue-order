const fs = require('fs');
const path = require('path');
const assert = require('assert');

const root = path.resolve(__dirname, '..');
const indexHtml = fs.readFileSync(path.join(root, 'index.html'), 'utf8');
const reportFiles = [
  'report_namrung_customer.html',
  'report_namrung_branch_00001_customer.html',
  'report_namrung_tool_customer.html',
  'report_namrung_thurakit_customer.html',
];

assert(
  indexHtml.includes('EXCLUDED_CUSTOMER_KEYWORDS'),
  'index.html should define a list of report customers to exclude'
);

for (const file of reportFiles) {
  const reportHtml = fs.readFileSync(path.join(root, file), 'utf8');
  const keywordMatch = reportHtml.match(/const REPORT_CUSTOMER_KEYWORD = '([^']+)';/);
  assert(keywordMatch, `${file} should define REPORT_CUSTOMER_KEYWORD`);

  const keyword = keywordMatch[1];
  assert(
    indexHtml.includes(keyword),
    `index.html should exclude report customer keyword: ${keyword}`
  );
}

assert(
  indexHtml.includes('EXCLUDED_CUSTOMER_KEYWORDS.some'),
  'index.html should hide any customer matching any report keyword'
);

console.log(`Verified index excludes customers used by ${reportFiles.length} report pages.`);
