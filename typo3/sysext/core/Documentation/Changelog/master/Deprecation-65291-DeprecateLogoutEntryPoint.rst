==================================================
Deprecation: #65283 - Deprecate logout entry point
==================================================

Description
===========

The following entry point has been marked as deprecated:

* typo3/logout.php


Impact
======

Using this entry points in a backend module will throw a deprecation message.


Migration
=========

Use ``\TYPO3\CMS\Backend\Utility\BackendUtility::getModuleUrl()`` instead with the according module name.

typo3/logout.php
``\TYPO3\CMS\Backend\Utility\BackendUtility::getModuleUrl('logout')``