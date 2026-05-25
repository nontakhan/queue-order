# Batch Status Actions Design

## Goal

Add multi-select status updates to the pending-items table in `index.html` while preserving all current single-row actions, including partial receive.

## Approved User Flow

- Each visible table row gains a selection checkbox.
- The table header gains a `select all on this page` checkbox.
- Select all affects only the currently displayed page, not rows on other pages or hidden search results.
- A batch action bar above the table shows the number of selected rows and provides:
  - Receive all remaining quantities.
  - Postpone.
  - Cancel.
  - Clear selection.
- Existing row-level buttons continue to work exactly as they do now.

Selection is cleared whenever the visible result set may change:

- Changing page.
- Applying or clearing filters.
- Changing search input results.
- Loading refreshed data after an action or automatic refresh.
- Changing location or database profile.

## Batch Receive Rules

- Batch receive is available only through `Receive all`.
- The user selects the receiving employee once for the batch.
- There is no quantity entry dialog in batch mode.
- Every selected row receives its full remaining quantity: `qty - received_qty_total`.
- A partially received row is completed by receiving its remaining quantity.
- The same receiving employee is recorded for every selected item and its receive-history row.
- A selected row with no remaining quantity or with invalid identity data makes the whole batch fail before any changes are committed.

Single-row `รับแล้ว` remains unchanged and continues to allow quantity entry for partial receive.

## Batch Postpone And Cancel Rules

- For postpone, the user chooses or enters one reason once. That same reason applies to all selected rows.
- For cancel, the user confirms once and all selected rows are cancelled.
- Both operations reset receive cumulative fields in the same manner as the existing single-row non-receive update behavior.

## Confirmation And Feedback

- A batch action is disabled until at least one row is selected.
- Before submission, show a confirmation dialog containing the action and selected item count.
- On success, show a success dialog and reload the current list; the selection is cleared.
- On failure, show the returned error and leave the stored records unchanged.

## API Design

Add a dedicated endpoint `api/update_status_batch.php` rather than changing the contract of `api/update_status.php`.

Request shape:

```text
new_status=<status>
items=<JSON array of {docno, cd_code, unit, price}>
received_by_employee=<required only for รับแล้ว>
remark=<required only when postponing with a reason>
```

The endpoint must:

- Validate that `items` is a non-empty array.
- Validate each row identity using `docno`, `cd_code`, and, when provided by the rendered row, `unit` plus `price`.
- Accept batch statuses only for `รับแล้ว`, `เลื่อน`, and `ยกเลิก`.
- Require a receiving employee for batch receive.
- Execute all row updates in one database transaction.
- Return one failure for the complete request when any selected row cannot be updated.

For batch receive, the endpoint reuses the existing cumulative receive model:

- Lock each selected item before calculating remaining quantity.
- Reject rows where remaining quantity is zero or less.
- Insert one `item_receive_history` row per selected item using its full remaining quantity.
- Update each selected row to `รับแล้ว`, set `received_qty_total = qty`, increment `received_count`, set `received_by_employee`, and clear `delivery_remark`.

For postpone or cancel, update each selected row within the same transaction using the same field-reset rules as the existing single-row endpoint.

The existing `api/update_status.php` remains the endpoint for single-row operations and is not used for multi-row orchestration.

## UI Implementation Boundary

Modify `index.html` only for the client-side batch interface:

- Track selected row identities in JavaScript state.
- Render row and header checkboxes.
- Render and update the batch action bar.
- Reuse the current employee selector and postpone-reason dialogs.
- Submit one request to `api/update_status_batch.php`.
- Stop checkbox/button clicks from opening item details.

No change is needed to history screens or schema because receive history and cumulative receive columns already exist.

## Error Handling

- Client validation prevents submission when no items are selected.
- API validation rejects malformed JSON, unsupported statuses, missing employee or item identity, missing rows, and receive attempts with no remaining quantity.
- Transaction rollback prevents partial batch completion.
- An HTTP/API error is presented through the existing SweetAlert error pattern.

## Verification

- Select individual rows and clear the selection.
- Select all on a page and confirm only visible rows are selected.
- Verify selection clears on page/filter/search/data refresh/location/profile changes.
- Batch receive pending and partially received rows; verify each becomes `รับแล้ว`, receives its full remaining amount, and records the same employee in history.
- Verify batch receive does not ask for quantity.
- Verify a batch receive containing an invalid/no-remaining row leaves every selected row unchanged.
- Batch postpone with a standard reason and a custom reason.
- Batch cancel.
- Verify the existing single-row receive flow still allows partial quantity entry.
- Verify the existing single-row postpone and cancel flows still work.

## Scope Exclusions

- Selecting records across multiple pages.
- Partially receiving multiple records in one batch.
- Altering the existing history display or database migration behavior.
