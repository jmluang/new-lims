# Integrating Sphere System Code Plan

Date: 2026-08-20
Branch: `feature/integrating-sphere-system-code`
Owner: Codex (architecture, review, acceptance)
Implementer: Claude through Herdr

## Goal

Add an independently scanned or manually entered equipment-system code to every new integrating-sphere inspection record. Display it immediately after the sample number in the inspection record list and remove the used-equipment summary from that list. The per-record detail and global used-equipment ledger continue to expose device information.

## Data contract

Add nullable historical fields to `integrating_sphere_inspection_records` through a new migration:

- `equipment_system_id`: nullable foreign key to `equipment_systems`, `nullOnDelete`
- `system_code`: nullable string snapshot with an index

The columns stay nullable for existing records. New records require a live, active equipment system; existing records remain editable when their linked system is renamed, disabled, or deleted.

The system code is an independent operator input. Do not infer it from selected equipment and do not add a new device-to-system consistency restriction.

## API

Extend the existing scan/manual lookup endpoint:

- `GET /api/integrating-sphere-inspection-records/lookup?type=system&code=sys-01`

Return `id`, `code`, `name`, and `status`. New lookup selections must resolve an active `equipment_systems` row.

Creation requires `equipment_system_id`, snapshots the current `equipment_systems.code`, and returns both `equipment_system_id` and `system_code`.

Update follows the existing retained/selected model:

- a system loaded from the record is `retained` and omitted from `PUT`, preserving the historical code
- an explicit scan/manual lookup is `selected` and sends `equipment_system_id`, replacing the snapshot
- a deleted linked system remains represented by `equipment_system_id = null` plus the stored `system_code`

Include `system_code` in inspection-list search. Do not change the global used-equipment ledger contract.

## UI

The create/edit sequence is:

```text
+---------------------------------------------------------------+
| 使用设备（先录入）                                             |
+---------------------------------------------------------------+
| 系统编码 [扫码/手输________________] [添加] [打开扫码]           |
| sys-01 · 系统1                                                 |
+---------------------------------------------------------------+
| 样品编号 [扫码/手输________________] [添加] [打开扫码]           |
+---------------------------------------------------------------+
| 测量值 ...                                                     |
+---------------------------------------------------------------+
```

The inspection record list becomes:

```text
ID | 样品编号 | 系统编码 | 色品坐标 X | 色品坐标 Y | ... | 操作
```

- Place `系统编码` immediately after `样品编号` on desktop.
- Remove the `使用设备` column and its values from the desktop inspection record list.
- Add `系统编码` to mobile record cards.
- Remove the equipment-number summary from mobile record cards.
- Add the system code to record detail.
- Change the record-list search label/placeholder to mention sample number and system code while retaining backend device search compatibility.
- Keep the global `使用设备总表` unchanged.

Follow the existing refined, utilitarian LIMS visual language. Reuse `QrScannerPanel`; do not add another camera/scanner implementation.

## Tests and acceptance

### Backend

- Lookup resolves active system codes for creators/editors without requiring `equipment_systems.read`.
- Disabled/unknown system codes cannot be newly selected.
- Create requires a live active system and snapshots its code.
- Default edit preserves the code after ledger rename, disable, or delete.
- Explicit replacement snapshots the selected system's current code.
- List search matches `system_code`.
- Existing legacy rows with null system fields remain readable/editable.
- Audit payloads include the snapshot fields.

### Frontend

- System scanner appears after the sample scanner and uses `QrScannerPanel`.
- Create payload requires and sends a selected live system.
- Update omits a retained system and sends only an explicitly selected replacement.
- Orphaned and retained system notices are visible and mutually exclusive with the selected notice.
- Desktop list shows ID, sample number, then system code, and does not render the used-equipment column.
- Mobile cards show system code and do not render the equipment summary.
- Detail shows system code; global used-equipment table remains unchanged.

### Gates

- Focused and full backend/frontend tests pass.
- Pint, lint, production build, fresh migrations, and `git diff --check` pass.
- Real Chrome desktop acceptance verifies scan/manual system lookup, save, list column order, hidden used-equipment column, and detail.
- Real Chrome mobile acceptance verifies the system scanner and record card without the used-equipment summary.
- Permission acceptance uses the canonical sample-manager account.
- Do not commit or push during implementation/review.
- Preserve the detached deployment worktree and the three unrelated untracked PDF plans.
