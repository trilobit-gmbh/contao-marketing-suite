<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2022 Leo Feyer
 *
 * @package   Contao Marketing Suite Administration
 * @author    Benny Born <benny.born@numero2.de>
 * @author    Michael Bösherz <michael.boesherz@numero2.de>
 * @license   Commercial
 * @copyright 2022 numero2 - Agentur für digitales Marketing
 */


namespace numero2\MarketingSuite\Backend;

use Contao\CMSConfig;
use Contao\Config;
use Contao\Crypto;
use Contao\Date;
use Contao\Environment;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\System;
use Doctrine\DBAL\Exception\DriverException;
use Exception;
use numero2\MarketingSuite\Api\MarketingSuite as API;


class License {


    /**
     * "It's a kind of magic magic magic"
     */
    public static function __callStatic( string $name, array $arguments ) {

        switch( $name ) {

            default:
                self::checkRootData();
                break;
        }
    }


    /**
     * Checks if the license data is valid
     */
    public static function checkRootData() {

        $objPages = null;

        try {

            $objPages = PageModel::findByType('root');

        } catch( DriverException $e ) {
            // exception in install tool is to be expected
        }

        if( $objPages ) {

            foreach( $objPages as $value ) {

                if( empty($value->cms_root_license) || empty($value->cms_root_key) || empty($value->cms_root_data) || empty($value->cms_root_sign) ) {
                    continue;
                }

                $oCrypto = null;
                $oCrypto = new Crypto($value->cms_root_key);

                $msg = '';
                if( !$oCrypto->verify($value->cms_root_data, $value->cms_root_sign) ) {
                    $msg = 'verify';
                }

                $data = $oCrypto->decryptPublic($value->cms_root_data);
                $data = StringUtil::deserialize($data);

                if( !is_array($data) || empty($data['features']) || empty($data['expires']) ) {

                    if( $msg ) {
                        $msg .= '_data';
                    } else {
                        $msg = 'data';
                    }
                }

                if( $msg ) {

                    $value->cms_root_key = null;
                    $value->cms_root_data = null;
                    $value->cms_root_sign = null;
                    $value->save();

                    try {

                        $oPage = $value->current();
                        $oPage->preventSaving(false);
                        $oPage->cms_root_key = $msg;

                        $oAPI = new API();
                        $oAPI->checkLicense($oPage->cms_root_license, $oPage);

                    } catch( Exception $e ) {
                    }
                }
            }
        }
    }


    /**
     * Checks if the feature given by alias is available at all or for the given root page.
     *
     * @param string $strAlias
     * @param integer $pageId
     *
     * @return boolean
     */
    public static function hasFeature( $strAlias, $rootPageId=0 ) {

        $objPages = [];

        try {

            // backend handling
            if( !$rootPageId ) {

                $objPages = PageModel::findByType('root');

                if( CMSConfig::get('testmode') && !self::hasNoLicense() && Auth::isBackendUserLoggedIn() ) {
                    return true;
                }

            // frontend handling
            } else {

                $objPage = PageModel::findById($rootPageId);

                if( $objPage && $objPage->type == 'root' ) {
                    $objPages[] = $objPage;
                }

                if( !CMSConfig::get('testmode') && self::isTestDomain($rootPageId) ) {
                    return false;
                }
                if( CMSConfig::get('testmode') && !Auth::isBackendUserLoggedIn() ) {
                    return false;
                }
                if( CMSConfig::get('testmode') && self::hasLicense($rootPageId) && Auth::isBackendUserLoggedIn() ) {
                    return true;
                }
            }


        } catch( DriverException $e ) {
            // expected in install tool
        }

        if( $objPages ) {

            foreach( $objPages as $value ) {

                if( empty($value->cms_root_license) || empty($value->cms_root_key) || empty($value->cms_root_data) || empty($value->cms_root_sign) ) {
                    continue;
                }

                $oCrypto = null;
                $oCrypto = new Crypto($value->cms_root_key);

                if( !$oCrypto->verify($value->cms_root_data, $value->cms_root_sign) ) {
                    continue;
                }

                $data = $oCrypto->decryptPublic($value->cms_root_data);
                $data = StringUtil::deserialize($data);

                if( !is_array($data) || empty($data['features']) || empty($data['expires']) ) {
                    continue;
                }

                if( $data['expires'] < time() ) {
                    continue;
                }

                if( in_array($strAlias, $data['features']) ) {
                    return true;
                }
            }
        }

        return false;
    }


