<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2022 Leo Feyer
 *
 * @package   Contao Marketing Suite
 * @author    Benny Born <benny.born@numero2.de>
 * @author    Michael Bösherz <michael.boesherz@numero2.de>
 * @license   Commercial
 * @copyright 2022 numero2 - Agentur für digitales Marketing
 */


namespace numero2\MarketingSuite;

use Contao\DataContainer;
use Contao\StringUtil;
use numero2\MarketingSuite\Helper\ContentElementStyleable as Helper;
use numero2\MarketingSuite\Helper\InterfaceStyleable;
use numero2\MarketingSuite\Helper\TraitContentElementStyleable;


class ContentButton extends ContentHyperlink implements InterfaceStyleable {


    use TraitContentElementStyleable;


    /**
     * Template
     * @var string
     */
    protected $strTemplate = 'ce_cms_button';

    /**
     * Marker if style preview is enabled
     * @var boolean
     */
    public $isStylePreview = false;


    /**
     * Generate the content element
     */
    protected function compile() {

        $this->Template->layout = $this->cms_layout_selector;

        // set default values for styling preview
        if( $this->isStylePreview ) {

            if( !$this->url && !$this->linkTitle ) {

                $this->url = '#';
                $this->linkTitle = 'Button';
            }

            $this->titleText = $this->titleText?:'Tooltip';
        }

        if( !$this->isStylePreview ) {

            $this->Template->cmsID = Helper::getUniqueID($this);
            $this->injectStylesheet();
        }

        parent::compile();
    }
}
