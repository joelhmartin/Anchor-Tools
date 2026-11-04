// @ts-check
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');

/**
 * Anchor Library file manager (admin): drag and drop onto folders / crumbs,
 * Move to... with Undo, top-level Duplicate, the OS-file overlay rule and the
 * Permissions modal failing closed.
 *
 * Requires wp-env and `npm run env:seed`, which enables the `library` module,
 * creates a page holding [anchor_file_manager] and the fixture tree
 *   E2E Lib A  (E2E Lib A1, e2e-a.txt)      E2E Lib B
 * and writes the ids to e2e/.seed.json. Every test starts by putting the tree back
 * the way the seed left it (through the page's own AJAX), so cases run in any order.
 */

const SEED = JSON.parse(fs.readFileSync(path.join(__dirname, '.seed.json'), 'utf8'));
const NAME_A = 'E2E Lib A';
const NAME_A1 = 'E2E Lib A1';
const NAME_B = 'E2E Lib B';

async function wpAdminLogin(page) {
  await page.goto('/wp-login.php');
  if (!(await page.locator('#user_login').isVisible().catch(() => false))) return;
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await Promise.all([page.waitForURL(/wp-admin/), page.click('#wp-submit')]);
}

/** Call a library AJAX action from the page (its nonce, its session). */
function ajax(page, action, data) {
  return page.evaluate(
    ([a, d]) => new Promise((resolve) => {
      window.jQuery.post(window.AnchorFM.ajax, Object.assign({ action: a, nonce: window.AnchorFM.nonce }, d))
        .always((res, _s, xhr) => resolve(res && res.success !== undefined ? res : (xhr && xhr.responseJSON) || res));
    }),
    [action, data || {}]
  );
}

async function resetTree(page) {
  const top = await ajax(page, 'anchor_fm_list', { folder_id: 0 });
  const folders = (top && top.data && top.data.folders) || [];
  // Copies made by a case ("<name> (copy)") are removed.
  for (const f of folders) {
    if (/^E2E Lib .*\(copy\)/.test(f.name)) await ajax(page, 'anchor_fm_delete_folder', { folder_id: f.id });
  }
  await ajax(page, 'anchor_fm_move_items', {
    items: JSON.stringify([{ kind: 'folder', id: SEED.library_folder_a }, { kind: 'folder', id: SEED.library_folder_b }]),
    target_folder_id: 0,
  });
  await ajax(page, 'anchor_fm_move_items', {
    items: JSON.stringify([{ kind: 'folder', id: SEED.library_folder_a1 }, { kind: 'file', id: SEED.library_file_id }]),
    target_folder_id: SEED.library_folder_a,
  });
}

const row = (page, kind, id) => page.locator(`[data-afm-row="${kind}:${id}"]`);
const treeBtn = (page, id) => page.locator(`.afm__treeBtn[data-afm-open-folder="${id}"]`);
const toast = (page) => page.locator('.afm__toast');

/** Open the portal page and wait for the root listing. */
async function openLibrary(page) {
  await page.goto(SEED.library_page_url);
  await expect(row(page, 'folder', SEED.library_folder_a)).toBeVisible();
}

async function openFolder(page, id) {
  await treeBtn(page, id).click();
  await expect(page.locator(`[data-afm-crumb="${id}"]`)).toBeVisible();
}

/** The folder an item is in now, straight from the server. */
async function listFolder(page, id) {
  const res = await ajax(page, 'anchor_fm_list', { folder_id: id });
  const d = (res && res.data) || {};
  return {
    folders: (d.folders || []).map((f) => f.name),
    files: (d.files || []).map((f) => f.name),
  };
}

test.describe.configure({ mode: 'serial' });
// Tall viewport: the page chrome sits above the file manager, and a drag needs the
// source row and the target (a crumb, a tree node) on screen at once.
test.use({ viewport: { width: 1440, height: 1600 } });

test.beforeEach(async ({ page }) => {
  await wpAdminLogin(page);
  await openLibrary(page);
  await resetTree(page);
  await openLibrary(page);
});

