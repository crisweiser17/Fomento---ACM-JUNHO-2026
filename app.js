/* ============================================================
 * app.js — Comportamentos transversais de UI (sem framework).
 * ------------------------------------------------------------
 * 1. Confirmação para ações perigosas: qualquer elemento com
 *    [data-confirm] abre um modal de confirmação antes de seguir.
 * 2. Estado de loading: forms/botões marcados ganham spinner ao enviar.
 * 3. Toasts: API global window.appToast(msg, type) + leitura de
 *    ?status=...&msg=... na URL para feedback pós-redirect.
 *
 * Depende do Bootstrap bundle (Modal/Toast) já carregado.
 * ========================================================== */
(function () {
    'use strict';

    /* ---------- Infra: injeta modal de confirmação + container de toasts ---------- */
    function injectScaffold() {
        if (!document.getElementById('appConfirmModal')) {
            const modal = document.createElement('div');
            modal.innerHTML = `
<div class="modal fade" id="appConfirmModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill text-warning me-2"></i><span data-confirm-title>Confirmar ação</span></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
      </div>
      <div class="modal-body" data-confirm-body>Tem certeza que deseja continuar?</div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
        <button type="button" class="btn btn-danger" data-confirm-ok><i class="bi bi-check-lg"></i> Confirmar</button>
      </div>
    </div>
  </div>
</div>`;
            document.body.appendChild(modal.firstElementChild);
        }
        if (!document.getElementById('appToastContainer')) {
            const c = document.createElement('div');
            c.id = 'appToastContainer';
            c.className = 'app-toast-container';
            document.body.appendChild(c);
        }
    }

    /* ---------- 1. Confirmação de ações perigosas ---------- */
    function setupConfirm() {
        const modalEl = document.getElementById('appConfirmModal');
        if (!modalEl || !window.bootstrap) return;
        const modal = new bootstrap.Modal(modalEl);
        let pending = null; // elemento que disparou a confirmação

        document.addEventListener('click', function (ev) {
            const trigger = ev.target.closest('[data-confirm]');
            if (!trigger) return;
            // Já confirmado? deixa seguir.
            if (trigger.dataset.confirmed === '1') return;

            ev.preventDefault();
            ev.stopPropagation();
            pending = trigger;

            const msg = trigger.getAttribute('data-confirm') || 'Tem certeza que deseja continuar?';
            const title = trigger.getAttribute('data-confirm-title') || 'Confirmar ação';
            modalEl.querySelector('[data-confirm-body]').textContent = msg;
            modalEl.querySelector('[data-confirm-title]').textContent = title;
            modal.show();
        }, true); // captura: roda antes de handlers da página

        modalEl.querySelector('[data-confirm-ok]').addEventListener('click', function () {
            modal.hide();
            if (!pending) return;
            const el = pending;
            pending = null;
            el.dataset.confirmed = '1';

            // Reproduz a ação original conforme o tipo de elemento.
            if (el.tagName === 'A' && el.href) {
                window.location.href = el.href;
            } else if (el.form || el.closest('form')) {
                const form = el.form || el.closest('form');
                // Preserva name/value de botões submit.
                if (el.name) {
                    const hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = el.name;
                    hidden.value = el.value || '';
                    form.appendChild(hidden);
                }
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            } else {
                el.click(); // re-dispara; agora passa por causa do data-confirmed
            }
        });
    }

    /* ---------- 2. Estado de loading em submit ---------- */
    function setupLoading() {
        document.addEventListener('submit', function (ev) {
            const form = ev.target;
            if (!(form instanceof HTMLFormElement)) return;
            if (form.dataset.noLoading === '1') return;

            const btn = form.querySelector('[type="submit"]:not([data-no-loading])');
            if (!btn || btn.dataset.loadingActive === '1') return;

            // Não trava em forms inválidos (validação nativa).
            if (typeof form.checkValidity === 'function' && !form.checkValidity()) return;

            btn.dataset.loadingActive = '1';
            btn.dataset.originalHtml = btn.innerHTML;
            const label = btn.getAttribute('data-loading-text') || 'Processando…';
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>' + label;
            btn.disabled = true;

            // Failsafe: reabilita se a navegação não ocorrer (ex.: erro JS).
            setTimeout(function () {
                if (btn.dataset.loadingActive === '1') {
                    btn.disabled = false;
                    btn.innerHTML = btn.dataset.originalHtml;
                    btn.dataset.loadingActive = '0';
                }
            }, 12000);
        });
    }

    /* ---------- 3. Toasts ---------- */
    function makeToast(message, type) {
        const container = document.getElementById('appToastContainer');
        if (!container || !window.bootstrap) {
            // fallback discreto
            if (type === 'error') { console.error(message); }
            return;
        }
        const palette = {
            success: { bg: 'text-bg-success', icon: 'bi-check-circle-fill' },
            error:   { bg: 'text-bg-danger',  icon: 'bi-exclamation-octagon-fill' },
            warning: { bg: 'text-bg-warning', icon: 'bi-exclamation-triangle-fill' },
            info:    { bg: 'text-bg-primary', icon: 'bi-info-circle-fill' },
        };
        const cfg = palette[type] || palette.info;
        const el = document.createElement('div');
        el.className = 'toast align-items-center ' + cfg.bg + ' border-0';
        el.setAttribute('role', 'alert');
        el.innerHTML =
            '<div class="d-flex">' +
              '<div class="toast-body"><i class="bi ' + cfg.icon + ' me-2"></i>' + escapeHtml(message) + '</div>' +
              '<button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Fechar"></button>' +
            '</div>';
        container.appendChild(el);
        const toast = new bootstrap.Toast(el, { delay: 5000 });
        toast.show();
        el.addEventListener('hidden.bs.toast', function () { el.remove(); });
    }

    function escapeHtml(s) {
        const d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    /* Lê ?status=success|deleted|error&msg=... e mostra toast equivalente,
       depois limpa esses params da URL (sem recarregar). */
    function readStatusFromUrl() {
        const params = new URLSearchParams(window.location.search);
        const status = params.get('status');
        if (!status) return;

        const msg = params.get('msg');
        if (status === 'error') {
            makeToast(msg || 'Ocorreu um erro ao processar a solicitação.', 'error');
        } else if (status === 'success' || status === 'deleted' || status === 'ok' || status === 'saved') {
            const defaults = {
                deleted: 'Registro excluído com sucesso.',
                saved:   'Alterações salvas com sucesso.',
            };
            makeToast(msg || defaults[status] || 'Operação concluída com sucesso.', 'success');
        } else if (status === 'warning') {
            makeToast(msg || 'Atenção.', 'warning');
        } else {
            return;
        }

        params.delete('status');
        params.delete('msg');
        const newQuery = params.toString();
        const newUrl = window.location.pathname + (newQuery ? '?' + newQuery : '') + window.location.hash;
        window.history.replaceState({}, '', newUrl);
    }

    // API pública
    window.appToast = makeToast;

    /* ---------- Boot ---------- */
    function init() {
        injectScaffold();
        setupConfirm();
        setupLoading();
        readStatusFromUrl();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
