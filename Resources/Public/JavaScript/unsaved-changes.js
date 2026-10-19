/**
 * Leave guard for the Translate grid: unsaved grid changes (highlighted Save button) are not lost
 * silently by a browser reload, an in-module link (tabs, Reload) or switching the backend module.
 * Module actions that carry the grid or are explicit (Save, Translate, filters, CSV, Create file,
 * Export ZIP) leave without asking, as before.
 */
import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

const ALLOW_WINDOW_MS = 2000;
const GUARDED_REQUEST_TYPES = ['typo3.setUrl', 'typo3.beforeSetUrl', 'typo3.refresh'];
const DIRTY_SAVE_BUTTON = 'button[form="translate_labels"][name="fileLocation"][value="local"].btn-success';

let unloadAllowedUntil = 0;

function allowUnload() {
  unloadAllowedUntil = Date.now() + ALLOW_WINDOW_MS;
}

function hasUnsavedChanges() {
  if (Date.now() < unloadAllowedUntil) {
    return false;
  }
  // The module shows its loader whenever it submits on purpose (Save, Translate, filter changes).
  const loader = document.getElementById('ns-t3al__loader');
  if (loader && loader.classList.contains('ns-show-overlay')) {
    return false;
  }
  return document.querySelector(DIRTY_SAVE_BUTTON) !== null;
}

function label(key, fallback) {
  return (typeof TYPO3 !== 'undefined' && TYPO3.lang && TYPO3.lang[key]) || fallback;
}

function confirmDiscard() {
  return new Promise((resolve, reject) => {
    const modal = Modal.confirm(
      label('unsaved.title', 'Unsaved changes'),
      label('unsaved.message', 'The label grid has unsaved changes. They are lost if you leave now.'),
      Severity.warning,
      [
        {
          text: label('unsaved.stay', 'Keep editing'),
          btnClass: 'btn-default',
          name: 'stay',
          active: true,
          trigger: () => modal.hideModal(),
        },
        {
          text: label('unsaved.discard', 'Discard changes'),
          btnClass: 'btn-warning',
          name: 'discard',
          trigger: () => {
            allowUnload();
            resolve();
            modal.hideModal();
          },
        },
      ]
    );
    modal.addEventListener('typo3-modal-hidden', () => reject(new Error('Leaving cancelled: unsaved changes')));
  });
}

function attachBackendNavigationGuard() {
  let scope = null;
  try {
    scope = top.TYPO3 && top.TYPO3.Backend ? top.TYPO3.Backend.consumerScope : null;
  } catch (e) {
    scope = null;
  }
  if (!scope || typeof scope.attach !== 'function') {
    return;
  }
  // The module menu sends beforeSetUrl and setUrl for one click; ask once per click.
  const decisions = new WeakMap();
  const consumer = {
    consume(request) {
      if (!request || typeof request.concernsTypes !== 'function'
        || !request.concernsTypes(GUARDED_REQUEST_TYPES) || !hasUnsavedChanges()) {
        return null;
      }
      const click = request.outerMostRequest || request;
      if (!decisions.has(click)) {
        decisions.set(click, confirmDiscard());
      }
      return decisions.get(click);
    },
  };
  scope.attach(consumer);
  window.addEventListener('pagehide', () => scope.detach(consumer));
  window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
      scope.attach(consumer);
    }
  });
}

window.addEventListener('beforeunload', (event) => {
  if (!hasUnsavedChanges()) {
    return;
  }
  event.preventDefault();
  event.returnValue = '';
});

document.addEventListener('submit', allowUnload, true);
document.addEventListener('click', (event) => {
  if (event.target instanceof Element && event.target.closest('[data-ns-t3al-download]')) {
    allowUnload();
  }
}, true);

attachBackendNavigationGuard();