test('(a) drag a folder onto another folder, then Undo', async ({ page }) => {
  await openFolder(page, SEED.library_folder_a);
  const src = row(page, 'folder', SEED.library_folder_a1);
  await expect(src).toBeVisible();
  // The target is B's node in the sidebar tree: A1 lives inside A, so B's list row is not on screen.
  await src.dragTo(treeBtn(page, SEED.library_folder_b));

  await expect(toast(page)).toContainText(`Moved 1 item to ${NAME_B}`);
  await expect(page.locator('.afm__toastAction')).toHaveText('Undo');
  expect((await listFolder(page, SEED.library_folder_b)).folders).toContain(NAME_A1);
  // C2: a move keeps you in the folder you were in.
  await expect(page.locator(`[data-afm-crumb="${SEED.library_folder_a}"]`)).toBeVisible();
  await expect(row(page, 'folder', SEED.library_folder_a1)).toHaveCount(0);

  await page.locator('.afm__toastAction').click();
  await expect(toast(page).filter({ hasText: 'Move undone' })).toBeVisible();
  await expect(row(page, 'folder', SEED.library_folder_a1)).toBeVisible();
  expect((await listFolder(page, SEED.library_folder_a)).folders).toContain(NAME_A1);
  expect((await listFolder(page, SEED.library_folder_b)).folders).not.toContain(NAME_A1);
});

test('(a2) drag a folder row onto a folder row in the same list', async ({ page }) => {
  // The "E2E Lib B" row is on screen at Home: drag the A folder row onto it.
  await row(page, 'folder', SEED.library_folder_a).dragTo(row(page, 'folder', SEED.library_folder_b));
  await expect(toast(page)).toContainText(`Moved 1 item to ${NAME_B}`);
  expect((await listFolder(page, SEED.library_folder_b)).folders).toContain(NAME_A);
  await page.locator('.afm__toastAction').click();
  await expect(toast(page).filter({ hasText: 'Move undone' })).toBeVisible();
  expect((await listFolder(page, 0)).folders).toContain(NAME_A);
});

test('(b) drag a folder onto the Home crumb to make it top level', async ({ page }) => {
  await openFolder(page, SEED.library_folder_a);
  await row(page, 'folder', SEED.library_folder_a1).dragTo(page.locator('[data-afm-crumb="0"]'));

  await expect(toast(page)).toContainText('Moved 1 item to Home');
  const top = (await listFolder(page, 0)).folders;
  expect(top).toContain(NAME_A1);
  expect((await listFolder(page, SEED.library_folder_a)).folders).not.toContain(NAME_A1);
});

test('(c) right-click a file, Move to..., pick a folder, Move here', async ({ page }) => {
  await openFolder(page, SEED.library_folder_a);
  await row(page, 'file', SEED.library_file_id).click({ button: 'right' });
  await page.locator('[data-afm-menu-action="move-to"]').click();

  const picker = page.locator('[data-afm-pick-tree]');
  await expect(picker).toBeVisible();
  await picker.locator('[data-afm-pick-id]').filter({ has: page.getByText(NAME_B, { exact: true }) }).click();
  await page.getByRole('button', { name: 'Move here' }).click();

  await expect(toast(page)).toContainText(`Moved 1 item to ${NAME_B}`);
  expect((await listFolder(page, SEED.library_folder_b)).files).toContain('e2e-a.txt');
  expect((await listFolder(page, SEED.library_folder_a)).files).not.toContain('e2e-a.txt');
});

