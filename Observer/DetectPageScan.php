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

use CrowdSec\Engine\Helper\Data as Helper;
use CrowdSec\Engine\Scenarios\PagesScan;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

class DetectPageScan implements ObserverInterface
{

    /**
     * @var Helper
     */
    private $helper;
    /**
     * @var PagesScan
     */
    private $scenario;

    public function __construct(
        Helper $helper,
        PagesScan $scenario
    ) {
        $this->helper = $helper;
        $this->scenario = $scenario;
    }

    public function execute(Observer $observer): DetectPageScan
    {
        $scenarioName = $this->scenario->getName();
        if (!$this->helper->isScenarioEnabled($scenarioName)) {
            return $this;
        }

        //@TODO try catch all and log error

        /**
         * @var $response \Magento\Framework\HTTP\PhpEnvironment\Response
         */
        $response = $observer->getEvent()->getResponse();

        $this->scenario->process($response);


        return $this;
    }
}
