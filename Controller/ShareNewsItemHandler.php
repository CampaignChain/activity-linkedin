<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) CampaignChain, Inc. <info@campaignchain.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CampaignChain\Activity\LinkedInBundle\Controller;

use CampaignChain\Channel\LinkedInBundle\REST\LinkedInClient;
use CampaignChain\CoreBundle\Controller\Module\AbstractActivityHandler;
use CampaignChain\CoreBundle\Util\ParserUtil;
use CampaignChain\Operation\LinkedInBundle\EntityService\NewsItem;
use CampaignChain\Operation\LinkedInBundle\Job\ShareNewsItem;
use Guzzle\Http\Client;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use Symfony\Component\Form\Form;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Operation\LinkedInBundle\Entity\NewsItem as NewsItemEntity;

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
            if(is_array($data)) {
                // If the news item has already been created, we modify its data.
                $newsItem = $this->contentService->getNewsItemByOperation($operation);

                $newsItem->setMessage($data['message']);
            }

            $newsItem = $this->searchUrl($newsItem);

        } catch (\Exception $e){
            // News item has not been created yet, so do it from the form data.
            $newsItem = $data;
            $newsItem = $this->searchUrl($newsItem);
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

    /**
     * Search for a url in the message,
     * if we find one, then we fetch it
     *
     * @param NewsItemEntity $newsItem entity from the DB or the form context
     * @param NewsItemEntity $data entity from the form
     * @return NewsItemEntity
     */
    private function searchUrl(NewsItemEntity $newsItem)
    {
        $pattern = '/[-a-zA-Z0-9:%_\+.~#?&\/\/=]{2,256}\.[a-z]{2,10}\b(\/[-a-zA-Z0-9:%_\+.~#?&\/\/=]*)?/i';

        if (!preg_match($pattern, $newsItem->getMessage(), $matches)) {
            return $newsItem;
        }

        $url = $matches[0];

        $result = $this->scrapeUrl($url);

        if (empty($result)) {
            $newsItem->setUrl(null);
            $newsItem->setLinkDescription(null);
            $newsItem->setLinkTitle(null);

            return $newsItem;
        }

        $newsItem->setLinkUrl(ParserUtil::sanitizeUrl($url));
        $newsItem->setLinkTitle($result['title']);
        $newsItem->setLinkDescription($result['description']);

        return $newsItem;
    }

    /**
     * Extract title and description from the URL
     *
     * @param $url
     * @return array
     */
    private function scrapeUrl($url)
    {
        if (!parse_url($url, PHP_URL_HOST)) {
            $url = 'http://' . $url;
        }

        $client = new Client($url);
        $guzzleRequest = $client->get();

        try {
            $response = $guzzleRequest->send();
        } catch(\Exception $e) {
            return [];
        }

        $crawler = new Crawler($response->getBody(true));

        $description = '';
        foreach ($crawler->filter('meta') as $node) {
            if ($node->getAttribute('name') == 'description') {
                $description = $node->getAttribute('content');
            }
        }

        return [
            'title' => trim($crawler->filter('title')->text()),
            'description' => $description,
        ];
    }
}