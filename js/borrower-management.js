'use strict';
(() => {
  const tbody = document.getElementById('adminBorrowerTableBody');
  if (!tbody) return;
  const endpoint = new URL('../modules/borrowers.php', document.currentScript.src);
  const tabs = [document.getElementById('systemUsersTab'), document.getElementById('registeredBorrowersTab')];
  const panels = [document.getElementById('systemUsersPanel'), document.getElementById('registeredBorrowersPanel')];
  const form = document.getElementById('adminBorrowerForm');
  const search = document.getElementById('adminBorrowerSearch');
  const feedback = document.getElementById('borrowerManagementFeedback');
  let listVersion = 0, dialogVersion = 0, timer, selected = 0, deleteId = null;
  const fields = ['id','student_faculty_id','first_name','last_name','department_course','contact_number','email'];
  const money = value => Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const name = row => [row.first_name, row.last_name].filter(Boolean).join(' ');
  function text(parent, tag, value, className) {
    const node = document.createElement(tag);
    node.textContent = value ?? '';
    if (className) node.className = className;
    parent.append(node);
    return node;
  }
  function message(target, value = '', error = false) {
    target.textContent = value; target.hidden = !value; target.dataset.error = String(error);
  }
  function empty(body, value) {
    body.replaceChildren(); text(text(body, 'tr', ''), 'td', value).colSpan = 6;
  }
  async function request(params = {}, method = 'GET', body) {
    const url = new URL(endpoint);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const options = { method, credentials: 'same-origin', cache: 'no-store' };
    if (body) { options.headers = { 'Content-Type': 'application/json' }; options.body = JSON.stringify(body); }
    const response = await fetch(url, options);
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load borrower records.');
    return data;
  }
  function activate(index, focus = false) {
    selected = index;
    tabs.forEach((tab, i) => { tab.setAttribute('aria-selected', String(i === index)); tab.tabIndex = i === index ? 0 : -1; panels[i].hidden = i !== index; });
    if (focus) tabs[index].focus();
    if (index === 1) load();
  }
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activate(index));
    tab.addEventListener('keydown', event => {
      if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
      event.preventDefault(); activate(event.key === 'Home' ? 0 : event.key === 'End' ? 1 : 1 - index, true);
    });
  });
  async function load() {
    const version = ++listVersion;
    try {
      const data = await request({ search: search.value.trim() });
      if (version !== listVersion) return;
      message(feedback);
      document.getElementById('registeredBorrowerCount').textContent = data.borrowers.length;
      empty(tbody, 'No registered borrowers match this search.');
      if (data.borrowers.length) tbody.replaceChildren();
      for (const borrower of data.borrowers) {
        const row = text(tbody, 'tr', '');
        for (const value of [borrower.student_faculty_id, name(borrower), borrower.department_course, borrower.contact_number || 'Not recorded', borrower.email || 'Not recorded']) text(row, 'td', value);
        const actions = text(text(row, 'td', ''), 'div', '', 'borrower-row-actions');
        for (const [label, cls, action] of [['Edit','btn-edit-borrower',() => edit(borrower.id)],['View History','btn-view-history',() => history(borrower.id)],['Delete','btn-delete-borrower',() => confirmDelete(borrower)]]) {
          const button = text(actions, 'button', label, `btn ${label === 'Delete' ? 'btn-danger' : 'btn-secondary'} btn-sm ${cls}`);
          button.type = 'button'; button.setAttribute('aria-label', `${label}: ${name(borrower)} (${borrower.student_faculty_id})`);
          button.addEventListener('click', action);
        }
      }
    } catch (error) { if (version === listVersion) message(feedback, error.message + ' Use Refresh to retry.', true); }
  }
  function openEdit(row = {}) {
    form.reset();
    fields.forEach(field => { form.elements.namedItem(field).value = row[field] ?? ''; });
    const legacySurname = row.student_faculty_id?.startsWith('LEGACY-') && !row.last_name;
    form.elements.last_name.required = !legacySurname;
    document.getElementById('borrowerEditTitle').textContent = row.id ? 'Edit Borrower' : 'Add Borrower';
    message(document.getElementById('borrowerFormFeedback'));
    document.getElementById('borrowerEditDialog').showModal();
  }
  async function edit(id) {
    const version = ++dialogVersion;
    try { const data = await request({ id }); if (version === dialogVersion) openEdit(data.borrower); }
    catch (error) { if (version === dialogVersion) message(feedback, error.message, true); }
  }
  async function history(id) {
    const version = ++dialogVersion;
    const dialog = document.getElementById('borrowerHistoryDialog');
    const body = document.getElementById('borrowerHistoryBody');
    document.getElementById('borrowerHistoryTitle').textContent = 'Borrowing History';
    message(document.getElementById('borrowerHistorySummary'), 'Loading borrowing history...');
    empty(body, 'Loading...'); dialog.showModal();
    try {
      const data = await request({ id, action: 'history' });
      if (version !== dialogVersion || !dialog.open) return;
      document.getElementById('borrowerHistoryTitle').textContent = `${name(data.borrower)} - ${data.borrower.student_faculty_id}`;
      const total = data.history.reduce((sum, loan) => sum + Number(loan.total_penalty_amount || 0), 0);
      message(document.getElementById('borrowerHistorySummary'), `${data.history.length} loan record(s). Recorded penalties: PHP ${money(total)}.`);
      empty(body, 'No borrowing history for this borrower.');
      if (data.history.length) body.replaceChildren();
      for (const loan of data.history) {
        const row = text(body, 'tr', '');
        for (const value of [`${loan.equip_code} - ${loan.equipment_name}`, `${loan.borrow_date} ${loan.time_out || ''}`, `${loan.due_date || loan.expected_return_date} ${loan.expected_return_time || ''}`, loan.actual_time_in || 'Not returned', loan.current_status || loan.status, `PHP ${money(loan.total_penalty_amount)}`]) text(row, 'td', value);
      }
    } catch (error) { if (version === dialogVersion) { empty(body, 'Unable to load history.'); message(document.getElementById('borrowerHistorySummary'), error.message, true); } }
  }
  function confirmDelete(row) {
    dialogVersion++;
    deleteId = row.id;
    document.getElementById('borrowerDeleteDescription').textContent = `Delete ${name(row)} (${row.student_faculty_id})?`;
    message(document.getElementById('borrowerDeleteFeedback'));
    document.getElementById('borrowerDeleteDialog').showModal();
  }
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = document.getElementById('saveBorrowerBtn');
    if (button.disabled || !form.reportValidity()) return;
    button.disabled = true;
    const body = Object.fromEntries(new FormData(form));
    const id = body.id;
    if (!id) delete body.id;
    try {
      const data = await request({}, id ? 'PUT' : 'POST', body);
      document.getElementById('borrowerEditDialog').close();
      await load(); message(feedback, data.message);
      tabs[selected].focus(); window.dispatchEvent(new Event('borrowers:changed'));
    } catch (error) { message(document.getElementById('borrowerFormFeedback'), error.message, true); }
    finally { button.disabled = false; }
  });
  document.getElementById('confirmDeleteBorrowerBtn').addEventListener('click', async event => {
    const button = event.currentTarget;
    if (button.disabled || !deleteId) return;
    button.disabled = true;
    try {
      const data = await request({}, 'DELETE', { id: deleteId });
      document.getElementById('borrowerDeleteDialog').close(); deleteId = null;
      await load(); message(feedback, data.message); tabs[selected].focus();
      window.dispatchEvent(new Event('borrowers:changed'));
    } catch (error) { message(document.getElementById('borrowerDeleteFeedback'), error.message, true); }
    finally { button.disabled = false; }
  });
  document.querySelectorAll('[data-borrower-close]').forEach(button => button.addEventListener('click', () => document.getElementById(button.dataset.borrowerClose).close()));
  for (const id of ['borrowerEditDialog','borrowerHistoryDialog','borrowerDeleteDialog']) {
    document.getElementById(id).addEventListener('close', () => { dialogVersion++; });
  }
  document.getElementById('addBorrowerBtn').addEventListener('click', () => { dialogVersion++; openEdit(); });
  document.getElementById('refreshBorrowersBtn').addEventListener('click', load);
  search.addEventListener('input', () => { listVersion++; clearTimeout(timer); timer = setTimeout(load, 220); });
  document.querySelector('.dash-tab[data-tab="users"]')?.addEventListener('click', () => { if (selected === 1) load(); });
})();
