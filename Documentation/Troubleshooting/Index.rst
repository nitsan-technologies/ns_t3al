.. include:: ../Includes.txt


.. _troubleshooting:

===============
Troubleshooting
===============

.. _faq:

Frequently asked questions
==========================

Do I need a license key?
   No. The extension is free. EXT:ns_license is only a required dependency
   and needs no activation.

Which provider should I use?
   Any provider with a valid API key works. DeepL and Google Translate are
   translation services; OpenAI and Gemini are language models and let you
   choose a model.

Does Translate overwrite my existing translations?
   No. Only empty translations are filled.

Are the AI results saved automatically?
   No. Review them in the grid and click :guilabel:`Save`.

Can I upload my own extensions to Crowdin?
   No. Crowdin upload only supports TYPO3 system extensions, see
   :ref:`crowdin`.

Why does an editor not see any extension?
   The extensions must be granted to the editor's backend user group, see
   :ref:`access-rights`.

Why can I not edit the English text of a system extension?
   The English source files of TYPO3 system extensions are read-only. Only
   translations can be saved.

.. _known-messages:

Messages and solutions
======================

``API key is missing`` / ``API key unavailable``
   Add the API key of the selected provider in the
   :ref:`extension configuration <configuration>`.

``The translation service rejected the request (HTTP 401)``
   The API key is invalid, the quota is used up, or the model is not
   available for your account. Check the key, quota and model.

``Nothing to translate``
   All labels in the selected languages already have a translation.

``No changes``
   The grid is identical to the files on disk; nothing was written.

``Too many labels, max_input_vars too small``
   Increase the PHP setting ``max_input_vars`` to at least the value shown
   in the message, or select fewer languages.

Another user is editing the file
   Another backend user opened the same file in the last 10 minutes. Agree
   who saves first to avoid overwriting each other's changes.

The module is missing from the backend menu
   Check that the extension is active and that your user or group has access
   to the module. Then clear all caches.
