'use strict';

(() => {
  const body = document.getElementById('overdueTableBody');
  if (!body) return;
  const endpoint = new URL('../modules/overdue.php', document.currentScript.src);
  const count = document.getElementById('overdueCount');
  const feedback = document.getElementById('overdueFeedback');
  const refresh = document.getElementById('refreshOverdueBtn');
  const previewDialog = document.getElementById('overdueEmailPreviewDialog');
  const previewDetails = document.getElementById('overdueEmailPreviewDetails');
  const previewMessage = document.getElementById('overdueEmailPreviewMessage');
  let previewVersion = 0;
  let emailMode = 'unconfigured';
  let loading = false;
  let hasLoaded = false;
  const emailStatusLabel = status => ({ Pending: 'Prepared; not sent yet', Accepted: 'Accepted by SMTP', Simulated: 'Preview only; not sent', Failed: 'Not sent', Skipped: 'Not sent', Unknown: 'Confirmation pending' }[status] || status || 'Not notified');

  function text(parent, tag, value, className) {
    const element = document.createElement(tag);
    element.textContent = value;
    if (className) element.className = className;
    parent.append(element);
    return element;
  }
  function message(value, error = false) {
    feedback.textContent = value;
    feedback.hidden = !value;
    feedback.classList.toggle('overdue-error', error);
  }
  async function request(options, params = {}) {
    const url = new URL(endpoint);
    Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
    const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
    const data = await response.json();
    if (!response.ok || !data.success) throw new Error(data.message || 'Unable to load overdue records.');
    return data;
  }
  async function showPreview(id) {
    const version = ++previewVersion;
    previewDetails.textContent = 'Loading recorded Email...';
    previewMessage.textContent = '';
    previewDialog.showModal();
    try {
      // This read-only endpoint reads the saved snapshot and never sends email.
      const data = await request(undefined, { action: 'email_preview', id });
      if (version !== previewVersion || !previewDialog.open) return;
      const email = data.email;
      previewDetails.textContent = `To: ${email.recipient_email || 'Not recorded'} | ${email.equip_code} | ${emailStatusLabel(email.status)} | ${email.processed_at || email.notification_date}`;
      previewMessage.textContent = `Subject: ${email.subject}\n\n${email.message}`;
    } catch (error) {
      if (version === previewVersion && previewDialog.open) previewDetails.textContent = error.message;
    }
  }
  previewDialog.addEventListener('close', () => { previewVersion++; });
  document.querySelectorAll('[data-close-email-preview]').forEach(button => button.addEventListener('click', () => previewDialog.close()));
  function render(rows) {
    body.replaceChildren();
    count.textContent = String(rows.length);
    if (!rows.length) {
      const cell = text(text(body, 'tr', ''), 'td', 'No overdue equipment.');
      cell.colSpan = 6;
      return;
    }
    for (const loan of rows) {
      const row = text(body, 'tr', '');
      const borrower = text(row, 'td', '');
      text(borrower, 'strong', loan.borrower_name);
      text(borrower, 'small', loan.student_faculty_id || 'No registered borrower linked');
      text(borrower, 'small', loan.email || 'No registered email');
      const equipment = text(row, 'td', '');
      text(equipment, 'strong', loan.equip_code);
      text(equipment, 'small', loan.equipment_name);
      text(row, 'td', `${loan.due_date} ${loan.expected_return_time || ''}`.trim());
      text(row, 'td', String(loan.days_overdue));
      text(row, 'td', Number(loan.penalty_amount).toFixed(2));
      const actions = text(row, 'td', '');
      const status = emailStatusLabel(loan.email_status);
      text(actions, 'small', status);
      if (loan.email_error) text(actions, 'small', 'Delivery requires attention.', 'overdue-error');
      const preview = text(actions, 'button', 'Preview Email', 'btn btn-secondary btn-sm');
      preview.type = 'button';
      preview.setAttribute('aria-label', `Preview recorded Email for ${loan.equip_code}`);
      preview.addEventListener('click', () => showPreview(loan.id));
      if (!loan.student_faculty_id) {
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'modal-form-input overdue-link-input';
        input.placeholder = 'Student/Faculty ID';
        input.setAttribute('aria-label', `Registered borrower ID for ${loan.equip_code}`);
        actions.append(input);
        const link = text(actions, 'button', 'Link borrower', 'btn btn-secondary btn-sm');
        link.type = 'button';
        link.addEventListener('click', () => act(link, { action: 'link_borrower', id: loan.id, student_faculty_id: input.value.trim() }));
      } else if (emailMode === 'live') {
        const notify = text(actions, 'button', 'Send email reminder', 'btn btn-primary btn-sm');
        notify.type = 'button';
        const today = loan.email_processed_at?.slice(0, 10);
        const handledToday = loan.email_notification_date === loan.database_today;
        // The server enforces daily deduplication; the UI also prevents repeated accepted sends.
        notify.disabled = !loan.email || (handledToday && ['Accepted', 'Processing', 'Unknown'].includes(loan.email_status));
        if (handledToday && loan.email_status === 'Accepted') notify.textContent = today ? `Accepted ${today}` : 'Accepted';
        notify.addEventListener('click', () => act(notify, { action: 'notify', id: loan.id }));
      }
    }
  }
  async function load(options = {}) {
    if (loading) return;
    loading = true;
    refresh.disabled = true;
    try {
      const data = await request();
      emailMode = data.email_mode;
      render(data.overdue);
      hasLoaded = true;
      if (!options.preserveFeedback) message('');
      if (emailMode === 'unconfigured') message('Emails are prepared but not sent. Gmail delivery needs administrator configuration.', true);
      else if (data.notification_error) message(data.notification_error, true);
    } catch (error) {
      // A failed initial request must not leave the static loading row on screen.
      if (!hasLoaded) {
        body.replaceChildren();
        const cell = text(text(body, 'tr', ''), 'td', 'Unable to load overdue records. Select Check overdue to retry.');
        cell.colSpan = 6;
        count.textContent = '\u2014';
      }
      message(`${error.message || 'Unable to load overdue records.'} Select Check overdue to retry.`, true);
    } finally {
      loading = false;
      refresh.disabled = false;
    }
  }
  async function act(button, fields) {
    button.disabled = true;
    message('');
    try {
      const data = await request({ method: 'POST', body: new URLSearchParams(fields) });
      message(data.message);
    } catch (error) {
      message(error.message, true);
    } finally {
      button.disabled = false;
      await load({ preserveFeedback: true });
    }
  }
  refresh.addEventListener('click', () => { message(''); load(); });
  window.addEventListener('borrowing:changed', load);
  setInterval(() => {
    if (!document.hidden && document.getElementById('tab-borrowing')?.classList.contains('active')) load();
  }, 60000);
  load();
})();
