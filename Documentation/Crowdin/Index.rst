.. include:: ../Includes.txt


.. _crowdin:

=======
Crowdin
=======

The extension can upload translations to a `Crowdin <https://crowdin.com>`__
project after saving them locally.

.. important::

   :guilabel:`Save to Crowdin` is only available for **TYPO3 system
   extensions**. The files are matched with the file layout of the TYPO3 Core
   Crowdin project (``/main/typo3/sysext/<extension>/…``, see
   :confval:`crowdinSystemExtensionPath <confval-crowdinsystemextensionpath>`).
   Local extensions do not show the button.

Requirements
============

*  A Crowdin access token in :confval:`accessToken <confval-accesstoken>`.
*  The Crowdin API URL in :confval:`crowdinApiUrl <confval-crowdinapiurl>`
   (default ``https://api.crowdin.com/api/v2/``).
*  The numeric ID of your Crowdin project.

Upload translations
===================

1. Set :guilabel:`Extension type` to :guilabel:`System` and select a system
   extension, a language file and the target languages.
2. Translate or edit the labels.
3. Enter the :guilabel:`Crowdin project ID` in the toolbar.
4. Click :guilabel:`Save to Crowdin`.

The files are saved locally first. Then the translations of every selected
language (not English) are uploaded to Crowdin. A message is shown for every
upload.

The Crowdin file is matched by its exact path
(:file:`<crowdinSystemExtensionPath>/<extension>/Resources/Private/Language/<file>`).
The TYPO3 language key is mapped to the Crowdin language ID of the project,
for example ``de`` → ``de``, ``pt_BR`` → ``pt-BR``, ``zh_CN`` → ``zh-CN`` and
``es`` → ``es-ES``.

Messages
========

``Project ID is required``
   Enter the Crowdin project ID before clicking :guilabel:`Save to Crowdin`.

``Crowdin access token missing``
   Add the token in the extension configuration. Nothing was saved.

``Crowdin upload is only available for TYPO3 system extensions``
   Use :guilabel:`Save` to save local extensions.

``Crowdin could not be reached``
   The local file was saved; try the upload again later.

``Crowdin rejected the access token``
   The token is invalid, expired or has no access to the project. Check the
   token and its permissions. The local file was saved.

``Crowdin project … was not found``
   Check the project ID. The local file was saved.

``No matching file found at Crowdin``
   The message shows the path that was looked up. Check that the project
   uses the TYPO3 Core file layout or adjust
   :confval:`crowdinSystemExtensionPath <confval-crowdinsystemextensionpath>`.

``Language … is not a target language of this Crowdin project``
   Add the language to the Crowdin project or select another language.
