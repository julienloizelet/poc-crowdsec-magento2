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

namespace CrowdSec\Engine\Block\Adminhtml\System\Config\Cache;

use CrowdSec\Engine\Block\Adminhtml\System\Config\Button;
use Magento\Framework\Data\Form\Element\AbstractElement;

class Clear extends Button
{

    /** @var string  */
    protected $oldTemplate = 'CrowdSec_Engine::system/config/cache/old/clear.phtml';
    /** @var string  */
    protected $template = 'CrowdSec_Engine::system/config/cache/clear.phtml';

    /**
     * Get the button and scripts contents
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $originalData = $element->getOriginalData();
        $oldCacheSystem = $this->helper->getCacheTechnology();
        $cacheOptions = $this->helper->getCacheSystemOptions();
        $oldCacheLabel = $cacheOptions[$oldCacheSystem] ?? __('Unknown');
        $buttonLabel = $oldCacheLabel ? __('Clear cache (%1)', $oldCacheLabel) : $originalData['button_label'];
        $this->addData(
            [
                'button_label' => $buttonLabel,
                'html_id' => $element->getHtmlId(),
                'ajax_url' => $this->_urlBuilder->getUrl('crowdsec-engine/system_config_cache/clear'),
            ]
        );

        return $this->_toHtml();
    }
}
