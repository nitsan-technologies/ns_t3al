.. include:: ../Includes.txt


.. _quick-configuration:

===================
Quick Configuration
===================

Two steps until you can translate your first label file. The extension is
free: no license key or license activation is needed.

Step 1: Add an API key
======================

Open :guilabel:`Admin Tools > Settings > Extension Configuration > ns_t3al`
and add the API key of at least one provider:

*  DeepL: :confval:`deeplApiKey <confval-deeplapikey>`
*  OpenAI: :confval:`openaiApiKey <confval-openaiapikey>`
*  Google Gemini: :confval:`geminiApiKey <confval-geminiapikey>`
*  Google Translate: :confval:`googleApiKey <confval-googleapikey>`

For Crowdin uploads also add :confval:`accessToken <confval-accesstoken>`.
All settings are described in :ref:`configuration`.

Step 2: Translate
=================

1. Open :guilabel:`Admin Tools > AI Localization` (on TYPO3 v14:
   :guilabel:`Administration > AI Localization`).
2. Select an extension, a language file and one or more target languages.
3. Choose the AI provider and click :guilabel:`Translate`.
4. Check the result and click :guilabel:`Save`.

.. figure:: ../Images/translate-result.png
   :alt: AI Localization module after DeepL filled the German translations
   :class: with-border with-shadow
   :zoom: lightbox

   Translations filled by the AI provider, ready to save.

Editors without admin rights also need access rights, see
:ref:`access-rights`.
