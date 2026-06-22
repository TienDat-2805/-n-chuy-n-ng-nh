(function () {
    window.TimeTableEnhanced = true;

    const pageSelector = '.container';
    const ajaxFormSelector = [
        'form[data-ajax-form]',
        'form[data-ajax-submit]',
        'form[data-async-room-form]',
        'form[data-async-subject-lecturer-form]',
        'form[data-async-schedule-form]'
    ].join(',');
    const debounceTimers = new WeakMap();
    let pageRequestId = 0;

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.content || '';
    }

    function currentPage() {
        return document.querySelector(pageSelector);
    }

    function replacePage(html, url, pushHistory = true) {
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const nextPage = doc.querySelector(pageSelector);
        const page = currentPage();

        if (!nextPage || !page) {
            window.location.href = url || window.location.href;
            return;
        }

        const focusState = focusedFieldState(page);

        const nextHeadTitle = doc.querySelector('.page-head h1');
        const currentHeadTitle = document.querySelector('.page-head h1');
        if (nextHeadTitle && currentHeadTitle) {
            currentHeadTitle.textContent = nextHeadTitle.textContent;
        }

        const nextDocumentTitle = doc.querySelector('title');
        if (nextDocumentTitle) {
            document.title = nextDocumentTitle.textContent;
        }

        page.innerHTML = nextPage.innerHTML;
        restoreFocusedField(page, focusState);

        if (url && pushHistory) {
            window.history.pushState({}, '', url);
        }

        initializePage(page);
    }

    async function fetchPage(url, pushHistory = true) {
        const requestId = ++pageRequestId;
        const response = await fetch(url, {
            headers: {
                'Accept': 'text/html'
            }
        });
        const html = await response.text();

        if (!response.ok) {
            throw new Error('Không thể tải dữ liệu.');
        }

        if (requestId !== pageRequestId) {
            return;
        }

        replacePage(html, response.url || url, pushHistory);
    }

    function focusedFieldState(page) {
        const active = document.activeElement;

        if (!active || !page.contains(active) || !active.name) {
            return null;
        }

        return {
            name: active.name,
            value: active.value,
            selectionStart: typeof active.selectionStart === 'number' ? active.selectionStart : null,
            selectionEnd: typeof active.selectionEnd === 'number' ? active.selectionEnd : null
        };
    }

    function restoreFocusedField(page, state) {
        if (!state) {
            return;
        }

        const nextField = Array.from(page.querySelectorAll('[name]'))
            .find((field) => field.name === state.name && field.value === state.value);

        if (!nextField) {
            return;
        }

        nextField.focus();

        if (state.selectionStart !== null && state.selectionEnd !== null && typeof nextField.setSelectionRange === 'function') {
            nextField.setSelectionRange(state.selectionStart, state.selectionEnd);
        }
    }

    function formUrl(form) {
        const params = new URLSearchParams(new FormData(form));
        const url = new URL(form.action || window.location.href, window.location.origin);

        Array.from(url.searchParams.keys()).forEach((key) => url.searchParams.delete(key));

        params.forEach((value, key) => {
            if (String(value).trim() !== '') {
                url.searchParams.append(key, value);
            }
        });

        return url.toString();
    }

    function submitButton(form) {
        return form.querySelector('button[type="submit"]');
    }

    function statusNode(form) {
        return form.querySelector('[data-async-status]');
    }

    function defaultLoadingText(form) {
        if (form.matches('[data-async-schedule-form]')) {
            return 'Đang xếp lịch...';
        }

        if (form.enctype === 'multipart/form-data') {
            return 'Đang import dữ liệu...';
        }

        return 'Đang xử lý...';
    }

    function setPending(form, pending) {
        const button = submitButton(form);
        const status = statusNode(form);

        if (button) {
            if (!button.dataset.defaultText) {
                button.dataset.defaultText = button.textContent;
            }

            button.disabled = pending;
            button.textContent = pending
                ? (form.dataset.loadingText || defaultLoadingText(form))
                : button.dataset.defaultText;
        }

        if (status && pending) {
            status.textContent = form.dataset.statusText || defaultLoadingText(form);
            status.dataset.state = 'saving';
        }
    }

    async function submitAjaxForm(form, pushHistory = true) {
        const method = (form.getAttribute('method') || 'GET').toUpperCase();

        if (method === 'GET') {
            await fetchPage(formUrl(form), pushHistory);
            return;
        }

        const confirmMessage = form.dataset.confirmMessage;
        if (confirmMessage && !window.confirm(confirmMessage)) {
            return;
        }

        pageRequestId++;
        setPending(form, true);

        try {
            const response = await fetch(form.action, {
                method: 'POST',
                body: new FormData(form),
                headers: {
                    'Accept': 'text/html',
                    'X-CSRF-TOKEN': csrfToken()
                }
            });

            const html = await response.text();
            if (!response.ok) {
                throw new Error('Không thể xử lý yêu cầu.');
            }

            replacePage(html, response.url || window.location.href, pushHistory);
        } catch (error) {
            const status = statusNode(form);
            if (status) {
                status.textContent = error.message || 'Không thể xử lý yêu cầu.';
                status.dataset.state = 'error';
            }

            setPending(form, false);
        }
    }

    function debounceSubmit(form) {
        window.clearTimeout(debounceTimers.get(form));

        const timer = window.setTimeout(() => {
            submitAjaxForm(form).catch(() => {});
        }, Number(form.dataset.debounce || 350));

        debounceTimers.set(form, timer);
    }

    function syncAvailabilityPanel(select) {
        const form = select.closest('form');
        const panel = form?.querySelector('.availability-limited-panel');

        if (panel) {
            panel.classList.toggle('is-hidden', select.value !== 'limited');
        }
    }

    function initializePage(root = document) {
        root.querySelectorAll('.availability-mode-select').forEach(syncAvailabilityPanel);
    }

    function conflictPage() {
        return document.querySelector('[data-conflicts-page]');
    }

    function conflictUrls() {
        const page = conflictPage();

        return {
            suggestions: page?.dataset.suggestionsUrl || '',
            apply: page?.dataset.applyUrl || ''
        };
    }

    function conflictTargets(row) {
        if (row.dataset.adjustmentTargets) {
            try {
                return JSON.parse(row.dataset.adjustmentTargets);
            } catch (error) {
                return [];
            }
        }

        const targets = Array.from(row.querySelectorAll('[data-target-button]')).map((button) => ({
            meetingId: button.dataset.targetMeetingId,
            label: button.dataset.targetLabel
        })).filter((target) => target.meetingId);

        row.dataset.adjustmentTargets = JSON.stringify(targets);

        return targets;
    }

    function adjustmentCell(row) {
        return row.querySelector('[data-adjustment-cell]');
    }

    function renderAdjustmentStatus(row, message, state = 'muted') {
        const cell = adjustmentCell(row);
        if (!cell) {
            return;
        }

        const status = document.createElement('span');
        status.className = state === 'loading'
            ? 'adjustment-loading'
            : (state === 'error' ? 'adjustment-status is-error' : 'adjustment-status');
        status.textContent = message;

        cell.replaceChildren(status);
    }

    function normalizeErrorMessage(data, fallback) {
        const message = typeof data?.message === 'string' ? data.message : '';

        if (message.toLowerCase().includes('selected conflict id is invalid')) {
            return 'Danh sách cảnh báo đã thay đổi, đang làm mới...';
        }

        if (message) {
            return message;
        }

        if (data?.errors && typeof data.errors === 'object') {
            const firstError = Object.values(data.errors)
                .flat()
                .find(Boolean);

            if (firstError) {
                return String(firstError);
            }
        }

        return fallback;
    }

    function isStaleConflictError(message) {
        return String(message || '').includes('Danh sách cảnh báo đã thay đổi');
    }

    function refreshConflictsSoon(delay = 400) {
        window.setTimeout(() => {
            fetchPage(window.location.href, false).catch(() => window.location.reload());
        }, delay);
    }

    function renderTargetPicker(row, message = '') {
        const cell = adjustmentCell(row);
        if (!cell) {
            return;
        }

        const picker = document.createElement('div');
        picker.className = 'adjustment-target-picker';

        const label = document.createElement('small');
        label.className = message ? 'adjustment-target-message' : '';
        label.textContent = message || 'Chọn lịch cần điều chỉnh';
        picker.append(label);

        conflictTargets(row).forEach((target) => {
            const button = document.createElement('button');
            button.className = 'adjustment-target-button';
            button.type = 'button';
            button.dataset.targetButton = '';
            button.dataset.targetMeetingId = target.meetingId;
            button.dataset.targetLabel = target.label;
            button.textContent = `Chỉnh ${target.label}`;
            picker.append(button);
        });

        cell.replaceChildren(picker);
    }

    async function loadSuggestions(row, button) {
        const urls = conflictUrls();
        if (!urls.suggestions) {
            return;
        }

        row.querySelectorAll('[data-target-button]').forEach((item) => {
            item.disabled = true;
            item.classList.toggle('active', item === button);
        });

        renderAdjustmentStatus(row, `Đang tìm phương án cho lịch ${button.dataset.targetLabel || ''}...`, 'loading');

        try {
            const response = await fetch(urls.suggestions, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken()
                },
                body: JSON.stringify({
                    conflict_id: Number(row.dataset.conflictId),
                    target_meeting_id: Number(button.dataset.targetMeetingId)
                })
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok || !data.ok) {
                throw new Error(normalizeErrorMessage(data, 'Không thể tính phương án điều chỉnh.'));
            }

            renderSuggestions(row, data.suggestions || []);
        } catch (error) {
            if (isStaleConflictError(error.message)) {
                renderAdjustmentStatus(row, error.message, 'loading');
                refreshConflictsSoon();
                return;
            }

            renderTargetPicker(row, error.message || 'Không thể tính phương án điều chỉnh.');
        }
    }

    function renderSuggestions(row, suggestions) {
        const cell = adjustmentCell(row);
        if (!cell) {
            return;
        }

        if (suggestions.length === 0) {
            renderAdjustmentStatus(row, 'Chưa tìm được phương án phù hợp.', 'muted');
            return;
        }

        const list = document.createElement('div');
        list.className = 'adjustment-list';

        suggestions.forEach((suggestion) => {
            const option = document.createElement('div');
            option.className = 'adjustment-option';

            const content = document.createElement('div');
            const title = document.createElement('strong');
            const targetLabel = suggestion.target_label || 'A';
            const targetTitle = suggestion.target_title || 'Lịch học phần';
            title.textContent = `Chỉnh lịch ${targetLabel}: ${targetTitle}`;

            const label = document.createElement('span');
            label.textContent = [suggestion.title, suggestion.label].filter(Boolean).join(' · ');

            const hint = document.createElement('small');
            hint.textContent = suggestion.hint || '';

            content.append(title, label, hint);

            const button = document.createElement('button');
            button.className = 'btn adjustment-save';
            button.type = 'button';
            button.textContent = 'Lưu';
            button.dataset.suggestion = JSON.stringify({
                meeting_id: suggestion.meeting_id,
                day_of_week: suggestion.day_of_week,
                start_period: suggestion.start_period,
                end_period: suggestion.end_period,
                room_id: suggestion.room_id
            });

            option.append(content, button);
            list.append(option);
        });

        const changeTarget = document.createElement('button');
        changeTarget.className = 'adjustment-change-target';
        changeTarget.type = 'button';
        changeTarget.textContent = 'Chọn lịch khác';

        cell.replaceChildren(list, changeTarget);
    }

    async function applySuggestion(row, button) {
        const urls = conflictUrls();
        if (!urls.apply) {
            return;
        }

        let payload = {};
        try {
            payload = JSON.parse(button.dataset.suggestion || '{}');
        } catch (error) {
            renderAdjustmentStatus(row, 'Phương án không hợp lệ.', 'error');
            return;
        }

        row.querySelectorAll('.adjustment-save').forEach((item) => item.disabled = true);
        button.textContent = 'Đang lưu...';

        try {
            const response = await fetch(urls.apply, {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken()
                },
                body: JSON.stringify(payload)
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok || !data.ok) {
                throw new Error(normalizeErrorMessage(data, 'Không thể lưu phương án này.'));
            }

            row.classList.add('is-resolved');
            renderAdjustmentStatus(row, 'Đã lưu phương án.', 'success');

            window.setTimeout(() => {
                row.classList.add('is-hiding');
                window.setTimeout(() => {
                    row.remove();
                    fetchPage(window.location.href, false).catch(() => {});
                }, 320);
            }, 3500);
        } catch (error) {
            if (isStaleConflictError(error.message)) {
                renderAdjustmentStatus(row, error.message, 'loading');
                refreshConflictsSoon();
                return;
            }

            row.querySelectorAll('.adjustment-save').forEach((item) => item.disabled = false);
            button.textContent = 'Lưu';
            renderAdjustmentStatus(row, error.message || 'Không thể lưu phương án này.', 'error');
        }
    }

    document.addEventListener('submit', (event) => {
        const form = event.target.closest(ajaxFormSelector);
        if (!form) {
            return;
        }

        event.preventDefault();
        submitAjaxForm(form).catch(() => {});
    });

    document.addEventListener('click', (event) => {
        const targetButton = event.target.closest('[data-target-button]');
        if (targetButton) {
            const row = targetButton.closest('[data-conflict-row]');
            if (row) {
                conflictTargets(row);
                loadSuggestions(row, targetButton);
            }
            return;
        }

        const changeTarget = event.target.closest('.adjustment-change-target');
        if (changeTarget) {
            const row = changeTarget.closest('[data-conflict-row]');
            if (row) {
                renderTargetPicker(row);
            }
            return;
        }

        const saveButton = event.target.closest('.adjustment-save');
        if (saveButton) {
            const row = saveButton.closest('[data-conflict-row]');
            if (row) {
                applySuggestion(row, saveButton);
            }
            return;
        }

        const link = event.target.closest('a');
        if (!link) {
            return;
        }

        const shouldHandle = link.matches('[data-ajax-link], .pagination-list a, .campus-tabs a, .conflict-group-tabs a');
        if (!shouldHandle || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target || link.hasAttribute('download')) {
            return;
        }

        event.preventDefault();
        fetchPage(link.href).catch(() => {
            window.location.href = link.href;
        });
    });

    document.addEventListener('input', (event) => {
        const form = event.target.closest('form[data-ajax-form][data-live-search]');
        if (form && event.target.matches('input[type="text"], input[type="search"], input:not([type])')) {
            debounceSubmit(form);
        }
    });

    document.addEventListener('change', (event) => {
        const availabilitySelect = event.target.closest('.availability-mode-select');
        if (availabilitySelect) {
            syncAvailabilityPanel(availabilitySelect);
        }

        const form = event.target.closest('form[data-ajax-form][data-auto-submit]');
        if (form) {
            submitAjaxForm(form).catch(() => {});
        }
    });

    window.addEventListener('popstate', () => {
        fetchPage(window.location.href, false).catch(() => window.location.reload());
    });

    document.addEventListener('DOMContentLoaded', () => initializePage(document));
})();
