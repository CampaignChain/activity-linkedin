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
use CampaignChain\CoreBundle\Controller\Module\AbstractActivityHandler;
use CampaignChain\Operation\LinkedInBundle\EntityService\NewsItem;
use CampaignChain\Operation\LinkedInBundle\Job\ShareNewsItem;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use Symfony\Component\Form\Form;
use CampaignChain\CoreBundle\Entity\Location;

class ShareNewsItemHandler extends AbstractActivityHandler
{
    protected $contentService;
    protected $restClient;
    protected $job;
    protected $templating;

    public function __construct(
        NewsItem $contentService,
        LinkedInClient $restClient,
        ShareNewsItem $job,
        TwigEngine $templating
    )
    {
        $this->contentService = $contentService;
        $this->restClient = $restClient;
        $this->job = $job;
        $this->templating = $templating;
    }

    public function getContent(Location $location, Operation $operation = null)
    {
        if($operation) {
            return $this->contentService->getNewsItemByOperation($operation);
        }

        return null;
    }

    public function processContent(Operation $operation, $data)
    {
        try {
            // If the news item has already been created, we modify its data.
            $newsItem = $this->contentService->getNewsItemByOperation($operation);
            $newsItem->setMessage($data['message']);
            $newsItem->setLinkUrl($data['submitUrl']);
            $newsItem->setLinkTitle($data['linkTitle']);
            $newsItem->setLinkDescription($data['description']);
        } catch (\Exception $e){
            // News item has not been created yet, so do it from the form data.
            $newsItem = $data;
        }

        return $newsItem;
    }

    public function postPersistNewEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    public function postPersistEditEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    public function readAction(Operation $operation)
    {
        $newsItem = $this->contentService->getNewsItemByOperation($operation);
        $activity = $operation->getActivity();

        $isLive = true;

        if(!$newsItem->getLinkedinData()){
            try {
                $connection = $this->restClient->connectByActivity($activity);

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

    private function publishNow(Operation $operation, Form $form)
    {
        if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
            $this->job->execute($operation->getId());
            $content = $this->contentService->getNewsItemByOperation($operation);
            $this->session->getFlashBag()->add(
                'success',
                'The news item was published. <a href="'.$content->getUrl().'">View it on Linkedin</a>.'
            );

            return true;
        }

        return false;
    }
}