    /**
     * Tests if the given domain is a valid test domain for the given root page
     *
     * @param integer $rootPageId
     * @param string  $domain
     *
     * @return boolean
     */
    public static function isTestDomain( $rootPageId ) {

        $objPage = null;
        $objPage = PageModel::findById($rootPageId);

        $domain = $objPage->dns?:Environment::get('host');

        if( $objPage && $objPage->type == 'root' ) {

            $oCrypto = null;
            $oCrypto = new Crypto($objPage->cms_root_key);

            if( $oCrypto->verify($objPage->cms_root_data, $objPage->cms_root_sign) ) {

                $data = $oCrypto->decryptPublic($objPage->cms_root_data);
                $data = StringUtil::deserialize($data);

                if( !empty($data['test_domains']) ) {
                    $domain = strrev($domain);
                    foreach( $data['test_domains'] as $testDomain ) {
                        if( !empty($testDomain) && strlen($testDomain) && stripos($domain, strrev($testDomain))===0 ) {
                            return true;
                        }
                    }
                }
            }
        }

        return false;
    }


    /**
     * Lists all licenses with their expire dates
     *
     * @return array
     */
    public static function expires() {

        $objPages = null;
        $objPages = PageModel::findByType('root');

        $expires = [];

        if( $objPages ) {

            foreach( $objPages as $value ) {

                if( empty($value->cms_root_license) ) {
                    continue;
                }

                $expires[$value->cms_root_license] = ['page' => $value->id];

                if( empty($value->cms_root_license) || empty($value->cms_root_key) || empty($value->cms_root_data) || empty($value->cms_root_sign) ) {
                    continue;
                }

                $crypt = new Crypto($value->cms_root_key);

                if( !$crypt->verify($value->cms_root_data, $value->cms_root_sign) ) {
                    continue;
                }

                $data = $crypt->decryptPublic($value->cms_root_data);
                $data = StringUtil::deserialize($data);

                if( !is_array($data) || empty($data['features']) || empty($data['expires']) || empty($data['expires_package']) ) {
                    continue;
                }

                $expires[$value->cms_root_license] = [
                    'expires' => $data['expires']
                ,   'expires_package' => $data['expires_package']
                ,   'page' => $value->id
                ];

            }
        }

        return $expires;
    }


    /**
     * Checks for new version of the Marketing Suite bundle. Displays licenses
     * without data, licenses that will expire within 7 days or are already expired.
     *
     * @return string
     */
    public function getSystemMessages() {

        System::loadLanguageFile('cms_license');

        $disableUpdateMessage = System::getContainer()->getParameter('marketing_suite.disable_update_message');

        $aMessages = [];

        if( self::checkForUpdate() && $disableUpdateMessage !== true ) {

            $aMessages[] = '<p class="tl_info">'.sprintf($GLOBALS['TL_LANG']['cms_license']['new_version'], CMSConfig::get('latest_version')).'</p>';
        }

        $expireDates = self::expires();

        if( count($expireDates) ) {

            foreach( $expireDates as $key => $value ) {

                $pageEditUrl = 'contao?do=page&act=edit&id='.$value['page'].'&rt='.REQUEST_TOKEN.'#pal_cms_legend';
                $packageCMSUrl = "https://contao-marketingsuite.com";

                if( count($value) <= 1 ) {

                    $aMessages[] = '<p class="tl_error">'.sprintf($GLOBALS['TL_LANG']['cms_license']['no_data'], $key, $pageEditUrl).'</p>';
                    continue;
                }

                if( $value['expires'] < time() ) {

                    if( $value['expires_package'] <= time() ) {

                        $aMessages[] = '<p class="tl_error">' .
                            sprintf($GLOBALS['TL_LANG']['cms_license']['expired_package'],
                                $key
                            ,   Date::parse(Config::get('datimFormat'), $value['expires_package'])
                            ,   $packageCMSUrl
                            )
                            . '</p>';

                    } else {

                        $aMessages[] = '<p class="tl_error">' .
                            sprintf($GLOBALS['TL_LANG']['cms_license']['expired'],
                                $key
                            ,   Date::parse(Config::get('datimFormat'), $value['expires'])
                            ,   $pageEditUrl
                            )
                            . '</p>';
                    }

                } else if( $value['expires'] < time()+7*86400 ) {

                    if( $value['expires'] != $value['expires_package'] ) {

                        $aMessages[] = '<p class="tl_error">' .
                            sprintf($GLOBALS['TL_LANG']['cms_license']['no_check'],
                                $key
                            ,   $pageEditUrl
                            )
                            . '</p>';
                    } else {

                        $aMessages[] = '<p class="tl_info">' .
                            sprintf($GLOBALS['TL_LANG']['cms_license']['will_expire'],
                                $key
                            ,   Date::parse(Config::get('datimFormat'), $value['expires'])
                            ,   $packageCMSUrl
                            )
                            . '</p>';
                    }
                }
            }
        }

        if( self::hasNoLicense() ) {

            $helpUrl = "https://contao-marketingsuite.com/support/wy372o";

            $aMessages[] = '<p class="tl_error">' .
                sprintf($GLOBALS['TL_LANG']['cms_license']['no_license']
                ,   $helpUrl
                )
                . '</p>';
        }

        if( count($aMessages) ) {
            return implode('', $aMessages);
        }

        return '';
    }


