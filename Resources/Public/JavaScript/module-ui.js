/**
 * Module UI helpers for full-page ns_t3al Admin Tools module
 * (dirty save highlight, guide modal)
 */
(function () {
  function bindDirtySaveHighlight() {
    const form = document.getElementById('translate_labels');
    if (!form) {
      return;
    }
    const markDirty = function (event) {
      // Controls placed in the card but owned by another form (CSV import) do not touch the grid.
      if (event.target.form && event.target.form !== form) {
        return;
      }
      document.querySelectorAll('button[name="fileLocation"][value="local"], input[name="fileLocation"][value="local"]').forEach(function (btn) {
        btn.classList.add('btn-success');
        btn.classList.remove('btn-default');
      });
    };
    form.addEventListener('change', markDirty);
    form.addEventListener('input', markDirty);
  }

  /**
   * Guide demo in a TYPO3 modal. TYPO3 v14 ships Bootstrap without the Modal component, so
   * data-bs-toggle="modal" would do nothing there. The demo URL is allow-listed server-side
   * (ModuleController::resolveGuideDemoUrl()). The iframe is passed as content instead of using
   * the modal type "iframe", which reads contentDocument and fails for the cross-origin demo;
   * the core class modal-type-iframe still provides the full-size iframe layout.
   */
  async function openGuideModal(trigger) {
    const demoUrl = trigger.dataset.nsT3alGuideUrl || '';
    if (demoUrl === '') {
      return;
    }
    const ModalModule = await import('@typo3/backend/modal.js');
    const Modal = ModalModule.default || ModalModule;

    const iframe = document.createElement('iframe');
    iframe.className = 'modal-iframe';
    iframe.src = demoUrl;
    iframe.title = trigger.dataset.nsT3alGuideTitle || '';
    iframe.allow = 'clipboard-write; fullscreen';
    iframe.allowFullscreen = true;
    iframe.referrerPolicy = 'no-referrer-when-downgrade';

    Modal.advanced({
      title: trigger.dataset.nsT3alGuideTitle || '',
      content: iframe,
      size: Modal.sizes.large,
      additionalCssClasses: ['modal-type-iframe'],
      buttons: [{
        text: trigger.dataset.nsT3alGuideClose || 'Close',
        btnClass: 'btn-default',
        trigger: function (event, modal) {
          modal.hideModal();
        },
      }],
    });
  }

  function bindGuideModal() {
    document.querySelectorAll('[data-ns-t3al-guide-open]').forEach(function (trigger) {
      trigger.addEventListener('click', function () {
        openGuideModal(trigger);
      });
    });

    if (document.querySelector('[data-ns-t3al-auto-open-guide]')) {
      const trigger = document.querySelector('[data-ns-t3al-guide-open]');
      if (trigger) {
        openGuideModal(trigger);
      }
    }
  }

  function init() {
    bindDirtySaveHighlight();
    bindGuideModal();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, { once: true });
  } else {
    init();
  }
})();
