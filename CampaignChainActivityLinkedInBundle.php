<?php

namespace CampaignChain\Activity\LinkedInBundle;

use CampaignChain\Activity\LinkedInBundle\DependencyInjection\CampaignChainActivityLinkedInExtension;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class CampaignChainActivityLinkedInBundle extends Bundle
{
    public function getContainerExtension()
    {
        return new CampaignChainActivityLinkedInExtension();
    }
}