test('(c2) Select all with a folder expanded moves only the folder, not its children', async ({ page }) => {
  await page.locator(`[data-afm-row-expand="${SEED.library_folder_a}"]`).click();
  await expect(row(page, 'folder', SEED.library_folder_a1)).toBeVisible();
  await row(page, 'folder', SEED.library_folder_a).click();
  await page.keyboard.press('ControlOrMeta+A');
  await row(page, 'folder', SEED.library_folder_b).click({ modifiers: ['ControlOrMeta'] }); // B is the target, not an item
  await page.locator('[data-afm-bulk="move"]').click();

  const picker = page.locator('[data-afm-pick-tree]');
  await expect(picker).toBeVisible();
  await picker.locator('[data-afm-pick-id]').filter({ has: page.getByText(NAME_B, { exact: true }) }).click();
  await page.getByRole('button', { name: 'Move here' }).click();

  await expect(toast(page)).toContainText(`Moved 1 item to ${NAME_B}`);
  expect((await listFolder(page, SEED.library_folder_b)).folders).toEqual([NAME_A]);
  const inA = await listFolder(page, SEED.library_folder_a);
  expect(inA.folders).toContain(NAME_A1);
  expect(inA.files).toContain('e2e-a.txt');
  // Put A back at Home: the next case's first page load expects it there.
  await ajax(page, 'anchor_fm_move_items', { items: JSON.stringify([{ kind: 'folder', id: SEED.library_folder_a }]), target_folder_id: 0 });
});

test('(d) an OS file drag shows the overlay; an internal drag never does', async ({ page }) => {
  await openFolder(page, SEED.library_folder_a);
  const root = page.locator('.afm').first();
  const overlayClass = /afm--drag(?!Mode)\b/;

  // An internal drag of a row over the list pane: no overlay.
  await page.evaluate((sel) => {
    const src = document.querySelector(sel);
    window.__dt = new DataTransfer();
    src.dispatchEvent(new DragEvent('dragstart', { bubbles: true, cancelable: true, dataTransfer: window.__dt }));
    document.querySelector('.afm__content').dispatchEvent(
      new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer: window.__dt })
    );
    document.querySelector('.afm__content').dispatchEvent(
      new DragEvent('dragover', { bubbles: true, cancelable: true, dataTransfer: window.__dt })
    );
  }, `[data-afm-row="file:${SEED.library_file_id}"]`);
  await expect(root).not.toHaveClass(overlayClass);
  await page.evaluate((sel) => {
    document.querySelector(sel).dispatchEvent(new DragEvent('dragend', { bubbles: true, cancelable: true, dataTransfer: window.__dt }));
  }, `[data-afm-row="file:${SEED.library_file_id}"]`);

  // An OS file (types: ['Files']) over the list pane: the overlay shows.
  await page.evaluate(() => {
    const dt = new DataTransfer();
    dt.items.add(new File(['hello'], 'from-os.txt', { type: 'text/plain' }));
    document.querySelector('.afm__content').dispatchEvent(
      new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer: dt })
    );
  });
  await expect(root).toHaveClass(overlayClass);
});

test('(e) Duplicate on a top-level folder creates "<name> (copy)" at Home', async ({ page }) => {
  await row(page, 'folder', SEED.library_folder_b).click({ button: 'right' });
  await page.locator('[data-afm-menu-action="duplicate"]').click();

  await expect(page.locator('[data-afm-row-kind="folder"] [data-afm-row-label]', { hasText: `${NAME_B} (copy)` })).toBeVisible();
  expect((await listFolder(page, 0)).folders).toContain(`${NAME_B} (copy)`);
});

test('(f) a failed permissions load leaves Save disabled', async ({ page }) => {
  await page.route(/admin-ajax\.php/, async (route) => {
    const body = route.request().postData() || '';
    if (body.includes('action=anchor_fm_get_permissions')) {
      await route.fulfill({ status: 500, contentType: 'application/json', body: JSON.stringify({ success: false, data: { message: 'boom' } }) });
      return;
    }
    await route.continue();
  });
  await row(page, 'folder', SEED.library_folder_a).click({ button: 'right' });
  await page.locator('[data-afm-menu-action="permissions"]').click();

  const save = page.locator('[data-afm-action="modal-primary"]');
  await expect(page.locator('[data-afm-modal-body]')).toContainText(/permissions|boom/i);
  await expect(save).toBeDisabled();
});
