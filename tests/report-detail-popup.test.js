const fs = require('fs');
const path = require('path');
const assert = require('assert');

const root = path.resolve(__dirname, '..');
const reportFiles = fs
  .readdirSync(root)
  .filter((file) => /^report_.*\.html$/.test(file));

assert(reportFiles.length > 0, 'expected at least one report page');

for (const file of reportFiles) {
  const html = fs.readFileSync(path.join(root, file), 'utf8');

  assert(
    html.includes('id="detailsModal"') && html.includes('id="detailsContent"'),
    `${file} should include the item detail modal shell`
  );
  assert(
    html.includes("const API_GET_DETAILS = './api/get_item_details.php';"),
    `${file} should use the same detail API as index.html`
  );
  assert(
    html.includes('class="detail-btn') && html.includes('showItemDetailsModal'),
    `${file} should render and handle a detail button`
  );
  assert(
    html.includes('data-unit=') && html.includes('data-price=') && html.includes('data-location-code='),
    `${file} should pass full row identity to the detail popup`
  );
  assert(
    html.includes('buildReceiveHistoryHTML(item)'),
    `${file} should show receive history in the detail popup`
  );
}

console.log(`Verified report detail popup contract for ${reportFiles.length} report pages.`);