    /**
     * Checks if there is a newer version of the bundle available
     *
     * @return boolean
     */
    public static function checkForUpdate() {
return true;
        $latestVersion = CMSConfig::get('latest_version');
        $lastCheck = CMSConfig::get('last_version_check');

        if( $lastCheck > time() ) {
            $lastCheck = 0;
        }

        if( !$latestVersion || !$lastCheck || $lastCheck < time()-86000 ) {

            $oAPI = new API();
            $oAPI->getLatestVersion();
            $latestVersion = CMSConfig::get('latest_version');
        }

        if( CMS_VERSION && $latestVersion ) {

            if( self::isInMajorVersion($latestVersion, CMS_VERSION) && version_compare(CMS_VERSION, $latestVersion, '<') ) {
                return true;
            }
        }

        return false;
    }


    /**
     * Checks if there is no license at all
     *
     * @return boolean
     */
    public static function hasNoLicense() {

        $numLicense = PageModel::countBy(['cms_root_license!=?'], ['']);

        if( !$numLicense ) {
            return true;
        }

        return false;
    }


    /**
     * Checks if the given root page id has a license
     *
     * @return boolean
     */
    public static function hasLicense( $pageId ) {

        $numLicense = PageModel::countBy(['cms_root_license!=? AND id=?'], ['', $pageId]);

        if( $numLicense ) {
            return true;
        }

        return false;
    }


    /**
     * Performs daily actions
     */
    public static function dailyCron() {

        $objPages = PageModel::findByType('root');
        $lastChecks = StringUtil::deserialize(CMSConfig::get('last_checks'));
        $lastChecksUp=[];

        if( $objPages ) {

            foreach( $objPages as $value ) {

                if( empty($value->cms_root_license) ) {
                    continue;
                }

                $lastCheck = 0;

                if( !empty($lastChecks[$value->cms_root_license]) && $lastChecks[$value->cms_root_license] < time() ) {
                    $lastCheck = $lastChecks[$value->cms_root_license];
                }

                if( $lastCheck < time()-86000 ) {

                    $oAPI = null;
                    $oAPI = new API();

                    try {

                        if( $oAPI->checkLicense($value->cms_root_license, $value->current()) ) {
                            $oAPI->getFeatures($value->cms_root_license, $value->current());
                        }

                    } catch( Exception $e ) {
                    }

                    $lastChecksUp[$value->cms_root_license] = time();

                } else {

                    $lastChecksUp[$value->cms_root_license] = $lastCheck;
                }
            }

            if( $lastChecksUp ) {
                CMSConfig::persist('last_checks', serialize($lastChecksUp));
            }
        }
    }


    /**
     * Performs weekly actions
     */
    public static function weeklyCron() {

        $lastRun = CMSConfig::get('weekly_run');

        if( !$lastRun || $lastRun < strtotime("-1 week") ) {

            // send usage
            if( CMSConfig::get('send_anonymized_data') == '1' ) {

                $oAPI = NULL;
                $oAPI = new API();

                try {
                    $oAPI->sendUsageData();
                } catch( Exception $e ) {
                }
            }

            CMSConfig::persist('weekly_run', time());
        }
    }


    /**
     * Check if the given version belongs to the same major version
     *
     * @param string $version
     * @param string $major
     *
     * @return bool
     */
    private static function isInMajorVersion( string $version, string $major ): bool {

        $major = intval($major);

        return version_compare($major.'.0.0', $version, '<=') && version_compare(($major+1).'.0.0', $version, '>');
    }
}
