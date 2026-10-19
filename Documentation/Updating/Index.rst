.. include:: ../Includes.txt


.. _updating:

========
Updating
========

Please read this page before you update AI Localization to a newer version.

Versioning
==========

The version number uses the format ``major.minor.patch``, for example
``1.0.0``. Check the :ref:`changelog` for what changed in each version.

Before updating
===============

*  Back up your database and files.
*  Back up the language files you edited with the module, if they are not
   under version control.
*  Read the :ref:`changelog` of all versions since your current version.
*  If possible, test the update on a staging system first.

Update
======

..  code-block:: bash
    :caption: Update via Composer

    composer update nitsan/ns-t3al
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

In classic mode, upload the new version in :guilabel:`Admin Tools >
Extensions`, run :guilabel:`Analyze Database Structure` and clear all
caches.

After updating
==============

Check your settings in :guilabel:`Admin Tools > Settings > Extension
Configuration > ns_t3al`. Open :guilabel:`Admin Tools > AI Localization` and
translate a test file.
