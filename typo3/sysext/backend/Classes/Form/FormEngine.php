<?php
namespace TYPO3\CMS\Backend\Form;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Backend\Configuration\TranslationConfigurationProvider;
use TYPO3\CMS\Backend\Form\Element\AbstractFormElement;
use TYPO3\CMS\Backend\Form\Element\InlineElement;
use TYPO3\CMS\Backend\Form\Element\NoneElement;
use TYPO3\CMS\Backend\Form\Utility\FormEngineUtility;
use TYPO3\CMS\Backend\Template\DocumentTemplate;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\DatabaseConnection;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\Bitmask\JsConfirmation;
use TYPO3\CMS\Core\Utility\DiffUtility;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Lang\LanguageService;


/**
 * 'TCEforms' - Class for creating the backend editing forms.
 *
 * @author Kasper Skårhøj <kasperYYYY@typo3.com>
 * @coauthor René Fritz <r.fritz@colorcube.de>
 */
class FormEngine {

	/**
	 * @var bool
	 */
	public $disableWizards = FALSE;

	/**
	 * @var array|NULL
	 */
	public $cachedAdditionalPreviewLanguages = NULL;

	/**
	 * @var string
	 */
	public $extJSCODE = '';

	/**
	 * @var array
	 */
	public $hiddenFieldAccum = array();

	/**
	 * @var string
	 */
	public $TBE_EDITOR_fieldChanged_func = '';

	/**
	 * @var bool
	 */
	public $loadMD5_JS = TRUE;

	/**
	 * Array where records in the default language is stored. (processed by transferdata)
	 *
	 * @var array
	 */
	public $defaultLanguageData = array();

	/**
	 * Array where records in the default language is stored (raw without any processing. used for making diff)
	 *
	 * @var array
	 */
	public $defaultLanguageData_diff = array();

	/**
	 * @var array
	 */
	public $additionalPreviewLanguageData = array();

	/**
	 * Alternative return URL path (default is \TYPO3\CMS\Core\Utility\GeneralUtility::linkThisScript())
	 *
	 * @var string
	 */
	public $returnUrl = '';

	/**
	 * Can be set to point to a field name in the form which will be set to '1' when the form
	 * is submitted with a *save* button. This way the recipient script can determine that
	 * the form was submitted for save and not "close" for example.
	 *
	 * @var string
	 */
	public $doSaveFieldName = '';

	/**
	 * Can be set TRUE/FALSE to whether palettes (secondary options) are in the topframe or in form.
	 * TRUE means they are NOT IN-form. So a collapsed palette is one, which is shown in the top frame, not in the page.
	 *
	 * @var bool
	 */
	public $palettesCollapsed = FALSE;

	/**
	 * If this evaluates to TRUE, the forms are rendering only localization relevant fields of the records.
	 *
	 * @var string
	 */
	public $localizationMode = '';

	/**
	 * Overrule the field order set in TCA[types][showitem], eg for tt_content this value,
	 * 'bodytext,image', would make first the 'bodytext' field, then the 'image' field (if set for display)...
	 * and then the rest in the old order.
	 *
	 * @var string
	 */
	public $fieldOrder = '';

	/**
	 * When enabled all elements are rendered non-editable
	 *
	 * @var bool
	 */
	protected $renderReadonly = FALSE;

	// INTERNAL, static
	/**
	 * The string to prepend formfield names with.
	 *
	 * @var string
	 */
	public $prependFormFieldNames = 'data';

	/**
	 * The string to prepend commands for tcemain::process_cmdmap with
	 *
	 * @var string
	 */
	public $prependCmdFieldNames = 'cmd';

	/**
	 * The string to prepend FILE form field names with
	 *
	 * @var string
	 */
	public $prependFormFieldNames_file = 'data_files';

	/**
	 * The string to prepend form field names that are active (not NULL)
	 *
	 * @var string
	 */
	protected $prependFormFieldNamesActive = 'control[active]';

	/**
	 * Set by readPerms()  (caching)
	 *
	 * @var string
	 */
	public $perms_clause = '';

	/**
	 * Set by readPerms()  (caching-flag)
	 *
	 * @var bool
	 */
	public $perms_clause_set = FALSE;

	/**
	 * Counter that is incremented before an RTE is created. Can be used for unique ids etc.
	 *
	 * @var int
	 */
	public $RTEcounter = 0;

	/**
	 * Total wrapping for the table rows
	 *
	 * @var string
	 */
	public $totalWrap = '<hr />|<hr />';

	/**
	 * Field template
	 *
	 * @var string
	 */
	public $fieldTemplate = '<strong>###FIELD_NAME###</strong><br />###FIELD_ITEM###<hr />';

	/**
	 * Template subpart for palette fields
	 *
	 * @var string
	 */
	protected $paletteFieldTemplate = '';

	/**
	 * Wrapping template code for a section
	 *
	 * @var string
	 * @deprecatd since TYPO3 CMS 7, will be removed in CMS 8
	 */
	public $sectionWrap = '';

	/**
	 * Template for palette headers
	 *
	 * @var string
	 */
	public $palFieldTemplateHeader = '';

	/**
	 * Template for palettes
	 *
	 * @var string
	 */
	public $palFieldTemplate = '';

	/**
	 * Set to the fields NOT to display, if any
	 *
	 * @var array|NULL
	 */
	public $excludeElements = NULL;

	/**
	 * During rendering of forms this will keep track of which palettes
	 * has already been rendered (so they are not rendered twice by mistake)
	 *
	 * @var array
	 */
	public $palettesRendered = array();

	/**
	 * This array of fields will be set as hidden-fields instead of rendered normally!
	 * For instance palette fields edited in the top frame are set as hidden fields
	 * since the main form has to submit the values.
	 * The top frame actually just sets the value in the main form!
	 *
	 * @var array
	 */
	public $hiddenFieldListArr = array();

	/**
	 * Used to register input-field names, which are required. (Done during rendering of the fields).
	 * This information is then used later when the JavaScript is made.
	 *
	 * @var array
	 */
	public $requiredFields = array();

	/**
	 * Used to register input-field names, which are required an have additional requirements.
	 * (e.g. like a date/time must be positive integer)
	 * The information of this array is merged with $this->requiredFields later.
	 *
	 * @var array
	 */
	public $requiredAdditional = array();

	/**
	 * Used to register the min and max number of elements
	 * for selector boxes where that apply (in the "group" type for instance)
	 *
	 * @var array
	 */
	public $requiredElements = array();

	/**
	 * Used to determine where $requiredFields or $requiredElements are nested (in Tabs or IRRE)
	 *
	 * @var array
	 */
	public $requiredNested = array();

	/**
	 * Keeps track of the rendering depth of nested records
	 *
	 * @var int
	 */
	public $renderDepth = 0;

	/**
	 * Color scheme buffer
	 *
	 * @var array
	 * @deprecatd since TYPO3 CMS 7, will be removed in CMS 8
	 */
	public $savedSchemes = array();

	/**
	 * holds the path an element is nested in (e.g. required for RTEhtmlarea)
	 *
	 * @var array
	 */
	public $dynNestedStack = array();

	// Internal, registers for user defined functions etc.
	/**
	 * Additional HTML code, printed before the form
	 *
	 * @var array
	 */
	public $additionalCode_pre = array();

	/**
	 * Additional JavaScript, printed before the form
	 *
	 * @var array
	 * @deprecatd since TYPO3 CMS 7, will be removed in CMS 8
	 */
	public $additionalJS_pre = array();

	/**
	 * Additional JavaScript printed after the form
	 *
	 * @var array
	 */
	public $additionalJS_post = array();

	/**
	 * Additional JavaScript executed on submit; If you set "OK" variable it will raise an error
	 * about RTEs not being loaded and offer to block further submission.
	 *
	 * @var array
	 */
	public $additionalJS_submit = array();

	/**
	 * Additional JavaScript executed when section element is deleted.
	 * This is necessary, for example, to correctly clean up HTMLArea RTE (bug #8232)
	 *
	 * @var array
	 */
	public $additionalJS_delete = array();

	/**
	 * @var \TYPO3\CMS\Backend\Form\Element\InlineElement
	 */
	public $inline;

	/**
	 * Array containing hook class instances called once for a form
	 *
	 * @var array
	 */
	public $hookObjectsMainFields = array();

	/**
	 * Array containing hook class instances called for each field
	 *
	 * @var array
	 */
	public $hookObjectsSingleField = array();

	/**
	 * Rows getting inserted into the headers (when called from the EditDocumentController)
	 *
	 * @var array
	 */
	public $extraFormHeaders = array();

	/**
	 * Form template, relative to typo3 directory
	 *
	 * @var string
	 */
	public $templateFile = '';

	/**
	 * protected properties which were public
	 * use old property name as key and new property name as value
	 * e.g. 'foo_BarName' => 'fooBarName'
	 *
	 * For each property a getter and setter method must be implemented!
	 * @see __set() and __get()
	 * @var array
	 */
	protected $protectedProperties = array(
		'renderReadonly' => 'renderReadonly'
	);

