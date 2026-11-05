'use strict';
(() => {
  const pending = document.getElementById('disposalRequestBody');
  const tracking = document.getElementById('disposalTrackingBody');
  if (!pending && !tracking) return;
  const approved = document.getElementById('disposalReportBody');
  const feedback = document.getElementById('disposalFeedback');
  const endpoint = new URL('../modules/disposal.php', document.currentScript.src);
  const labels = { Pending: 'Pending Approval', Approved: 'Approved for Disposal', Rejected: 'Disposal Rejected' };
  let version = 0;
  let reviewing = false;
  const money = value => Number(value || 0).toFixed(2);
  function text(parent, tag, value, className) {
    const element = document.createElement(tag);
    element.textContent = value ?? '';
    if (className) element.className = className;
    parent.append(element);
    return element;
  }
  function empty(body, message, columns) {
    if (!body) return;
    body.replaceChildren();
    text(text(body, 'tr', ''), 'td', message).colSpan = columns;
  }
  function show(message) {
    if (!feedback) return;
    feedback.textContent = message;
    feedback.hidden = !message;
  }
  async function request(options, params = {}) {
    const url = new URL(endpoint);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load disposal records.');
    return data;
  }
  function renderPending(records, canReview) {
    if (!pending) return;
    // Keep unfinished notes across scheduled updates.
    const notes = new Map([...pending.querySelectorAll('input[data-request-id]')].map(input => [input.dataset.requestId, input.value]));
    empty(pending, 'No pending disposal requests.', 6);
    if (records.length) pending.replaceChildren();
    for (const item of records) {
      const row = text(pending, 'tr', '');
      text(row, 'td', item.equip_code);
      text(row, 'td', item.equipment_name);
      text(row, 'td', item.reported_by);
      const reason = text(row, 'td', item.reason, 'disposal-reason');
      text(reason, 'small', `Assessed: ${money(item.assessed_cost)}; penalties: ${money(item.total_penalty_amount)}`, 'disposal-costs');
      text(row, 'td', item.requested_at);
      const cell = text(row, 'td', '');
      if (!canReview) { text(cell, 'span', labels.Pending, 'disposal-status disposal-status-pending'); continue; }
      const actions = text(cell, 'div', '', 'disposal-actions');
      const note = text(actions, 'input', '', 'modal-form-input');
      note.type = 'text';
      note.maxLength = 2000;
      note.dataset.requestId = item.id;
      note.value = notes.get(String(item.id)) || '';
      note.placeholder = 'Review note (required to reject)';
      note.setAttribute('aria-label', `Review note for ${item.equip_code}`);
      const buttons = text(actions, 'div', '', 'disposal-action-buttons');
      for (const decision of ['Approved', 'Rejected']) {
        const button = text(buttons, 'button', decision === 'Approved' ? 'Approve Disposal' : 'Reject',
          `btn btn-secondary btn-sm ${decision === 'Approved' ? 'btn-approve-disposal' : 'btn-reject-disposal'}`);
        button.type = 'button';
        button.addEventListener('click', async () => {
          if (reviewing) return;
          if (decision === 'Rejected' && !note.value.trim()) {
            show('Enter a reason before rejecting this disposal request.'); note.focus(); return;
          }
          reviewing = true;
          version++; // An earlier list response cannot replace the active controls.
          const allButtons = pending.querySelectorAll('button');
          allButtons.forEach(b => { b.disabled = true; });
          try {
            const result = await request({ method: 'POST', body: new URLSearchParams({ id: item.id, decision, note: note.value.trim() }) });
            reviewing = false;
            await load();
            show(result.message);
            window.dispatchEvent(new CustomEvent('disposal:changed', { detail: { source: 'disposal-panel' } }));
          } catch (error) { show(error.message); }
          finally { reviewing = false; allButtons.forEach(b => { b.disabled = false; }); }
        });
      }
    }
  }
  function renderApproved(records) {
    if (!approved) return;
    empty(approved, 'No approved disposed equipment.', 7);
    if (records.length) approved.replaceChildren();
    for (const item of records) {
      const row = text(approved, 'tr', '');
      text(row, 'td', `${item.equip_code} - ${item.equipment_name}`);
      text(row, 'td', item.disposal_approved_at || item.reviewed_at);
      text(row, 'td', item.reason, 'disposal-reason');
      text(row, 'td', money(item.assessed_cost));
      text(row, 'td', `Damage: ${money(item.damage_penalty_amount)}; Overdue: ${money(item.overdue_penalty_amount)}`);
      text(row, 'td', item.borrower_name ? `${item.borrower_name} (${item.student_faculty_id || 'legacy borrower'})` : 'Inspection; no borrower charged');
      text(row, 'td', item.review_note || 'Approved');
    }
  }
  function renderTracking(records) {
    if (!tracking) return;
    empty(tracking, 'No disposal requests have been recorded.', 8);
    if (records.length) tracking.replaceChildren();
    records.sort((a, b) => (a.disposal_status === 'Pending' ? 0 : 1) - (b.disposal_status === 'Pending' ? 0 : 1) || String(b.requested_at).localeCompare(String(a.requested_at)) || Number(b.id) - Number(a.id));
    for (const item of records) {
      const row = text(tracking, 'tr', '');
      text(row, 'td', item.equip_code);
      text(row, 'td', item.equipment_name);
      text(row, 'td', item.reported_by);
      text(row, 'td', item.reason, 'disposal-reason');
      text(row, 'td', item.requested_at);
      text(text(row, 'td', ''), 'span', labels[item.disposal_status] || item.disposal_status,
        `disposal-status disposal-status-${String(item.disposal_status).toLowerCase()}`);
      text(row, 'td', item.review_note || (item.disposal_status === 'Pending' ? 'Awaiting Admin review' : '-'));
      text(row, 'td', item.disposal_approved_at || item.reviewed_at || '-');
    }
  }
  async function load() {
    if (reviewing) return;
    const current = ++version;
    try {
      const data = await request();
      if (current !== version) return;
      const pendingRecords = data.pending || [], approvedRecords = data.approved || [], rejectedRecords = data.rejected || [];
      renderPending(pendingRecords, data.can_review === true);
      renderApproved(approvedRecords);
      renderTracking([...pendingRecords, ...approvedRecords, ...rejectedRecords]);
      show('');
    } catch (error) {
      if (current === version) show(`${error.message} Displayed records may be out of date. Use Refresh to retry.`);
    }
  }
  const requestDialog = document.getElementById('requestDisposalDialog');
  const requestForm = document.getElementById('disposalRequestForm');
  if (requestDialog && requestForm) {
    const equipmentSelect = document.getElementById('disposalEquipmentSelect');
    const submit = document.getElementById('submitDisposalRequestBtn');
    const requestFeedback = document.getElementById('disposalRequestFeedback');
    let candidatesVersion = 0;
    function requestMessage(message = '') {
      requestFeedback.textContent = message;
      requestFeedback.hidden = !message;
      requestFeedback.dataset.error = String(!!message);
    }
    document.getElementById('requestDisposalBtn')?.addEventListener('click', async () => {
      const current = ++candidatesVersion;
      requestForm.reset(); requestMessage();
      equipmentSelect.replaceChildren();
      const loading = text(equipmentSelect, 'option', 'Loading equipment...'); loading.value = '';
      submit.disabled = true; equipmentSelect.disabled = true;
      document.getElementById('disposalRequestReporter').textContent = 'Your account and submission time will be recorded automatically.';
      requestDialog.showModal();
      try {
        const data = await request(undefined, { action: 'eligible_equipment' });
        if (current !== candidatesVersion || !requestDialog.open) return;
        equipmentSelect.replaceChildren();
        const placeholder = text(equipmentSelect, 'option', data.equipment.length ? 'Select equipment' : 'No eligible equipment available'); placeholder.value = '';
        for (const equipment of data.equipment) {
          const option = text(equipmentSelect, 'option', `${equipment.equip_code} - ${equipment.name}`);
          option.value = equipment.id;
        }
        document.getElementById('disposalRequestReporter').textContent = `Reported by: ${data.reported_by}`;
        equipmentSelect.disabled = data.equipment.length === 0;
        submit.disabled = data.equipment.length === 0;
      } catch (error) {
        if (current === candidatesVersion && requestDialog.open) requestMessage('Unable to load equipment. Close and reopen this form to retry.');
      }
    });
    requestDialog.addEventListener('close', () => { candidatesVersion++; });
    document.querySelectorAll('[data-close-disposal-request]').forEach(button => button.addEventListener('click', () => requestDialog.close()));
    requestForm.addEventListener('submit', async event => {
      event.preventDefault();
      if (submit.disabled || !requestForm.reportValidity()) return;
      const reason = document.getElementById('disposalRequestReason').value.trim();
      if (!reason) { requestMessage('Enter a damage reason before submitting.'); return; }
      submit.disabled = true; requestMessage();
      try {
        const data = await request({ method: 'POST', body: new URLSearchParams({ action: 'request', equipment_id: equipmentSelect.value, reason }) });
        requestDialog.close(); requestForm.reset();
        await load(); show(data.message);
        window.dispatchEvent(new CustomEvent('disposal:changed', { detail: { source: 'disposal-panel' } }));
        window.dispatchEvent(new CustomEvent('damage:changed', { detail: { source: 'disposal-panel' } }));
      } catch (error) { requestMessage(error.message); }
      finally { submit.disabled = false; }
    });
  }
  document.getElementById('refreshDisposalBtn')?.addEventListener('click', load);
  document.querySelectorAll('.dash-tab[data-tab="equipment"], .dash-tab[data-tab="equip-list"]').forEach(tab => tab.addEventListener('click', load));
  window.addEventListener('damage:changed', event => { if (event.detail?.source !== 'disposal-panel') load(); });
  window.addEventListener('disposal:changed', event => { if (event.detail?.source !== 'disposal-panel') load(); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
  setInterval(() => {
    const panel = (tracking || pending).closest('.tab-panel');
    if (!document.hidden && (!panel || panel.classList.contains('active')) && !pending?.contains(document.activeElement)) load();
  }, 20000);
  load();
})();
