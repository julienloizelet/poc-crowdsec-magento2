<?php declare(strict_types=1);
/**
 * CrowdSec_Engine Extension
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the MIT LICENSE
 * that is bundled with this package in the file LICENSE
 *
 * @category   CrowdSec
 * @package    CrowdSec_Engine
 * @copyright  Copyright (c)  2023+ CrowdSec
 * @author     CrowdSec team
 * @see        https://crowdsec.net CrowdSec Official Website
 * @license    MIT LICENSE
 *
 */

/**
 *
 * @category CrowdSec
 * @package  CrowdSec_Engine
 * @module   Engine
 * @author   CrowdSec team
 *
 */

namespace CrowdSec\Engine\Observer;

use Magento\Framework\App\Response\Http;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use CrowdSec\Engine\Helper\Data as Helper;
use CrowdSec\Engine\CapiEngine\Remediation;
use Magento\Framework\HTTP\PhpEnvironment\Response;
use CrowdSec\Engine\Constants;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Cms\Api\GetBlockByIdentifierInterface as BlockGetter;
use CrowdSec\Engine\Setup\Patch\Data\CreateCmsBanBlock;
use Magento\Framework\Filter\Template;
use Psr\Cache\CacheException;
use Psr\Cache\InvalidArgumentException;

class BounceIp implements ObserverInterface
{

    /**
     * @var BlockGetter
     */
    private $blockGetter;
    private $filterTemplate;
    /**
     * @var Helper
     */
    private $helper;
    /**
     * @var Remediation
     */
    private $remediation;
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    public function __construct(
        Helper $helper,
        Remediation $remediation,
        StoreManagerInterface $storeManager,
        BlockGetter $blockGetter,
        Template $filterTemplate
    ) {
        $this->helper = $helper;
        $this->remediation = $remediation;
        $this->storeManager = $storeManager;
        $this->blockGetter = $blockGetter;
        $this->filterTemplate = $filterTemplate;
    }

    /**
     * @throws CacheException
     * @throws InvalidArgumentException
     */
    public function execute(Observer $observer): BounceIp
    {
        try {
            if (!$this->helper->shouldBounceBan()) {
                return $this;
            }

            $ip = $this->helper->getRealIp();
            $remediation = $this->remediation->getIpRemediation($ip);
            if ($remediation === Constants::REMEDIATION_BAN) {
                /**
                 * @var $response Response
                 */
                $response = $observer->getEvent()->getResponse();
                $response->setNoCacheHeaders();
                $storeId = $this->storeManager->getStore()->getId();
                $banBlock = $this->blockGetter->execute(CreateCmsBanBlock::CMS_BLOCK_BAN, (int)$storeId);

                $content = '<div>IP banned by CrowdSec Engine</div>';
                if ($html = $banBlock->getContent()) {
                    $array['ip'] = $ip;
                    $this->filterTemplate->setVariables($array);
                    $content = $this->filterTemplate->filter($html);
                }

                $response->setBody($content)->setStatusCode(Http::STATUS_CODE_403);
            }
        } catch (\Exception $e) {
            $this->helper->getLogger()->critical('Technical error while bouncing ip', ['message' => $e->getMessage()]);
        }

        return $this;
    }
}
