=====================================================
Deprecation: #65283 - Deprecate show item entry point
=====================================================

Description
===========

The following entry point has been marked as deprecated:

* typo3/show_item.php


Impact
======

Using this entry points in a backend module will throw a deprecation message.


Migration
=========

Use ``\TYPO3\CMS\Backend\Utility\BackendUtility::getModuleUrl()`` instead with the according module name.

typo3/show_item.php
``\TYPO3\CMS\Backend\Utility\BackendUtility::getModuleUrl('show_item')``