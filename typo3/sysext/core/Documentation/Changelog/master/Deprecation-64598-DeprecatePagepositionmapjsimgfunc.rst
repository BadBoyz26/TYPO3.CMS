==========================================================
Deprecation - #64598: Deprecate PagePositionMap::JSimgFunc
==========================================================

Description
===========

The following function has been marked as deprecated:

* \TYPO3\CMS\Backend\Tree\View\PagePositionMap::JSimgFunc

This function was used only in class PagePositionMap. The implemented "onmouseover" / "onmouseout"
behaviour to switch between two images was dropped entirely for now. If this is needed, it should
done a different way.


Impact
======

Using this function in a backend module will throw a deprecation message.


Affected Installations
======================

Every Extension that uses the deprecated function.


Migration
=========

Write own JavaScript function for your extension to handle onmouseover and onmouseout events to
switch between two images.