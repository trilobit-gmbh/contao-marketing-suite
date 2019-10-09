<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2018 Leo Feyer
 *
 * @package   Contao Marketing Suite
 * @author    Benny Born <benny.born@numero2.de>
 * @author    Michael Bösherz <michael.boesherz@numero2.de>
 * @license   Commercial
 * @copyright 2018 numero2 - Agentur für digitales Marketing
 */


namespace numero2\MarketingSuite\BackendModule;

use Contao\Backend;
use Contao\BackendModule as CoreBackendModule;
use Contao\BackendUser;
use Contao\Controller;
use Contao\Environment;
use Contao\Input;
use Contao\InvalidArgumentException;
use Contao\StringUtil;
use Contao\System;
use numero2\MarketingSuite\Backend\Help;
use numero2\MarketingSuite\Backend\License as tabizni;


class Module extends CoreBackendModule {


    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'backend/modules/module';

    /**
     * Backend modules
     * @var array
     */
    protected $arrModules = [];


    /**
    * Generate the module
    *
    * @return string
    */
    public function generate() {

        $this->arrModules = [];
        $this->arrModules = $this->getModules();

        // Open module
        if( Input::get('mod') != '' ) {

            $objBEHelp = null;
            $objBEHelp = new Help();

            return $objBEHelp->generate() . $this->getModule(Input::get('mod'));
        }

        return parent::generate();
    }


    /**
     * Compile the module
     */
    protected function compile() {
        $this->Template->modules = $this->arrModules;
    }


    /**
     * Get modules
     *
     * @return array
     */
    protected function getModules() {

        $return = [];

        $moduleGroup = NULL;
        $moduleGroup = Input::get('do');

        $groupName = substr($moduleGroup,4);

        if( empty($GLOBALS['CMS_MOD'][$groupName]) ) {
            throw new InvalidArgumentException('Back end module "' . $groupName . '" is not defined in the CMS_MOD array');
        }

        foreach( $GLOBALS['CMS_MOD'][$groupName] as $moduleName => $moduleConfig ) {

            if( !empty($moduleConfig['callback']) ) {
                if( !$moduleConfig['callback']::isAvailable() ) {
                    continue;
                }
            }

            if( !empty($moduleConfig['tables']) && empty(array_intersect($moduleConfig['tables'], $GLOBALS['BE_MOD']['marketing_suite'][$moduleGroup]['tables'])) ) {
                continue;
            }

            $return[$moduleName] = $moduleConfig;

            $return[$moduleName] += [
                'label'         => $GLOBALS['TL_LANG']['CMS'][$moduleName][0]
            ,   'description'   => $GLOBALS['TL_LANG']['CMS'][$moduleName][1]
            ,   'class'         => StringUtil::standardize($moduleName)
            ,   'href'          => TL_SCRIPT . '?do=' . $moduleGroup . '&mod=' . $moduleName
            ];
        }

        return $return;
    }


    /**
     * Open a module and return it as HTML
     *
     * @param string
     *
     * @return string|null
     */
    protected function getModule( $module ) {

        $arrModule = [];
        $arrModule = $this->arrModules[$module];
        $dc = $this->objDc;
        tabizni::riz();

        // Redirect with table parameter
        $strTable = Input::get('table');
        if( $strTable == '' && $arrModule['callback'] == '' ) {
            Controller::redirect(Backend::addToUrl('table=' . $arrModule['tables'][0]));
        }

        // Add module style sheet
        if( isset($arrModule['stylesheet']) ) {
            foreach( (array)$arrModule['stylesheet'] as $stylesheet ) {
                $GLOBALS['TL_CSS'][] = $stylesheet;
            }
        }

        // Add module javascript
        if( isset($arrModule['javascript']) ) {
            foreach( (array)$arrModule['javascript'] as $javascript ) {
                $GLOBALS['TL_JAVASCRIPT'][] = $javascript;
            }
        }

        // Call module callback
        if( class_exists($arrModule['callback']) ) {

            $objCallback = new $arrModule['callback']($dc, $arrModule);
            return $objCallback->generate();

        // Custom action (if key is not defined in config.php the default action will be called)
        } elseif( Input::get('key') && isset($arrModule[Input::get('key')]) ) {

            $objCallback = new $arrModule[Input::get('key')][0]();
            return $objCallback->{$arrModule[Input::get('key')][1]}($dc, $strTable, $arrModule);

        // Default action
        }  elseif( is_object($dc) ) {

            $act = (string) Input::get('act');

            if( '' === $act || 'paste' === $act || 'select' === $act ) {
                $act = ($dc instanceof \listable) ? 'showAll' : 'edit';
            }

            switch ($act) {
                case 'delete':
                case 'show':
                case 'showAll':
                case 'undo':
                    if (!$dc instanceof \listable) {
                        System::log('Data container ' . $strTable . ' is not listable', __METHOD__, TL_ERROR);
                        trigger_error('The current data container is not listable', E_USER_ERROR);
                    }
                    break;
                case 'create':
                case 'cut':
                case 'cutAll':
                case 'copy':
                case 'copyAll':
                case 'move':
                case 'edit':
                    if (!$dc instanceof \editable) {
                        System::log('Data container ' . $strTable . ' is not editable', __METHOD__, TL_ERROR);
                        trigger_error('The current data container is not editable', E_USER_ERROR);
                    }
                    break;
            }

            $strContent = "";

            // gives the chance to add some content between the helper and the form itself
            // not a core functionality!
            if( $arrModule['pre_form_callback'] ) {

                $objCallback = NULL;
                $objCallback = new $arrModule['pre_form_callback'][0]($dc, $arrModule);
                $strContent .= $objCallback->{$arrModule['pre_form_callback'][1]}();
            }

            $strContent .= $dc->$act();

            return $strContent;
        }

        return null;
    }


    /**
     * Appends tables of all sub modules to the current backend module
     */
    public function initializeBackendModuleTables() {

        foreach( $GLOBALS['CMS_MOD'] as $groupName => $cmsConfig) {
            $moduleGroup = 'cms_'.$groupName;

            if( !array_key_exists($moduleGroup, $GLOBALS['BE_MOD']['marketing_suite']) ) {
                continue;
            }

            foreach( $GLOBALS['CMS_MOD'][$groupName] as $moduleName => $moduleConfig ) {

                if( !array_key_exists('tables', $GLOBALS['BE_MOD']['marketing_suite'][$moduleGroup]) ) {
                    $GLOBALS['BE_MOD']['marketing_suite'][$moduleGroup]['tables'] = [];
                }

                if( array_key_exists('tables', $moduleConfig) ) {

                    if( count($GLOBALS['CMS_MOD'][$groupName]) ) {

                        foreach( $moduleConfig['tables'] as $moduleTable ) {

                            if( !in_array($moduleTable, $GLOBALS['BE_MOD']['marketing_suite'][$moduleGroup]['tables']) ) {

                                $this->loadDataContainer($moduleTable);

                                if( !$GLOBALS['TL_DCA'][$moduleTable]['config']['isAvailable'] ) {
                                    continue;
                                }

                                $GLOBALS['BE_MOD']['marketing_suite'][$moduleGroup]['tables'][] = $moduleTable;
                            }
                        }
                    }
                }

                if( empty($GLOBALS['BE_MOD']['marketing_suite'][$moduleGroup]['tables']) ) {
                    unset($GLOBALS['BE_MOD']['marketing_suite'][$moduleGroup]);
                }
            }
        }
    }
}
