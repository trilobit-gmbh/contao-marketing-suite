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
 * @copyright 2019 numero2 - Agentur für digitales Marketing
 */


namespace numero2\MarketingSuiteBundle\Controller;


use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use numero2\MarketingSuite\LinkShortenerStatisticsModel;


class LinkShortenerController {


    /**
     * Will be called when a matching route was found und will redirect to the target
     *
     * @param  LinkShortenerModel $_content
     * @param  Request $request
     *
     * @return Response
     */
    public function __invoke($_content, Request $request) {

        // log request for statistics
        $oAgent = \Environment::get('agent');

        $oStats = new LinkShortenerStatisticsModel();

        $oStats->tstamp = time();
        $oStats->pid = $_content->id;
        $oStats->referer = $request->headers->get('referer');
        $oStats->unique_id = md5($request->getClientIp().$oAgent->string);
        $oStats->user_agent = $oAgent->string;
        $oStats->os = $oAgent->os;
        $oStats->browser = $oAgent->browser;
        $oStats->is_mobile = ($oAgent->mobile?'1':'');
        $oStats->is_bot = '';

        if( $oStats->browser == "other" ){

            $data = json_decode(file_get_contents(TL_ROOT.'/vendor/numero2/contao-marketing-suite/src/Resources/vendor/crawler-user-agents/crawler-user-agents.json'), true);

            foreach( $data as $entry ) {
                if( preg_match('/'.$entry['pattern'].'/', $oAgent->string) ) {
                    $oStats->is_bot = '1';
                    break;
                }
            }
        }

        $oStats->save();

        return new RedirectResponse(
            $_content->getTarget(),
            Response::HTTP_FOUND,
            [
                'Cache-Control' => 'no-cache',
            ]
        );
    }
}
