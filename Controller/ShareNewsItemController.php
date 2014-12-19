<?php
/*
 * This file is part of the CampaignChain package.
 *
 * (c) Sandro Groganz <sandro@campaignchain.com>
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

    public function newAction(Request $request)
    {
        $wizard = $this->get('campaignchain.core.activity.wizard');
        $campaign = $wizard->getCampaign();
        $activity = $wizard->getActivity();

        $activity->setEqualsOperation(true);

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $shareNewsItemOperation = new ShareNewsItemOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));
        $operationForms[] = array(
            'identifier' => self::OPERATION_IDENTIFIER,
            'form' => $shareNewsItemOperation,
            'label' => 'LinkedIn Message',
        );
        $activityType->setOperationForms($operationForms);
        $activityType->setCampaign($campaign);

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $activity = $wizard->end();

            // Get the operation module.
            $operationService = $this->get('campaignchain.core.operation');
            $operationModule = $operationService->getOperationModule('campaignchain/operation-linkedin', 'campaignchain-linkedin-share-news-item');

            // The activity equals the operation. Thus, we create a new operation with the same data.
            $operation = new Operation();
            $operation->setName($activity->getName());
            $operation->setActivity($activity);
            $activity->addOperation($operation);
            $operationModule->addOperation($operation);
            $operation->setOperationModule($operationModule);

            // The Operation creates a Location, i.e. the post
            // will be accessible through a URL after publishing.
            // Get the location module for the user stream.
            $locationService = $this->get('campaignchain.core.location');
            $locationModule = $locationService->getLocationModule(
                'campaignchain/location-linkedin',
                'campaignchain-linkedin-user'
            );

            $location = new Location();
            $location->setLocationModule($locationModule);
            $location->setParent($activity->getLocation());
            $location->setName($activity->getName());
            $location->setStatus(Medium::STATUS_UNPUBLISHED);
            $location->setOperation($operation);
            $operation->addLocation($location);

            // Get the status data from request.
            $status = $form->get(self::OPERATION_IDENTIFIER)->getData();
            // Link the status with the operation.
            $status->setOperation($operation);

            $repository = $this->getDoctrine()->getManager();

            // Make sure that data stays intact by using transactions.
            try {
                $repository->getConnection()->beginTransaction();

                $repository->persist($activity);
                $repository->persist($status);

                // We need the activity ID for storing the hooks. Hence we must flush here.
                $repository->flush();

                // TODO: Make sure that data stays intact. If below flushing does not work, we have to roll back above flush.
                $hookService = $this->get('campaignchain.core.hook');
                $activity = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $activity, $form, true);

                $repository->flush();

                $repository->getConnection()->commit();
            } catch (\Exception $e) {
                $repository->getConnection()->rollback();
                throw $e;
            }

            $this->get('session')->getFlashBag()->add(
                'success',
                'Your new LinkedIn activity <a href="'.$this->generateUrl('campaignchain_core_activity_edit', array('id' => $activity->getId())).'">'.$activity->getName().'</a> was created successfully.'
            );

            // Status Update to be sent immediately?
            // TODO: This is an intermediary hardcoded hack and should be instead handled by the scheduler.
            if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
                $job = $this->get('campaignchain.operation.linkedin.job.share_news_item');
                $job->execute($operation->getId());
                // TODO: Add different flashbag which includes link to posted message on Facebook
            }

            return $this->redirect($this->generateUrl('campaignchain_core_activities'));

            //return $this->redirect($this->generateUrl('task_success'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Operation:new.html.twig',
            array(
                'page_title' => 'New LinkedIn News Item',
                'activity' => $activity,
                'campaign' => $campaign,
                'channel_module' => $wizard->getChannelModule(),
                'channel_module_bundle' => $wizard->getChannelModuleBundle(),
                'location' => $wizard->getLocation(),
                'form' => $form->createView(),
                'form_submit_label' => 'Save',
                'form_cancel_route' => 'campaignchain_core_activities_new'
            ));

    }

    public function editAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);
        $operationService = $this->get('campaignchain.operation.linkedin.news_item');
        $newsitem = $operationService->getNewsItemByOperation($operation);

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $shareNewsItemOperation = new ShareNewsItemOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));
        $shareNewsItemOperation->setNewsItem($newsitem);
        $operationForms[] = array(
            'identifier' => self::OPERATION_IDENTIFIER,
            'form' => $shareNewsItemOperation,
            'label' => 'LinkedIn Message',
        );
        $activityType->setOperationForms($operationForms);
        $activityType->setCampaign($campaign);

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        if ($form->isValid()) {
            // Get the status data from request.
            $status = $form->get(self::OPERATION_IDENTIFIER)->getData();

            $repository = $this->getDoctrine()->getManager();

            // The activity equals the operation. Thus, we update the operation with the same data.
            $activityService = $this->get('campaignchain.core.activity');
            $operation = $activityService->getOperation($id);
            $operation->setName($activity->getName());
            $repository->persist($operation);

            $repository->persist($status);

            $hookService = $this->get('campaignchain.core.hook');
            $activity = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $activity, $form);
            $repository->persist($activity);

            $repository->flush();


            $this->get('session')->getFlashBag()->add(
                'success',
                'Your LinkedIn activity <a href="'.$this->generateUrl('campaignchain_core_activity_edit', array('id' => $activity->getId())).'">'.$activity->getName().'</a> was edited successfully.'
            );

            if ($form->get('campaignchain_hook_campaignchain_due')->has('execution_choice') && $form->get('campaignchain_hook_campaignchain_due')->get('execution_choice')->getData() == 'now') {
                $job = $this->get('campaignchain.operation.linkedin.job.share_news_item');
                $job->execute($operation->getId());
                // TODO: Add different flashbag which includes link to posted message on Facebook
            }

            return $this->redirect($this->generateUrl('campaignchain_core_activities'));
        }

        return $this->render(
            'CampaignChainCoreBundle:Operation:new.html.twig',
            array(
                'page_title' => 'Edit LinkedIn News Item',
                'activity' => $activity,
                'form' => $form->createView(),
                'form_submit_label' => 'Save',
                'form_cancel_route' => 'campaignchain_core_activities'
            ));
    }

    public function editModalAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);
        $operationService = $this->get('campaignchain.operation.linkedin.news_item');
        $newsitem = $operationService->getNewsItemByOperation($operation);

        $activityType = $this->get('campaignchain.core.form.type.activity');
        $activityType->setBundleName(self::BUNDLE_NAME);
        $activityType->setModuleIdentifier(self::MODULE_IDENTIFIER);
        $shareNewsItemOperation = new ShareNewsItemOperationType($this->getDoctrine()->getManager(), $this->get('service_container'));
        $shareNewsItemOperation->setNewsItem($newsitem);
        $operationForms[] = array(
            'identifier' => self::OPERATION_IDENTIFIER,
            'form' => $shareNewsItemOperation,
            'label' => 'LinkedIn News Item',
        );
        $activityType->setOperationForms($operationForms);
        $activityType->setCampaign($campaign);
        $activityType->setView('modal');

        $form = $this->createForm($activityType, $activity);

        $form->handleRequest($request);

        return $this->render(
            'CampaignChainCoreBundle:Base:new_modal.html.twig',
            array(
                'page_title' => 'Edit LinkedIn News Item',
                'form' => $form->createView(),
            ));
    }

    public function editApiAction(Request $request, $id)
    {
        $responseData = array();

        $data = $request->get('campaignchain_core_activity');

        $responseData['data'] = $data;

        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $activity->setName($data['name']);

        // The activity equals the operation. Thus, we update the operation with the same data.
        $operation = $activityService->getOperation($id);
        $operation->setName($data['name']);

        $operationService = $this->get('campaignchain.operation.linkedin.news_item');
        $newsitem = $operationService->getNewsItemByOperation($operation);

        $newsitem->setMessage($data[self::OPERATION_IDENTIFIER]['message']);

        $repository = $this->getDoctrine()->getManager();
        $repository->persist($activity);
        $repository->persist($operation);
        $repository->persist($status);

        $hookService = $this->get('campaignchain.core.hook');
        $activity = $hookService->processHooks(self::BUNDLE_NAME, self::MODULE_IDENTIFIER, $activity, $data);

        $repository->flush();

//        // Status Update should be sent immediately
//        if (isset($data['actions']['send']) && $data['actions']['send'] == 1) {
//            $job = $this->get('campaignchain.operation.linkedin.job.update_status');
//            $job->execute($operation);
//            // TODO: Add different flashbag which includes link to posted message on Facebook
//            // TODO: If this previously was a scheduled activity, then reset the schedule
//        }

        $encoders = array(new JsonEncoder());
        $normalizers = array(new GetSetMethodNormalizer());
        $serializer = new Serializer($normalizers, $encoders);

        $response = new Response($serializer->serialize($responseData, 'json'));
        return $response->setStatusCode(Response::HTTP_OK);
    }

    public function readAction(Request $request, $id)
    {
        $activityService = $this->get('campaignchain.core.activity');
        $activity = $activityService->getActivity($id);
        $campaign = $activity->getCampaign();

        // Get the one operation.
        $operation = $activityService->getOperation($id);
        $operationService = $this->get('campaignchain.operation.linkedin.news_item');
        $newsitem = $operationService->getNewsItemByOperation($operation);

        if(!$newsitem->getLinkedinData()){
            $client = $this->container->get('campaignchain.channel.linkedin.rest.client');
            $connection = $client->connectByActivity($activity);

            // Get the data of the item as stored by Linkedin
            $request = $connection->get('people/~/network/updates/key='.$newsitem->getUpdateKey().'?format=json');
            $response = $request->send()->json();
            $newsitem->setLinkedinData($response);

            $this->getDoctrine()->getManager()->flush();
        }

        return $this->render(
            'CampaignChainOperationLinkedInBundle::read.html.twig',
            array(
                'page_title' => $activity->getName(),
                'news_item' => $newsitem,
                'activity' => $activity,
            ));
    }
}