	/**
	 * Constructor function, setting internal variables, loading the styles used.
	 *
	 */
	public function __construct() {
		// Create instance of InlineElement only if this a non-IRRE-AJAX call:
		if (!isset($GLOBALS['ajaxID']) || strpos($GLOBALS['ajaxID'], InlineElement::class . '::') !== 0) {
			$this->inline = GeneralUtility::makeInstance(InlineElement::class);
		}
		// Prepare user defined objects (if any) for hooks which extend this function:
		$this->hookObjectsMainFields = array();
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tceforms.php']['getMainFieldsClass'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tceforms.php']['getMainFieldsClass'] as $classRef) {
				$this->hookObjectsMainFields[] = GeneralUtility::getUserObj($classRef);
			}
		}
		$this->hookObjectsSingleField = array();
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tceforms.php']['getSingleFieldClass'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tceforms.php']['getSingleFieldClass'] as $classRef) {
				$this->hookObjectsSingleField[] = GeneralUtility::getUserObj($classRef);
			}
		}
		$this->templateFile = 'sysext/backend/Resources/Private/Templates/FormEngine.html';
	}

	/**
	 * Fallback method to protect public properties
	 * This is only a temporary solution and will be removed in TYPO3 CMS 8
	 *
	 * @param string $name name of the property
	 * @param mixed $value value of the property
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function __set($name, $value) {
		if (array_key_exists($name, $this->protectedProperties)) {
			$method = 'set' . ucfirst($this->protectedProperties[$name]);
			if (is_callable(array($this, $method))) {
				GeneralUtility::deprecationLog('direct access to "FormEngine::$' . $name . '" is deprecated, use "FormEngine::' . $method . '()" instead.');
				call_user_func_array(array($this, $method), array($value));
			}
		}
	}

	/**
	 * Fallback method to protect public properties
	 * This is only a temporary solution and will be removed in TYPO3 CMS 8
	 *
	 * @param string $name name of the property
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function __get($name) {
		if (array_key_exists($name, $this->protectedProperties)) {
			$method = 'get' . ucfirst($this->protectedProperties[$name]);
			if (is_callable(array($this, $method))) {
				GeneralUtility::deprecationLog('direct access to "FormEngine::$' . $name . '" is deprecated, use "FormEngine::' . $method . '()" instead.');
				call_user_func(array($this, $method));
			}
		}
	}

	/**
	 * Set render read only flag
	 *
	 * @param bool $value
	 */
	public function setRenderReadonly($value) {
		$this->renderReadonly = (bool)$value;
	}

	/**
	 * Get render readonly flag
	 *
	 * @return bool
	 */
	public function getRenderReadonly() {
		return $this->renderReadonly;
	}

	/**
	 * Initialize various internal variables.
	 *
	 * @return void
	 */
	public function initDefaultBEmode() {
		$this->prependFormFieldNames = 'data';
		$this->setNewBEDesign();
		$this->inline->init($this);
	}

	/*******************************************************
	 *
	 * Rendering the forms, fields etc
	 *
	 *******************************************************/
	/**
	 * Will return the TCEform element for just a single field from a record.
	 * The field must be listed in the currently displayed fields (as found in [types][showitem]) for the record.
	 * This also means that the $table/$row supplied must be complete so the list of fields to show can be found correctly
	 *
	 * @param string $table The table name
	 * @param array $row The record from the table for which to render a field.
	 * @param string $theFieldToReturn The field name to return the TCEform element for.
	 * @return string HTML output
	 * @see getMainFields()
	 */
	public function getSoloField($table, $row, $theFieldToReturn) {
		if (!isset($GLOBALS['TCA'][$table])) {
			return '';
		}
		$typeNum = $this->getRTypeNum($table, $row);
		if (isset($GLOBALS['TCA'][$table]['types'][$typeNum])) {
			$itemList = $GLOBALS['TCA'][$table]['types'][$typeNum]['showitem'];
			if ($itemList) {
				$fields = GeneralUtility::trimExplode(',', $itemList, TRUE);
				$excludeElements = ($this->excludeElements = $this->getExcludeElements($table, $row, $typeNum));
				foreach ($fields as $fieldInfo) {
					$parts = explode(';', $fieldInfo);
					$theField = trim($parts[0]);
					if (!in_array($theField, $excludeElements) && (string)$theField === (string)$theFieldToReturn) {
						if ($GLOBALS['TCA'][$table]['columns'][$theField]) {
							$sField = $this->getSingleField($table, $theField, $row, $parts[1], 1, $parts[3], $parts[2]);
							return $sField['ITEM'];
						}
					}
				}
			}
		}
		return '';
	}

	/**
	 * Based on the $table and $row of content, this displays the complete TCEform for the record.
	 * The input-$row is required to be preprocessed if necessary by eg.
	 * the \TYPO3\CMS\Backend\Form\DataPreprocessor class. For instance the RTE content
	 * should be transformed through this class first.
	 *
	 * @param string $table The table name
	 * @param array $row The record from the table for which to render a field.
	 * @param int $depth Depth level
	 * @param array $overruleTypesArray Overrule types array. Can be used to override the showitem etc. configuration for the TCA types of the table. Can contain all settings which are possible in the TCA 'types' section. See e.g. $TCA['tt_content']['types'].
	 * @return string HTML output
	 * @see getSoloField()
	 */
	public function getMainFields($table, array $row, $depth = 0, array $overruleTypesArray = array()) {
		$languageService = $this->getLanguageService();
		$this->renderDepth = $depth;
		// Init vars:
		$out_array = array(array());
		$out_array_meta = array(
			array(
				'title' => $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.generalTab'),
			),
		);
		$out_pointer = 0;
		$out_sheet = 0;
		$this->palettesRendered = array();
		$this->palettesRendered[$this->renderDepth][$table] = array();
		// Hook: getMainFields_preProcess (requested by Thomas Hempel for use with the "dynaflex" extension)
		foreach ($this->hookObjectsMainFields as $hookObj) {
			if (method_exists($hookObj, 'getMainFields_preProcess')) {
				$hookObj->getMainFields_preProcess($table, $row, $this);
			}
		}
		$tabIdentString = '';
		$tabIdentStringMD5 = '';
		if ($GLOBALS['TCA'][$table]) {
			// Load the description content for the table.
			if ($this->doLoadTableDescr($table)) {
				$languageService->loadSingleTableDescription($table);
			}
			// Get the current "type" value for the record.
			$typeNum = $this->getRTypeNum($table, $row);
			// Find the list of fields to display:
			if ($GLOBALS['TCA'][$table]['types'][$typeNum]) {
				$itemList = $GLOBALS['TCA'][$table]['types'][$typeNum]['showitem'];
				if (is_array($overruleTypesArray) && isset($overruleTypesArray[$typeNum]['showitem'])) {
					$itemList = $overruleTypesArray[$typeNum]['showitem'];
				}
				// If such a list existed...
				if ($itemList) {
					// Explode the field list and possibly rearrange the order of the fields, if configured for
					$fields = GeneralUtility::trimExplode(',', $itemList, TRUE);
					if ($this->fieldOrder) {
						$fields = $this->rearrange($fields);
					}
					// Get excluded fields, added fiels and put it together:
					$excludeElements = ($this->excludeElements = $this->getExcludeElements($table, $row, $typeNum));
					$fields = $this->mergeFieldsWithAddedFields($fields, $this->getFieldsToAdd($table, $row, $typeNum), $table);
					// If TCEforms will render a tab menu in the next step, push the name to the tab stack:
					if (strstr($itemList, '--div--') !== FALSE) {
						$tabIdentString = 'TCEforms:' . $table . ':' . $row['uid'];
						$tabIdentStringMD5 = $this->getDocumentTemplate()->getDynTabMenuId($tabIdentString);
						// Remember that were currently working on the general tab:
						if (isset($fields[0]) && strpos($fields[0], '--div--') !== 0) {
							$this->pushToDynNestedStack('tab', $tabIdentStringMD5 . '-1');
						}
					}
					// Traverse the fields to render:
					$cc = 0;
					foreach ($fields as $fieldInfo) {
						// Exploding subparts of the field configuration:
						// this is documented as this:
						// fieldname;fieldlabel;paletteidorlinebreaktodisplay;extradata;colorscheme
						// fieldname can also be "--div--" or "--palette--"
						// the last option colorscheme was dropped with TYPO3 CMS 7

						list($theField, $fieldLabel, $additionalPalette, $extraFieldProcessingData)  = explode(';', $fieldInfo);

						// Render the field:
						if (!in_array($theField, $excludeElements)) {
							if ($GLOBALS['TCA'][$table]['columns'][$theField]) {
								$sFieldPal = '';
								if ($additionalPalette && !isset($this->palettesRendered[$this->renderDepth][$table][$additionalPalette])) {
									$sFieldPal = $this->getPaletteFields($table, $row, $additionalPalette);
									$this->palettesRendered[$this->renderDepth][$table][$additionalPalette] = 1;
								}
								$sField = $this->getSingleField($table, $theField, $row, $fieldLabel, 0, $extraFieldProcessingData, $additionalPalette);
								if ($sField) {
									$sField .= $sFieldPal;
								}
								$out_array[$out_sheet][$out_pointer] .= $sField;
							} elseif ($theField == '--div--') {
								if ($cc > 0) {
									// Remove last tab entry from the dynNestedStack:
									$out_sheet++;
									// Remove the previous sheet from stack (if any):
									$this->popFromDynNestedStack('tab', $tabIdentStringMD5 . '-' . $out_sheet);
									// Remember on which sheet we're currently working:
									$this->pushToDynNestedStack('tab', $tabIdentStringMD5 . '-' . ($out_sheet + 1));
									$out_array[$out_sheet] = array();
									$out_array_meta[$out_sheet]['title'] = $languageService->sL($fieldLabel);
									// Register newline for Tab
									$out_array_meta[$out_sheet]['newline'] = $additionalPalette == 'newline';
								} else {
									// Setting alternative title for "General" tab if "--div--" is the very first element.
									$out_array_meta[$out_sheet]['title'] = $languageService->sL($fieldLabel);
									// Only add the first tab to the dynNestedStack if there are more tabs:
									if ($tabIdentString && strpos($itemList, '--div--', strlen($fieldInfo))) {
										$this->pushToDynNestedStack('tab', $tabIdentStringMD5 . '-1');
									}
								}
							} elseif ($theField == '--palette--') {
								if ($additionalPalette && !isset($this->palettesRendered[$this->renderDepth][$table][$additionalPalette])) {
									// Render a 'header' if not collapsed
									if ($GLOBALS['TCA'][$table]['palettes'][$additionalPalette]['canNotCollapse'] && $fieldLabel) {
										$out_array[$out_sheet][$out_pointer] .= $this->getPaletteFields($table, $row, $additionalPalette, $languageService->sL($fieldLabel));
									} else {
										$out_array[$out_sheet][$out_pointer] .= $this->getPaletteFields($table, $row, $additionalPalette, '', '', $languageService->sL($fieldLabel));
									}
									$this->palettesRendered[$this->renderDepth][$table][$additionalPalette] = 1;
								}
							}
						}
						$cc++;
					}
				}
			}
		}
		// Hook: getMainFields_postProcess (requested by Thomas Hempel for use with the "dynaflex" extension)
		foreach ($this->hookObjectsMainFields as $hookObj) {
			if (method_exists($hookObj, 'getMainFields_postProcess')) {
				$hookObj->getMainFields_postProcess($table, $row, $this);
			}
		}
		// Rendering Main palettes, if any
		$mParr = GeneralUtility::trimExplode(',', $GLOBALS['TCA'][$table]['ctrl']['mainpalette']);
		$i = 0;
		if (count($mParr)) {
			foreach ($mParr as $mP) {
				if (!isset($this->palettesRendered[$this->renderDepth][$table][$mP])) {
					$temp_palettesCollapsed = $this->palettesCollapsed;
					$this->palettesCollapsed = FALSE;
					$label = $i == 0
						? $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.generalOptions')
						: $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.generalOptions_more');
					$out_array[$out_sheet][$out_pointer] .= $this->getPaletteFields($table, $row, $mP, $label);
					$this->palettesCollapsed = $temp_palettesCollapsed;
					$this->palettesRendered[$this->renderDepth][$table][$mP] = 1;
				}
				$i++;
				if ($this->renderDepth) {
					$this->renderDepth--;
				}
			}
		}
		// Return the imploded $out_array:
		// Create parts array for the tab menu:
		$parts = array();
		foreach ($out_array as $idx => $sheetContent) {
			$content = implode('', $sheetContent);
			$parts[$idx] = array(
				'label' => $out_array_meta[$idx]['title'],
				'content' => $content,
				'newline' => $out_array_meta[$idx]['newline']
			);
		}
		if (count($parts) > 1) {
			// Unset the current level of tab menus:
			$this->popFromDynNestedStack('tab', $tabIdentStringMD5 . '-' . ($out_sheet + 1));
			$output = $this->getDynTabMenu($parts, $tabIdentString);
		} else {
			// If there is only one tab/part there is no need to wrap it into the dynTab code
			$output = isset($parts[0]) ? trim($parts[0]['content']) : '';
		}
		// Only one tab, so just implode and wrap the background image (= tab container) around:
		if ($out_sheet === 0) {
			$output = '<div class="tab-content">' . $output . '</div>';
		}

		return $output;
	}

	/**
	 * Will return the TCEform elements for a pre-defined list of fields.
	 * Notice that this will STILL use the configuration found in the list [types][showitem] for those fields which are found there. So ideally the list of fields given as argument to this function should also be in the current [types][showitem] list of the record.
	 * Used for displaying forms for the frontend edit icons for instance.
	 *
	 * @param string $table The table name
	 * @param array $row The record array.
	 * @param string $list Commalist of fields from the table. These will be shown in the specified order in a form.
	 * @return string TCEform elements in a string.
	 */
	public function getListedFields($table, $row, $list) {
		if ($this->doLoadTableDescr($table)) {
			$this->getLanguageService()->loadSingleTableDescription($table);
		}
		$out = '';
		$types_fieldConfig = BackendUtility::getTCAtypes($table, $row, 1);
		$editFieldList = array_unique(GeneralUtility::trimExplode(',', $list, TRUE));
		foreach ($editFieldList as $theFieldC) {
			list($theField, $palFields) = preg_split('/\\[|\\]/', $theFieldC);
			$theField = trim($theField);
			$palFields = trim($palFields);
			if ($GLOBALS['TCA'][$table]['columns'][$theField]) {
				$parts = GeneralUtility::trimExplode(';', $types_fieldConfig[$theField]['origString']);
				// Don't sent palette pointer - there are no options anyways for a field-list.
				$sField = $this->getSingleField($table, $theField, $row, $parts[1], 0, $parts[3], 0);
				$out .= $sField;
			}
			if ($palFields) {
				$out .= $this->getPaletteFields($table, $row, '', '', implode(',', GeneralUtility::trimExplode('|', $palFields, TRUE)));
			}
		}
		return $out;
	}

	/**
	 * Creates a palette (collection of secondary options).
	 *
	 * @param string $table The table name
	 * @param array $row The row array
	 * @param string $palette The palette number/pointer
	 * @param string $header Header string for the palette (used when in-form). If not set, no header item is made.
	 * @param string $itemList Optional alternative list of fields for the palette
	 * @param string $collapsedHeader Optional Link text for activating a palette (when palettes does not have another form element to belong to).
	 * @return string HTML code.
	 */
	public function getPaletteFields($table, $row, $palette, $header = '', $itemList = '', $collapsedHeader = NULL) {
		$out = '';
		$parts = $this->loadPaletteElements($table, $row, $palette, $itemList);
		// Put palette together if there are fields in it:
		if (count($parts)) {
			$realFields = 0;
			foreach ($parts as $part) {
				if ($part['NAME'] !== '--linebreak--') {
					$realFields++;
					break;
				}
			}
			if ($realFields > 0) {

				$code = $this->printPalette($parts);
				$collapsed = $this->isPalettesCollapsed($table, $palette);
				$isHiddenPalette = !empty($GLOBALS['TCA'][$table]['palettes'][$palette]['isHiddenPalette']);


				if ($collapsed && $collapsedHeader !== NULL && !$isHiddenPalette) {
					$code = $this->wrapCollapsiblePalette($code, 'FORMENGINE_' . $table . '_' . $palette . '_' . $row['uid'], $collapsed);
				} else {
					$code = '<div class="row">' . $code . '</div>';
				}

				$out = '
					<!-- getPaletteFields -->
					<fieldset class="'. ($isHiddenPalette ? 'hide' : 'form-section') . '">
						' . ($header ? '<h4 class="form-section-headline">' . htmlspecialchars($header) . '</h4>' : '') . '
						' . ($collapsedHeader ? '<h4 class="form-section-headline">' . htmlspecialchars($collapsedHeader) . '</h4>' : '') . '
						' . $code . '
					</fieldset>';
			}
		}
		return $out;
	}

	/**
	 * Returns the form HTML code for a database table field.
	 *
	 * @param string $table The table name
	 * @param string $field The field name
	 * @param array $row The record to edit from the database table.
	 * @param string $altName Alternative field name label to show.
	 * @param bool $palette Set this if the field is on a palette (in top frame), otherwise not. (if set, field will render as a hidden field).
	 * @param string $extra The "extra" options from "Part 4" of the field configurations found in the "types" "showitem" list. Typically parsed by $this->getSpecConfFromString() in order to get the options as an associative array.
	 * @param int $pal The palette pointer.
	 * @return mixed String (normal) or array (palettes)
	 */
	public function getSingleField($table, $field, $row, $altName = '', $palette = FALSE, $extra = '', $pal = 0) {
		$backendUser = $this->getBackendUserAuthentication();

		// Hook: getSingleField_preProcess
		foreach ($this->hookObjectsSingleField as $hookObj) {
			if (method_exists($hookObj, 'getSingleField_preProcess')) {
				$hookObj->getSingleField_preProcess($table, $field, $row, $altName, $palette, $extra, $pal, $this);
			}
		}
		$out = '';
		$PA = array();
		$PA['altName'] = $altName;
		$PA['palette'] = $palette;
		$PA['extra'] = $extra;
		$PA['pal'] = $pal;
		// Get the TCA configuration for the current field:
		$PA['fieldConf'] = $GLOBALS['TCA'][$table]['columns'][$field];
		$PA['fieldConf']['config']['form_type'] = $PA['fieldConf']['config']['form_type'] ?: $PA['fieldConf']['config']['type'];

		// Using "form_type" locally in this script
		$skipThisField = $this->inline->skipField($table, $field, $row, $PA['fieldConf']['config']);

		// Evaluate display condition
		$displayConditionResult = TRUE;
		if (is_array($PA['fieldConf']) && $PA['fieldConf']['displayCond'] && is_array($row)) {
			/** @var $elementConditionMatcher ElementConditionMatcher */
			$elementConditionMatcher = GeneralUtility::makeInstance(ElementConditionMatcher::class);
			$displayConditionResult = $elementConditionMatcher->match($PA['fieldConf']['displayCond'], $row);
		}
		// Check if this field is configured and editable (according to excludefields + other configuration)
		if (
			is_array($PA['fieldConf'])
			&& !$skipThisField
			&& (!$PA['fieldConf']['exclude'] || $backendUser->check('non_exclude_fields', $table . ':' . $field))
			&& $PA['fieldConf']['config']['form_type'] != 'passthrough'
			&& ($backendUser->isRTE() || !$PA['fieldConf']['config']['showIfRTE'])
			&& $displayConditionResult
			&& (!$GLOBALS['TCA'][$table]['ctrl']['languageField'] || $PA['fieldConf']['l10n_display'] || ($PA['fieldConf']['l10n_mode'] !== 'exclude') || $row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] <= 0)
			&& (!$GLOBALS['TCA'][$table]['ctrl']['languageField'] || !$this->localizationMode || $this->localizationMode === $PA['fieldConf']['l10n_cat'])
		) {
			// Fetching the TSconfig for the current table/field. This includes the $row which means that
			$PA['fieldTSConfig'] = FormEngineUtility::getTSconfigForTableRow($table, $row, $field);
			// If the field is NOT disabled from TSconfig (which it could have been) then render it
			if (!$PA['fieldTSConfig']['disabled']) {
				// Override fieldConf by fieldTSconfig:
				$PA['fieldConf']['config'] = FormEngineUtility::overrideFieldConf($PA['fieldConf']['config'], $PA['fieldTSConfig']);
				// Init variables:
				$PA['itemFormElName'] = $this->prependFormFieldNames . '[' . $table . '][' . $row['uid'] . '][' . $field . ']';
				// Form field name, in case of file uploads
				$PA['itemFormElName_file'] = $this->prependFormFieldNames_file . '[' . $table . '][' . $row['uid'] . '][' . $field . ']';
				// Form field name, to activate elements
				// If the "eval" list contains "null", elements can be deactivated which results in storing NULL to database
				$PA['itemFormElNameActive'] = $this->prependFormFieldNamesActive . '[' . $table . '][' . $row['uid'] . '][' . $field . ']';
				// The value to show in the form field.
				$PA['itemFormElValue'] = $row[$field];
				$PA['itemFormElID'] = $this->prependFormFieldNames . '_' . $table . '_' . $row['uid'] . '_' . $field;
				// Set field to read-only if configured for translated records to show default language content as readonly
				if ($PA['fieldConf']['l10n_display'] && GeneralUtility::inList($PA['fieldConf']['l10n_display'], 'defaultAsReadonly') && $row[$GLOBALS['TCA'][$table]['ctrl']['languageField']] > 0) {
					$PA['fieldConf']['config']['readOnly'] = TRUE;
					$PA['itemFormElValue'] = $this->defaultLanguageData[$table . ':' . $row['uid']][$field];
				}
				if (strpos($GLOBALS['TCA'][$table]['ctrl']['type'], ':') === FALSE) {
					$typeField = $GLOBALS['TCA'][$table]['ctrl']['type'];
				} else {
					$typeField = substr($GLOBALS['TCA'][$table]['ctrl']['type'], 0, strpos($GLOBALS['TCA'][$table]['ctrl']['type'], ':'));
				}
				// Create a JavaScript code line which will ask the user to save/update the form due to changing the element. This is used for eg. "type" fields and others configured with "requestUpdate"
				if (
					!empty($GLOBALS['TCA'][$table]['ctrl']['type'])
					&& $field === $typeField
					|| !empty($GLOBALS['TCA'][$table]['ctrl']['requestUpdate'])
					&& GeneralUtility::inList(str_replace(' ', '', $GLOBALS['TCA'][$table]['ctrl']['requestUpdate']), $field)
				) {
					if ($backendUser->jsConfirmation(JsConfirmation::TYPE_CHANGE)) {
						$alertMsgOnChange = 'if (confirm(TBE_EDITOR.labels.onChangeAlert) && TBE_EDITOR.checkSubmit(-1)){ TBE_EDITOR.submitForm() };';
					} else {
						$alertMsgOnChange = 'if (TBE_EDITOR.checkSubmit(-1)){ TBE_EDITOR.submitForm() };';
					}
				} else {
					$alertMsgOnChange = '';
				}
				// Render as a hidden field?
				if (in_array($field, $this->hiddenFieldListArr)) {
					$this->hiddenFieldAccum[] = '<input type="hidden" name="' . $PA['itemFormElName'] . '" value="' . htmlspecialchars($PA['itemFormElValue']) . '" />';
				} else {
					$languageService = $this->getLanguageService();
					// Render as a normal field:
					$PA['label'] = $PA['altName'] ?: $PA['fieldConf']['label'];
					$PA['label'] = $PA['fieldTSConfig']['label'] ?: $PA['label'];
					$PA['label'] = $PA['fieldTSConfig']['label.'][$languageService->lang] ?: $PA['label'];
					$PA['label'] = $languageService->sL($PA['label']);
					// JavaScript code for event handlers:
					$PA['fieldChangeFunc'] = array();
					$PA['fieldChangeFunc']['TBE_EDITOR_fieldChanged'] = 'TBE_EDITOR.fieldChanged(\'' . $table . '\',\'' . $row['uid'] . '\',\'' . $field . '\',\'' . $PA['itemFormElName'] . '\');';
					$PA['fieldChangeFunc']['alert'] = $alertMsgOnChange;
					// If this is the child of an inline type and it is the field creating the label
					if ($this->inline->isInlineChildAndLabelField($table, $field)) {
						$inlineObjectId = implode(InlineElement::Structure_Separator, array(
							$this->inline->inlineNames['object'],
							$table,
							$row['uid']
						));
						$PA['fieldChangeFunc']['inline'] = 'inline.handleChangedField(\'' . $PA['itemFormElName'] . '\',\'' . $inlineObjectId . '\');';
					}
					// Based on the type of the item, call a render function:
					$item = $this->getSingleField_SW($table, $field, $row, $PA);
					// Add language + diff
					if ($PA['fieldConf']['l10n_display'] && (GeneralUtility::inList($PA['fieldConf']['l10n_display'], 'hideDiff') || GeneralUtility::inList($PA['fieldConf']['l10n_display'], 'defaultAsReadonly'))) {
						$renderLanguageDiff = FALSE;
					} else {
						$renderLanguageDiff = TRUE;
					}
					if ($renderLanguageDiff) {
						$item = $this->renderDefaultLanguageContent($table, $field, $row, $item);
						$item = $this->renderDefaultLanguageDiff($table, $field, $row, $item);
					}
					// If the record has been saved and the "linkTitleToSelf" is set, we make the field name into a link, which will load ONLY this field in the EditDocumentController
					$label = htmlspecialchars($PA['label'], ENT_COMPAT, 'UTF-8', FALSE);
					if (MathUtility::canBeInterpretedAsInteger($row['uid']) && $PA['fieldTSConfig']['linkTitleToSelf'] && !GeneralUtility::_GP('columnsOnly')) {
						$lTTS_url = BackendUtility::getModuleUrl('record_edit', array(
							'edit[' . $table . '][' . $row['uid'] . ']' => 'edit',
							'columnsOnly' => $field,
							'returnUrl' => $this->thisReturnUrl()
						));
						$label = '<a href="' . htmlspecialchars($lTTS_url) . '">' . $label . '</a>';
					}

					if (isset($PA['fieldConf']['config']['mode']) && $PA['fieldConf']['config']['mode'] == 'useOrOverridePlaceholder') {
						$placeholder = $this->getPlaceholderValue($table, $field, $PA['fieldConf']['config'], $row);
						$onChange = 'typo3form.fieldTogglePlaceholder(' . GeneralUtility::quoteJSvalue($PA['itemFormElName']) . ', !this.checked)';
						$checked = $PA['itemFormElValue'] === NULL ? '' : ' checked="checked"';

						$this->additionalJS_post[] = 'typo3form.fieldTogglePlaceholder('
							. GeneralUtility::quoteJSvalue($PA['itemFormElName']) . ', ' . ($checked ? 'false' : 'true') . ');';

						$noneElement = GeneralUtility::makeInstance(NoneElement::class, $this);
						$noneElementConfiguration = $PA;
						$noneElementConfiguration['itemFormElValue'] = GeneralUtility::fixed_lgd_cs($placeholder, 30);
						$noneElementHtml = $noneElement->render('', '', '', $noneElementConfiguration);

						$item = '
							<input type="hidden" name="' . htmlspecialchars($PA['itemFormElNameActive']) . '" value="0" />
							<div class="checkbox">
								<label>
									<input type="checkbox" name="' . htmlspecialchars($PA['itemFormElNameActive']) . '" value="1" id="tce-forms-textfield-use-override-' . $field . '-' . $row['uid'] . '" onchange="' . htmlspecialchars($onChange) . '"' . $checked . ' />
									' . sprintf($languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.placeholder.override'), BackendUtility::getRecordTitlePrep($placeholder, 20)) . '
								</label>
							</div>
							<div class="t3js-formengine-placeholder-placeholder">
								' . $noneElementHtml . '
							</div>
							<div class="t3js-formengine-placeholder-formfield">' . $item . '</div>';
					}

					// Wrap the label with help text
					$PA['label'] = ($label = BackendUtility::wrapInHelp($table, $field, $label));
					// Create output value:
					if ($PA['fieldConf']['config']['form_type'] == 'user' && $PA['fieldConf']['config']['noTableWrapping']) {
						$out = $item;
					} elseif ($PA['palette']) {
						// Array:
						$out = array(
							'NAME' => $label,
							'ID' => $row['uid'],
							'FIELD' => $field,
							'TABLE' => $table,
							'ITEM' => $item,
							'ITEM_DISABLED' => ($this->isDisabledNullValueField($table, $field, $row, $PA) ? ' disabled' : ''),
							'ITEM_NULLVALUE' => $this->renderNullValueWidget($table, $field, $row, $PA),
						);
					} else {
						$out = '
							<fieldset class="form-section">
								<!-- getSingleField -->
								<div class="form-group t3js-formengine-palette-field">
									<label class="t3js-formengine-label">
										' . $label . '
										<img name="req_' . $table . '_' . $row['uid'] . '_' . $field . '" src="clear.gif" class="t3js-formengine-field-required" alt="" />
									</label>
									<div class="t3js-formengine-field-item ' . ($this->isDisabledNullValueField($table, $field, $row, $PA) ? ' disabled' : '') . '">
										<div class="t3-form-field-disable"></div>
										' . $this->renderNullValueWidget($table, $field, $row, $PA) . '
										' . $item . '
									</div>
								</div>
							</fieldset>
						';
					}
				}
			}
		}
		// Hook: getSingleField_postProcess
		foreach ($this->hookObjectsSingleField as $hookObj) {
			if (method_exists($hookObj, 'getSingleField_postProcess')) {
				$hookObj->getSingleField_postProcess($table, $field, $row, $out, $PA, $this);
			}
		}
		// Return value (string or array)
		return $out;
	}

	/**
	 * Rendering a single item for the form
	 *
	 * @param string $table Table name of record
	 * @param string $field Fieldname to render
	 * @param array $row The record
	 * @param array $PA Parameters array containing a lot of stuff. Value by Reference!
	 * @return string Returns the item as HTML code to insert
	 * @access private
	 * @see getSingleField(), getSingleField_typeFlex_draw()
	 */
	public function getSingleField_SW($table, $field, $row, &$PA) {
		$PA['fieldConf']['config']['form_type'] = $PA['fieldConf']['config']['form_type'] ?: $PA['fieldConf']['config']['type'];
		// Using "form_type" locally in this script
		// Hook: getSingleField_beforeRender
		foreach ($this->hookObjectsSingleField as $hookObject) {
			if (method_exists($hookObject, 'getSingleField_beforeRender')) {
				$hookObject->getSingleField_beforeRender($table, $field, $row, $PA);
			}
		}
		$type = $PA['fieldConf']['config']['form_type'];
		if ($type === 'inline') {
			$item = $this->inline->getSingleField_typeInline($table, $field, $row, $PA);
		} else {
			$typeClassNameMapping = array(
				'input' => 'InputElement',
				'text' => 'TextElement',
				'check' => 'CheckboxElement',
				'radio' => 'RadioElement',
				'select' => 'SelectElement',
				'group' => 'GroupElement',
				'none' => 'NoneElement',
				'user' => 'UserElement',
				'flex' => 'FlexElement',
				'unknown' => 'UnknownElement',
			);
			if (!isset($typeClassNameMapping[$type])) {
				$type = 'unknown';
			}
			$formElement = GeneralUtility::makeInstance('TYPO3\\CMS\\Backend\\Form\\Element\\' . $typeClassNameMapping[$type], $this);
			if ($formElement instanceof AbstractFormElement) {
				$formElement->setGlobalOptions($this->getConfigurationOptionsForChildElements());
			}
			$item = $formElement->render($table, $field, $row, $PA);
		}
		return $item;
	}

	/**
	 * Returns an array of global form settings to be given to child elements.
	 *
	 * @return array
	 */
	protected function getConfigurationOptionsForChildElements() {
		return array(
			'renderReadonly' => $this->getRenderReadonly(),
			'disabledWizards' => $this->disableWizards,
			'returnUrl' => $this->thisReturnUrl(),
			// Inline is handed over temporarily until FormEngine uses a real object tree
			'inline' => $this->inline,
		);
	}

	/**********************************************************
	 *
	 * Rendering of each TCEform field type
	 *
	 ************************************************************/

	/**
	 * Renders a view widget to handle and activate NULL values.
	 * The widget is enabled by using 'null' in the 'eval' TCA definition.
	 *
	 * @param string $table Name of the table
	 * @param string $field Name of the field
	 * @param array $row Accordant data of the record row
	 * @param array $PA Parameters array with rendering instructions
	 * @return string Widget (if any).
	 */
	protected function renderNullValueWidget($table, $field, array $row, array $PA) {
		$widget = '';

		$config = $PA['fieldConf']['config'];
		if (
			!empty($config['eval']) && GeneralUtility::inList($config['eval'], 'null')
			&& (empty($config['mode']) || $config['mode'] !== 'useOrOverridePlaceholder')
		) {
			$checked = $PA['itemFormElValue'] === NULL ? '' : ' checked="checked"';
			$onChange = htmlspecialchars(
				'typo3form.fieldSetNull(\'' . $PA['itemFormElName'] . '\', !this.checked)'
			);

			$widget = '
				<div class="checkbox">
					<label>
						<input type="hidden" name="' . $PA['itemFormElNameActive'] . '" value="0" />
						<input type="checkbox" name="' . $PA['itemFormElNameActive'] . '" value="1" onchange="' . $onChange . '"' . $checked . ' /> &nbsp;
					</label>
				</div>';
		}

		return $widget;
	}

	/**
	 * Determines whether the current field value is considered as NULL value.
	 * Using NULL values is enabled by using 'null' in the 'eval' TCA definition.
	 *
	 * @param string $table Name of the table
	 * @param string $field Name of the field
	 * @param array $row Accordant data
	 * @param array $PA Parameters array with rendering instructions
	 * @return bool
	 */
	protected function isDisabledNullValueField($table, $field, array $row, array $PA) {
		$result = FALSE;

		$config = $PA['fieldConf']['config'];
		if ($PA['itemFormElValue'] === NULL && !empty($config['eval'])
			&& GeneralUtility::inList($config['eval'], 'null')
			&& (empty($config['mode']) || $config['mode'] !== 'useOrOverridePlaceholder')) {

			$result = TRUE;
		}

		return $result;
	}


	/************************************************************
	 *
	 * "Configuration" fetching/processing functions
	 *
	 ************************************************************/
	/**
	 * Calculate and return the current "types" pointer value for a record
	 *
	 * @param string $table The table name. MUST be in $GLOBALS['TCA']
	 * @param array $row The row from the table, should contain at least the "type" field, if applicable.
	 * @return string Return the "type" value for this record, ready to pick a "types" configuration from the $GLOBALS['TCA'] array.
	 * @throws \RuntimeException
	 */
	public function getRTypeNum($table, $row) {
		$typeNum = 0;
		$field = $GLOBALS['TCA'][$table]['ctrl']['type'];
		if ($field) {
			if (strpos($field, ':') !== FALSE) {
				list($pointerField, $foreignTypeField) = explode(':', $field);
				$fieldConfig = $GLOBALS['TCA'][$table]['columns'][$pointerField]['config'];
				$relationType = $fieldConfig['type'];
				if ($relationType === 'select') {
					$foreignUid = $row[$pointerField];
					$foreignTable = $fieldConfig['foreign_table'];
				} elseif ($relationType === 'group') {
					$values = FormEngineUtility::extractValuesOnlyFromValueLabelList($row[$pointerField]);
					list(, $foreignUid) = GeneralUtility::revExplode('_', $values[0], 2);
					$allowedTables = explode(',', $fieldConfig['allowed']);
					// Always take the first configured table.
					$foreignTable = $allowedTables[0];
				} else {
					throw new \RuntimeException('TCA Foreign field pointer fields are only allowed to be used with group or select field types.', 1325861239);
				}
				if ($foreignUid) {
					$foreignRow = BackendUtility::getRecord($foreignTable, $foreignUid, $foreignTypeField);
					$this->registerDefaultLanguageData($foreignTable, $foreignRow);
					if ($foreignRow[$foreignTypeField]) {
						$foreignTypeFieldConfig = $GLOBALS['TCA'][$table]['columns'][$field];
						$typeNum = $this->getLanguageOverlayRawValue($foreignTable, $foreignRow, $foreignTypeField, $foreignTypeFieldConfig);
					}
				}
			} else {
				$typeFieldConfig = $GLOBALS['TCA'][$table]['columns'][$field];
				$typeNum = $this->getLanguageOverlayRawValue($table, $row, $field, $typeFieldConfig);
			}
		}
		if (empty($typeNum)) {
			// If that value is an empty string, set it to "0" (zero)
			$typeNum = 0;
		}
		// If current typeNum doesn't exist, set it to 0 (or to 1 for historical reasons, if 0 doesn't exist)
		if (!$GLOBALS['TCA'][$table]['types'][$typeNum]) {
			$typeNum = $GLOBALS['TCA'][$table]['types']['0'] ? 0 : 1;
		}
		// Force to string. Necessary for eg '-1' to be recognized as a type value.
		$typeNum = (string)$typeNum;
		return $typeNum;
	}

	/**
	 * Used to adhoc-rearrange the field order normally set in the [types][showitem] list
	 *
	 * @param array $fields A [types][showitem] list of fields, exploded by ",
	 * @return array Returns rearranged version (keys are changed around as well.)
	 * @see getMainFields()
	 */
	public function rearrange($fields) {
		$fO = array_flip(GeneralUtility::trimExplode(',', $this->fieldOrder, TRUE));
		$newFields = array();
		foreach ($fields as $cc => $content) {
			$cP = GeneralUtility::trimExplode(';', $content);
			if (isset($fO[$cP[0]])) {
				$newFields[$fO[$cP[0]]] = $content;
				unset($fields[$cc]);
			}
		}
		ksort($newFields);
		$fields = array_merge($newFields, $fields);
		return $fields;
	}

	/**
	 * Producing an array of field names NOT to display in the form,
	 * based on settings from subtype_value_field, bitmask_excludelist_bits etc.
	 * Notice, this list is in NO way related to the "excludeField" flag
	 *
	 * @param string $table Table name, MUST be in $GLOBALS['TCA']
	 * @param array $row A record from table.
	 * @param string $typeNum A "type" pointer value, probably the one calculated based on the record array.
	 * @return array Array with fieldnames as values. The fieldnames are those which should NOT be displayed "anyways
	 * @see getMainFields()
	 */
	public function getExcludeElements($table, $row, $typeNum) {
		// Init:
		$excludeElements = array();
		// If a subtype field is defined for the type
		if ($GLOBALS['TCA'][$table]['types'][$typeNum]['subtype_value_field']) {
			$sTfield = $GLOBALS['TCA'][$table]['types'][$typeNum]['subtype_value_field'];
			if (trim($GLOBALS['TCA'][$table]['types'][$typeNum]['subtypes_excludelist'][$row[$sTfield]])) {
				$excludeElements = GeneralUtility::trimExplode(',', $GLOBALS['TCA'][$table]['types'][$typeNum]['subtypes_excludelist'][$row[$sTfield]], TRUE);
			}
		}
		// If a bitmask-value field has been configured, then find possible fields to exclude based on that:
		if ($GLOBALS['TCA'][$table]['types'][$typeNum]['bitmask_value_field']) {
			$sTfield = $GLOBALS['TCA'][$table]['types'][$typeNum]['bitmask_value_field'];
			$sTValue = MathUtility::forceIntegerInRange($row[$sTfield], 0);
			if (is_array($GLOBALS['TCA'][$table]['types'][$typeNum]['bitmask_excludelist_bits'])) {
				foreach ($GLOBALS['TCA'][$table]['types'][$typeNum]['bitmask_excludelist_bits'] as $bitKey => $eList) {
					$bit = substr($bitKey, 1);
					if (MathUtility::canBeInterpretedAsInteger($bit)) {
						$bit = MathUtility::forceIntegerInRange($bit, 0, 30);
						if ($bitKey[0] === '-' && !($sTValue & pow(2, $bit)) || $bitKey[0] === '+' && $sTValue & pow(2, $bit)) {
							$excludeElements = array_merge($excludeElements, GeneralUtility::trimExplode(',', $eList, TRUE));
						}
					}
				}
			}
		}
		// Return the array of elements:
		return $excludeElements;
	}

	/**
	 * Finds possible field to add to the form, based on subtype fields.
	 *
	 * @param string $table Table name, MUST be in $GLOBALS['TCA']
	 * @param array $row A record from table.
	 * @param string $typeNum A "type" pointer value, probably the one calculated based on the record array.
	 * @return array An array containing two values: 1) Another array containing field names to add and 2) the subtype value field.
	 * @see getMainFields()
	 */
	public function getFieldsToAdd($table, $row, $typeNum) {
		// Init:
		$addElements = array();
		// If a subtype field is defined for the type
		$sTfield = '';
		if ($GLOBALS['TCA'][$table]['types'][$typeNum]['subtype_value_field']) {
			$sTfield = $GLOBALS['TCA'][$table]['types'][$typeNum]['subtype_value_field'];
			if (trim($GLOBALS['TCA'][$table]['types'][$typeNum]['subtypes_addlist'][$row[$sTfield]])) {
				$addElements = GeneralUtility::trimExplode(',', $GLOBALS['TCA'][$table]['types'][$typeNum]['subtypes_addlist'][$row[$sTfield]], TRUE);
			}
		}
		// Return the return
		return array($addElements, $sTfield);
	}

	/**
	 * Merges the current [types][showitem] array with the array of fields to add for the current subtype field of the "type" value.
	 *
	 * @param array $fields A [types][showitem] list of fields, exploded by ",
	 * @param array $fieldsToAdd The output from getFieldsToAdd()
	 * @param string $table The table name, if we want to consider it's palettes when positioning the new elements
	 * @return array Return the modified $fields array.
	 * @see getMainFields(),getFieldsToAdd()
	 */
	public function mergeFieldsWithAddedFields($fields, $fieldsToAdd, $table = '') {
		if (count($fieldsToAdd[0])) {
			$c = 0;
			$found = FALSE;
			foreach ($fields as $fieldInfo) {
				list($fieldName, $label, $paletteName) = GeneralUtility::trimExplode(';', $fieldInfo);
				if ($fieldName === $fieldsToAdd[1]) {
					$found = TRUE;
				} elseif ($fieldName === '--palette--' && $paletteName && $table !== '') {
					// Look inside the palette
					if (is_array($GLOBALS['TCA'][$table]['palettes'][$paletteName])) {
						$itemList = $GLOBALS['TCA'][$table]['palettes'][$paletteName]['showitem'];
						if ($itemList) {
							$paletteFields = GeneralUtility::trimExplode(',', $itemList, TRUE);
							foreach ($paletteFields as $info) {
								$fieldParts = GeneralUtility::trimExplode(';', $info);
								$theField = $fieldParts[0];
								if ($theField === $fieldsToAdd[1]) {
									$found = TRUE;
									break 1;
								}
							}
						}
					}
				}
				if ($found) {
					array_splice($fields, $c + 1, 0, $fieldsToAdd[0]);
					break;
				}
				$c++;
			}
		}
		return $fields;
	}

	/**
	 * Loads the elements of a palette (collection of secondary options) in an array.
	 *
	 * @param string $table The table name
	 * @param array $row The row array
	 * @param string $palette The palette number/pointer
	 * @param string $itemList Optional alternative list of fields for the palette
	 * @return array The palette elements
	 */
	public function loadPaletteElements($table, $row, $palette, $itemList = '') {
		$parts = array();
		// Getting excludeElements, if any.
		if (!is_array($this->excludeElements)) {
			$this->excludeElements = $this->getExcludeElements($table, $row, $this->getRTypeNum($table, $row));
		}
		// Load the palette TCEform elements
		if ($GLOBALS['TCA'][$table] && (is_array($GLOBALS['TCA'][$table]['palettes'][$palette]) || $itemList)) {
			$itemList = $itemList ? $itemList : $GLOBALS['TCA'][$table]['palettes'][$palette]['showitem'];
			if ($itemList) {
				$fields = GeneralUtility::trimExplode(',', $itemList, TRUE);
				foreach ($fields as $info) {
					$fieldParts = GeneralUtility::trimExplode(';', $info);
					$theField = $fieldParts[0];
					if ($theField === '--linebreak--') {
						$parts[]['NAME'] = '--linebreak--';
					} elseif (!in_array($theField, $this->excludeElements) && $GLOBALS['TCA'][$table]['columns'][$theField]) {
						$elem = $this->getSingleField($table, $theField, $row, $fieldParts[1], 1, '', $fieldParts[2]);
						if (is_array($elem)) {
							$parts[] = $elem;
						}
					}
				}
			}
		}
		return $parts;
	}

	/************************************************************
	 *
	 * Display of localized content etc.
	 *
	 ************************************************************/
	/**
	 * Will register data from original language records if the current record is a translation of another.
	 * The original data is shown with the edited record in the form.
	 * The information also includes possibly diff-views of what changed in the original record.
	 * Function called from outside (see EditDocumentController + quick edit) before rendering a form for a record
	 *
	 * @param string $table Table name of the record being edited
	 * @param array $rec Record array of the record being edited
	 * @return void
	 */
	public function registerDefaultLanguageData($table, $rec) {
		// Add default language:
		if (
			$GLOBALS['TCA'][$table]['ctrl']['languageField'] && $rec[$GLOBALS['TCA'][$table]['ctrl']['languageField']] > 0
			&& $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']
			&& (int)$rec[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']] > 0
		) {
			$lookUpTable = $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerTable']
				? $GLOBALS['TCA'][$table]['ctrl']['transOrigPointerTable']
				: $table;
			// Get data formatted:
			$this->defaultLanguageData[$table . ':' . $rec['uid']] = BackendUtility::getRecordWSOL(
				$lookUpTable,
				(int)$rec[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']]
			);
			// Get data for diff:
			if ($GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField']) {
				$this->defaultLanguageData_diff[$table . ':' . $rec['uid']] = unserialize($rec[$GLOBALS['TCA'][$table]['ctrl']['transOrigDiffSourceField']]);
			}
			// If there are additional preview languages, load information for them also:
			$prLang = $this->getAdditionalPreviewLanguages();
			foreach ($prLang as $prL) {
				/** @var $t8Tools TranslationConfigurationProvider */
				$t8Tools = GeneralUtility::makeInstance(TranslationConfigurationProvider::class);
				$tInfo = $t8Tools->translationInfo($lookUpTable, (int)$rec[$GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField']], $prL['uid']);
				if (is_array($tInfo['translations']) && is_array($tInfo['translations'][$prL['uid']])) {
					$this->additionalPreviewLanguageData[$table . ':' . $rec['uid']][$prL['uid']] = BackendUtility::getRecordWSOL($table, (int)$tInfo['translations'][$prL['uid']]['uid']);
				}
			}
		}
	}

	/**
	 * Creates language-overlay for a field value
	 * This means the requested field value will be overridden with the data from the default language.
	 * Can be used to render read only fields for example.
	 *
	 * @param string $table Table name of the record being edited
	 * @param array $row Record array of the record being edited in current language
	 * @param string $field Field name represented by $item
	 * @param array $fieldConf Content of $PA['fieldConf']
	 * @return string Unprocessed field value merged with default language data if needed
	 */
	public function getLanguageOverlayRawValue($table, $row, $field, $fieldConf) {
		$value = $row[$field];
		if (is_array($this->defaultLanguageData[$table . ':' . $row['uid']])) {
			if (
				$fieldConf['l10n_mode'] == 'exclude'
				|| $fieldConf['l10n_mode'] == 'mergeIfNotBlank' && trim($this->defaultLanguageData[$table . ':' . $row['uid']][$field]) !== ''
			) {
				$value = $this->defaultLanguageData[$table . ':' . $row['uid']][$field];
			}
		}
		return $value;
	}

	/**
	 * Renders the display of default language record content around current field.
	 * Will render content if any is found in the internal array, $this->defaultLanguageData,
	 * depending on registerDefaultLanguageData() being called prior to this.
	 *
	 * @param string $table Table name of the record being edited
	 * @param string $field Field name represented by $item
	 * @param array $row Record array of the record being edited
	 * @param string $item HTML of the form field. This is what we add the content to.
	 * @return string Item string returned again, possibly with the original value added to.
	 * @see getSingleField(), registerDefaultLanguageData()
	 */
	public function renderDefaultLanguageContent($table, $field, $row, $item) {
		if (is_array($this->defaultLanguageData[$table . ':' . $row['uid']])) {
			$defaultLanguageValue = BackendUtility::getProcessedValue($table, $field, $this->defaultLanguageData[$table . ':' . $row['uid']][$field], 0, 1, FALSE, $this->defaultLanguageData[$table . ':' . $row['uid']]['uid']);
			$fieldConfig = $GLOBALS['TCA'][$table]['columns'][$field];
			// Don't show content if it's for IRRE child records:
			if ($fieldConfig['config']['type'] != 'inline') {
				if ($defaultLanguageValue !== '') {
					$item .= '<div class="t3-form-original-language">' . FormEngineUtility::getLanguageIcon($table, $row, 0)
						. $this->getMergeBehaviourIcon($fieldConfig['l10n_mode'])
						. $this->previewFieldValue($defaultLanguageValue, $fieldConfig, $field) . '</div>';
				}
				$previewLanguages = $this->getAdditionalPreviewLanguages();
				foreach ($previewLanguages as $previewLanguage) {
					$defaultLanguageValue = BackendUtility::getProcessedValue($table, $field, $this->additionalPreviewLanguageData[$table . ':' . $row['uid']][$previewLanguage['uid']][$field], 0, 1);
					if ($defaultLanguageValue !== '') {
						$item .= '<div class="t3-form-original-language">'
							. FormEngineUtility::getLanguageIcon($table, $row, ('v' . $previewLanguage['ISOcode']))
							. $this->getMergeBehaviourIcon($fieldConfig['l10n_mode'])
							. $this->previewFieldValue($defaultLanguageValue, $fieldConfig, $field) . '</div>';
					}
				}
			}
		}
		return $item;
	}

	/**
	 * Renders the diff-view of default language record content compared with what the record was originally translated from.
	 * Will render content if any is found in the internal array, $this->defaultLanguageData,
	 * depending on registerDefaultLanguageData() being called prior to this.
	 *
	 * @param string $table Table name of the record being edited
	 * @param string $field Field name represented by $item
	 * @param array $row Record array of the record being edited
	 * @param string  $item HTML of the form field. This is what we add the content to.
	 * @return string Item string returned again, possibly with the original value added to.
	 * @see getSingleField(), registerDefaultLanguageData()
	 */
	public function renderDefaultLanguageDiff($table, $field, $row, $item) {
		if (is_array($this->defaultLanguageData_diff[$table . ':' . $row['uid']])) {
			// Initialize:
			$dLVal = array(
				'old' => $this->defaultLanguageData_diff[$table . ':' . $row['uid']],
				'new' => $this->defaultLanguageData[$table . ':' . $row['uid']]
			);
			// There must be diff-data:
			if (isset($dLVal['old'][$field])) {
				if ((string)$dLVal['old'][$field] !== (string)$dLVal['new'][$field]) {
					// Create diff-result:
					$t3lib_diff_Obj = GeneralUtility::makeInstance(DiffUtility::class);
					$diffres = $t3lib_diff_Obj->makeDiffDisplay(
						BackendUtility::getProcessedValue($table, $field, $dLVal['old'][$field], 0, 1),
						BackendUtility::getProcessedValue($table, $field, $dLVal['new'][$field], 0, 1)
					);
					$item .= '<div class="t3-form-original-language-diff">
						<div class="t3-form-original-language-diffheader">' . htmlspecialchars($this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.changeInOrig')) . '</div>
						<div class="t3-form-original-language-diffcontent">' . $diffres . '</div>
					</div>';
				}
			}
		}
		return $item;
	}

	/************************************************************
	 *
	 * Form element helper functions
	 *
	 ************************************************************/
	/**
	 * Creates style attribute content for optgroup tags in a selector box, primarily setting it
	 * up to show the icon of an element as background image (works in mozilla).
	 *
	 * @param string $iconString Icon string for option item
	 * @return string Style attribute content, if any
	 */
	public function optgroupTagStyle($iconString) {
		if (!$iconString) {
			return '';
		}
		list($selIconFile, $selIconInfo) = FormEngineUtility::getIcon($iconString);
		if (empty($selIconFile)) {
			// Skip background style if image is unavailable
			return '';
		}
		$padLeft = $selIconInfo[0] + 4;
		if ($padLeft >= 18 && $padLeft <= 24) {
			// In order to get the same padding for all option tags even if icon sizes differ a little,
			// set it to 22, if it was between 18 and 24 pixels.
			$padLeft = 22;
		}
		$padTop = MathUtility::forceIntegerInRange(($selIconInfo[1] - 12) / 2, 0);
		return 'background: #ffffff url(' . $selIconFile . ') 0 0 no-repeat; padding-top: ' . $padTop . 'px; padding-left: ' . $padLeft . 'px;';
	}

	/**
	 * Add the id and the style property to the field palette
	 *
	 * @param string $code Palette Code
	 * @param string $id Collapsible ID
	 * @param string $collapsed Collapsed status
	 * @return bool Is collapsed
	 */
	public function wrapCollapsiblePalette($code, $id, $collapsed) {
		$display = $collapsed ? '' : ' in';
		$id = str_replace('.', '', $id);
		$out = '
			<!-- wrapCollapsiblePalette -->
			<p>
				<button class="btn btn-default" type="button" data-toggle="collapse" data-target="#' . $id . '" aria-expanded="false" aria-controls="' . $id . '">
					' . IconUtility::getSpriteIcon('actions-system-options-view') . '
					' . htmlspecialchars($this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.moreOptions')) . '
				</button>
			</p>
			<div id="' . $id . '" class="form-section-collapse collapse' . $display . '">
				<div class="row">' . $code . '</div>
			</div>';
		return $out;
	}


	/**
	 * Returns the "returnUrl" of the form. Can be set externally or will be taken from "GeneralUtility::linkThisScript()"
	 *
	 * @return string Return URL of current script
	 */
	public function thisReturnUrl() {
		return $this->returnUrl ? $this->returnUrl : GeneralUtility::linkThisScript();
	}

	/**
	 * Returns the form field for a single HIDDEN field.
	 * (Not used anywhere...?)
	 *
	 * @param string $table Table name
	 * @param string $field Field name
	 * @param array $row The row
	 * @return string The hidden-field <input> tag.
	 */
	public function getSingleHiddenField($table, $field, $row) {
		$item = '';
		if ($GLOBALS['TCA'][$table]['columns'][$field]) {
			$uid = $row['uid'];
			$itemName = $this->prependFormFieldNames . '[' . $table . '][' . $uid . '][' . $field . ']';
			$itemValue = $row[$field];
			$item = '<input type="hidden" name="' . $itemName . '" value="' . htmlspecialchars($itemValue) . '" />';
		}
		return $item;
	}

	/**
	 * Create dynamic tab menu
	 *
	 * @param array $menuItems Items for the tab menu, fed to template::getDynTabMenu()
	 * @param string $identString ID string for the tab menu
	 * @param int $dividersToTabsBehaviour If set to '1' empty tabs will be removed, If set to '2' empty tabs will be disabled, deprecated, and not in use anymore since TYPO3 CMS 7
	 * @return string HTML for the menu
	 */
	public function getDynTabMenu($menuItems, $identString, $dividersToTabsBehaviour = -1) {
		// if the third (obsolete) parameter is used, throw a deprecation warning
		if ($dividersToTabsBehaviour !== -1) {
			GeneralUtility::deprecationLog('The parameter $dividersToTabsBehaviour in FormEngine::getDynTabMenu is deprecated. Please remove this option from your code');
		}
		$docTemplate = $this->getDocumentTemplate();
		if (is_object($docTemplate)) {
			$docTemplate->backPath = '';
			return $docTemplate->getDynamicTabMenu($menuItems, $identString, 1, FALSE, FALSE);
		} else {
			$output = '';
			foreach ($menuItems as $menuItem) {
				if (!empty($menuItem['content'])) {
					$output .= '
					<h3>' . htmlspecialchars($menuItem['label']) . '</h3>
					' . ($menuItem['description'] ? '<p>' . nl2br(htmlspecialchars($menuItem['description'])) . '</p>' : '') . '
					' . $menuItem['content'];
				}
			}
			return $output;
		}
	}

	/********************************************
	 *
	 * Template functions
	 *
	 ********************************************/
	/**
	 * Sets the design to the backend design.
	 * Backend
	 *
	 * @return void
	 */
	public function setNewBEDesign() {
		$template = GeneralUtility::getUrl(PATH_typo3 . $this->templateFile);
		// Wrapping all table rows for a particular record being edited:
		$this->totalWrap = HtmlParser::getSubpart($template, '###TOTALWRAP###');
		// Wrapping a single field:
		$this->fieldTemplate = HtmlParser::getSubpart($template, '###FIELDTEMPLATE###');
		$this->paletteFieldTemplate = HtmlParser::getSubpart($template, '###PALETTEFIELDTEMPLATE###');
		$this->palFieldTemplate = HtmlParser::getSubpart($template, '###PALETTE_FIELDTEMPLATE###');
		$this->palFieldTemplateHeader = HtmlParser::getSubpart($template, '###PALETTE_FIELDTEMPLATE_HEADER###');
	}

	/**
	 * This inserts the content of $inArr into the field-template
	 *
	 * @param array $inArr Array with key/value pairs to insert in the template.
	 * @param string $altTemplate Alternative template to use instead of the default.
	 * @return string
	 */
	public function intoTemplate($inArr, $altTemplate = '') {
		// Put into template_
		$fieldTemplateParts = explode('###FIELD_', $altTemplate ?: $this->fieldTemplate);
		$out = current($fieldTemplateParts);
		foreach ($fieldTemplateParts as $part) {
			list($key, $val) = explode('###', $part, 2);
			$out .= $inArr[$key];
			$out .= $val;
		}
		return $out;
	}

	/**
	 * Wraps all the table rows into a single table.
	 * Used externally from scripts like EditDocumentController and PageLayoutController (which uses FormEngine)
	 *
	 * @param string $c Code to output between table-parts; table rows
	 * @param array $rec The record
	 * @param string $table The table name
	 * @return string
	 */
	public function wrapTotal($c, $rec, $table) {
		$parts = $this->replaceTableWrap(explode('|', $this->totalWrap, 2), $rec, $table);
		return $parts[0] . $c . $parts[1] . implode('', $this->hiddenFieldAccum);
	}

	/**
	 * Generates a token and returns an input field with it
	 *
	 * @param string $formName Context of the token
	 * @param string $tokenName The name of the token GET/POST variable
	 * @return string A complete input field
	 */
	static public function getHiddenTokenField($formName = 'securityToken', $tokenName = 'formToken') {
		$formprotection = FormProtectionFactory::get();
		return '<input type="hidden" name="' . $tokenName . '" value="' . $formprotection->generateToken($formName) . '" />';
	}

	/**
	 * This replaces markers in the total wrap
	 *
	 * @param array $arr An array of template parts containing some markers.
	 * @param array $rec The record
	 * @param string $table The table name
	 * @return string
	 */
	public function replaceTableWrap($arr, $rec, $table) {
		$icon = IconUtility::getSpriteIconForRecord($table, $rec, array('title' => $this->getRecordPath($table, $rec)));
		// Make "new"-label
		$languageService = $this->getLanguageService();
		if (strstr($rec['uid'], 'NEW')) {
			$newLabel = ' <span class="typo3-TCEforms-newToken">' . $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.new', TRUE) . '</span>';
			// BackendUtility::fixVersioningPid Should not be used here because NEW records are not offline workspace versions...
			$truePid = BackendUtility::getTSconfig_pidValue($table, $rec['uid'], $rec['pid']);
			$prec = BackendUtility::getRecordWSOL('pages', $truePid, 'title');
			$pageTitle = BackendUtility::getRecordTitle('pages', $prec, TRUE, FALSE);
			$rLabel = '<em>[PID: ' . $truePid . '] ' . $pageTitle . '</em>';
			// Fetch translated title of the table
			$tableTitle = $languageService->sL($GLOBALS['TCA'][$table]['ctrl']['title']);
			if ($table === 'pages') {
				$label = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.createNewPage', TRUE);
				$pageTitle = sprintf($label, $tableTitle);
			} else {
				$label = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.createNewRecord', TRUE);
				if ($rec['pid'] == 0) {
					$label = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.createNewRecordRootLevel', TRUE);
				}
				$pageTitle = sprintf($label, $tableTitle, $pageTitle);
			}
		} else {
			$newLabel = ' <span class="typo3-TCEforms-recUid">[' . $rec['uid'] . ']</span>';
			$rLabel = BackendUtility::getRecordTitle($table, $rec, TRUE, FALSE);
			$prec = BackendUtility::getRecordWSOL('pages', $rec['pid'], 'uid,title');
			// Fetch translated title of the table
			$tableTitle = $languageService->sL($GLOBALS['TCA'][$table]['ctrl']['title']);
			if ($table === 'pages') {
				$label = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.editPage', TRUE);
				// Just take the record title and prepend an edit label.
				$pageTitle = sprintf($label, $tableTitle, $rLabel);
			} else {
				$label = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.editRecord', TRUE);
				$pageTitle = BackendUtility::getRecordTitle('pages', $prec, TRUE, FALSE);
				if ($rLabel === BackendUtility::getNoRecordTitle(TRUE)) {
					$label = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.editRecordNoTitle', TRUE);
				}
				if ($rec['pid'] == 0) {
					$label = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.editRecordRootLevel', TRUE);
				}
				if ($rLabel !== BackendUtility::getNoRecordTitle(TRUE)) {
					// Just take the record title and prepend an edit label.
					$pageTitle = sprintf($label, $tableTitle, $rLabel, $pageTitle);
				} else {
					// Leave out the record title since it is not set.
					$pageTitle = sprintf($label, $tableTitle, $pageTitle);
				}
			}
			$icon = $this->getControllerDocumentTemplate()->wrapClickMenuOnIcon($icon, $table, $rec['uid'], 1, '', '+copy,info,edit,view');
		}
		foreach ($arr as $k => $v) {
			// Make substitutions:
			$arr[$k] = str_replace(
				array(
					'###PAGE_TITLE###',
					'###ID_NEW_INDICATOR###',
					'###RECORD_LABEL###',
					'###TABLE_TITLE###',
					'###RECORD_ICON###'
				),
				array(
					$pageTitle,
					$newLabel,
					$rLabel,
					htmlspecialchars($languageService->sL($GLOBALS['TCA'][$table]['ctrl']['title'])),
					$icon
				),
				$arr[$k]
			);
		}
		return $arr;
	}

	/**
	 * Creates HTML output for a palette
	 *
	 * @param array $palArr The palette array to print
	 * @return string HTML output
	 */
	public function printPalette($palArr) {

		// GROUP FIELDS
		$groupedFields = array();
		$row = 0;
		$lastLineWasLinebreak = TRUE;
		foreach ($palArr as $field){
			if ($field['NAME'] === '--linebreak--') {
				if (!$lastLineWasLinebreak) {
					$row++;
					$groupedFields[$row][] = $field;
					$row++;
					$lastLineWasLinebreak = TRUE;
				}
			} else {
				$lastLineWasLinebreak = FALSE;
				$groupedFields[$row][] = $field;
			}
		}

		$out = '';
		// PROCESS FIELDS
		foreach ($groupedFields as $fields) {

			$numberOfItems = count($fields);
			$cols = $numberOfItems;
			$colWidth = (int)floor(12 / $cols);

			// COLS
			$colClass = "col-md-12";
			$colClear = array();
			if ($colWidth == 6) {
				$colClass = "col-sm-6";
				$colClear = array(
					2 => 'visible-sm-block visible-md-block visible-lg-block',
				);
			} elseif ($colWidth === 4) {
				$colClass = "col-sm-4";
				$colClear = array(
					3 => 'visible-sm-block visible-md-block visible-lg-block',
				);
			} elseif ($colWidth === 3) {
				$colClass = "col-sm-6 col-md-3";
				$colClear = array(
					2 => 'visible-sm-block',
					4 => 'visible-md-block visible-lg-block',
				);
			} elseif ($colWidth <= 2) {
				$colClass = "checkbox-column col-sm-6 col-md-3 col-lg-2";
				$colClear = array(
					2 => 'visible-sm-block',
					4 => 'visible-md-block',
					6 => 'visible-lg-block'
				);
			}

			// RENDER FIELDS
			for ($counter = 0; $counter < $numberOfItems; $counter++) {
				$content = $fields[$counter];
				if ($content['NAME'] === '--linebreak--') {
					if ($counter !== $numberOfItems) {
						$out .= '<div class="clearfix"></div>';
					}
				} else {

					// ITEM
					$out .= '
						<!-- printPalette -->
						<div class="form-group t3js-formengine-palette-field ' . $colClass . '">
							<label class="t3js-formengine-label">
								' . $content['NAME'] . '
								<img name="req_' . $content['TABLE'] . '_' . $content['ID'] . '_' . $content['FIELD'] . '" src="clear.gif" class="t3js-formengine-field-required" alt="" />
							</label>
							' . $content['ITEM_NULLVALUE'] . '
							<div class="t3js-formengine-field-item ' . $content['ITEM_DISABLED'] . '">
								<div class="t3-form-field-disable"></div>
								' . $content['ITEM'] . '
							</div>
						</div>';

					// BREAKPOINTS
					if ($counter + 1 < $numberOfItems && !empty($colClear)) {
						foreach ($colClear as $rowBreakAfter => $clearClass) {
							if (($counter + 1) % $rowBreakAfter === 0) {
								$out .= '<div class="clearfix '. $clearClass . '"></div>';
							}
						}
					}
				}
			}
		}
		return $out;
	}

	/********************************************
	 *
	 * JavaScript related functions
	 *
	 ********************************************/
	/**
	 * JavaScript code added BEFORE the form is drawn:
	 *
	 * @return string A <script></script> section with JavaScript.
	 */
	public function JStop() {
		$out = '';
		// Additional top HTML:
		if (count($this->additionalCode_pre)) {
			$out .= implode('

				<!-- NEXT: -->
			', $this->additionalCode_pre);
		}
		return $out;
	}

	/**
	 * JavaScript code used for input-field evaluation.
	 *
	 * Example use:
	 *
	 * $msg .= 'Distribution time (hh:mm dd-mm-yy):<br /><input type="text" name="send_mail_datetime_hr"'
	 *         . ' onchange="typo3form.fieldGet(\'send_mail_datetime\', \'datetime\', \'\', 0,0);"'
	 *         . $this->getTBE()->formWidth(20) . ' /><input type="hidden" value="' . $GLOBALS['EXEC_TIME']
	 *         . '" name="send_mail_datetime" /><br />';
	 * $this->extJSCODE .= 'typo3form.fieldSet("send_mail_datetime", "datetime", "", 0,0);';
	 *
	 * ... and then include the result of this function after the form
	 *
	 * @param string $formname The identification of the form on the page.
	 * @param bool $update Just extend/update existing settings, e.g. for AJAX call
	 * @return string A section with JavaScript - if $update is FALSE, embedded in <script></script>
	 */
	public function JSbottom($formname = 'forms[0]', $update = FALSE) {
		$languageService = $this->getLanguageService();
		$jsFile = array();
		$elements = array();
		$out = '';
		// Required:
		foreach ($this->requiredFields as $itemImgName => $itemName) {
			$match = array();
			if (preg_match('/^(.+)\\[((\\w|\\d|_)+)\\]$/', $itemName, $match)) {
				$record = $match[1];
				$field = $match[2];
				$elements[$record][$field]['required'] = 1;
				$elements[$record][$field]['requiredImg'] = $itemImgName;
				if (isset($this->requiredAdditional[$itemName]) && is_array($this->requiredAdditional[$itemName])) {
					$elements[$record][$field]['additional'] = $this->requiredAdditional[$itemName];
				}
			}
		}
		// Range:
		foreach ($this->requiredElements as $itemName => $range) {
			if (preg_match('/^(.+)\\[((\\w|\\d|_)+)\\]$/', $itemName, $match)) {
				$record = $match[1];
				$field = $match[2];
				$elements[$record][$field]['range'] = array($range[0], $range[1]);
				$elements[$record][$field]['rangeImg'] = $range['imgName'];
			}
		}
		$this->TBE_EDITOR_fieldChanged_func = 'TBE_EDITOR.fieldChanged_fName(fName,formObj[fName+"_list"]);';
		if (!$update) {
			if ($this->loadMD5_JS) {
				$this->loadJavascriptLib('sysext/backend/Resources/Public/JavaScript/md5.js');
			}
			$pageRenderer = $this->getPageRenderer();
			// load the main module for FormEngine with all important JS functions
			$pageRenderer->loadRequireJsModule('TYPO3/CMS/Backend/FormEngine', 'function(FormEngine) {
				FormEngine.setBrowserUrl(' . GeneralUtility::quoteJSvalue(BackendUtility::getModuleUrl('browser')) . ');
			}');
			$pageRenderer->loadPrototype();
			$pageRenderer->loadJquery();
			$pageRenderer->loadExtJS();
			$beUserAuth = $this->getBackendUserAuthentication();
			// Make textareas resizable and flexible ("autogrow" in height)
			$textareaSettings = array(
				'autosize'  => (bool)$beUserAuth->uc['resizeTextareas_Flexible']
			);
			$pageRenderer->addInlineSettingArray('Textarea', $textareaSettings);

			$this->loadJavascriptLib('sysext/backend/Resources/Public/JavaScript/jsfunc.evalfield.js');
			$this->loadJavascriptLib('sysext/backend/Resources/Public/JavaScript/jsfunc.tbe_editor.js');
			$this->loadJavascriptLib('sysext/backend/Resources/Public/JavaScript/ValueSlider.js');
			// Needed for FormEngine manipulation (date picker)
			$dateFormat = ($GLOBALS['TYPO3_CONF_VARS']['SYS']['USdateFormat'] ? array('MM-DD-YYYY', 'HH:mm MM-DD-YYYY') : array('DD-MM-YYYY', 'HH:mm DD-MM-YYYY'));
			$pageRenderer->addInlineSetting('DateTimePicker', 'DateFormat', $dateFormat);

			// support placeholders for IE9 and lower
			$clientInfo = GeneralUtility::clientInfo();
			if ($clientInfo['BROWSER'] == 'msie' && $clientInfo['VERSION'] <= 9) {
				$this->loadJavascriptLib('sysext/core/Resources/Public/JavaScript/Contrib/placeholders.jquery.min.js');
			}

			// @todo: remove scriptaclous once suggest is moved to RequireJS, see #55575
			$pageRenderer->loadScriptaculous();
			$this->loadJavascriptLib('sysext/backend/Resources/Public/JavaScript/tceforms.js');
			$this->loadJavascriptLib('sysext/backend/Resources/Public/JavaScript/jsfunc.tceforms_suggest.js');

			// If IRRE fields were processed, add the JavaScript functions:
			if ($this->inline->inlineCount) {
				// We want to load jQuery-ui inside our js. Enable this using requirejs.
				$pageRenderer->loadRequireJs();
				$this->loadJavascriptLib('sysext/backend/Resources/Public/JavaScript/jsfunc.inline.js');
				$out .= '
				inline.setPrependFormFieldNames("' . $this->inline->prependNaming . '");
				inline.setNoTitleString("' . addslashes(BackendUtility::getNoRecordTitle(TRUE)) . '");
				';
			}
			$out .= '
			TBE_EDITOR.images.req.src = "' . IconUtility::skinImg('', 'gfx/required_h.gif', '', 1) . '";
			TBE_EDITOR.images.clear.src = "clear.gif";

			TBE_EDITOR.formname = "' . $formname . '";
			TBE_EDITOR.formnameUENC = "' . rawurlencode($formname) . '";
			TBE_EDITOR.backPath = "";
			TBE_EDITOR.prependFormFieldNames = "' . $this->prependFormFieldNames . '";
			TBE_EDITOR.prependFormFieldNamesUENC = "' . rawurlencode($this->prependFormFieldNames) . '";
			TBE_EDITOR.prependFormFieldNamesCnt = ' . substr_count($this->prependFormFieldNames, '[') . ';
			TBE_EDITOR.isPalettedoc = null;
			TBE_EDITOR.doSaveFieldName = "' . ($this->doSaveFieldName ? addslashes($this->doSaveFieldName) : '') . '";
			TBE_EDITOR.labels.fieldsChanged = ' . GeneralUtility::quoteJSvalue($languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.fieldsChanged')) . ';
			TBE_EDITOR.labels.fieldsMissing = ' . GeneralUtility::quoteJSvalue($languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.fieldsMissing')) . ';
			TBE_EDITOR.labels.maxItemsAllowed = ' . GeneralUtility::quoteJSvalue($languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.maxItemsAllowed')) . ';
			TBE_EDITOR.labels.refresh_login = ' . GeneralUtility::quoteJSvalue($languageService->sL('LLL:EXT:lang/locallang_core.xlf:mess.refresh_login')) . ';
			TBE_EDITOR.labels.onChangeAlert = ' . GeneralUtility::quoteJSvalue($languageService->sL('LLL:EXT:lang/locallang_core.xlf:mess.onChangeAlert')) . ';
			TBE_EDITOR.labels.remainingCharacters = ' . GeneralUtility::quoteJSvalue($languageService->sL('LLL:EXT:lang/locallang_core.xlf:labels.remainingCharacters')) . ';
			evalFunc.USmode = ' . ($GLOBALS['TYPO3_CONF_VARS']['SYS']['USdateFormat'] ? '1' : '0') . ';

			TBE_EDITOR.customEvalFunctions = {};

			';
		}
		// Add JS required for inline fields
		if (count($this->inline->inlineData)) {
			$out .= '
			inline.addToDataArray(' . json_encode($this->inline->inlineData) . ');
			';
		}
		// Registered nested elements for tabs or inline levels:
		if (count($this->requiredNested)) {
			$out .= '
			TBE_EDITOR.addNested(' . json_encode($this->requiredNested) . ');
			';
		}
		// Elements which are required or have a range definition:
		if (count($elements)) {
			$out .= '
			TBE_EDITOR.addElements(' . json_encode($elements) . ');
			TBE_EDITOR.initRequired();
			';
		}
		// $this->additionalJS_submit:
		if ($this->additionalJS_submit) {
			$additionalJS_submit = implode('', $this->additionalJS_submit);
			$additionalJS_submit = str_replace(array(CR, LF), '', $additionalJS_submit);
			$out .= '
			TBE_EDITOR.addActionChecks("submit", "' . addslashes($additionalJS_submit) . '");
			';
		}
		$out .= LF . implode(LF, $this->additionalJS_post) . LF . $this->extJSCODE;
		// Regular direct output:
		if (!$update) {
			$spacer = LF . TAB;
			$out = $spacer . implode($spacer, $jsFile) . GeneralUtility::wrapJS($out);
		}
		return $out;
	}

	/**
	 * Prints necessary JavaScript for TCEforms (after the form HTML).
	 * currently this is used to transform page-specific options in the TYPO3.Settings array for JS
	 * so the JS module can access these values
	 *
	 * @return string
	 */
	public function printNeededJSFunctions() {
		/** @var $pageRenderer \TYPO3\CMS\Core\Page\PageRenderer */
		$pageRenderer = $this->getControllerDocumentTemplate()->getPageRenderer();

		// set variables to be accessible for JS
		$pageRenderer->addInlineSetting('FormEngine', 'formName', 'editform');
		$pageRenderer->addInlineSetting('FormEngine', 'backPath', '');

		// Integrate JS functions for the element browser if such fields or IRRE fields were processed
		$pageRenderer->addInlineSetting('FormEngine', 'legacyFieldChangedCb', 'function() { ' . $this->TBE_EDITOR_fieldChanged_func . ' };');

		return $this->JSbottom('editform');
	}

	/**
	 * Returns necessary JavaScript for the top
	 *
	 * @return string
	 */
	public function printNeededJSFunctions_top() {
		return $this->JStop('editform');
	}

	/**
	 * Includes a javascript library that exists in the core /typo3/ directory. The
	 * backpath is automatically applied.
	 * This method acts as wrapper for $GLOBALS['SOBE']->doc->loadJavascriptLib($lib).
	 *
	 * @param string $lib Library name. Call it with the full path like "contrib/prototype/prototype.js" to load it
	 * @return void
	 */
	public function loadJavascriptLib($lib) {
		$this->getControllerDocumentTemplate()->loadJavascriptLib($lib);
	}

	/**
	 * Wrapper for access to the current page renderer object
	 *
	 * @return \TYPO3\CMS\Core\Page\PageRenderer
	 */
	protected function getPageRenderer() {
		return $this->getControllerDocumentTemplate()->getPageRenderer();
	}

	/********************************************
	 *
	 * Various helper functions
	 *
	 ********************************************/

	/**
	 * Returns TRUE if the given $row is new (i.e. has not been saved to the database)
	 *
	 * @param string $table
	 * @param array $row
	 * @return bool
	 */
	protected function isNewRecord($table, $row) {
		return !MathUtility::canBeInterpretedAsInteger($row['uid']) && GeneralUtility::isFirstPartOfStr($row['uid'], 'NEW');
	}

	/**
	 * Return record path (visually formatted, using BackendUtility::getRecordPath() )
	 *
	 * @param string $table Table name
	 * @param array $rec Record array
	 * @return string The record path.
	 * @see BackendUtility::getRecordPath()
	 */
	public function getRecordPath($table, $rec) {
		BackendUtility::fixVersioningPid($table, $rec);
		list($tscPID, $thePidValue) = BackendUtility::getTSCpidCached($table, $rec['uid'], $rec['pid']);
		if ($thePidValue >= 0) {
			return BackendUtility::getRecordPath($tscPID, $this->readPerms(), 15);
		}
		return '';
	}

	/**
	 * Returns the select-page read-access SQL clause.
	 * Returns cached string, so you can call this function as much as you like without performance loss.
	 *
	 * @return string
	 */
	public function readPerms() {
		if (!$this->perms_clause_set) {
			$this->perms_clause = $this->getBackendUserAuthentication()->getPagePermsClause(1);
			$this->perms_clause_set = TRUE;
		}
		return $this->perms_clause;
	}

	/**
	 * Returns TRUE, if the palette, $palette, is collapsed (not shown, but found in top-frame) for the table.
	 *
	 * @param string $table The table name
	 * @param int $palette The palette pointer/number
	 * @return bool
	 */
	public function isPalettesCollapsed($table, $palette) {
		if (is_array($GLOBALS['TCA'][$table]['palettes'][$palette]) && $GLOBALS['TCA'][$table]['palettes'][$palette]['isHiddenPalette']) {
			return TRUE;
		}
		if ($GLOBALS['TCA'][$table]['ctrl']['canNotCollapse']) {
			return FALSE;
		}
		if (is_array($GLOBALS['TCA'][$table]['palettes'][$palette]) && $GLOBALS['TCA'][$table]['palettes'][$palette]['canNotCollapse']) {
			return FALSE;
		}
		return $this->palettesCollapsed;
	}

	/**
	 * Returns TRUE if descriptions should be loaded always
	 *
	 * @param string $table Table for which to check
	 * @return bool
	 */
	public function doLoadTableDescr($table) {
		return $GLOBALS['TCA'][$table]['interface']['always_description'];
	}

	/**
	 * Renders an icon to indicate the way the translation and the original is merged (if this is relevant).
	 *
	 * If a field is defined as 'mergeIfNotBlank' this is useful information for an editor. He/she can leave the field blank and
	 * the original value will be used. Without this hint editors are likely to copy the contents even if it is not necessary.
	 *
	 * @param string $l10nMode Localization mode from TCA
	 * @return string
	 */
	protected function getMergeBehaviourIcon($l10nMode) {
		$icon = '';
		if ($l10nMode === 'mergeIfNotBlank') {
			$icon = IconUtility::getSpriteIcon('actions-edit-merge-localization', array('title' => $this->getLanguageService()->sL('LLL:EXT:lang/locallang_misc.xlf:localizeMergeIfNotBlank')));
		}
		return $icon;
	}

	/**
	 * Rendering preview output of a field value which is not shown as a form field but just outputted.
	 *
	 * @param string $value The value to output
	 * @param array $config Configuration for field.
	 * @param string $field Name of field.
	 * @return string HTML formatted output
	 */
	public function previewFieldValue($value, $config, $field = '') {
		if ($config['config']['type'] === 'group' && ($config['config']['internal_type'] === 'file' || $config['config']['internal_type'] === 'file_reference')) {
			// Ignore uploadfolder if internal_type is file_reference
			if ($config['config']['internal_type'] === 'file_reference') {
				$config['config']['uploadfolder'] = '';
			}
			$show_thumbs = TRUE;
			$table = 'tt_content';
			// Making the array of file items:
			$itemArray = GeneralUtility::trimExplode(',', $value, TRUE);
			// Showing thumbnails:
			$thumbsnail = '';
			if ($show_thumbs) {
				$imgs = array();
				foreach ($itemArray as $imgRead) {
					$imgP = explode('|', $imgRead);
					$imgPath = rawurldecode($imgP[0]);
					$rowCopy = array();
					$rowCopy[$field] = $imgPath;
					// Icon + clickmenu:
					$absFilePath = GeneralUtility::getFileAbsFileName($config['config']['uploadfolder'] ? $config['config']['uploadfolder'] . '/' . $imgPath : $imgPath);
					$fileInformation = pathinfo($imgPath);
					$fileIcon = IconUtility::getSpriteIconForFile($imgPath, array('title' => htmlspecialchars($fileInformation['basename'] . ($absFilePath && @is_file($absFilePath) ? ' (' . GeneralUtility::formatSize(filesize($absFilePath)) . 'bytes)' : ' - FILE NOT FOUND!'))));
					$imgs[] =
						'<span class="text-nowrap">' .
							BackendUtility::thumbCode(
								$rowCopy,
								$table,
								$field,
								'',
								'',
								$config['config']['uploadfolder'], 0, ' align="middle"'
							) .
							($absFilePath ? $this->getControllerDocumentTemplate()->wrapClickMenuOnIcon($fileIcon, $absFilePath, 0, 1, '', '+copy,info,edit,view') : $fileIcon) . $imgPath .
						'</span>';
				}
				$thumbsnail = implode('<br />', $imgs);
			}
			return $thumbsnail;
		} else {
			return nl2br(htmlspecialchars($value));
		}
	}

	/**
	 * Generates and return information about which languages the current user should see in preview, configured by options.additionalPreviewLanguages
	 *
	 * @return array Array of additional languages to preview
	 */
	public function getAdditionalPreviewLanguages() {
		if (!isset($this->cachedAdditionalPreviewLanguages)) {
			$this->cachedAdditionalPreviewLanguages = array();
			if ($this->getBackendUserAuthentication()->getTSConfigVal('options.additionalPreviewLanguages')) {
				$uids = GeneralUtility::intExplode(',', $this->getBackendUserAuthentication()->getTSConfigVal('options.additionalPreviewLanguages'));
				foreach ($uids as $uid) {
					if ($sys_language_rec = BackendUtility::getRecord('sys_language', $uid)) {
						$this->cachedAdditionalPreviewLanguages[$uid] = array('uid' => $uid);
						if (!empty($sys_language_rec['language_isocode'])) {
							$this->cachedAdditionalPreviewLanguages[$uid]['ISOcode'] = $sys_language_rec['language_isocode'];
						} elseif ($sys_language_rec['static_lang_isocode'] && ExtensionManagementUtility::isLoaded('static_info_tables')) {
							GeneralUtility::deprecationLog('Usage of the field "static_lang_isocode" is discouraged, and will stop working with CMS 8. Use the built-in language field "language_isocode" in your sys_language records.');
							$staticLangRow = BackendUtility::getRecord('static_languages', $sys_language_rec['static_lang_isocode'], 'lg_iso_2');
							if ($staticLangRow['lg_iso_2']) {
								$this->cachedAdditionalPreviewLanguages[$uid]['uid'] = $uid;
								$this->cachedAdditionalPreviewLanguages[$uid]['ISOcode'] = $staticLangRow['lg_iso_2'];
							}
						}
					}
				}
			}
		}
		return $this->cachedAdditionalPreviewLanguages;
	}

	/**
	 * Push a new element to the dynNestedStack. Thus, every object know, if it's
	 * nested in a tab or IRRE level and in which order this was processed.
	 *
	 * @param string $type Type of the level, e.g. "tab" or "inline
	 * @param string $ident Identifier of the level
	 * @return void
	 */
	public function pushToDynNestedStack($type, $ident) {
		$this->dynNestedStack[] = array($type, $ident);
	}

	/**
	 * Remove an element from the dynNestedStack. If $type and $ident
	 * are set, the last element will only be removed, if it matches
	 * what is expected to be removed.
	 *
	 * @param string $type Type of the level, e.g. "tab" or "inline
	 * @param string $ident Identifier of the level
	 * @return void
	 */
	public function popFromDynNestedStack($type = NULL, $ident = NULL) {
		if ($type != NULL && $ident != NULL) {
			$last = end($this->dynNestedStack);
			if ($type == $last[0] && $ident == $last[1]) {
				array_pop($this->dynNestedStack);
			}
		} else {
			array_pop($this->dynNestedStack);
		}
	}

	/**
	 * Get the dynNestedStack as associative array.
	 * The result is e.g. ['tab','DTM-ABCD-1'], ['inline','data[13][table][uid][field]'], ['tab','DTM-DEFG-2'], ...
	 *
	 * @param bool $json Return a JSON string instead of an array - default: FALSE
	 * @param bool $skipFirst Skip the first element in the dynNestedStack - default: FALSE
	 * @return mixed Returns an associative array by default. If $json is TRUE, it will be returned as JSON string.
	 */
	public function getDynNestedStack($json = FALSE, $skipFirst = FALSE) {
		$result = $this->dynNestedStack;
		if ($skipFirst) {
			array_shift($result);
		}
		return $json ? json_encode($result) : $result;
	}

	/**
	 * Takes care of registering properties in requiredFields and requiredElements.
	 * The current hierarchy of IRRE and/or Tabs is stored. Thus, it is possible to determine,
	 * which required field/element was filled incorrectly and show it, even if the Tab or IRRE
	 * level is hidden.
	 *
	 * @param string $type Type of requirement ('field' or 'range')
	 * @param string $name The name of the form field
	 * @param mixed $value For type 'field' string, for type 'range' array
	 * @return void
	 */
	public function registerRequiredProperty($type, $name, $value) {
		if ($type == 'field' && is_string($value)) {
			$this->requiredFields[$name] = $value;
			// requiredFields have name/value swapped! For backward compatibility we keep this:
			$itemName = $value;
		} elseif ($type == 'range' && is_array($value)) {
			$this->requiredElements[$name] = $value;
			$itemName = $name;
		} else {
			$itemName = '';
		}
		// Set the situation of nesting for the current field:
		$this->registerNestedElement($itemName);
	}

	/**
	 * Sets the current situation of nested tabs and inline levels for a given element.
	 *
	 * @param string $itemName The element the nesting should be stored for
	 * @param bool $setLevel Set the reverse level lookup - default: TRUE
	 * @return void
	 */
	protected function registerNestedElement($itemName, $setLevel = TRUE) {
		$dynNestedStack = $this->getDynNestedStack();
		if (count($dynNestedStack) && preg_match('/^(.+\\])\\[(\\w+)\\]$/', $itemName, $match)) {
			array_shift($match);
			$this->requiredNested[$itemName] = array(
				'parts' => $match,
				'level' => $dynNestedStack
			);
		}
	}

	/**
	 * Return the placeholder attribute for an input field.
	 *
	 * @param string $table
	 * @param string $field
	 * @param array $config
	 * @param array $row
	 * @return string
	 */
	public function getPlaceholderAttribute($table, $field, array $config, array $row) {
		$value = $this->getPlaceholderValue($table, $field, $config, $row);

		// Cleanup the string and support 'LLL:'
		$value = htmlspecialchars(trim($this->getLanguageService()->sL($value)));
		return empty($value) ? '' : ' placeholder="' . $value . '" ';
	}

	/**
	 * Determine and get the value for the placeholder for an input field.
	 *
	 * @param string $table
	 * @param string $field
	 * @param array $config
	 * @param array $row
	 * @return mixed
	 */
	protected function getPlaceholderValue($table, $field, array $config, array $row) {
		$value = trim($config['placeholder']);
		if (!$value) {
			return '';
		}
		// Check if we have a reference to another field value from the current record
		if (substr($value, 0, 6) === '__row|') {
			/** @var FormDataTraverser $traverser */
			$traverseFields = GeneralUtility::trimExplode('|', substr($value, 6));
			$traverser = GeneralUtility::makeInstance(FormDataTraverser::class, $this);
			$value = $traverser->getTraversedFieldValue($traverseFields, $table, $row);
		}

		return $value;
	}

	/**
	 * Insert additional style sheet link
	 *
	 * @param string $key Some key identifying the style sheet
	 * @param string $href Uri to the style sheet file
	 * @param string $title Value for the title attribute of the link element
	 * @param string $relation Value for the rel attribute of the link element
	 * @return void
	 */
	public function addStyleSheet($key, $href, $title = '', $relation = 'stylesheet') {
		$this->getControllerDocumentTemplate()->addStyleSheet($key, $href, $title, $relation);
	}

	/**
	 * @return BackendUserAuthentication
	 */
	protected function getBackendUserAuthentication() {
		return $GLOBALS['BE_USER'];
	}

	/**
	 * @return DocumentTemplate
	 */
	protected function getControllerDocumentTemplate() {
		// $GLOBALS['SOBE'] might be any kind of PHP class (controller most of the times)
		// These class do not inherit from any common class, but they all seem to have a "doc" member
		return $GLOBALS['SOBE']->doc;
	}

	/**
	 * @return DatabaseConnection
	 */
	protected function getDatabaseConnection() {
		return $GLOBALS['TYPO3_DB'];
	}

	/**
	 * @return LanguageService
	 */
	protected function getLanguageService() {
		return $GLOBALS['LANG'];
	}

	/**
	 * @return DocumentTemplate
	 */
	protected function getDocumentTemplate() {
		return $GLOBALS['TBE_TEMPLATE'];
	}






	/**
	 * All properties and methods below are deprecated since TYPO3 CMS 7 and will be removed in TYPO3 CMS 8
	 */



	/**
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $printNeededJS = array();

	/**
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $palFieldArr = array();

	/**
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $isPalettedoc = FALSE;

	/**
	 * @var int
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $paletteMargin = 1;

	/**
	 * @var string
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $defStyle = '';

	/**
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $cachedTSconfig = array();

	/**
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $cachedLanguageFlag = array();

	/**
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $cachedTSconfig_fieldLevel = array();

	/**
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $transformedRow = array();

	/**
	 * Set this to the 'backPath' pointing back to the typo3 admin directory
	 * from the script where this form is displayed.
	 *
	 * @var string
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $backPath = '';

	/**
	 * If set, the RTE is disabled (from form display, eg. by checkbox in the bottom of the page!)
	 *
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $disableRTE = FALSE;

	/**
	 * If FALSE, then all CSH will be disabled
	 *
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $globalShowHelp = TRUE;

	/**
	 * If set to FALSE, palettes will NEVER be rendered.
	 *
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $doPrintPalette = TRUE;

	/**
	 * Enable click menu on reference icons.
	 *
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $enableClickMenu = FALSE;

	/**
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $enableTabMenu = FALSE;

	/**
	 * Form field width compensation: Factor of "size=12" to "style="width: 12*12px"
	 * for form field widths of style-aware browsers
	 *
	 * @var float
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $form_rowsToStylewidth = 12;

	/**
	 * Value that gets added for style="width: ...px" for textareas compared to input fields.
	 *
	 * @var int
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	protected $form_additionalTextareaStyleWidth = 23;

	/**
	 * Form field width compensation: Compensation for large documents, doc-tab (editing)
	 *
	 * @var float
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $form_largeComp = 1.33;

	/**
	 * The number of chars expected per row when the height of a text area field is
	 * automatically calculated based on the number of characters found in the field content.
	 *
	 * @var int
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $charsPerRow = 40;

	/**
	 * The maximum abstract value for textareas
	 *
	 * @var int
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $maxTextareaWidth = 48;

	/**
	 * The default abstract value for input and textarea elements
	 *
	 * @var int
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $defaultInputWidth = 30;

	/**
	 * The minimum abstract value for input and textarea elements
	 *
	 * @var int
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $minimumInputWidth = 10;

	/**
	 * The maximum abstract value for input and textarea elements
	 *
	 * @var int
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $maxInputWidth = 50;

	/**
	 * Default style for the selector boxes used for multiple items in "select" and "group" types.
	 *
	 * @var string
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $defaultMultipleSelectorStyle = '';

	/**
	 * The name attribute of the form
	 *
	 * @var string
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $formName = 'editform';

	/**
	 * Used to indicate the mode of CSH (Context Sensitive Help),
	 * whether it should be icons-only ('icon') or not at all (blank).
	 *
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $edit_showFieldHelp = FALSE;

	/**
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $edit_docModuleUpload = FALSE;

	/**
	 * Loaded with info about the browser when class is instantiated
	 *
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $clientInfo = array();

	/**
	 * TRUE, if RTE is possible for the current user (based on result from BE_USER->isRTE())
	 *
	 * @var bool
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $RTEenabled = FALSE;

	/**
	 * If $this->RTEenabled was FALSE, you can find the reasons listed in this array
	 * which is filled with reasons why the RTE could not be loaded)
	 *
	 * @var string
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $RTEenabled_notReasons = '';

	/**
	 * Contains current color scheme
	 *
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $colorScheme = array();

	/**
	 * Contains current class scheme
	 *
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $classScheme = array();

	/**
	 * Contains the default color scheme
	 *
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $defColorScheme = array();

	/**
	 * Contains the default class scheme
	 *
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $defClassScheme = array();

	/**
	 * Contains field style values
	 *
	 * @var array|NULL
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $fieldStyle = NULL;

	/**
	 * Contains border style values
	 *
	 * @var array|NULL
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $borderStyle = NULL;

	/**
	 * An accumulation of messages from the class
	 *
	 * @var array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public $commentMessages = array();

	/**
	 * Generation of TCEform elements of the type "input"
	 * This will render a single-line input form field, possibly with various control/validation features
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\InputElement
	 */
	public function getSingleField_typeInput($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\InputElement::class, $this)
			->render($table, $field, $row, $PA);
	}

	/**
	 * Generation of TCEform elements of the type "text"
	 * This will render a <textarea> OR RTE area form field, possibly with various control/validation features
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\TextElement
	 */
	public function getSingleField_typeText($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\TextElement::class, $this)
			->setGlobalOptions($this->getConfigurationOptionsForChildElements())
			->render($table, $field, $row, $PA);
	}

	/**
	 * Generation of TCEform elements of the type "check"
	 * This will render a check-box OR an array of checkboxes
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\CheckboxElement
	 */
	public function getSingleField_typeCheck($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\CheckboxElement::class, $this)
			->setGlobalOptions($this->getConfigurationOptionsForChildElements())
			->render($table, $field, $row, $PA);
	}

	/**
	 * Generation of TCEform elements of the type "radio"
	 * This will render a series of radio buttons.
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\RadioElement
	 */
	public function getSingleField_typeRadio($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\RadioElement::class, $this)
			->setGlobalOptions($this->getConfigurationOptionsForChildElements())
			->render($table, $field, $row, $PA);
	}

	/**
	 * Generation of TCEform elements of the type "select"
	 * This will render a selector box element, or possibly a special construction with two selector boxes.
	 * That depends on configuration.
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\SelectElement
	 */
	public function getSingleField_typeSelect($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\SelectElement::class, $this)
			->setGlobalOptions($this->getConfigurationOptionsForChildElements())
			->render($table, $field, $row, $PA);
	}

	/**
	 * Generation of TCEform elements of the type "group"
	 * This will render a selectorbox into which elements from either the file system or database can be inserted. Relations.
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since 7.0 - will be removed two versions later; Use \TYPO3\CMS\Backend\Form\Element\GroupElement
	 */
	public function getSingleField_typeGroup($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\GroupElement::class, $this)
			->setGlobalOptions($this->getConfigurationOptionsForChildElements())
			->render($table, $field, $row, $PA);
	}

	/**
	 * Generation of TCEform elements of the type "none"
	 * This will render a non-editable display of the content of the field.
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\NoneElement
	 */
	public function getSingleField_typeNone($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(NoneElement::class, $this)
			->render($table, $field, $row, $PA);
	}

	/**
	 * HTML rendering of a value which is not editable.
	 *
	 * @param array $config Configuration for the display
	 * @param string $itemValue The value to display
	 * @return string The HTML code for the display
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\NoneElement
	 */
	public function getSingleField_typeNone_render($config, $itemValue) {
		GeneralUtility::logDeprecatedFunction();
		$noneElement = GeneralUtility::makeInstance(NoneElement::class, $this);
		$elementConfiguration = array(
			'fieldConf' => array(
				'config' => $config,
			),
			'itemFormElValue' => $itemValue,
		);
		return $noneElement->render('', '', '', $elementConfiguration);
	}

	/**
	 * Handler for Flex Forms
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\FlexElement
	 */
	public function getSingleField_typeFlex($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\FlexElement::class, $this)
			->setGlobalOptions($this->getConfigurationOptionsForChildElements())
			->render($table, $field, $row, $PA);
	}

	/**
	 * Creates the language menu for FlexForms:
	 *
	 * @param array $languages
	 * @param string $elName
	 * @param array $selectedLanguage
	 * @param bool $multi
	 * @return string HTML for menu
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getSingleField_typeFlex_langMenu($languages, $elName, $selectedLanguage, $multi = TRUE) {
		GeneralUtility::logDeprecatedFunction();
		$opt = array();
		foreach ($languages as $lArr) {
			$opt[] = '<option value="' . htmlspecialchars($lArr['ISOcode']) . '"'
				. (in_array($lArr['ISOcode'], $selectedLanguage) ? ' selected="selected"' : '') . '>'
				. htmlspecialchars($lArr['title']) . '</option>';
		}
		$output = '<select id="' . str_replace('.', '', uniqid('tceforms-multiselect-', TRUE))
			. ' class="tceforms-select tceforms-multiselect tceforms-flexlangmenu" name="' . $elName . '[]"'
			. ($multi ? ' multiple="multiple" size="' . count($languages) . '"' : '') . '>' . implode('', $opt)
			. '</select>';
		return $output;
	}

	/**
	 * Creates the menu for selection of the sheets:
	 *
	 * @param array $sArr Sheet array for which to render the menu
	 * @param string $elName Form element name of the field containing the sheet pointer
	 * @param string $sheetKey Current sheet key
	 * @return string HTML for menu
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getSingleField_typeFlex_sheetMenu($sArr, $elName, $sheetKey) {
		GeneralUtility::logDeprecatedFunction();
		$tCells = array();
		$pct = round(100 / count($sArr));
		foreach ($sArr as $sKey => $sheetCfg) {
			if ($this->getBackendUserAuthentication()->jsConfirmation(JsConfirmation::TYPE_CHANGE)) {
				$onClick = 'if (confirm(TBE_EDITOR.labels.onChangeAlert) && TBE_EDITOR.checkSubmit(-1)){'
					. 'document.editform[\'' . $elName . '\'].value=\'' . $sKey . '\'; TBE_EDITOR.submitForm()};';
			} else {
				$onClick = 'if(TBE_EDITOR.checkSubmit(-1)){ document.editform[\'' . $elName . '\'].value=\'' . $sKey . '\'; TBE_EDITOR.submitForm();}';
			}
			$tCells[] = '<td width="' . $pct . '%" style="'
				. ($sKey == $sheetKey ? 'background-color: #9999cc; font-weight: bold;' : 'background-color: #aaaaaa;')
				. ' cursor: hand;" onclick="' . htmlspecialchars($onClick) . '" align="center">'
				. ($sheetCfg['ROOT']['TCEforms']['sheetTitle'] ? $this->getLanguageService()->sL($sheetCfg['ROOT']['TCEforms']['sheetTitle']) : $sKey)
				. '</td>';
		}
		return '<table border="0" cellpadding="0" cellspacing="2" class="typo3-TCEforms-flexForm-sheetMenu"><tr>' . implode('', $tCells) . '</tr></table>';
	}

	/**
	 * Handler for unknown types.
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\UnknownElement
	 */
	public function getSingleField_typeUnknown($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\UnknownElement::class, $this)
			->setGlobalOptions($this->getConfigurationOptionsForChildElements())
			->render($table, $field, $row, $PA);
	}

	/**
	 * User defined field type
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. Use \TYPO3\CMS\Backend\Form\Element\UserElement
	 */
	public function getSingleField_typeUser($table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $item = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Form\Element\UserElement::class, $this)
			->setGlobalOptions($this->getConfigurationOptionsForChildElements())
			->render($table, $field, $row, $PA);
	}

	/**
	 * Overrides the TCA field configuration by TSconfig settings.
	 *
	 * Example TSconfig: TCEform.<table>.<field>.config.appearance.useSortable = 1
	 * This overrides the setting in $GLOBALS['TCA'][<table>]['columns'][<field>]['config']['appearance']['useSortable'].
	 *
	 * @param array $fieldConfig $GLOBALS['TCA'] field configuration
	 * @param array $TSconfig TSconfig
	 * @return array Changed TCA field configuration
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8.
	 */
	public function overrideFieldConf($fieldConfig, $TSconfig) {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::overrideFieldConf($fieldConfig, $TSconfig);
	}

	/**
	 * Extracting values from a value/label list (as made by transferData class)
	 *
	 * @param array $itemFormElValue Values in an array
	 * @return array Input string exploded with comma and for each value only the label part is set in the array. Keys are numeric
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8.
	 */
	public function extractValuesOnlyFromValueLabelList($itemFormElValue) {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::extractValuesOnlyFromValueLabelList($itemFormElValue);
	}

	/**
	 * Initializes language icons etc.
	 *
	 * @param string $table Table name
	 * @param array $row Record
	 * @param string $sys_language_uid Sys language uid OR ISO language code prefixed with "v", eg. "vDA
	 * @return string
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8.
	 */
	public function getLanguageIcon($table, $row, $sys_language_uid) {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::getLanguageIcon($table, $row, $sys_language_uid);
	}

	/**
	 * Format field content of various types if $config['format'] is set to date, filesize, ..., user
	 * This is primarily for the field type none but can be used for user field types for example
	 *
	 * @param array $config Configuration for the display
	 * @param string $itemValue The value to display
	 * @return string Formatted Field content
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8. This is now encapsulated within NoneElement
	 */
	public function formatValue($config, $itemValue) {
		GeneralUtility::logDeprecatedFunction();
		$format = trim($config['format']);
		switch ($format) {
			case 'date':
				if ($itemValue) {
					$option = trim($config['format.']['option']);
					if ($option) {
						if ($config['format.']['strftime']) {
							$value = strftime($option, $itemValue);
						} else {
							$value = date($option, $itemValue);
						}
					} else {
						$value = date('d-m-Y', $itemValue);
					}
				} else {
					$value = '';
				}
				if ($config['format.']['appendAge']) {
					$age = BackendUtility::calcAge(
						$GLOBALS['EXEC_TIME'] - $itemValue,
						$this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.minutesHoursDaysYears')
					);
					$value .= ' (' . $age . ')';
				}
				$itemValue = $value;
				break;
			case 'datetime':
				// compatibility with "eval" (type "input")
				if ($itemValue !== '') {
					$itemValue = date('H:i d-m-Y', (int)$itemValue);
				}
				break;
			case 'time':
				// compatibility with "eval" (type "input")
				if ($itemValue !== '') {
					$itemValue = date('H:i', (int)$itemValue);
				}
				break;
			case 'timesec':
				// compatibility with "eval" (type "input")
				if ($itemValue !== '') {
					$itemValue = date('H:i:s', (int)$itemValue);
				}
				break;
			case 'year':
				// compatibility with "eval" (type "input")
				if ($itemValue !== '') {
					$itemValue = date('Y', (int)$itemValue);
				}
				break;
			case 'int':
				$baseArr = array('dec' => 'd', 'hex' => 'x', 'HEX' => 'X', 'oct' => 'o', 'bin' => 'b');
				$base = trim($config['format.']['base']);
				$format = $baseArr[$base] ?: 'd';
				$itemValue = sprintf('%' . $format, $itemValue);
				break;
			case 'float':
				$precision = MathUtility::forceIntegerInRange($config['format.']['precision'], 1, 10, 2);
				$itemValue = sprintf('%.' . $precision . 'f', $itemValue);
				break;
			case 'number':
				$format = trim($config['format.']['option']);
				$itemValue = sprintf('%' . $format, $itemValue);
				break;
			case 'md5':
				$itemValue = md5($itemValue);
				break;
			case 'filesize':
				// We need to cast to int here, otherwise empty values result in empty output,
				// but we expect zero.
				$value = GeneralUtility::formatSize((int)$itemValue);
				if ($config['format.']['appendByteSize']) {
					$value .= ' (' . $itemValue . ')';
				}
				$itemValue = $value;
				break;
			case 'user':
				$func = trim($config['format.']['userFunc']);
				if ($func) {
					$params = array(
						'value' => $itemValue,
						'args' => $config['format.']['userFunc'],
						'config' => $config,
						'pObj' => &$this
					);
					$itemValue = GeneralUtility::callUserFunction($func, $params, $this);
				}
				break;
			default:
				// Do nothing e.g. when $format === ''
		}
		return $itemValue;
	}

	/**
	 * Wraps the icon of a relation item (database record or file) in a link opening the context menu for the item.
	 *
	 * @param string $str The icon HTML to wrap
	 * @param string $table Table name (eg. "pages" or "tt_content") OR the absolute path to the file
	 * @param int $uid The uid of the record OR if file, just blank value.
	 * @return string HTML
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getClickMenu($str, $table, $uid = 0) {
		GeneralUtility::logDeprecatedFunction();
		return $this->getControllerDocumentTemplate()->wrapClickMenuOnIcon($str, $table, $uid, 1, '', '+copy,info,edit,view');
	}

	/**
	 * Returns the "special" configuration (from the "types" "showitem" list) for a fieldname based on input table/record
	 * (Not used anywhere...?)
	 *
	 * @param string $table The table name
	 * @param array $row The table row (Should at least contain the "uid" value, even if "NEW..." string. The "pid" field is important as well, and negative values will be intepreted as pointing to a record from the same table.)
	 * @param string $field Specify the field name.
	 * @return array|NULL
	 * @see getSpecConfFromString(), BackendUtility::getTCAtypes()
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getSpecConfForField($table, $row, $field) {
		GeneralUtility::logDeprecatedFunction();
		// Finds the current "types" configuration for the table/row:
		$types_fieldConfig = BackendUtility::getTCAtypes($table, $row);
		// If this is an array, then traverse it:
		if (is_array($types_fieldConfig)) {
			foreach ($types_fieldConfig as $vconf) {
				// If the input field name matches one found in the 'types' list, then return the 'special' configuration.
				if ($vconf['field'] == $field) {
					return $vconf['spec'];
				}
			}
		}
		return NULL;
	}

	/**
	 * Returns the "special" configuration of an "extra" string (non-parsed)
	 *
	 * @param string $extraString The "Part 4" of the fields configuration in "types" "showitem" lists.
	 * @param string $defaultExtras The ['defaultExtras'] value from field configuration
	 * @return array An array with the special options in.
	 * @see getSpecConfForField(), BackendUtility::getSpecConfParts()
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getSpecConfFromString($extraString, $defaultExtras) {
		GeneralUtility::logDeprecatedFunction();
		return BackendUtility::getSpecConfParts($extraString, $defaultExtras);
	}

	/**
	 * Renders the diff-view of vDEF fields in flexforms
	 *
	 * @param array $vArray Record array of the record being edited
	 * @param string $vDEFkey HTML of the form field. This is what we add the content to.
	 * @return string Item string returned again, possibly with the original value added to.
	 * @see getSingleField(), registerDefaultLanguageData()
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function renderVDEFDiff($vArray, $vDEFkey) {
		GeneralUtility::logDeprecatedFunction();
		$item = NULL;
		if (
			$GLOBALS['TYPO3_CONF_VARS']['BE']['flexFormXMLincludeDiffBase'] && isset($vArray[$vDEFkey . '.vDEFbase'])
			&& (string)$vArray[$vDEFkey . '.vDEFbase'] !== (string)$vArray['vDEF']
		) {
			// Create diff-result:
			$t3lib_diff_Obj = GeneralUtility::makeInstance(DiffUtility::class);
			$diffres = $t3lib_diff_Obj->makeDiffDisplay($vArray[$vDEFkey . '.vDEFbase'], $vArray['vDEF']);
			$item = '<div class="typo3-TCEforms-diffBox">' . '<div class="typo3-TCEforms-diffBox-header">'
				. htmlspecialchars($this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.changeInOrig')) . ':</div>' . $diffres . '</div>';
		}
		return $item;
	}

	/**
	 * Creates style attribute content for option tags in a selector box, primarily setting
	 * it up to show the icon of an element as background image (works in mozilla)
	 *
	 * @param string $iconString Icon string for option item
	 * @return string Style attribute content, if any
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function optionTagStyle($iconString) {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::optionTagStyle($iconString);
	}

	/**
	 * Get icon (for example for selector boxes)
	 *
	 * @param string $icon Icon reference
	 * @return array Array with two values; the icon file reference, the icon file information array (getimagesize())
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getIcon($icon) {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::getIcon($icon);
	}

	/**
	 * Renders the $icon, supports a filename for skinImg or sprite-icon-name
	 *
	 * @param string $icon The icon passed, could be a file-reference or a sprite Icon name
	 * @param string $alt Alt attribute of the icon returned
	 * @param string $title Title attribute of the icon return
	 * @return string A tag representing to show the asked icon
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getIconHtml($icon, $alt = '', $title = '') {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::getIconHtml($icon, $alt, $title);
	}

	/**
	 * Wraps a string with a link to the palette.
	 *
	 * @param string $header The string to wrap in an A-tag
	 * @param string $table The table name for which to open the palette.
	 * @param array $row The palette pointer.
	 * @param int $palette The record array
	 * @param mixed $retFunc Not used
	 * @return array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function wrapOpenPalette($header, $table, $row, $palette, $retFunc) {
		GeneralUtility::logDeprecatedFunction();
		$id = 'TCEFORMS_' . $table . '_' . $palette . '_' . $row['uid'];
		$res = '<a href="#" onclick="TBE_EDITOR.toggle_display_states(\'' . $id . '\',\'block\',\'none\'); return false;" >' . $header . '</a>';
		return array($res, '');
	}

	/**
	 * Add the id and the style property to the field palette
	 *
	 * @param string $code Palette Code
	 * @param string $table The table name for which to open the palette.
	 * @param string $row Palette ID
	 * @param string $palette The record array
	 * @param bool $collapsed TRUE if collapsed
	 * @return bool Is collapsed
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function wrapPaletteField($code, $table, $row, $palette, $collapsed) {
		GeneralUtility::logDeprecatedFunction();
		$display = $collapsed ? 'none' : 'block';
		$id = 'TCEFORMS_' . $table . '_' . $palette . '_' . $row['uid'];
		$code = '<div id="' . $id . '" style="display:' . $display . ';" >' . $code . '</div>';
		return $code;
	}

	/**
	 * Returns element reference for form element name
	 *
	 * @param string $itemName Form element name
	 * @return string Form element reference (JS)
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function elName($itemName) {
		GeneralUtility::logDeprecatedFunction();
		return 'document.editform[\'' . $itemName . '\']';
	}

	/**
	 * Returns the max-width in pixels for a <input>/<textarea>-element
	 *
	 * @param int $size The abstract size value (1-48)
	 * @return int max-width in pixels
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function formMaxWidth($size = 48) {
		GeneralUtility::logDeprecatedFunction();
		$size = round($size * $this->form_largeComp);
		$width = ceil($size * $this->form_rowsToStylewidth);
		return $width;
	}

	/**
	 * Returns parameters to set the width for a <input>/<textarea>-element
	 *
	 * @param int $size The abstract size value (1-48)
	 * @param bool $textarea If this is for a text area.
	 * @return string Either a "style" attribute string or "cols"/"size" attribute string.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function formWidth($size = 48, $textarea = FALSE) {
		GeneralUtility::logDeprecatedFunction();
		$fieldWidthAndStyle = $this->formWidthAsArray($size, $textarea);
		// Setting width by style-attribute. 'cols' MUST be avoided with NN6+
		$widthAndStyleAttributes = ' style="' . htmlspecialchars($fieldWidthAndStyle['style']) . '"';
		if ($fieldWidthAndStyle['class']) {
			$widthAndStyleAttributes .= ' class="' . htmlspecialchars($fieldWidthAndStyle['class']) . '"';
		}
		return $widthAndStyleAttributes;
	}

	/**
	 * Returns parameters to set the width for a <input>/<textarea>-element
	 *
	 * @param int $size The abstract size value (1-48)
	 * @param bool $textarea If set, calculates sizes for a text area.
	 * @return array An array containing style, class, and width attributes.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function formWidthAsArray($size = 48, $textarea = FALSE) {
		GeneralUtility::logDeprecatedFunction();
		$fieldWidthAndStyle = array('style' => '', 'class' => '', 'width' => '');
		$widthInPixels = $this->formMaxWidth($size);
		$fieldWidthAndStyle['style'] = 'width: ' . $widthInPixels . 'px; ';
		$fieldWidthAndStyle['class'] = 'formfield-' . ($textarea ? 'text' : 'input');
		return $fieldWidthAndStyle;
	}

	/**
	 * Get style CSS values for the current field type.
	 *
	 * @param string $type Field type (eg. "check", "radio", "select")
	 * @return string CSS attributes
	 * @see formElStyleClassValue()
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function formElStyle($type) {
		GeneralUtility::logDeprecatedFunction();
		return $this->formElStyleClassValue($type);
	}

	/**
	 * Get class attribute value for the current field type.
	 *
	 * @param string $type Field type (eg. "check", "radio", "select")
	 * @return string CSS attributes
	 * @see formElStyleClassValue()
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function formElClass($type) {
		GeneralUtility::logDeprecatedFunction();
		return $this->formElStyleClassValue($type, TRUE);
	}

	/**
	 * Get style CSS values for the current field type.
	 *
	 * @param string $type Field type (eg. "check", "radio", "select")
	 * @param bool $class If set, will return value only if prefixed with CLASS, otherwise must not be prefixed "CLASS
	 * @return string CSS attributes
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function formElStyleClassValue($type, $class = FALSE) {
		GeneralUtility::logDeprecatedFunction();
		// Get value according to field:
		if (isset($this->fieldStyle[$type])) {
			$style = trim($this->fieldStyle[$type]);
		} else {
			$style = trim($this->fieldStyle['all']);
		}
		// Check class prefixed:
		if (substr($style, 0, 6) == 'CLASS:') {
			$out = $class ? trim(substr($style, 6)) : '';
		} else {
			$out = !$class ? $style : '';
		}
		return $out;
	}

	/**
	 * Return default "style" / "class" attribute line.
	 *
	 * @param string $type Field type (eg. "check", "radio", "select")
	 * @param string $additionalClass Additional class(es) to be added
	 * @return string CSS attributes
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function insertDefStyle($type, $additionalClass = '') {
		GeneralUtility::logDeprecatedFunction();
		$cssClasses = trim('t3-formengine-field-' . $type . ' ' . $additionalClass);
		return 'class="' . htmlspecialchars($cssClasses) . '"';
	}

	/**
	 * Initialize item array (for checkbox, selectorbox, radio buttons)
	 * Will resolve the label value.
	 *
	 * @param array $fieldValue The "columns" array for the field (from TCA)
	 * @return array An array of arrays with three elements; label, value, icon
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function initItemArray($fieldValue) {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::initItemArray($fieldValue);
	}

	/**
	 * Merges items into an item-array, optionally with an icon
	 * example:
	 * TCEFORM.pages.doktype.addItems.13 = My Label
	 * TCEFORM.pages.doktype.addItems.13.icon = EXT:t3skin/icons/gfx/i/pages.gif
	 *
	 * @param array $items The existing item array
	 * @param array $iArray An array of items to add. NOTICE: The keys are mapped to values, and the values and mapped to be labels. No possibility of adding an icon.
	 * @return array The updated $item array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function addItems($items, $iArray) {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::addItems($items, $iArray);
	}

	/**
	 * Perform user processing of the items arrays of checkboxes, selectorboxes and radio buttons.
	 *
	 * @param array $items The array of items (label,value,icon)
	 * @param array $iArray The "itemsProcFunc." from fieldTSconfig of the field.
	 * @param array $config The config array for the field.
	 * @param string $table Table name
	 * @param array $row Record row
	 * @param string $field Field name
	 * @return array The modified $items array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function procItems($items, $iArray, $config, $table, $row, $field) {
		GeneralUtility::logDeprecatedFunction();
		$languageService = $this->getLanguageService();
		$params = array();
		$params['items'] = &$items;
		$params['config'] = $config;
		$params['TSconfig'] = $iArray;
		$params['table'] = $table;
		$params['row'] = $row;
		$params['field'] = $field;
		// The itemsProcFunc method may throw an exception.
		// If it does display an error message and return items unchanged.
		try {
			GeneralUtility::callUserFunction($config['itemsProcFunc'], $params, $this);
		} catch (\Exception $exception) {
			$fieldLabel = $field;
			if (isset($GLOBALS['TCA'][$table]['columns'][$field]['label'])) {
				$fieldLabel = $languageService->sL($GLOBALS['TCA'][$table]['columns'][$field]['label']);
			}
			$message = sprintf(
				$languageService->sL('LLL:EXT:lang/locallang_core.xlf:error.items_proc_func_error'),
				$fieldLabel,
				$exception->getMessage()
			);
			/** @var $flashMessage FlashMessage */
			$flashMessage = GeneralUtility::makeInstance(
				FlashMessage::class,
				htmlspecialchars($message),
				'',
				FlashMessage::ERROR,
				TRUE
			);
			/** @var $flashMessageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
			$flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
			$defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
			$defaultFlashMessageQueue->enqueue($flashMessage);
		}
		return $items;
	}

	/**
	 * Prints the selector box form-field for the db/file/select elements (multiple)
	 *
	 * @param string $fName Form element name
	 * @param string $mode Mode "db", "file" (internal_type for the "group" type) OR blank (then for the "select" type)
	 * @param string $allowed Commalist of "allowed
	 * @param array $itemArray The array of items. For "select" and "group"/"file" this is just a set of value. For "db" its an array of arrays with table/uid pairs.
	 * @param string $selector Alternative selector box.
	 * @param array $params An array of additional parameters, eg: "size", "info", "headers" (array with "selector" and "items"), "noBrowser", "thumbnails
	 * @param string $onFocus On focus attribute string
	 * @param string $table (optional) Table name processing for
	 * @param string $field (optional) Field of table name processing for
	 * @param string $uid (optional) uid of table record processing for
	 * @param array $config (optional) The TCA field config
	 * @throws \RuntimeException
	 * @return string The form fields for the selection.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function dbFileIcons($fName, $mode, $allowed, $itemArray, $selector = '', $params = array(), $onFocus = '', $table = '', $field = '', $uid = '', $config = array()) {
		// dbFileIcons() in AbstractFormElement is now protected. The method was never meant to be
		// called directly. Let's throw a friendly exception if someone still does it.
		throw new \RuntimeException('dbFileIcons() can not be called directly', 1424031815);
	}

	/**
	 * Returns array of elements from clipboard to insert into GROUP element box.
	 *
	 * @param string $allowed Allowed elements, Eg "pages,tt_content", "gif,jpg,jpeg,png
	 * @param string $mode Mode of relations: "db" or "file
	 * @throws \RuntimeException
	 * @return array Array of elements in values (keys are insignificant), if none found, empty array.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getClipboardElements($allowed, $mode) {
		// getClipboardElements() in AbstractFormElement is now protected. The method was never meant to be
		// called directly. Let's throw a friendly exception if someone still does it.
		throw new \RuntimeException('getClipboardElements() can not be called directly', 1424031814);
	}

	/**
	 * Overwrite this function in own extended class to add own markers for output
	 *
	 * @param array $marker Array with key/value pairs to insert in the template.
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return array Marker array for template output
	 * @see function intoTemplate()
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function addUserTemplateMarkers($marker, $table, $field, $row, &$PA) {
		GeneralUtility::logDeprecatedFunction();
		return $marker;
	}

	/**
	 * Wraps an element in the $out_array with the template row for a "section" ($this->sectionWrap)
	 *
	 * @param array $out_array The array with form elements stored in (passed by reference and changed!)
	 * @param int $out_pointer The pointer to the entry in the $out_array  (passed by reference and incremented!)
	 * @return void
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8, not in use anymore
	 */
	public function wrapBorder(&$out_array, &$out_pointer) {
		GeneralUtility::logDeprecatedFunction();
		if ($this->sectionWrap && $out_array[$out_pointer]) {
			$tableAttribs = 'border="0" cellspacing="0" cellpadding="0" width="100%" class="table table-border"';
			$out_array[$out_pointer] = str_replace('###CONTENT###', $out_array[$out_pointer], str_replace('###TABLE_ATTRIBS###', $tableAttribs, $this->sectionWrap));
			$out_pointer++;
		}
	}

	/**
	 * Replaces colorscheme markers in the template string
	 *
	 * @param string $inTemplate Template string with markers to be substituted.
	 * @return string
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function rplColorScheme($inTemplate) {
		GeneralUtility::logDeprecatedFunction();
		return str_replace(
			array(
				// Colors:
				'###BGCOLOR###',
				'###BGCOLOR_HEAD###',
				'###FONTCOLOR_HEAD###',
				// Classes:
				'###CLASSATTR_1###',
				'###CLASSATTR_2###',
				'###CLASSATTR_4###'
			),
			array(
				// Colors:
				$this->colorScheme[0] ? ' bgcolor="' . $this->colorScheme[0] . '"' : '',
				$this->colorScheme[1] ? ' bgcolor="' . $this->colorScheme[1] . '"' : '',
				$this->colorScheme[3],
				// Classes:
				$this->classScheme[0] ? ' class="' . $this->classScheme[0] . '"' : '',
				$this->classScheme[1] ? ' class="' . $this->classScheme[1] . '"' : '',
				$this->classScheme[3] ? ' class="' . $this->classScheme[3] . '"' : ''
			),
			$inTemplate
		);
	}

	/**
	 * Returns divider.
	 * Currently not implemented and returns only blank value.
	 *
	 * @return string Empty string
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getDivider() {
		GeneralUtility::logDeprecatedFunction();
		return '';
	}

	/**
	 * Setting the current color scheme ($this->colorScheme) based on $this->defColorScheme plus input string.
	 *
	 * @param string $scheme A color scheme string.
	 * @return void
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function setColorScheme($scheme) {
		GeneralUtility::logDeprecatedFunction();
		$this->colorScheme = $this->defColorScheme;
		$this->classScheme = $this->defClassScheme;
		$parts = GeneralUtility::trimExplode(',', $scheme);
		foreach ($parts as $key => $col) {
			// Split for color|class:
			list($color, $class) = GeneralUtility::trimExplode('|', $col);
			// Handle color values:
			if ($color) {
				$this->colorScheme[$key] = $color;
			}
			if ($color == '-') {
				$this->colorScheme[$key] = '';
			}
			// Handle class values:
			if ($class) {
				$this->classScheme[$key] = $class;
			}
			if ($class == '-') {
				$this->classScheme[$key] = '';
			}
		}
	}

	/**
	 * Reset color schemes.
	 *
	 * @return void
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function resetSchemes() {
		GeneralUtility::logDeprecatedFunction();
		$this->setColorScheme($GLOBALS['TBE_STYLES']['colorschemes'][0]);
		$this->fieldStyle = $GLOBALS['TBE_STYLES']['styleschemes'][0];
		$this->borderStyle = $GLOBALS['TBE_STYLES']['borderschemes'][0];
	}

	/**
	 * Store current color scheme
	 *
	 * @return void
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function storeSchemes() {
		GeneralUtility::logDeprecatedFunction();
		$this->savedSchemes['classScheme'] = $this->classScheme;
		$this->savedSchemes['colorScheme'] = $this->colorScheme;
		$this->savedSchemes['fieldStyle'] = $this->fieldStyle;
		$this->savedSchemes['borderStyle'] = $this->borderStyle;
	}

	/**
	 * Restore the saved color scheme
	 *
	 * @return void
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function restoreSchemes() {
		GeneralUtility::logDeprecatedFunction();
		$this->classScheme = $this->savedSchemes['classScheme'];
		$this->colorScheme = $this->savedSchemes['colorScheme'];
		$this->fieldStyle = $this->savedSchemes['fieldStyle'];
		$this->borderStyle = $this->savedSchemes['borderStyle'];
	}

	/**
	 * Gets default record. Maybe not used anymore. FE-editor?
	 *
	 * @param string $table Database Tablename
	 * @param int $pid PID value (positive / negative)
	 * @return array|NULL "default" row.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getDefaultRecord($table, $pid = 0) {
		GeneralUtility::logDeprecatedFunction();
		if ($GLOBALS['TCA'][$table]) {
			$row = array();
			if ($pid < 0 && $GLOBALS['TCA'][$table]['ctrl']['useColumnsForDefaultValues']) {
				// Fetches the previous record:
				$db = $this->getDatabaseConnection();
				$res = $db->exec_SELECTquery('*', $table, 'uid=' . abs($pid) . BackendUtility::deleteClause($table));
				if ($drow = $db->sql_fetch_assoc($res)) {
					// Gets the list of fields to copy from the previous record.
					$fArr = explode(',', $GLOBALS['TCA'][$table]['ctrl']['useColumnsForDefaultValues']);
					foreach ($fArr as $theF) {
						if ($GLOBALS['TCA'][$table]['columns'][$theF]) {
							$row[$theF] = $drow[$theF];
						}
					}
				}
				$db->sql_free_result($res);
			}
			foreach ($GLOBALS['TCA'][$table]['columns'] as $field => $info) {
				if (isset($info['config']['default'])) {
					$row[$field] = $info['config']['default'];
				}
			}
			return $row;
		}
		return NULL;
	}

	/**
	 * Fetches language label for key
	 *
	 * @param string $str Language label reference, eg. 'LLL:EXT:lang/locallang_core.xlf:labels.blablabla'
	 * @return string The value of the label, fetched for the current backend language.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function sL($str) {
		GeneralUtility::logDeprecatedFunction();
		return $this->getLanguageService()->sL($str);
	}

	/**
	 * Returns language label from locallang_core.xlf
	 * Labels must be prefixed with either "l_" or "m_".
	 * The prefix "l_" maps to the prefix "labels." inside locallang_core.xlf
	 * The prefix "m_" maps to the prefix "mess." inside locallang_core.xlf
	 *
	 * @param string $str The label key
	 * @return string The value of the label, fetched for the current backend language.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getLL($str) {
		GeneralUtility::logDeprecatedFunction();
		$content = '';
		switch (substr($str, 0, 2)) {
			case 'l_':
				$content = $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:labels.' . substr($str, 2));
				break;
			case 'm_':
				$content = $this->getLanguageService()->sL('LLL:EXT:lang/locallang_core.xlf:mess.' . substr($str, 2));
				break;
		}
		return $content;
	}

	/**
	 * Return TSCpid (cached)
	 * Using BackendUtility::getTSCpid()
	 *
	 * @param string $table Tablename
	 * @param string $uid UID value
	 * @param string $pid PID value
	 * @return array Array of two integers; first is the real PID of a record, second is the PID value for TSconfig.
	 * @see BackendUtility::getTSCpid()
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getTSCpid($table, $uid, $pid) {
		GeneralUtility::logDeprecatedFunction();
		return BackendUtility::getTSCpidCached($table, $uid, $pid);
	}

	/**
	 * Returns an array of available languages (to use for FlexForms)
	 *
	 * @param bool $onlyIsoCoded If set, only languages which are paired with a static_info_table / static_language record will be returned.
	 * @param bool $setDefault If set, an array entry for a default language is set.
	 * @return array
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function getAvailableLanguages($onlyIsoCoded = TRUE, $setDefault = TRUE) {
		GeneralUtility::logDeprecatedFunction();
		$isL = ExtensionManagementUtility::isLoaded('static_info_tables');
		// Find all language records in the system:
		$db = $this->getDatabaseConnection();
		$res = $db->exec_SELECTquery('language_isocode,static_lang_isocode,title,uid', 'sys_language', 'pid=0 AND hidden=0' . BackendUtility::deleteClause('sys_language'), '', 'title');
		// Traverse them:
		$output = array();
		if ($setDefault) {
			$output[0] = array(
				'uid' => 0,
				'title' => 'Default language',
				'ISOcode' => 'DEF'
			);
		}
		while ($row = $db->sql_fetch_assoc($res)) {
			$output[$row['uid']] = $row;
			if (!empty($row['language_isocode'])) {
				$output[$row['uid']]['ISOcode'] = $row['language_isocode'];
			} elseif ($isL && $row['static_lang_isocode']) {
				\TYPO3\CMS\Core\Utility\GeneralUtility::deprecationLog('Usage of the field "static_lang_isocode" is discouraged, and will stop working with CMS 8. Use the built-in language field "language_isocode" in your sys_language records.');
				$rr = BackendUtility::getRecord('static_languages', $row['static_lang_isocode'], 'lg_iso_2');
				if ($rr['lg_iso_2']) {
					$output[$row['uid']]['ISOcode'] = $rr['lg_iso_2'];
				}
			}
			if ($onlyIsoCoded && !$output[$row['uid']]['ISOcode']) {
				unset($output[$row['uid']]);
			}
		}
		$db->sql_free_result($res);
		return $output;
	}

	/**
	 * Returns TSconfig for table/row
	 * Multiple requests to this function will return cached content so there is no performance loss in calling
	 * this many times since the information is looked up only once.
	 *
	 * @param string $table The table name
	 * @param array $row The table row (Should at least contain the "uid" value, even if "NEW..." string. The "pid" field is important as well, and negative values will be intepreted as pointing to a record from the same table.)
	 * @param string $field Optionally you can specify the field name as well. In that case the TSconfig for the field is returned.
	 * @return mixed The TSconfig values (probably in an array)
	 * @see BackendUtility::getTCEFORM_TSconfig()
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function setTSconfig($table, $row, $field = '') {
		GeneralUtility::logDeprecatedFunction();
		$mainKey = $table . ':' . $row['uid'];
		if (!isset($this->cachedTSconfig[$mainKey])) {
			$this->cachedTSconfig[$mainKey] = BackendUtility::getTCEFORM_TSconfig($table, $row);
		}
		if ($field) {
			return $this->cachedTSconfig[$mainKey][$field];
		} else {
			return $this->cachedTSconfig[$mainKey];
		}
	}

	/**
	 * Add selector box items of more exotic kinds.
	 *
	 * @param array $items The array of items (label,value,icon)
	 * @param array $fieldValue The "columns" array for the field (from TCA)
	 * @param array $TSconfig TSconfig for the table/row
	 * @param string $field The fieldname
	 * @return array The $items array modified.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function addSelectOptionsToItemArray($items, $fieldValue, $TSconfig, $field) {
		GeneralUtility::logDeprecatedFunction();
		return FormEngineUtility::addSelectOptionsToItemArray($items, $fieldValue, $TSconfig, $field);
	}

	/**
	 * Creates value/label pair for a backend module (main and sub)
	 *
	 * @param string $value The module key
	 * @return string The rawurlencoded 2-part string to transfer to interface
	 * @access private
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function addSelectOptionsToItemArray_makeModuleData($value) {
		GeneralUtility::logDeprecatedFunction();
		$label = '';
		// Add label for main module:
		$pp = explode('_', $value);
		if (count($pp) > 1) {
			$label .= $this->getLanguageService()->moduleLabels['tabs'][($pp[0] . '_tab')] . '>';
		}
		// Add modules own label now:
		$label .= $this->getLanguageService()->moduleLabels['tabs'][$value . '_tab'];
		return $label;
	}

	/**
	 * Adds records from a foreign table (for selector boxes)
	 *
	 * @param array $items The array of items (label,value,icon)
	 * @param array $fieldValue The 'columns' array for the field (from TCA)
	 * @param array $TSconfig TSconfig for the table/row
	 * @param string $field The fieldname
	 * @param bool $pFFlag If set, then we are fetching the 'neg_' foreign tables.
	 * @return array The $items array modified.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function foreignTable($items, $fieldValue, $TSconfig, $field, $pFFlag = FALSE) {
		GeneralUtility::logDeprecatedFunction();
		$languageService = $this->getLanguageService();
		// Init:
		$pF = $pFFlag ? 'neg_' : '';
		$f_table = $fieldValue['config'][$pF . 'foreign_table'];
		$uidPre = $pFFlag ? '-' : '';
		// Exec query:
		$res = BackendUtility::exec_foreign_table_where_query($fieldValue, $field, $TSconfig, $pF);
		// Perform error test
		$db = $this->getDatabaseConnection();
		if ($db->sql_error()) {
			$msg = htmlspecialchars($db->sql_error());
			$msg .= '<br />' . LF;
			$msg .= $languageService->sL('LLL:EXT:lang/locallang_core.xlf:error.database_schema_mismatch');
			$msgTitle = $languageService->sL('LLL:EXT:lang/locallang_core.xlf:error.database_schema_mismatch_title');
			/** @var $flashMessage FlashMessage */
			$flashMessage = GeneralUtility::makeInstance(FlashMessage::class, $msg, $msgTitle, FlashMessage::ERROR, TRUE);
			/** @var $flashMessageService FlashMessageService */
			$flashMessageService = GeneralUtility::makeInstance(FlashMessageService::class);
			/** @var $defaultFlashMessageQueue FlashMessageQueue */
			$defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
			$defaultFlashMessageQueue->enqueue($flashMessage);
			return array();
		}
		// Get label prefix.
		$lPrefix = $languageService->sL($fieldValue['config'][$pF . 'foreign_table_prefix']);
		// Get icon field + path if any:
		$iField = $GLOBALS['TCA'][$f_table]['ctrl']['selicon_field'];
		$iPath = trim($GLOBALS['TCA'][$f_table]['ctrl']['selicon_field_path']);
		// Traverse the selected rows to add them:
		while ($row = $db->sql_fetch_assoc($res)) {
			BackendUtility::workspaceOL($f_table, $row);
			if (is_array($row)) {
				// Prepare the icon if available:
				if ($iField && $iPath && $row[$iField]) {
					$iParts = GeneralUtility::trimExplode(',', $row[$iField], TRUE);
					$icon = '../' . $iPath . '/' . trim($iParts[0]);
				} elseif (GeneralUtility::inList('singlebox,checkbox', $fieldValue['config']['renderMode'])) {
					$icon = IconUtility::mapRecordTypeToSpriteIconName($f_table, $row);
				} else {
					$icon = '';
				}
				// Add the item:
				$items[] = array(
					$lPrefix . htmlspecialchars(BackendUtility::getRecordTitle($f_table, $row)),
					$uidPre . $row['uid'],
					$icon
				);
			}
		}
		$db->sql_free_result($res);
		return $items;
	}

	/**
	 * Rendering wizards for form fields.
	 *
	 * @param array $itemKinds Array with the real item in the first value, and an alternative item in the second value.
	 * @param array $wizConf The "wizard" key from the config array for the field (from TCA)
	 * @param string $table Table name
	 * @param array $row The record array
	 * @param string $field The field name
	 * @param array $PA Additional configuration array. (passed by reference!)
	 * @param string $itemName The field name
	 * @param array $specConf Special configuration if available.
	 * @param bool $RTE Whether the RTE could have been loaded.
	 * @return string The new item value.
	 * @deprecated since TYPO3 CMS 7, will be removed in TYPO3 CMS 8
	 */
	public function renderWizards($itemKinds, $wizConf, $table, $row, $field, &$PA, $itemName, $specConf, $RTE = FALSE) {
		// renderWizards() in AbstractFormElement is now protected. The method was never meant to be
		// called directly. Let's throw a friendly exception if someone still does it.
		throw new \RuntimeException('renderWizards() can not be called directly', 1424031813);
	}

}
