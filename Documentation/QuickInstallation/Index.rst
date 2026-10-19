.. include:: ../Includes.txt


.. _quick-installation:

==================
Quick Installation
==================

Installing AI Localization is quick. Choose one of the ways below. For
all requirements and details, see :ref:`installation`.

.. _quick-installation-composer:

Via Composer using the command line
===================================

..  code-block:: bash
    :caption: Install via Composer

    composer require nitsan/ns-t3al
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

Composer also installs the required extension EXT:ns_license.

.. _quick-installation-em:

Via the Extensions module
=========================

In classic mode (without Composer) you can use the Extension Manager in the
TYPO3 backend.

1. Open :guilabel:`Admin Tools > Extensions`.
2. Choose :guilabel:`Get Extensions` from the drop-down at the top, click
   :guilabel:`Update now` to refresh the extension list, then search for
   ``ns_t3al`` and click :guilabel:`Import and Install`. Install
   EXT:ns_license (``ns_license``) the same way first.

   If you have the extension as a ZIP file instead, click
   :guilabel:`Upload Extension` and upload it.
3. Activate the extension.
4. Run :guilabel:`Admin Tools > Maintenance > Analyze Database Structure`
   and create the table ``tx_nst3al_domain_model_logs``.
5. Clear all caches.

You can also download the extension from the
`TYPO3 Extension Repository (TER) <https://extensions.typo3.org/extension/ns_t3al>`__.

Next: :ref:`quick-configuration`.
