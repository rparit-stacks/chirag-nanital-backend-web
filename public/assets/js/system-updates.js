'use strict';

/**
 * System Updates page controller.
 * - Submits the update ZIP via axios and kicks off a queued job on the server.
 * - Polls the update row and mirrors status / step / progress / log into the UI.
 * - Auto-resumes polling on page refresh if a pending row is still active.
 */
(function () {
    const form = document.getElementById('update-form');
    const liveCard = document.getElementById('live-log-card');
    const liveText = document.getElementById('live-log-text');
    const liveStatus = document.getElementById('live-log-status');
    const liveVersion = document.getElementById('live-log-version');
    const liveStep = document.getElementById('live-log-step');
    const liveProgressBar = document.getElementById('live-log-progress-bar');
    const liveStaleBanner = document.getElementById('live-log-stale-banner');
    const copyBtn = document.getElementById('copy-live-log');
    const applyBtn = document.getElementById('apply-update-btn');
    const errorBox = document.getElementById('update-error-box');
    const errorList = document.getElementById('update-error-list');

    const config = document.getElementById('system-update-config');
    const cfg = config ? config.dataset : {};
    const latestUrl = cfg.latestUrl;
    const logUrlTmpl = cfg.logUrl;
    const labels = {
        starting: cfg.labelStarting || 'Starting…',
        processing: cfg.labelProcessing || 'Processing…',
        applied: cfg.labelApplied || 'Applied',
        failed: cfg.labelFailed || 'Failed',
        applying: cfg.labelApplying || 'Applying…',
        apply: cfg.labelApply || 'Apply Update',
        copied: cfg.labelCopied || 'Copied',
        copy: cfg.labelCopy || 'Copy',
        stuck: cfg.labelStuck || 'No progress for several minutes — run may be stuck.',
    };

    let pollTimer = null;
    let pollIntervalMs = 2000;
    let pollErrorStreak = 0;
    let currentUpdateId = null;
    let pollStartedAt = null;
    const POLL_MAX_MS = 45 * 60 * 1000; // 45 min safety ceiling

    function setStatus(text, variant) {
        if (!liveStatus) return;
        liveStatus.textContent = text;
        liveStatus.className = 'badge ' + (variant || 'bg-secondary-lt');
    }

    function setStep(stepKey) {
        if (!liveStep) return;
        if (!stepKey) { liveStep.textContent = ''; return; }
        // step comes as snake_case (e.g. "rolling_back"); dataset key is camel-case of the hyphenated data-* attr
        const camel = stepKey.replace(/_/g, '-')
            .split('-').map((s, i) => i === 0 ? s : s.charAt(0).toUpperCase() + s.slice(1)).join('');
        const attr = 'labelStep' + camel.charAt(0).toUpperCase() + camel.slice(1);
        liveStep.textContent = cfg[attr] || stepKey;
    }

    function setProgress(n) {
        if (!liveProgressBar) return;
        const pct = Math.max(0, Math.min(100, Number(n) || 0));
        liveProgressBar.style.width = pct + '%';
        liveProgressBar.setAttribute('aria-valuenow', String(pct));
        liveProgressBar.textContent = pct + '%';
    }

    function showStaleBanner(show) {
        if (!liveStaleBanner) return;
        liveStaleBanner.classList.toggle('d-none', !show);
    }

    function clearErrors() {
        if (errorBox) errorBox.classList.add('d-none');
        if (errorList) errorList.innerHTML = '';
    }

    function renderErrors(messages) {
        if (!errorBox || !errorList) return;
        errorList.innerHTML = '';
        messages.forEach((m) => {
            const li = document.createElement('li');
            li.textContent = m;
            errorList.appendChild(li);
        });
        errorBox.classList.remove('d-none');
    }

    function disableForm() {
        if (applyBtn) {
            applyBtn.disabled = true;
            applyBtn.textContent = labels.applying;
        }
    }

    function enableForm() {
        if (applyBtn) {
            applyBtn.disabled = false;
            applyBtn.textContent = labels.apply;
        }
    }

    function applyPayload(data) {
        if (!data) return;

        if (!currentUpdateId && data.id) currentUpdateId = data.id;

        if (liveText) {
            liveText.textContent = data.log || '';
            liveText.scrollTop = liveText.scrollHeight;
        }
        if (liveVersion) {
            liveVersion.textContent = data.version && data.id
                ? `(v${data.version} #${data.id})`
                : '';
        }

        setStep(data.step);
        setProgress(data.progress);
        showStaleBanner(Boolean(data.is_stale));

        const status = (data.status || '').toLowerCase();
        if (status === 'pending') {
            setStatus(labels.processing, 'bg-warning-lt');
        } else if (status === 'applied') {
            setStatus(labels.applied, 'bg-success-lt');
            setProgress(100);
            stopPolling();
            enableForm();
        } else if (status === 'failed') {
            setStatus(labels.failed, 'bg-danger-lt');
            stopPolling();
            enableForm();
        }
    }

    async function pollOnce() {
        try {
            const url = currentUpdateId
                ? logUrlTmpl.replace('__ID__', currentUpdateId)
                : latestUrl;
            const { data } = await window.axios.get(url);
            pollErrorStreak = 0;
            pollIntervalMs = 2000;
            applyPayload(data);
        } catch (err) {
            pollErrorStreak += 1;
            if (pollErrorStreak >= 3 && pollIntervalMs < 10000) {
                pollIntervalMs = 5000;
                schedulePoll();
            }
        }

        if (pollStartedAt && Date.now() - pollStartedAt > POLL_MAX_MS) {
            stopPolling();
            setStatus(labels.failed, 'bg-danger-lt');
            showStaleBanner(true);
            enableForm();
        }
    }

    function schedulePoll() {
        if (pollTimer) clearInterval(pollTimer);
        pollTimer = setInterval(pollOnce, pollIntervalMs);
    }

    function startPolling() {
        pollStartedAt = pollStartedAt || Date.now();
        pollErrorStreak = 0;
        pollIntervalMs = 2000;
        pollOnce();
        schedulePoll();
    }

    function stopPolling() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
        pollStartedAt = null;
    }

    function showLiveCard() {
        if (liveCard) liveCard.classList.remove('d-none');
    }

    if (copyBtn && liveText) {
        copyBtn.addEventListener('click', function () {
            navigator.clipboard.writeText(liveText.textContent || '');
            this.textContent = labels.copied;
            setTimeout(() => (this.textContent = labels.copy), 1500);
        });
    }

    if (form) {
        form.addEventListener('submit', async function (e) {
            e.preventDefault();
            if (!form.package.files.length) return;
            if (applyBtn && applyBtn.disabled) return; // double-submit guard

            clearErrors();
            showLiveCard();
            if (liveText) liveText.textContent = '';
            if (liveVersion) liveVersion.textContent = '';
            currentUpdateId = null;
            setStatus(labels.starting, 'bg-secondary-lt');
            setStep('queued');
            setProgress(0);
            showStaleBanner(false);
            disableForm();

            try {
                const formData = new FormData(form);
                const { data } = await window.axios.post(form.action, formData);
                if (data && data.update_id) currentUpdateId = data.update_id;
                if (data && data.data) applyPayload(data.data);
                startPolling();
            } catch (err) {
                const resp = err && err.response;
                const payload = resp && resp.data;
                if (payload && payload.errors) {
                    const msgs = [];
                    Object.keys(payload.errors).forEach((k) => {
                        (payload.errors[k] || []).forEach((m) => msgs.push(m));
                    });
                    renderErrors(msgs);
                } else if (payload && payload.message) {
                    renderErrors([payload.message]);
                } else {
                    renderErrors(['Network error. Please try again.']);
                }
                stopPolling();
                enableForm();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', async function () {
        if (!latestUrl) return;
        try {
            const { data } = await window.axios.get(latestUrl);
            if (data && (data.status || '').toLowerCase() === 'pending') {
                currentUpdateId = data.id;
                showLiveCard();
                disableForm();
                applyPayload(data);
                startPolling();
            }
        } catch (_) { /* ignore — page still usable */ }
    });
})();
