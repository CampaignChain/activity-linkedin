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

use CampaignChain\CoreBundle\Entity\Location;
use CampaignChain\CoreBundle\Entity\Medium;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Session\Session;
use CampaignChain\CoreBundle\Entity\Operation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use CampaignChain\Operation\LinkedInBundle\Form\Type\ShareNewsItemOperationType;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\GetSetMethodNormalizer;

class ShareNewsItemController extends Controller
{
    const BUNDLE_NAME = 'campaignchain/activity-linkedin';
    const MODULE_IDENTIFIER = 'campaignchain-linkedin-share-news-item';
    const OPERATION_IDENTIFIER = self::MODULE_IDENTIFIER;

    public function readAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);
        $operationService = $this->get('campaignchain.operation.linkedin.news_item');
        $newsitem = $operationService->getNewsItemByOperation($operation);

        $isLive = true;

        if(!$newsitem->getLinkedinData()){
            try {
                $client = $this->container->get('campaignchain.channel.linkedin.rest.client');
                $connection = $client->connectByActivity($activity);

                // Get the data of the item as stored by Linkedin
                $request = $connection->get('people/~/network/updates/key='.$newsitem->getUpdateKey().'?format=json');
                $response = $request->send()->json();

                $newsitem->setLinkedinData($response);

                $this->getDoctrine()->getManager()->flush();
            } catch (\Exception $e) {
                $isLive = false;
            }
        }

        return $this->render(
            'CampaignChainOperationLinkedInBundle::read.html.twig',
            array(
                'page_title' => $activity->getName(),
                'news_item' => $newsitem,
                'activity' => $activity,
                'is_live' => $isLive,
            ));
    }
}