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

namespace CrowdSec\Engine\Helper;

use CrowdSec\CapiClient\ClientException;
use CrowdSec\Engine\Api\Data\EventInterface;
use CrowdSec\Engine\Api\EventRepositoryInterface;
use CrowdSec\Engine\CapiEngine\Storage;
use CrowdSec\Engine\CapiEngine\Watcher;
use CrowdSec\Engine\Constants;
use CrowdSec\Engine\Helper\Data as Helper;
use CrowdSec\Engine\Logger\Logger;
use CrowdSec\Engine\Model\EventFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SortOrder;
use Magento\Framework\Api\SortOrderFactory;
use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\Event\Manager;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class Event extends AbstractHelper
{
    /**
     * @var Manager
     */
    protected $eventManager;
    /**
     * @var EventFactory
     */
    private $eventFactory;
    /**
     * @var EventRepositoryInterface
     */
    private $eventRepository;
    /**
     * @var Helper
     */
    private $helper;
    /**
     * @var array
     */
    private $lastEvent = [];
    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;
    /**
     * @var SortOrderFactory
     */
    private $sortOrderFactory;
    /**
     * @var Storage
     */
    private $storage;

    /**
     * Data constructor.
     *
     * @param Context $context
     * @param Helper $helper
     * @param EventFactory $eventFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param SortOrderFactory $sortOrderFactory
     * @param EventRepositoryInterface $eventRepository
     * @param Storage $storage
     * @param Manager $eventManager
     */
    public function __construct(
        Context $context,
        Helper $helper,
        EventFactory $eventFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        SortOrderFactory $sortOrderFactory,
        EventRepositoryInterface $eventRepository,
        Storage $storage,
        Manager $eventManager
    ) {
        $this->helper = $helper;
        $this->eventFactory = $eventFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->sortOrderFactory = $sortOrderFactory;
        $this->eventRepository = $eventRepository;
        $this->storage = $storage;
        $this->eventManager = $eventManager;

        parent::__construct($context);
    }

    /**
     * Helper to add an event with "alert_triggered" status in database.
     *
     * @param array $alert
     * @return bool
     */
    public function addAlertToQueue(array $alert): bool
    {
        $result = false;
        try {
            $currentTime = time();
            if ($this->validateAlert($alert)) {
                // Index definition must be guaranteed by the validateAlert method
                $ip = $alert['ip'];
                $scenario = $alert['scenario'];
                $event = $this->getLastEvent($ip, $scenario);

                if (!$event->getId()) {
                    $this->saveAlertEvent($event, $alert, $currentTime);
                    $result = true;

                } elseif ($event->getStatusId() === EventInterface::STATUS_SIGNAL_PUSHED &&
                                    !$this->isInBlackHole(time(), $event, EventInterface::BLACK_HOLE_DEFAULT)) {
                    $freshEvent = $this->eventFactory->create();
                    $freshEvent->setIp($event->getIp())->setScenario($event->getScenario());
                    $this->saveAlertEvent($freshEvent, $alert, $currentTime);
                    $result = true;

                } elseif ($event->getStatusId() === EventInterface::STATUS_ALERT_TRIGGERED) {
                    $this->helper->getLogger()->debug('Alert already in queue', ['event_id' => $event->getId()]);
                }
            }
        } catch (\Exception $e) {
            $this->helper->getLogger()->error('Error while adding alert to queue', ['message' => $e->getMessage()]);
        }

        return $result;
    }

    /**
     * Retrieve last event for some ip and scenario.
     *
     * If there is no saved event, return an empty event.
     *
     * @param string $ip
     * @param string $scenario
     * @return EventInterface
     * @throws InputException
     * @throws LocalizedException
     */
    public function getLastEvent(string $ip, string $scenario): EventInterface
    {
        if (!isset($this->lastEvent[$ip][$scenario])) {
            $sort = $this->sortOrderFactory->create()
                ->setField(EventInterface::LAST_EVENT_DATE)
                ->setDirection(SortOrder::SORT_DESC);

            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter(EventInterface::SCENARIO, $scenario)
                ->addFilter(EventInterface::IP, $ip)
                ->setPageSize(1)
                ->setCurrentPage(1)
                ->setSortOrders([$sort])
                ->create();

            $events = $this->eventRepository->getList($searchCriteria);
            $firstItem = current($events->getItems());

            $this->lastEvent[$ip][$scenario] = is_object($firstItem) && $firstItem->getId() ? $firstItem :
                $this->eventFactory->create()->setIp($ip)->setScenario($scenario);
        }

        return $this->lastEvent[$ip][$scenario];
    }

    /**
     * Retrieve the leaking bucket count.
     *
     * @param int $currentTime // timestamp
     * @param int $lastBucketFill
     * @param int $lastEventTime // timestamp
     * @param int $leakSpeed // in seconds
     * @return int
     */
    public function getLeakingBucketCount(
        int $currentTime,
        int $lastBucketFill,
        int $lastEventTime,
        int $leakSpeed
    ): int {
        $bucketFill = $lastBucketFill - floor(($currentTime - $lastEventTime) / $leakSpeed);

        return $bucketFill < 0 ? 0 : (int)$bucketFill;
    }

    /**
     * Retrieve the logger.
     *
     * @return Logger
     */
    public function getLogger(): Logger
    {
        return $this->helper->getLogger();
    }

    /**
     * An event is in "black hole" when the last event is too recent
     *
     * @param int $time
     * @param EventInterface $event
     * @param int $blackHoleDuration
     * @return bool
     */
    public function isInBlackHole(int $time, EventInterface $event, int $blackHoleDuration): bool
    {
        $lastEventDate = (int)strtotime($event->getLastEventDate());
        $result = $lastEventDate + $blackHoleDuration > $time;
        if ($result) {
            $this->helper->getLogger()->debug(
                'Event is in black hole',
                [
                    'event_id' => $event->getId(),
                    'last_event_date' => $lastEventDate,
                    'time' => $time,
                    'black_hole_duration' => $blackHoleDuration
                ]
            );
        }

        return $result;
    }

    /**
     * Push signals to CAPI
     *
     * @param Watcher $watcher
     * @param int $max
     * @param int $maxError
     * @param int $timeDelay
     * @return void
     * @throws LocalizedException
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function pushSignals(Watcher $watcher, int $max, int $maxError, int $timeDelay): array
    {
        $result = ['candidates' => 0, 'pushed' => 0, 'errors' => 0];
        $lastPush = $this->storage->retrieveLastPush();

        if ($lastPush + $timeDelay > time()) {
            // It's too early, wait for the next round.
            $this->helper->getLogger()->debug('Last push is too recent', ['delay' => $timeDelay, $result]);

            return $result;
        }

        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(EventInterface::STATUS_ID, EventInterface::STATUS_ALERT_TRIGGERED)
            ->addFilter(EventInterface::ERROR_COUNT, $maxError, 'lteq')
            ->create();

        $events = $this->eventRepository->getList($searchCriteria)->getItems();
        $result['candidates'] = count($events);

        $signals = [];
        $pushed = [];
        $i = 0;
        /**
         * Event.
         *
         * @var $event \CrowdSec\Engine\Model\Event
         */
        while ($event = array_shift($events)) {
            $i++;
            if ($i > $max) {
                break;
            }
            $pushed[$event->getId()] = true;

            $lastEventTime = (int)strtotime($event->getLastEventDate());

            $signalDate = (new \DateTime())->setTimestamp($lastEventTime);
            try {
                $context = $event->getContext();
                $duration = $context['duration'] ?? Constants::DURATION;

                $signals[] = $watcher->buildSimpleSignalForIp(
                    $event->getIp(),
                    $event->getScenario(),
                    $signalDate,
                    '',
                    $duration
                );
            } catch (ClientException $e) {
                unset($pushed[$event->getId()]);
                $errorCount = $event->getErrorCount() + 1;
                $event->setErrorCount($errorCount);
                $this->eventRepository->save($event);
                $result['errors'] += 1;

                $this->helper->getLogger()->info(
                    'Error while build signal for event',
                    $event->toArray(['event_id', 'ip', 'scenario', 'last_event_date', 'context', 'error_count'])
                );
            }
        }

        if ($signals) {
            $pushedIds = array_keys($pushed);
            try {
                $watcher->pushSignals($signals);

                $this->storage->storeLastPush(time());

                $this->eventRepository->massUpdateByIds(
                    ['status_id' => EventInterface::STATUS_SIGNAL_PUSHED],
                    $pushedIds
                );

                $result['pushed'] += count($pushedIds);

            } catch (ClientException $e) {
                $this->eventRepository->massUpdateByIds(
                    ['error_count' => new \Zend_Db_Expr('error_count + 1')],
                    $pushedIds
                );
                $result['errors'] += count($pushedIds);

                $this->helper->getLogger()->error('Error while pushing signals', ['candidates' => $pushedIds]);
            }
        }
        $this->helper->getLogger()->info('Signals have been pushed', $result);

        return $result;
    }

    /**
     * Save an event for some alert.
     *
     * @param EventInterface $event
     * @param array $alert
     * @param int $currentTime
     * @return EventInterface
     * @throws LocalizedException
     */
    private function saveAlertEvent(EventInterface $event, array $alert, int $currentTime): EventInterface
    {
        $event
            ->setStatusId(EventInterface::STATUS_ALERT_TRIGGERED)
            ->setContext(array_merge($event->getContext(), ['duration' => $this->helper->getBanDuration()]))
            ->setCount(1);

        $lastEventDate = date('Y-m-d h:i:s', $alert['last_event_date'] ?? $currentTime);

        $event->setLastEventDate($lastEventDate);

        $this->helper->getLogger()->debug(
            'Triggered alert will be saved',
            [
                'ip' => $event->getIp(),
                'scenario' => $event->getScenario()
            ]
        );

        return $this->eventRepository->save($event);
    }

    /**
     * Basic checks for alert validation.
     *
     * @param array $alert
     * @return bool
     */
    private function validateAlert(array $alert): bool
    {
        $result = true;
        $messageSlug = 'Error while adding triggered alert';

        if (empty($alert['ip']) || empty($alert['scenario'])) {
            $this->helper->getLogger()->debug($messageSlug, ['message' => 'Ip and Scenario are required']);
            $result = false;
        } elseif (1 !== preg_match(Constants::SCENARIO_REGEX, $alert['scenario'])) {
            $this->helper->getLogger()->debug(
                $messageSlug,
                ['message' => 'Scenario name does not conform to the convention']
            );
            $result = false;
        } elseif (!empty($alert['last_event_date']) && !is_int($alert['last_event_date'])) {
            $this->helper->getLogger()->debug(
                $messageSlug,
                ['message' => 'Last event date must be a timestamp integer']
            );
            $result = false;
        }

        return $result;
    }
}
