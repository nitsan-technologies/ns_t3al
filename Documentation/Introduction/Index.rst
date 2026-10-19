.. include:: ../Includes.txt


.. _introduction:

============
Introduction
============

.. _what-it-does:

What does it do?
================

**AI Localization** (``EXT:ns_t3al``) adds the backend module
:guilabel:`Admin Tools > AI Localization`. In this module you work with the
XLIFF language files of any installed extension:

*  Edit the English source and the translations of several languages side by
   side in one grid.
*  Fill empty translations with an AI or machine translation provider:
   **DeepL**, **OpenAI**, **Google Gemini** or **Google Translate**.
*  Review the suggestions and save them to the language files of the
   extension.
*  Upload the translations of TYPO3 system extensions to **Crowdin**.
*  Search a label text in the language files of all extensions.
*  Create new language files, import and export CSV files, and download all
   language files of an extension as a ZIP archive.
*  Follow every saved change in the translation logs, with a diff per label.

.. figure:: ../Images/translate-grid.png
   :alt: Translate tab with ns_license, locallang_licensemodule.xlf and German selected
   :class: with-border with-shadow
   :zoom: lightbox

   The Translate tab: choose an extension, a language file and the target
   languages.

.. _features:

Features
========

Spreadsheet-style label grid
   One row per label key, one column per language. Empty translations and
   labels that are identical to the English source are highlighted.

Translate only what is missing
   :guilabel:`Translate` only fills **empty** translations. Translations that
   already exist are never overwritten.

Review before saving
   AI results are shown in the grid first. Nothing is written to disk until
   you click :guilabel:`Save`.

Careful file writing
   Only changed files are written, existing XLIFF formatting, notes and
   attributes are kept, and the TYPO3 label cache is flushed after a save so
   the new labels are used immediately.

Access control
   Administrators can edit every extension. Editors only see the extensions
   that are granted to their backend user group, see
   :ref:`access-rights`.

Concurrent-edit warning
   When another backend user opened the same language file in the last
   10 minutes, the module shows a warning.

Interactive guide
   The :guilabel:`Guide` button opens an interactive walkthrough of the
   module.

.. _supported-providers:

Supported translation providers
===============================

==================  ===================================================
Provider            Models / endpoint
==================  ===================================================
DeepL               DeepL API Free or Pro (configurable URL)
OpenAI              gpt-5.6-terra, gpt-5.6-luna, gpt-5.6-sol, gpt-4o
Google Gemini       gemini-2.5-flash, gemini-2.5-flash-lite,
                    gemini-3.6-flash
Google Translate    Cloud Translation API v2 (configurable URL)
==================  ===================================================

You only need an API key for the providers you want to use. See
:ref:`configuration`.

.. _supported-versions:

Supported TYPO3 versions
========================

*  TYPO3 12.4 LTS
*  TYPO3 13.4 LTS
*  TYPO3 14.3 LTS

The extension works in Composer mode and in classic mode.

.. _system-requirements:

System requirements
===================

*  TYPO3 12.4 LTS, 13.4 LTS or 14.3 LTS
*  PHP 8.2 to 8.5
*  EXT:ns_license (``nitsan/ns-license``) ``^14.5.4`` (installed
   automatically, no license activation needed)
*  An API key for at least one translation provider

.. _need-support:

Need support?
=============

See :ref:`support` or :ref:`troubleshooting`.
