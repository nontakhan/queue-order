# Partial Receive Design

## Goal

Add support for receiving only part of an order line while preserving the current full receive flow. Staff should be able to record how many units were received each time, who received them, and later continue receiving the remaining quantity.

## Current Behavior

The current `index.html` receive button sends `docno`, `cd_code`, `new_status`, and `received_by_employee` to `api/update_status.php`. The API updates `transfer_data_from_mssql.delivery_status` to `รับแล้ว` and stores the selected staff name in `received_by_employee`.

There is no separate receive history. A line is either pending, postponed, cancelled, or fully received.

## New Behavior

When a user clicks `รับแล้ว`, the system will:

- Ask for the receiving staff name using the existing employee selection flow.
- Show a receive quantity popup.
- Show total quantity, already received quantity, and remaining quantity.
- Let staff enter the received quantity for this attempt.
- Include calculator-style buttons for easier arithmetic at the counter.
- Validate that the received quantity is greater than zero and not more than the remaining quantity.
- Save one history row for the receive attempt.
- Update the original item's cumulative receive fields.
- Set the item status to `รับบางส่วน` if the item is not fully received.
- Set the item status to `รับแล้ว` only when the cumulative received quantity reaches the original quantity.

The staff name stored in history is the selected receiving staff name only. The system will not store the admin or logged-in user who clicked save.

## Database Design

Each configured database profile in `db_profiles.php` must receive the same schema changes. The current profiles are expected to include `queue`, `queue_hq`, and `queue_nrg`, and the setup tool should iterate over all profiles rather than hardcoding database names.

Add cumulative fields to `transfer_data_from_mssql`:

- `received_qty_total DECIMAL(15,3) NOT NULL DEFAULT 0`
- `received_count INT NOT NULL DEFAULT 0`

Add a new table:

```sql
CREATE TABLE item_receive_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    docno VARCHAR(100) NOT NULL,
    cd_code VARCHAR(100) NOT NULL,
    received_qty DECIMAL(15,3) NOT NULL,
    received_by_employee VARCHAR(255) NOT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    note VARCHAR(500) NULL,
    INDEX idx_receive_history_item (docno, cd_code),
    INDEX idx_receive_history_received_at (received_at)
);
```

The schema setup should be idempotent, similar to `setup_received_by_employee.sql`, so it can be run safely more than once.

## API Design

Keep `api/update_status.php` backward-compatible:

- Existing status updates continue to work for `เลื่อน`, `ยกเลิก`, status reset, and old full receive calls.
- If `new_status=รับแล้ว` and `received_qty` is omitted, the API treats it as a full receive for compatibility.
- If `received_qty` is present, the API uses partial receive logic.

Partial receive logic:

- Load the target item by `docno` and `cd_code`.
- Read `qty` and current `received_qty_total`.
- Calculate remaining quantity as `qty - received_qty_total`.
- Reject quantity values less than or equal to zero.
- Reject quantity values greater than remaining quantity.
- Insert one row into `item_receive_history`.
- Update `received_qty_total`, `received_count`, `received_by_employee`, `delivery_remark = NULL`, and `delivery_status`.
- Use `รับบางส่วน` when the new total is less than `qty`.
- Use `รับแล้ว` when the new total is equal to or greater than `qty`.

The update and history insert should run in a transaction so the item and history stay consistent.

## Fetching And Display

Update `api/fetch_items.php`:

- Include `received_qty_total` and `received_count` in list results.
- For the main pending list, include rows where status is pending or `รับบางส่วน`.
- Existing history filters by status should continue to work.

Update `api/get_item_details.php`:

- Return item fields as it does today.
- Include receive history for the selected item, ordered newest first or oldest first consistently.

Update `index.html`:

- Show progress such as `รับแล้ว 40 / 100 เส้น` for partial items.
- Keep the `รับแล้ว` action available for partial items.
- Add the calculator-style receive quantity popup after staff selection.
- Keep the current success refresh behavior.

Update `history.html`:

- Support viewing `รับบางส่วน` status.
- Show receive progress in the table.
- Show receive history in the item details modal.

## Calculator Popup

The quantity popup should use SweetAlert2 like the current dialogs and should include:

- Read-only summary for total, received, and remaining.
- Numeric input for quantity received this time.
- Buttons for digits 0-9.
- Decimal point button.
- Backspace button.
- Clear button.
- `รับทั้งหมดที่เหลือ` shortcut.
- Confirm and cancel buttons.

The popup should not perform database writes until the user confirms.

## Compatibility And Migration

Existing received rows may have `delivery_status = รับแล้ว` but `received_qty_total = 0` after migration. The setup or first implementation should backfill these rows to:

- `received_qty_total = qty`
- `received_count = 1`

No historical rows can be reconstructed for old received items unless the old data already contains that history. The system should not invent detailed history for prior receives. It is acceptable for old received items to show no detailed history or one clearly marked migrated summary row if we choose to create one.

Recommended migration behavior:

- Backfill cumulative totals for old `รับแล้ว` rows.
- Do not create fake `item_receive_history` rows for old data.
- New receive actions from this feature onward create detailed history.

## Testing Plan

Manual test cases:

- Receive full remaining quantity on a pending item; status becomes `รับแล้ว`.
- Receive less than remaining quantity; status becomes `รับบางส่วน`.
- Receive the remaining quantity on a `รับบางส่วน` item; status becomes `รับแล้ว`.
- Try receiving zero; the UI/API rejects it.
- Try receiving more than remaining; the UI/API rejects it.
- Verify history rows are created per receive attempt.
- Verify each DB profile has the new schema and behavior.
- Verify postpone, cancel, delete, and status reset still work.

## Open Decisions

No open decisions remain for the approved scope.
