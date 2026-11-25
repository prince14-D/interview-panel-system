/**
 * MYS Interview Panel Management & Scoring System — Shared Frontend Helpers
 */
window.MYS = {
    csrfToken: function () {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    },

    postJson: async function (url, payload) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': this.csrfToken(),
                'Accept': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        const data = await res.json().catch(() => ({ ok: false, error: 'Invalid server response' }));
        if (!res.ok || data.ok === false) {
            throw new Error(data.error || 'Request failed');
        }
        return data;
    },

    copyText: function (text, btn) {
        if (!navigator.clipboard) {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            ta.remove();
        } else {
            navigator.clipboard.writeText(text);
        }
        if (btn) {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2"></i> Copied';
            btn.classList.add('btn-success');
            setTimeout(() => {
                btn.innerHTML = orig;
                btn.classList.remove('btn-success');
            }, 1800);
        }
    }
};
