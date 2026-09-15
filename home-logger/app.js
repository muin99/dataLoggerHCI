// BUILD_MARKER: 2026-09-15-ui-redesign — bump this string on every
// change; after uploading, `curl -s <site>/app.js | grep BUILD_MARKER`
// should echo this exact line back if the upload actually took.

(() => {
  const state = { categories: [], workers: [] };

  async function api(path, options = {}) {
    const response = await fetch(path, options);
    let data = null;
    try {
      data = await response.json();
    } catch {
      /* CSV/empty responses */
    }
    if (!response.ok)
      throw new Error(
        (data && data.error) || `Request failed (${response.status})`,
      );
    return data;
  }
  async function apiJson(path, method, body) {
    return api(path, {
      method,
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
  }

  function toast(message, isError = false) {
    const el = document.getElementById("toast");
    el.textContent = message;
    el.style.background = isError ? "var(--danger)" : "var(--text)";
    el.hidden = false;
    clearTimeout(toast._t);
    toast._t = setTimeout(() => {
      el.hidden = true;
    }, 3200);
  }

  function money(n) {
    return "৳" + Number(n || 0).toFixed(2);
  }
  function fmtDate(s) {
    if (!s) return "—";
    const d = new Date(s.replace(" ", "T"));
    return isNaN(d)
      ? s
      : d.toLocaleString(undefined, {
          month: "short",
          day: "numeric",
          hour: "2-digit",
          minute: "2-digit",
        });
  }
  function badge(status) {
    return `<span class="badge ${status}">${(status || "").replace("_", " ")}</span>`;
  }
  function esc(s) {
    const d = document.createElement("div");
    d.textContent = s ?? "";
    return d.innerHTML;
  }
  function fmtDuration(totalSeconds) {
    const s = Math.max(0, totalSeconds || 0);
    const h = Math.floor(s / 3600),
      m = Math.floor((s % 3600) / 60);
    if (h > 0) return `${h}h ${m}m`;
    if (m > 0) return `${m}m`;
    return `${s}s`;
  }

  // ---------- Theme ----------
  // No stored preference => leave data-theme unset so the stylesheet's
  // prefers-color-scheme rules decide (matches the OS setting on first
  // visit); only an explicit toggle click pins it either way from then on.
  const root = document.documentElement;
  function applyTheme(t) {
    if (t) {
      root.setAttribute("data-theme", t);
      localStorage.setItem("hl-theme", t);
    } else {
      root.removeAttribute("data-theme");
      localStorage.removeItem("hl-theme");
    }
  }
  const storedTheme = localStorage.getItem("hl-theme");
  if (storedTheme) applyTheme(storedTheme);
  document.getElementById("theme-toggle").addEventListener("click", () => {
    const current =
      root.getAttribute("data-theme") ||
      (matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light");
    applyTheme(current === "dark" ? "light" : "dark");
  });

  // ---------- Sidebar: mobile drawer + desktop collapse ----------
  const appShell = document.getElementById("app-shell");
  const scrim = document.getElementById("scrim");
  const collapseBtn = document.getElementById("sidebar-collapse-btn");
  function openMobileNav() {
    document.body.classList.add("nav-open");
  }
  function closeMobileNav() {
    document.body.classList.remove("nav-open");
  }
  document
    .getElementById("menu-toggle")
    .addEventListener("click", openMobileNav);
  document
    .getElementById("sidebar-close-btn")
    .addEventListener("click", closeMobileNav);
  scrim.addEventListener("click", closeMobileNav);

  if (localStorage.getItem("hl-sidebar-collapsed") === "1")
    appShell.classList.add("is-collapsed");
  collapseBtn.addEventListener("click", () => {
    const collapsed = appShell.classList.toggle("is-collapsed");
    localStorage.setItem("hl-sidebar-collapsed", collapsed ? "1" : "0");
    collapseBtn.setAttribute(
      "aria-label",
      collapsed ? "Expand sidebar" : "Collapse sidebar",
    );
  });

  // ---------- Nav / view switching (real show/hide, not cosmetic scroll) ----------
  const views = {
    overview: loadOverview,
    attendance: loadAttendance,
    objects: loadObjects,
    receipts: loadReceipts,
    notifications: loadNotifications,
    categories: loadCategories,
    settings: loadSettings,
  };
  function showView(name) {
    document
      .querySelectorAll(".nav-link")
      .forEach((b) => b.classList.toggle("active", b.dataset.view === name));
    document
      .querySelectorAll(".view")
      .forEach((s) => s.classList.toggle("active", s.dataset.view === name));
    document.getElementById("view-title").textContent = document
      .querySelector(`.nav-link[data-view="${name}"]`)
      .textContent.trim()
      .replace(/\d+$/, "")
      .trim();
    location.hash = name;
    (views[name] || (() => {}))();
  }
  document.getElementById("nav").addEventListener("click", (e) => {
    const btn = e.target.closest(".nav-link");
    if (btn) {
      showView(btn.dataset.view);
      closeMobileNav();
    }
  });
  showView((location.hash || "#overview").slice(1));

  // ---------- Health ----------
  async function checkHealth() {
    const dot = document.getElementById("health-dot"),
      label = document.getElementById("health-label");
    if (checkHealth._inFlight) return;
    checkHealth._inFlight = true;
    try {
      const res = await api("api/health.php");
      dot.className = "status-dot ok";
      label.textContent = "Online";
    } catch {
      dot.className = "status-dot bad";
      label.textContent = "Offline";
    } finally {
      checkHealth._inFlight = false;
    }
  }
  // Kept deliberately infrequent: on shared hosting with a small PHP worker
  // pool, background polling can compete with the device's slow (10-30s) AI
  // capture requests for the same limited workers and cause the capture to
  // fail with a bare 502. The _inFlight guards also stop overlapping polls
  // from piling up if a request runs long.
  checkHealth();
  setInterval(checkHealth, 60000);

  // ---------- Notifications badge (lightweight poll) ----------
  async function pollBadge() {
    if (pollBadge._inFlight) return;
    pollBadge._inFlight = true;
    try {
      const data = await api("api/dashboard.php");
      const badgeEl = document.getElementById("nav-badge");
      if (data.pending_notifications > 0) {
        badgeEl.hidden = false;
        badgeEl.textContent = data.pending_notifications;
      } else badgeEl.hidden = true;
    } catch {
      /* ignore transient errors */
    } finally {
      pollBadge._inFlight = false;
    }
  }
  pollBadge();
  setInterval(pollBadge, 30000);

  // ================= OVERVIEW =================
  async function loadOverview() {
    try {
      const data = await api("api/dashboard.php");
      document.getElementById("stat-on-site").textContent =
        data.workers_on_site;
      document.getElementById("stat-objects-today").textContent =
        `${data.objects_today.count} (${money(data.objects_today.value)})`;
      document.getElementById("stat-spend").textContent = money(
        data.spend_this_month,
      );
      document.getElementById("stat-notify").textContent =
        data.pending_notifications;

      const feed = await api("api/logs.php");
      const rows = feed.feed
        .map(
          (f) => `<tr>
        <td>${fmtDate(f.created_at)}</td><td style="text-transform:capitalize">${f.kind}</td>
        <td>${esc(f.summary)}</td><td>${f.amount != null ? money(f.amount) : "—"}</td><td>${badge(f.status)}</td>
      </tr>`,
        )
        .join("");
      document.querySelector("#feed-table tbody").innerHTML =
        rows ||
        '<tr><td colspan="5" class="empty-note">No activity yet.</td></tr>';
    } catch (e) {
      toast(e.message, true);
    }
  }

  // ================= ATTENDANCE =================
  let lastAttendanceLogs = [];
  const dateInput = document.getElementById("attendance-date");
  const rangeSelect = document.getElementById("attendance-range");
  dateInput.value = new Date().toISOString().slice(0, 10);
  rangeSelect.addEventListener("change", () => {
    dateInput.hidden = rangeSelect.value !== "date";
    renderAttendanceLog();
  });
  dateInput.addEventListener("change", renderAttendanceLog);

  async function loadAttendance() {
    await refreshCategoriesAndWorkers();
    renderWorkers();
    await renderAttendanceLog();
  }
  function renderWorkers() {
    const rows = state.workers
      .map(
        (w) => `<tr>
      <td>${esc(w.name)}</td><td>${esc(w.role)}</td><td><code>${esc(w.rfid_uid || "—")}</code></td>
      <td>${w.last_action ? esc(w.last_action.replace("_", " ")) + " · " + fmtDate(w.last_seen_at) : "No activity"}</td>
      <td><button class="link-btn" data-edit-worker="${w.id}">Edit</button> <button class="link-btn danger" data-archive-worker="${w.id}">Archive</button></td>
    </tr>`,
      )
      .join("");
    document.querySelector("#workers-table tbody").innerHTML =
      rows ||
      '<tr><td colspan="5" class="empty-note">No workers yet.</td></tr>';
  }
  async function renderAttendanceLog() {
    try {
      const query =
        rangeSelect.value === "date"
          ? `date=${dateInput.value}`
          : `days=${rangeSelect.value}`;
      const data = await api(`api/attendance.php?${query}`);
      const rangeLabel =
        data.summary.from === data.summary.to
          ? data.summary.from
          : `${data.summary.from} – ${data.summary.to}`;
      document.getElementById("attendance-summary").innerHTML =
        `<span>${esc(rangeLabel)}</span><span>On site now: <b>${data.summary.on_site}</b></span><span>Checked in: <b>${data.summary.checked_in}</b></span><span>Checked out: <b>${data.summary.checked_out}</b></span>`;
      lastAttendanceLogs = data.logs;
      const rows = data.logs
        .map(
          (l) => `<tr>
        <td>${fmtDate(l.occurred_at)}</td><td>${esc(l.worker)}</td><td style="text-transform:capitalize">${l.event_type.replace("_", " ")}</td>
        <td style="text-transform:capitalize">${l.source}</td><td>${badge(l.status)}</td>
        <td>
          <button class="link-btn" data-edit-attendance="${l.id}">Edit</button>
          <button class="link-btn danger" data-delete-attendance="${l.id}">Delete</button>
        </td>
      </tr>`,
        )
        .join("");
      document.querySelector("#attendance-table tbody").innerHTML =
        rows ||
        '<tr><td colspan="6" class="empty-note">No entries for this date.</td></tr>';
    } catch (e) {
      toast(e.message, true);
    }
  }
  document
    .querySelector("#attendance-table")
    .addEventListener("click", async (e) => {
      const editId = e.target.dataset.editAttendance;
      if (editId) {
        openAttendanceDialog(lastAttendanceLogs.find((l) => l.id === editId));
        return;
      }
      const deleteId = e.target.dataset.deleteAttendance;
      if (!deleteId) return;
      if (!confirm("Delete this attendance entry? This cannot be undone."))
        return;
      try {
        await api(`api/attendance.php?id=${deleteId}`, { method: "DELETE" });
        toast("Attendance entry deleted.");
        await renderAttendanceLog();
      } catch (err) {
        toast(err.message, true);
      }
    });

  // Attendance edit dialog — reuses the same log row cached from the last
  // render rather than a separate fetch-by-id endpoint.
  const attendanceDialog = document.getElementById("attendance-dialog");
  const attendanceEditForm = document.getElementById("attendance-edit-form");
  function toDatetimeLocal(mysqlDateTime) {
    if (!mysqlDateTime) return "";
    const d = new Date(mysqlDateTime.replace(" ", "T"));
    if (isNaN(d)) return "";
    const pad = (n) => String(n).padStart(2, "0");
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
  }
  function openAttendanceDialog(log) {
    if (!log) return;
    attendanceEditForm.reset();
    attendanceEditForm.id.value = log.id;
    const select = document.getElementById("attendance-edit-worker");
    select.innerHTML = state.workers
      .map((w) => `<option value="${w.id}">${esc(w.name)}</option>`)
      .join("");
    select.value = log.worker_id;
    attendanceEditForm.event_type.value = log.event_type;
    attendanceEditForm.occurred_at.value = toDatetimeLocal(log.occurred_at);
    attendanceEditForm.note.value = log.note || "";
    attendanceDialog.showModal();
  }
  attendanceEditForm.addEventListener("submit", async (e) => {
    if (e.submitter && e.submitter.value === "cancel") {
      attendanceDialog.close();
      return;
    }
    e.preventDefault();
    const fd = new FormData(attendanceEditForm);
    try {
      await apiJson("api/attendance.php", "PUT", {
        id: fd.get("id"),
        worker_id: fd.get("worker_id"),
        event_type: fd.get("event_type"),
        occurred_at: fd.get("occurred_at"),
        note: fd.get("note"),
      });
      attendanceDialog.close();
      toast("Attendance entry saved.");
      await renderAttendanceLog();
    } catch (err) {
      toast(err.message, true);
    }
  });
  document
    .getElementById("attendance-delete-btn")
    .addEventListener("click", async () => {
      const id = attendanceEditForm.id.value;
      if (
        !id ||
        !confirm("Delete this attendance entry? This cannot be undone.")
      )
        return;
      try {
        await api(`api/attendance.php?id=${id}`, { method: "DELETE" });
        attendanceDialog.close();
        toast("Attendance entry deleted.");
        await renderAttendanceLog();
      } catch (err) {
        toast(err.message, true);
      }
    });

  // Worker dialog
  const workerDialog = document.getElementById("worker-dialog");
  const workerForm = document.getElementById("worker-form");
  document
    .getElementById("add-worker-btn")
    .addEventListener("click", () => openWorkerDialog());
  document.querySelector("#workers-table").addEventListener("click", (e) => {
    const editId = e.target.dataset.editWorker;
    const archiveId = e.target.dataset.archiveWorker;
    if (editId) openWorkerDialog(state.workers.find((w) => w.id === editId));
    if (archiveId) archiveWorker(archiveId);
  });
  function openWorkerDialog(worker) {
    workerForm.reset();
    workerForm.id.value = worker ? worker.id : "";
    document.getElementById("worker-dialog-title").textContent = worker
      ? "Edit worker"
      : "Add worker";
    const select = document.getElementById("worker-role-select");
    select.innerHTML = state.categories
      .filter((c) => c.type === "attendance")
      .map((c) => `<option value="${c.id}">${esc(c.name)}</option>`)
      .join("");
    if (worker) {
      workerForm.name.value = worker.name;
      workerForm.phone.value = worker.phone || "";
      workerForm.rfid_uid.value = worker.rfid_uid || "";
      workerForm.notes.value = worker.notes || "";
      if (worker.role_id) select.value = worker.role_id;
    }
    workerDialog.showModal();
  }
  workerForm.addEventListener("submit", async (e) => {
    if (e.submitter && e.submitter.value === "cancel") {
      workerDialog.close();
      return;
    }
    e.preventDefault();
    const fd = new FormData(workerForm);
    const payload = {
      action: "save_worker",
      id: fd.get("id"),
      name: fd.get("name"),
      role_id: fd.get("role_id"),
      phone: fd.get("phone"),
      rfid_uid: fd.get("rfid_uid"),
      notes: fd.get("notes"),
    };
    try {
      await apiJson("api/workers.php", "POST", payload);
      workerDialog.close();
      toast("Worker saved.");
      await loadAttendance();
    } catch (err) {
      toast(err.message, true);
    }
  });
  async function archiveWorker(id) {
    if (!confirm("Archive this worker? Their history is kept.")) return;
    try {
      await apiJson("api/workers.php", "POST", {
        action: "archive_worker",
        id,
      });
      toast("Worker archived.");
      await loadAttendance();
    } catch (e) {
      toast(e.message, true);
    }
  }

  // ================= OBJECTS =================
  async function loadObjects() {
    try {
      const data = await api("api/objects.php");
      const sel = document.getElementById("manual-object-category");
      sel.innerHTML = data.categories
        .map(
          (c) =>
            `<option value="${c.id}">${esc(c.name)} (${money(c.price_per_unit)}/${esc(c.unit_label)})</option>`,
        )
        .join("");

      document.querySelector("#object-categories-table tbody").innerHTML =
        data.categories
          .map(
            (c) => `<tr>
        <td>${esc(c.name)}</td><td>${money(c.price_per_unit)}</td><td>${esc(c.unit_label)}</td>
        <td><button class="link-btn" data-edit-cat="${c.id}" data-cat-type="object">Edit</button></td>
      </tr>`,
          )
          .join("") ||
        '<tr><td colspan="4" class="empty-note">No categories yet.</td></tr>';

      document.querySelector("#objects-table tbody").innerHTML =
        data.logs
          .map(
            (l) => `<tr>
        <td>${l.image_url ? `<img class="thumb" src="${esc(l.image_url)}" data-lightbox>` : "—"}</td>
        <td>${esc(l.category)}</td><td>${l.quantity}</td><td>${money(l.total_price)}</td>
        <td style="text-transform:capitalize">${l.source}</td><td>${badge(l.status)}</td><td>${fmtDate(l.created_at)}</td>
        <td>
          <button class="link-btn" data-edit-log="${l.id}">Edit</button>
          <button class="link-btn danger" data-delete-log="${l.id}">Delete</button>
        </td>
      </tr>`,
          )
          .join("") ||
        '<tr><td colspan="8" class="empty-note">No object logs yet.</td></tr>';
    } catch (e) {
      toast(e.message, true);
    }
  }
  document
    .getElementById("manual-object-form")
    .addEventListener("submit", async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await api("api/objects.php", { method: "POST", body: fd });
        toast("Object logged.");
        e.target.reset();
        e.target.quantity.value = 1;
        await loadObjects();
      } catch (err) {
        toast(err.message, true);
      }
    });
  document
    .querySelector("#objects-table")
    .addEventListener("click", async (e) => {
      const editId = e.target.dataset.editLog;
      if (editId) {
        openObjectReviewDialog(editId);
        return;
      }
      const id = e.target.dataset.deleteLog;
      if (!id) return;
      if (!confirm("Delete this log entry?")) return;
      try {
        await api(`api/objects.php?id=${id}`, { method: "DELETE" });
        toast("Deleted.");
        await loadObjects();
      } catch (err) {
        toast(err.message, true);
      }
    });

  // ================= RECEIPTS =================
  document
    .getElementById("receipts-filter")
    .addEventListener("change", loadReceipts);
  async function loadReceipts() {
    try {
      const status = document.getElementById("receipts-filter").value;
      const data = await api(
        `api/receipts.php${status ? "?status=" + status : ""}`,
      );
      document.querySelector("#receipts-table tbody").innerHTML =
        data.receipts
          .map(
            (r) => `<tr>
        <td>${r.image_url ? `<img class="thumb" src="${esc(r.image_url)}" data-lightbox>` : "—"}</td>
        <td>${esc(r.merchant_name || "—")}</td>
        <td>${r.subtotal != null ? money(r.subtotal) : "—"}</td>
        <td>${r.discount != null ? money(r.discount) : "—"}</td>
        <td>${r.tax != null ? money(r.tax) : "—"}</td>
        <td>${money(r.total)}</td><td>${badge(r.status)}</td><td>${fmtDate(r.created_at)}</td>
        <td>
          <button class="link-btn" data-open-receipt="${r.id}">Open</button>
          <button class="link-btn danger" data-delete-receipt="${r.id}">Delete</button>
        </td>
      </tr>`,
          )
          .join("") ||
        '<tr><td colspan="9" class="empty-note">No receipts yet.</td></tr>';
    } catch (e) {
      toast(e.message, true);
    }
  }
  document
    .querySelector("#receipts-table")
    .addEventListener("click", async (e) => {
      const openId = e.target.dataset.openReceipt;
      const deleteId = e.target.dataset.deleteReceipt;
      if (openId) {
        openReceiptDialog(openId);
        return;
      }
      if (deleteId) {
        if (
          !confirm(
            "Delete this receipt? This cannot be undone, regardless of its status.",
          )
        )
          return;
        try {
          await api(`api/receipts.php?id=${deleteId}`, { method: "DELETE" });
          toast("Receipt deleted.");
          await loadReceipts();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
      }
    });
  document
    .querySelector("#needs-review-table")
    .addEventListener("click", async (e) => {
      const openId = e.target.dataset.openReceipt;
      const acceptId = e.target.dataset.acceptReceipt;
      const rejectId = e.target.dataset.rejectReceipt;
      if (openId) {
        openReceiptDialog(openId);
        return;
      }
      if (acceptId) {
        try {
          await apiJson("api/notifications.php", "POST", {
            kind: "receipt",
            id: acceptId,
            action: "acknowledge",
          });
          toast("Receipt accepted.");
          await loadNotifications();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
        return;
      }
      if (rejectId) {
        if (!confirm("Reject this receipt? It will be discarded.")) return;
        try {
          await apiJson("api/notifications.php", "POST", {
            kind: "receipt",
            id: rejectId,
            action: "reject",
          });
          toast("Receipt rejected.");
          await loadNotifications();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
      }
    });

  const receiptDialog = document.getElementById("receipt-dialog");
  let currentReceiptId = null;
  async function openReceiptDialog(id) {
    try {
      const data = await api(`api/receipts.php?id=${id}`);
      currentReceiptId = id;
      const r = data.receipt;
      document.getElementById("receipt-image").src = r.image_url || "";
      document.getElementById("receipt-merchant").value = r.merchant_name || "";
      document.getElementById("receipt-discount").value = r.discount ?? "";
      document.getElementById("receipt-tax").value = r.tax ?? "";
      renderReceiptItems(r.items);
      updateReceiptTotal();

      // Flag older receipts (captured before discount/tax were tracked, or
      // otherwise) whose stored numbers don't actually add up — the live
      // recompute above already shows what WILL be saved if edited now, but
      // this tells the user the currently-stored total predates/disagrees
      // with that and may need a correction, not just a re-save.
      const note = document.getElementById("receipt-stored-note");
      const expected = Math.max(
        0,
        (r.subtotal ?? 0) - (r.discount ?? 0) + (r.tax ?? 0),
      );
      if (r.subtotal != null && Math.abs(expected - r.total) > 0.01) {
        note.hidden = false;
        note.textContent = `Stored total (${money(r.total)}) doesn't match subtotal − discount + tax (${money(expected)}) — this record may be missing a discount/tax that was never captured. Review against the photo above.`;
      } else {
        note.hidden = true;
      }
      receiptDialog.showModal();
    } catch (e) {
      toast(e.message, true);
    }
  }
  function renderReceiptItems(items) {
    const body = document.querySelector("#receipt-items-table tbody");
    body.innerHTML = "";
    (items.length
      ? items
      : [{ name: "", quantity: 1, unit_price: null, line_total: null }]
    ).forEach(addReceiptItemRow);
  }
  function addReceiptItemRow(item) {
    const tr = document.createElement("tr");
    tr.innerHTML = `<td><input type="text" value="${esc(item.name || "")}" data-field="name"></td>
      <td><input type="number" step="0.01" value="${item.quantity ?? 1}" data-field="quantity" style="width:60px"></td>
      <td><input type="number" step="0.01" value="${item.unit_price ?? ""}" data-field="unit_price" style="width:80px"></td>
      <td><input type="number" step="0.01" value="${item.line_total ?? ""}" data-field="line_total" style="width:80px"></td>
      <td><button class="link-btn danger" type="button" data-remove-item>✕</button></td>`;
    tr.querySelectorAll("input").forEach((i) =>
      i.addEventListener("input", updateReceiptTotal),
    );
    tr.querySelector("[data-remove-item]").addEventListener("click", () => {
      tr.remove();
      updateReceiptTotal();
    });
    document.querySelector("#receipt-items-table tbody").appendChild(tr);
  }
  document
    .getElementById("receipt-add-item")
    .addEventListener("click", () =>
      addReceiptItemRow({ name: "", quantity: 1 }),
    );
  function updateReceiptTotal() {
    let itemSum = 0;
    document.querySelectorAll("#receipt-items-table tbody tr").forEach((tr) => {
      const lt = parseFloat(
        tr.querySelector('[data-field="line_total"]').value,
      );
      itemSum += isNaN(lt) ? 0 : lt;
    });
    const discount =
      parseFloat(document.getElementById("receipt-discount").value) || 0;
    const tax = parseFloat(document.getElementById("receipt-tax").value) || 0;
    document.getElementById("receipt-subtotal-display").textContent =
      itemSum.toFixed(2);
    document.getElementById("receipt-total-display").textContent = Math.max(
      0,
      itemSum - discount + tax,
    ).toFixed(2);
  }
  document
    .getElementById("receipt-discount")
    .addEventListener("input", updateReceiptTotal);
  document
    .getElementById("receipt-tax")
    .addEventListener("input", updateReceiptTotal);
  document
    .getElementById("receipt-close-btn")
    .addEventListener("click", () => receiptDialog.close());
  document
    .getElementById("receipt-delete-btn")
    .addEventListener("click", async () => {
      if (
        !confirm(
          "Delete this receipt? This cannot be undone, regardless of its status.",
        )
      )
        return;
      try {
        await api(`api/receipts.php?id=${currentReceiptId}`, {
          method: "DELETE",
        });
        toast("Receipt deleted.");
        receiptDialog.close();
        await loadReceipts();
        await loadNotifications();
        await pollBadge();
      } catch (e) {
        toast(e.message, true);
      }
    });
  document
    .getElementById("receipt-save-btn")
    .addEventListener("click", async () => {
      const items = [
        ...document.querySelectorAll("#receipt-items-table tbody tr"),
      ]
        .map((tr) => ({
          name: tr.querySelector('[data-field="name"]').value,
          quantity: tr.querySelector('[data-field="quantity"]').value,
          unit_price:
            tr.querySelector('[data-field="unit_price"]').value || null,
          line_total:
            tr.querySelector('[data-field="line_total"]').value || null,
        }))
        .filter((i) => i.name.trim() !== "");
      try {
        await apiJson("api/receipts.php", "PUT", {
          id: currentReceiptId,
          merchant: document.getElementById("receipt-merchant").value,
          items,
          discount: document.getElementById("receipt-discount").value || null,
          tax: document.getElementById("receipt-tax").value || null,
        });
        toast("Receipt saved.");
        receiptDialog.close();
        await loadReceipts();
        await loadNotifications();
        await pollBadge();
      } catch (e) {
        toast(e.message, true);
      }
    });

  // ================= NOTIFICATIONS =================
  async function loadNotifications() {
    try {
      const data = await api("api/notifications.php");

      const grid = document.getElementById("unknown-objects-grid");
      grid.innerHTML = data.unknown_objects.length
        ? data.unknown_objects
            .map(
              (o) => `
        <div class="notify-card" data-unknown-object="${o.id}">
          <img src="${esc(o.image_url)}" data-lightbox>
          <div class="notify-body">
            <select>
              <option value="">Assign category…</option>
              ${data.object_categories.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join("")}
            </select>
            <div class="notify-card-actions">
              <button class="ghost-btn" data-assign-object="${o.id}" style="flex:1">Assign</button>
              <button class="ghost-btn" data-dismiss-object="${o.id}">✕</button>
            </div>
          </div>
        </div>`,
            )
            .join("")
        : '<p class="empty-note">Nothing pending.</p>';

      document.querySelector("#unknown-tags-table tbody").innerHTML = data
        .unknown_tags.length
        ? data.unknown_tags
            .map(
              (t) => `
        <tr><td><code>${esc(t.id)}</code></td><td>${t.hit_count}</td><td>${fmtDate(t.last_seen_at)}</td>
        <td>
          <select data-tag-worker-select="${esc(t.id)}"><option value="">Assign worker…</option>${state.workers.map((w) => `<option value="${w.id}">${esc(w.name)}</option>`).join("")}</select>
          <button class="link-btn" data-assign-tag="${esc(t.id)}">Assign</button>
          <button class="link-btn danger" data-dismiss-tag="${esc(t.id)}">Dismiss</button>
        </td></tr>`,
            )
            .join("")
        : '<tr><td colspan="4" class="empty-note">Nothing pending.</td></tr>';

      document.querySelector("#needs-review-objects-table tbody").innerHTML =
        data.needs_review_objects.length
          ? data.needs_review_objects
              .map(
                (o) => `
        <tr><td>${o.image_url ? `<img class="thumb" src="${esc(o.image_url)}" data-lightbox>` : "—"}</td>
        <td>${esc(o.category)} × ${o.quantity}</td><td>${money(o.total_price)}</td>
        <td>${o.status === "pending" ? `Pending &middot; auto-confirms in ${fmtDuration(o.seconds_remaining)}` : badge("needs_review")}</td>
        <td>${fmtDate(o.created_at)}</td>
        <td>
          <button class="link-btn danger" data-reject-object="${o.id}">Reject</button>
          <button class="link-btn" data-accept-object="${o.id}">Accept</button>
          <button class="link-btn" data-open-object="${o.id}">Modify</button>
        </td></tr>`,
              )
              .join("")
          : '<tr><td colspan="6" class="empty-note">Nothing pending.</td></tr>';

      document.querySelector("#needs-review-table tbody").innerHTML = data
        .needs_review_receipts.length
        ? data.needs_review_receipts
            .map(
              (r) => `
        <tr><td>${r.image_url ? `<img class="thumb" src="${esc(r.image_url)}" data-lightbox>` : "—"}</td>
        <td>${esc(r.merchant_name || "—")}</td><td>${money(r.total)}</td><td>${fmtDate(r.created_at)}</td>
        <td>
          <button class="link-btn danger" data-reject-receipt="${r.id}">Reject</button>
          <button class="link-btn" data-accept-receipt="${r.id}">Accept</button>
          <button class="link-btn" data-open-receipt="${r.id}">Modify</button>
        </td></tr>`,
            )
            .join("")
        : '<tr><td colspan="5" class="empty-note">Nothing pending.</td></tr>';
    } catch (e) {
      toast(e.message, true);
    }
  }
  document
    .querySelector("#needs-review-objects-table")
    .addEventListener("click", async (e) => {
      const openId = e.target.dataset.openObject;
      const acceptId = e.target.dataset.acceptObject;
      const rejectId = e.target.dataset.rejectObject;
      if (openId) {
        openObjectReviewDialog(openId);
        return;
      }
      if (acceptId) {
        try {
          await apiJson("api/notifications.php", "POST", {
            kind: "object",
            id: acceptId,
            action: "acknowledge",
          });
          toast("Object accepted.");
          await loadNotifications();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
        return;
      }
      if (rejectId) {
        if (!confirm("Reject this object log? It will be discarded.")) return;
        try {
          await apiJson("api/notifications.php", "POST", {
            kind: "object",
            id: rejectId,
            action: "reject",
          });
          toast("Object rejected.");
          await loadNotifications();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
      }
    });

  // Used both for objects flagged via device B5 (needs_review) and for
  // editing any already-confirmed log from the Objects table — a log is
  // fully CRUD-able regardless of how it got there, not just while pending.
  const objectReviewDialog = document.getElementById("object-review-dialog");
  const objectReviewForm = document.getElementById("object-review-form");
  async function openObjectReviewDialog(id) {
    if (!state.categories.length) await refreshCategoriesAndWorkers();
    let row;
    try {
      row = (await api(`api/objects.php?id=${id}`)).log;
    } catch (e) {
      toast("That log could not be found.", true);
      await loadObjects().catch(() => {});
      return;
    }
    document.getElementById("object-review-title").textContent =
      row.status === "needs_review" ? "Flagged object" : "Edit object";
    objectReviewForm.reset();
    objectReviewForm.id.value = id;
    document.getElementById("object-review-image").src = row.image_url || "";
    const select = document.getElementById("object-review-category");
    select.innerHTML = state.categories
      .filter((c) => c.type === "object")
      .map((c) => `<option value="${c.id}">${esc(c.name)}</option>`)
      .join("");
    select.value = row.category_id; // pre-select its current category, not just the alphabetically-first one
    objectReviewForm.quantity.value = row.quantity;
    objectReviewDialog.showModal();
  }
  document
    .getElementById("object-review-delete-btn")
    .addEventListener("click", async () => {
      const id = objectReviewForm.id.value;
      if (!confirm("Delete this log entry? This cannot be undone.")) return;
      try {
        await api(`api/objects.php?id=${id}`, { method: "DELETE" });
        objectReviewDialog.close();
        toast("Deleted.");
        await loadNotifications();
        await pollBadge();
        await loadObjects().catch(() => {});
      } catch (err) {
        toast(err.message, true);
      }
    });
  objectReviewForm.addEventListener("submit", async (e) => {
    if (e.submitter && e.submitter.value === "cancel") {
      objectReviewDialog.close();
      return;
    }
    e.preventDefault();
    const fd = new FormData(objectReviewForm);
    try {
      await apiJson("api/objects.php", "PUT", {
        id: fd.get("id"),
        category_id: fd.get("category_id"),
        quantity: fd.get("quantity"),
      });
      objectReviewDialog.close();
      toast("Object updated and confirmed.");
      await loadNotifications();
      await pollBadge();
      await loadObjects().catch(() => {});
    } catch (err) {
      toast(err.message, true);
    }
  });
  document
    .getElementById("unknown-objects-grid")
    .addEventListener("click", async (e) => {
      const assignId = e.target.dataset.assignObject,
        dismissId = e.target.dataset.dismissObject;
      if (assignId) {
        const select = e.target.closest(".notify-card").querySelector("select");
        if (!select.value) {
          toast("Pick a category first.", true);
          return;
        }
        try {
          await apiJson("api/notifications.php", "POST", {
            kind: "unknown_object",
            id: assignId,
            action: "assign",
            category_id: select.value,
          });
          toast("Logged and assigned.");
          await loadNotifications();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
      }
      if (dismissId) {
        try {
          await apiJson("api/notifications.php", "POST", {
            kind: "unknown_object",
            id: dismissId,
            action: "dismiss",
          });
          toast("Dismissed.");
          await loadNotifications();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
      }
    });
  document
    .querySelector("#unknown-tags-table")
    .addEventListener("click", async (e) => {
      const assignUid = e.target.dataset.assignTag,
        dismissUid = e.target.dataset.dismissTag;
      if (assignUid) {
        const select = document.querySelector(
          `[data-tag-worker-select="${assignUid}"]`,
        );
        if (!select.value) {
          toast("Pick a worker first.", true);
          return;
        }
        try {
          await apiJson("api/notifications.php", "POST", {
            kind: "unknown_tag",
            id: assignUid,
            action: "assign",
            worker_id: select.value,
          });
          toast("Tag assigned.");
          await loadNotifications();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
      }
      if (dismissUid) {
        try {
          await apiJson("api/notifications.php", "POST", {
            kind: "unknown_tag",
            id: dismissUid,
            action: "dismiss",
          });
          toast("Dismissed.");
          await loadNotifications();
          await pollBadge();
        } catch (err) {
          toast(err.message, true);
        }
      }
    });

  // ================= CATEGORIES =================
  async function refreshCategoriesAndWorkers() {
    const [catData, workerData] = await Promise.all([
      api("api/categories.php"),
      api("api/workers.php"),
    ]);
    state.categories = catData.categories;
    state.workers = workerData.workers;
  }
  async function loadCategories() {
    await refreshCategoriesAndWorkers();
    const objectCats = state.categories.filter((c) => c.type === "object");
    const attendanceCats = state.categories.filter(
      (c) => c.type === "attendance",
    );
    document.querySelector("#categories-object-table tbody").innerHTML =
      objectCats
        .map(
          (c) => `<tr>
      <td>${esc(c.name)}</td><td>${money(c.price_per_unit)}</td><td>${esc(c.unit_label)}</td><td>${c.object_log_count}</td>
      <td><button class="link-btn" data-edit-cat="${c.id}" data-cat-type="object">Edit</button> <button class="link-btn danger" data-delete-cat="${c.id}">Delete</button></td>
    </tr>`,
        )
        .join("") ||
      '<tr><td colspan="5" class="empty-note">None yet.</td></tr>';
    document.querySelector("#categories-attendance-table tbody").innerHTML =
      attendanceCats
        .map(
          (c) => `<tr>
      <td>${esc(c.name)}</td><td>${c.worker_count}</td>
      <td><button class="link-btn" data-edit-cat="${c.id}" data-cat-type="attendance">Edit</button> <button class="link-btn danger" data-delete-cat="${c.id}">Delete</button></td>
    </tr>`,
        )
        .join("") ||
      '<tr><td colspan="3" class="empty-note">None yet.</td></tr>';
  }
  document.body.addEventListener("click", async (e) => {
    const addType = e.target.dataset.addCategory;
    const editId = e.target.dataset.editCat,
      editType = e.target.dataset.catType;
    const deleteId = e.target.dataset.deleteCat;
    if (addType) openCategoryDialog(addType);
    if (editId)
      openCategoryDialog(
        editType,
        state.categories.find((c) => c.id === editId) ||
          (await findCategoryFresh(editId)),
      );
    if (deleteId) {
      if (!confirm("Delete this category?")) return;
      try {
        await api(`api/categories.php?id=${deleteId}`, { method: "DELETE" });
        toast("Deleted.");
        await loadCategories();
      } catch (err) {
        toast(err.message, true);
      }
    }
  });
  async function findCategoryFresh(id) {
    if (!state.categories.length) await refreshCategoriesAndWorkers();
    return state.categories.find((c) => c.id === id);
  }
  const categoryDialog = document.getElementById("category-dialog");
  const categoryForm = document.getElementById("category-form");
  function openCategoryDialog(type, category) {
    categoryForm.reset();
    categoryForm.id.value = category ? category.id : "";
    categoryForm.type.value = type;
    document.getElementById("category-dialog-title").textContent =
      (category ? "Edit " : "Add ") +
      (type === "object" ? "object category" : "attendance role");
    document.getElementById("category-price-row").style.display =
      type === "object" ? "flex" : "none";
    document.getElementById("category-unit-row").style.display =
      type === "object" ? "flex" : "none";
    if (category) {
      categoryForm.name.value = category.name;
      categoryForm.price_per_unit.value = category.price_per_unit ?? 0;
      categoryForm.unit_label.value = category.unit_label ?? "pcs";
      categoryForm.color.value = category.color ?? "#DDF2E6";
    }
    categoryDialog.showModal();
  }
  categoryForm.addEventListener("submit", async (e) => {
    if (e.submitter && e.submitter.value === "cancel") {
      categoryDialog.close();
      return;
    }
    e.preventDefault();
    const fd = new FormData(categoryForm);
    const id = fd.get("id");
    const payload = {
      name: fd.get("name"),
      type: fd.get("type"),
      price_per_unit: fd.get("price_per_unit"),
      unit_label: fd.get("unit_label"),
      color: fd.get("color"),
    };
    try {
      if (id) {
        payload.id = id;
        await apiJson("api/categories.php", "PUT", payload);
      } else await apiJson("api/categories.php", "POST", payload);
      categoryDialog.close();
      toast("Category saved.");
      await loadCategories();
      await loadObjects().catch(() => {});
    } catch (err) {
      toast(err.message, true);
    }
  });

  // ================= SETTINGS =================
  async function loadSettings() {
    try {
      const data = await api("api/settings.php");
      document.querySelector(
        '#settings-form [name="confirm_window_seconds"]',
      ).value = data.settings.confirm_window_seconds;
      document.querySelector(
        '#settings-form [name="sound_notifications"]',
      ).checked = data.settings.sound_notifications;
    } catch (e) {
      toast(e.message, true);
    }
  }
  document
    .getElementById("settings-form")
    .addEventListener("submit", async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await apiJson("api/settings.php", "POST", {
          confirm_window_seconds: fd.get("confirm_window_seconds"),
          sound_notifications: fd.get("sound_notifications") ? 1 : 0,
        });
        toast("Settings saved.");
      } catch (err) {
        toast(err.message, true);
      }
    });

  // ================= Lightbox =================
  const lightbox = document.getElementById("lightbox-dialog");
  document.body.addEventListener("click", (e) => {
    if (e.target.matches("[data-lightbox]")) {
      document.getElementById("lightbox-image").src = e.target.src;
      lightbox.showModal();
    }
  });
  lightbox.addEventListener("click", () => lightbox.close());
})();
