<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\LinkedInBundle\Controller;

use CampaignChain\Channel\LinkedInBundle\REST\LinkedInClient;
use CampaignChain\CoreBundle\Controller\Module\ActivityModuleHandlerInterface;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Operation\LinkedInBundle\EntityService\NewsItem;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;

class ShareNewsItemHandler implements ActivityModuleHandlerInterface
{
    protected $detailService;
    protected $restClient;
    protected $templating;

    public function __construct(
        NewsItem $detailService,
        TwigEngine $templating,
        LinkedInClient $restClient
    )
    {
        $this->detailService = $detailService;
        $this->templating = $templating;
        $this->restClient = $restClient;
    }

    public function getOperationDetail(Location $location, Operation $operation = null)
    {
        if($operation) {
            return $this->detailService->getNewsItemByOperation($operation);
        }

        return null;
    }

    public function processOperationDetail(Operation $operation, $data)
    {
        $newsItem = $this->detailService->getNewsItemByOperation($operation);
        $newsItem->setMessage($data['message']);
        $newsItem->setLinkUrl($data['submitUrl']);
        $newsItem->setLinkTitle($data['linkTitle']);
        $newsItem->setLinkDescription($data['description']);
        return $newsItem;
    }

    public function readOperationDetail(Operation $operation)
    {
        $newsItem = $this->detailService->getNewsItemByOperation($operation);
        $activity = $operation->getActivity();

        $isLive = true;

        if(!$newsItem->getLinkedinData()){
            try {
                $client = $this->container->get('campaignchain.channel.linkedin.rest.client');
                $connection = $client->connectByActivity($activity);

                // Get the data of the item as stored by Linkedin
                $request = $connection->get('people/~/network/updates/key='.$newsItem->getUpdateKey().'?format=json');
                $response = $request->send()->json();

                $newsItem->setLinkedinData($response);

                $this->getDoctrine()->getManager()->flush();
            } catch (\Exception $e) {
                $isLive = false;
            }
        }

        return $this->templating->renderResponse(
            'CampaignChainOperationLinkedInBundle::read.html.twig',
            array(
                'page_title' => $activity->getName(),
                'news_item' => $newsItem,
                'activity' => $activity,
                'is_live' => $isLive,
            ));
    }
}