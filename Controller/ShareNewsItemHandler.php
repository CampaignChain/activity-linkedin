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
use Doctrine\ORM\EntityManager;
use Guzzle\Http\Client;
use Symfony\Bundle\TwigBundle\TwigEngine;
use Symfony\Component\DomCrawler\Crawler;
use CampaignChain\CoreBundle\Entity\Operation;
use Symfony\Component\Form\Form;
use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\Operation\LinkedInBundle\Entity\NewsItem as NewsItemEntity;
use Symfony\Component\HttpFoundation\Session\Session;

/**
 * Class ShareNewsItemHandler
 * @package CampaignChain\Activity\LinkedInBundle\Controller
 */
class ShareNewsItemHandler extends AbstractActivityHandler
{
    /** @var NewsItem  */
    protected $contentService;

    /** @var LinkedInClient  */
    protected $restClient;

    /** @var ShareNewsItem  */
    protected $job;

    /** @var TwigEngine  */
    protected $templating;

    /** @var Session  */
    protected $session;

    /** @var EntityManager  */
    protected $entityManager;

    /**
     * ShareNewsItemHandler constructor.
     * @param NewsItem $contentService
     * @param LinkedInClient $restClient
     * @param ShareNewsItem $job
     * @param TwigEngine $templating
     * @param Session $session
     * @param EntityManager $entityManager
     */
    public function __construct(
        NewsItem $contentService,
        LinkedInClient $restClient,
        ShareNewsItem $job,
        TwigEngine $templating,
        Session $session,
        EntityManager $entityManager
    )
    {
        $this->contentService = $contentService;
        $this->restClient = $restClient;
        $this->job = $job;
        $this->templating = $templating;
        $this->session = $session;
        $this->entityManager = $entityManager;
    }

    /**
     * @param Location $location
     * @param Operation|null $operation
     * @return NewsItemEntity|null
     * @throws \Exception
     */
    public function getContent(Location $location, Operation $operation = null)
    {
        if($operation) {
            return $this->contentService->getNewsItemByOperation($operation);
        }

        return null;
    }

    /**
     * @param Operation $operation
     * @param Form $data
     * @return array|NewsItemEntity|Form
     */
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

    /**
     * @param Operation $operation
     * @param Form $form
     * @param null $content
     */
    public function postPersistNewEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    /**
     * @param Operation $operation
     * @param Form $form
     * @param null $content
     */
    public function postPersistEditEvent(Operation $operation, Form $form, $content = null)
    {
        // Content to be published immediately?
        $this->publishNow($operation, $form);
    }

    /**
     * @param Operation $operation
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Exception
     */
    public function readAction(Operation $operation)
    {
        $newsItem = $this->contentService->getNewsItemByOperation($operation);
        $activity = $operation->getActivity();
        $locationModuleIdentifier = $activity->getLocation()->getLocationModule()->getIdentifier();
        $isCompanyPageShare = 'campaignchain-linkedin-page' == $locationModuleIdentifier;

        $isLive = true;

        if(!$newsItem->getLinkedinData()){
            $connection = $this->restClient->getConnectionByActivity($activity);
            $response = $connection->getCompanyUpdate($activity, $newsItem);
            if (!is_null($response)) {
                $newsItem->setLinkedinData($response);

                $this->entityManager->persist($newsItem);
                $this->entityManager->flush();
            } else {
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
                'is_company' => $isCompanyPageShare,
            ));
    }

    /**
     * @param Operation $operation
     * @param Form $form
     * @return bool
     * @throws \Exception
     */
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