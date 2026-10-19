.. include:: ../../Includes.txt


.. _access-rights:

=============
Access rights
=============

Administrators can use the module for every extension. Editors (non-admin
backend users) need two permissions in their backend user group.

1. **Module access** – in :guilabel:`System > Backend Users > Backend user
   groups`, edit the group and allow the module
   :guilabel:`AI Localization` on the :guilabel:`Access Rights` tab
   (:guilabel:`Modules`).
2. **Editable extensions** – on the same tab, under
   :guilabel:`Custom module options > AI Localization: editable
   extensions`, select the extensions the group may translate.

TYPO3 system extensions are not offered in this list; only administrators
can work with them.

What editors can do
===================

==================================  ==========  ===========
Action                              Admin       Editor
==================================  ==========  ===========
Edit translations                   yes         granted
                                                extensions
Translate with AI, Save             yes         granted
                                                extensions
Edit keys and the English source    yes         no
Add, delete and move rows           yes         no
Create file, Import CSV             yes         no
Search, Logs, Export CSV / ZIP      yes         granted
                                                extensions
TYPO3 system extensions             yes         no
==================================  ==========  ===========

When no extension has been granted yet, the module shows a notice asking the
editor to contact an administrator.
