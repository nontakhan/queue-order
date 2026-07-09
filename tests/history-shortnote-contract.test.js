const fs = require('fs');
const path = require('path');
const assert = require('assert');

const root = path.resolve(__dirname, '..');
const historyHtml = fs.readFileSync(path.join(root, 'history.html'), 'utf8');
const bootstrapPhp = fs.readFileSync(path.join(root, 'api', '_bootstrap.php'), 'utf8');
const fetchItemsPhp = fs.readFileSync(path.join(root, 'api', 'fetch_items.php'), 'utf8');
const setupPartialReceivePhp = fs.readFileSync(path.join(root, 'setup_partial_receive.php'), 'utf8');
const updateShortnotePath = path.join(root, 'api', 'update_shortnote.php');

assert(
  /ค้นหา DocNo\s*\/\s*ชื่อลูกค้า\s*\/\s*ชื่อสินค้า/.test(historyHtml),
  'history search label should mention product-name search'
);

assert(
  fetchItemsPhp.includes('(docno LIKE ? OR custname LIKE ? OR cd_name LIKE ?)'),
  'fetch_items.php should search docno, customer name, and product name'
);

assert(
  historyHtml.includes('id="shortnoteSearchInput"') && historyHtml.includes('id="shortnoteSearchBtn"'),
  'history.html should provide a dedicated shortnote search input and button'
);

assert(
  historyHtml.includes('id="searchFiltersRow"') &&
    historyHtml.includes('id="dateFiltersRow"') &&
    historyHtml.includes('lg:grid-cols-2') &&
    historyHtml.includes('md:grid-cols-2'),
  'history filters should put both search inputs on the first row and both date inputs on the next row'
);

assert(
  fetchItemsPhp.includes("$shortnoteSearch") && fetchItemsPhp.includes('shortnote LIKE ?'),
  'fetch_items.php should support a dedicated shortnote search parameter'
);

assert(
  fetchItemsPhp.includes('shortnote') && historyHtml.includes('item.shortnote'),
  'shortnote should be returned by the API and rendered in the history table'
);

assert(
  historyHtml.includes('class="shortnote-btn') &&
    historyHtml.includes('buildShortnoteButtonHTML') &&
    historyHtml.includes('showShortnoteAddModal') &&
    historyHtml.includes('data-has-shortnote='),
  'history.html should render shortnote as a stateful icon button that can open an add-note modal'
);

assert(
  historyHtml.includes('shortnote-btn--has-note') &&
    historyHtml.includes('shortnote-btn--empty') &&
    historyHtml.includes('showItemDetailsModal(row.dataset)'),
  'shortnote button should use separate colors for existing/missing notes and open the existing detail modal when a note exists'
);

assert(
  !historyHtml.includes('class="shortnote-input') &&
    !historyHtml.includes('class="save-shortnote-btn'),
  'history table should not show inline shortnote inputs or inline save buttons'
);

assert(
  setupPartialReceivePhp.includes("'transfer_data_from_mssql', 'shortnote'"),
  'setup_partial_receive.php should add transfer_data_from_mssql.shortnote when missing'
);

assert(
  bootstrapPhp.includes('function app_ensure_transfer_shortnote_column') &&
    fetchItemsPhp.includes('app_ensure_transfer_shortnote_column($conn)'),
  'fetch_items.php should ensure transfer_data_from_mssql.shortnote exists before querying it'
);

assert(fs.existsSync(updateShortnotePath), 'api/update_shortnote.php should exist');

const updateShortnotePhp = fs.readFileSync(updateShortnotePath, 'utf8');
assert(
  updateShortnotePhp.includes('UPDATE transfer_data_from_mssql') && updateShortnotePhp.includes('shortnote = ?'),
  'update_shortnote.php should persist shortnote to transfer_data_from_mssql.shortnote'
);

console.log('Verified history search and shortnote contract.');
