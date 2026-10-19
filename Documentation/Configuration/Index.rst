.. include:: ../Includes.txt


.. _configuration:

=============
Configuration
=============

All settings are made in :guilabel:`Admin Tools > Settings > Extension
Configuration > ns_t3al`. They are stored in ``config/system/settings.php``.

.. important::

   API keys and access tokens are secrets. Do not commit them to a public
   Git repository.

.. toctree::
   :hidden:

   AccessRights/Index

.. _configuration-openai:

OpenAI
======

.. confval:: openaiApiKey
   :name: confval-openaiapikey
   :type: string
   :Default: (empty)

   Your OpenAI API key.

.. confval:: openaiApiModel
   :name: confval-openaiapimodel
   :type: options
   :Default: gpt-5.6-terra

   Default OpenAI model: ``gpt-5.6-terra``, ``gpt-5.6-luna``,
   ``gpt-5.6-sol`` or ``gpt-4o``. The model can also be switched in the
   module toolbar.

.. _configuration-deepl:

DeepL
=====

.. confval:: deeplApiKey
   :name: confval-deeplapikey
   :type: string
   :Default: (empty)

   Your DeepL API authentication key.

.. confval:: deeplApiUrl
   :name: confval-deeplapiurl
   :type: string
   :Default: https://api-free.deepl.com/v2/translate

   DeepL translate endpoint. Use ``https://api-free.deepl.com/v2/translate``
   for DeepL API Free and ``https://api.deepl.com/v2/translate`` for DeepL
   API Pro.

.. _configuration-google:

Google Translate
================

.. confval:: googleApiKey
   :name: confval-googleapikey
   :type: string
   :Default: (empty)

   API key of a Google Cloud project with the Cloud Translation API enabled.

.. confval:: googleApiUrl
   :name: confval-googleapiurl
   :type: string
   :Default: https://translation.googleapis.com/language/translate/v2

   Google Cloud Translation API (v2) endpoint.

.. _configuration-gemini:

Google Gemini
=============

.. confval:: geminiApiKey
   :name: confval-geminiapikey
   :type: string
   :Default: (empty)

   Your Gemini API key from Google AI Studio.

.. confval:: geminiApiModel
   :name: confval-geminiapimodel
   :type: options
   :Default: gemini-2.5-flash

   Default Gemini model: ``gemini-2.5-flash``, ``gemini-2.5-flash-lite`` or
   ``gemini-3.6-flash``.

.. _configuration-crowdin:

Crowdin
=======

.. confval:: accessToken
   :name: confval-accesstoken
   :type: string
   :Default: (empty)

   Crowdin personal access token. Required for
   :guilabel:`Save to Crowdin`, see :ref:`crowdin`. The field is a password
   field, so the token is not shown on screen.

.. confval:: crowdinApiUrl
   :name: confval-crowdinapiurl
   :type: string
   :Default: https://api.crowdin.com/api/v2/

   Crowdin API v2 base URL, for Crowdin Enterprise e.g.
   ``https://<organization>.api.crowdin.com/api/v2/``. A missing trailing
   slash is added; an empty value uses the default.

.. confval:: crowdinSystemExtensionPath
   :name: confval-crowdinsystemextensionpath
   :type: string
   :Default: /main/typo3/sysext

   Crowdin directory that contains the TYPO3 system extensions. The file of
   :file:`EXT:<extension>/Resources/Private/Language/<file>` is looked up at
   :file:`<path>/<extension>/Resources/Private/Language/<file>`. Change it
   only if your Crowdin project uses a different layout.

.. _configuration-general:

General settings
================

.. confval:: enableLogs
   :name: confval-enablelogs
   :type: boolean
   :Default: 1

   Record every saved change in the translation logs (:guilabel:`Logs` tab).

.. confval:: enableExport
   :name: confval-enableexport
   :type: boolean
   :Default: 1

   Show the :guilabel:`Export ZIP` action. Export ZIP is never offered for
   TYPO3 system extensions.

.. confval:: logRetentionDays
   :name: confval-logretentiondays
   :type: int+
   :Default: 365

   Logs older than this number of days are deleted whenever a new log entry
   is written. ``0`` keeps all logs.
