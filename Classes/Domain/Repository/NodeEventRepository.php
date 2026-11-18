<?php

declare(strict_types=1);

namespace Shel\Neos\History\Domain\Repository;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\QueryResultInterface;
use Neos\Neos\EventLog\Domain\Model\Event;
use Neos\Neos\EventLog\Domain\Model\NodeEvent;
use Neos\Neos\EventLog\Domain\Repository\EventRepository;

/**
 * The repository for events
 */
#[Flow\Scope('singleton')]
class NodeEventRepository extends EventRepository
{
    const ENTITY_CLASSNAME = NodeEvent::class;

    /**
     * Cache for already resolved inherited change events, keyed by the uid of the publish
     * event they belong to.
     *
     * @var array<int, list<Event>>
     */
    private array $inheritedChangeEventsCache = [];

    /**
     * Cache for the inherited accounts, keyed by workspace name, site identifier and node
     * identifier they were collected for.
     *
     * @var array<string, array<string, list<int>>>
     */
    private array $inheritedAccountEventUidsCache = [];

    /**
     * Find all events which are "top-level" and in a given workspace (or are not NodeEvents)
     *
     * @param int $offset
     * @param int $limit
     * @param string $workspaceName
     */
    public function findRelevantEventsByWorkspace(
        $offset,
        $limit,
        $workspaceName,
        string $siteIdentifier = null,
        string $nodeIdentifier = null,
        string $accountIdentifier = null,
        \DateTime $startDate = null,
        \DateTime $endDate = null
    ) : QueryResultInterface {
        $query = $this->prepareRelevantEventsQuery();
        $queryBuilder = $query->getQueryBuilder();
        $queryBuilder
            ->andWhere('e.workspaceName = :workspaceName AND e.eventType = :eventType')
            ->setParameter('workspaceName', $workspaceName)
            ->setParameter('eventType', 'Node.Published')
        ;
        if ($siteIdentifier !== null) {
            $siteCondition = '%' . trim(json_encode(['site' => $siteIdentifier], JSON_PRETTY_PRINT), "{}\n\t ") . '%';
            $queryBuilder
                ->andWhere('NEOSCR_TOSTRING(e.data) LIKE :site')
                ->setParameter('site', $siteCondition)
            ;
        }
        if ($nodeIdentifier !== null) {
            $queryBuilder
                ->andWhere('e.nodeIdentifier = :nodeIdentifier')
                ->setParameter('nodeIdentifier', $nodeIdentifier)
            ;
        }
        if ($accountIdentifier !== null) {
            // Publish events which inherit their change events from another workspace carry the
            // account of whoever published, not the accounts of the change events shown inside
            // them - they have to be matched by their identifier, see findInheritedAccountEventUids()
            $inheritedPublishEventUids = $this->findInheritedAccountEventUids(
                $workspaceName,
                $siteIdentifier,
                $nodeIdentifier
            )[$accountIdentifier] ?? [];
            if ($inheritedPublishEventUids !== []) {
                $queryBuilder
                    ->andWhere('(e.accountIdentifier = :accountIdentifier OR e.uid IN (:inheritedPublishEventUids))')
                    ->setParameter('accountIdentifier', $accountIdentifier)
                    ->setParameter('inheritedPublishEventUids', $inheritedPublishEventUids)
                ;
            } else {
                $queryBuilder
                    ->andWhere('e.accountIdentifier = :accountIdentifier')
                    ->setParameter('accountIdentifier', $accountIdentifier)
                ;
            }
        }
        if ($startDate !== null) {
            $queryBuilder
                ->andWhere('e.timestamp >= :startDate')
                ->setParameter('startDate', $startDate)
            ;
        }
        if ($endDate !== null) {
            // Set end date to end of day (23:59:59)
            $endDate->setTime(23, 59, 59);
            $queryBuilder
                ->andWhere('e.timestamp <= :endDate')
                ->setParameter('endDate', $endDate)
            ;
        }
        $queryBuilder->setFirstResult($offset);
        $queryBuilder->setMaxResults($limit);

        return $query->execute();
    }

    /**
     * Find all account identifiers that modified a specific site
     */
    public function findAccountIdentifiers(
        string $workspaceName,
        string $siteIdentifier = null,
        string $nodeIdentifier = null
    ) : array {
        $query = $this->prepareRelevantEventsQuery();
        $queryBuilder = $query->getQueryBuilder();
        $queryBuilder
            ->andWhere('e.workspaceName = :workspaceName AND e.eventType = :eventType')
            ->setParameter('workspaceName', $workspaceName)
            ->setParameter('eventType', 'Node.Published')
        ;
        if ($siteIdentifier !== null) {
            $siteCondition = '%' . trim(json_encode(['site' => $siteIdentifier], JSON_PRETTY_PRINT), "{}\n\t ") . '%';
            $queryBuilder
                ->andWhere('NEOSCR_TOSTRING(e.data) LIKE :site')
                ->setParameter('site', $siteCondition)
            ;
        }
        if ($nodeIdentifier !== null) {
            $queryBuilder
                ->andWhere('e.nodeIdentifier = :nodeIdentifier')
                ->setParameter('nodeIdentifier', $nodeIdentifier)
            ;
        }

        $queryBuilder->groupBy('e.accountIdentifier');
        $queryBuilder->orderBy(null);

        $dql = str_replace('SELECT e', 'SELECT e.accountIdentifier', rtrim($queryBuilder->getDql(), ' ORDER BY '));

        $dqlQuery = $this->createDqlQuery($dql);
        $dqlQuery->setParameters($query->getParameters());

        $accountIdentifiers = array_map(static function($result) {
            return $result['accountIdentifier'];
        }, $dqlQuery->execute());

        // Add the accounts of the change events which childless publish events inherit from
        // other workspaces, so they can be filtered for although the publish event itself
        // carries the account of whoever published it
        $inheritedAccountEventUids = $this->findInheritedAccountEventUids($workspaceName, $siteIdentifier, $nodeIdentifier);

        return array_values(array_unique(array_merge($accountIdentifiers, array_keys($inheritedAccountEventUids))));
    }

    /**
     * Find the change events which a "Node.Published" event inherits from the publish events
     * of the workspace it was published from.
     *
     * A "Node.Published" event only carries the change events which were still unattached in
     * the workspace that got published directly. If that workspace itself received its changes
     * through another publish - a workspace which is itself the base workspace of another
     * workspace, so that content passes through it on the way to its destination - the change
     * events are already bound to the publish event of the source workspace, and the publish
     * event of the target workspace has no child events at all.
     *
     * This resolves the missing content by searching the source workspace for publish events of
     * the same document node which were created after the previous publish of that node into
     * the target workspace, and returning the child events of those events.
     *
     * @return Event[]
     */
    public function findInheritedChangeEvents(NodeEvent $publishEvent): array
    {
        $eventIdentifier = (int)$this->persistenceManager->getIdentifierByObject($publishEvent);
        if (isset($this->inheritedChangeEventsCache[$eventIdentifier])) {
            return $this->inheritedChangeEventsCache[$eventIdentifier];
        }

        $sourceWorkspaceName = $this->extractSourceWorkspaceName($publishEvent);
        if ($sourceWorkspaceName === null || $sourceWorkspaceName === $publishEvent->getWorkspaceName()) {
            return $this->inheritedChangeEventsCache[$eventIdentifier] = [];
        }

        $query = $this->createQuery();
        $queryBuilder = $query->getQueryBuilder();
        $queryBuilder
            ->andWhere('e.parentEvent IS NULL AND e.eventType = :eventType')
            ->andWhere('e.workspaceName = :sourceWorkspaceName')
            ->andWhere('e.documentNodeIdentifier = :documentNodeIdentifier')
            ->andWhere('e.dimensionsHash = :dimensionsHash')
            ->setParameter('eventType', 'Node.Published')
            ->setParameter('sourceWorkspaceName', $sourceWorkspaceName)
            ->setParameter('documentNodeIdentifier', $publishEvent->getDocumentNodeIdentifier())
            ->setParameter('dimensionsHash', $publishEvent->getDimensionsHash())
        ;
        $previousPublishUid = $this->findPreviousPublishEventUid($publishEvent);
        if ($previousPublishUid !== null) {
            $queryBuilder
                ->andWhere('e.uid > :previousPublishUid AND e.uid < :publishEventUid')
                ->setParameter('previousPublishUid', $previousPublishUid)
                ->setParameter('publishEventUid', $eventIdentifier)
            ;
        } else {
            $queryBuilder
                ->andWhere('e.uid < :publishEventUid')
                ->setParameter('publishEventUid', $eventIdentifier)
            ;
        }
        $queryBuilder->orderBy('e.uid', 'ASC');

        $changeEvents = [];
        foreach ($query->execute() as $interveningPublishEvent) {
            if (!$interveningPublishEvent instanceof NodeEvent) {
                continue;
            }
            foreach ($interveningPublishEvent->getChildEvents() as $childEvent) {
                /** @var Event $childEvent */
                $changeEvents[] = $childEvent;
            }
        }

        return $this->inheritedChangeEventsCache[$eventIdentifier] = $changeEvents;
    }

    /**
     * Collect the accounts of the change events which the publish events of the given workspace
     * inherit from other workspaces, mapped to the identifiers of the publish events they belong
     * to.
     *
     * Those publish events carry the account of whoever published them, while the work shown
     * inside them was done by the accounts of the inherited change events - without collecting
     * them here, those accounts would be missing from the account filter although their changes
     * appear in the workspace's history.
     *
     * @return array<string, list<int>>
     */
    public function findInheritedAccountEventUids(
        string $workspaceName,
        string $siteIdentifier = null,
        string $nodeIdentifier = null
    ): array {
        $cacheKey = $workspaceName . '|' . ($siteIdentifier ?? '') . '|' . ($nodeIdentifier ?? '');
        if (isset($this->inheritedAccountEventUidsCache[$cacheKey])) {
            return $this->inheritedAccountEventUidsCache[$cacheKey];
        }

        $accountEventUids = [];
        foreach ($this->findPublishEventsEligibleForInheritance($workspaceName, $siteIdentifier, $nodeIdentifier) as $publishEvent) {
            $publishEventUid = (int)$this->persistenceManager->getIdentifierByObject($publishEvent);
            foreach ($this->findInheritedChangeEvents($publishEvent) as $changeEvent) {
                $accountIdentifier = $changeEvent->getAccountIdentifier();
                if ($accountIdentifier === null || in_array($publishEventUid, $accountEventUids[$accountIdentifier] ?? [], true)) {
                    continue;
                }
                $accountEventUids[$accountIdentifier][] = $publishEventUid;
            }
        }

        return $this->inheritedAccountEventUidsCache[$cacheKey] = $accountEventUids;
    }

    /**
     * Find the publish events of the given workspace which have no child events of their own but
     * could inherit some: top-level "Node.Published" events for which an older "Node.Published"
     * event of another workspace exists that concerns the same document node and dimensions.
     *
     * @return NodeEvent[]
     */
    protected function findPublishEventsEligibleForInheritance(
        string $workspaceName,
        string $siteIdentifier = null,
        string $nodeIdentifier = null
    ): array {
        $query = $this->prepareRelevantEventsQuery();
        $queryBuilder = $query->getQueryBuilder();
        $queryBuilder
            ->andWhere('e.workspaceName = :workspaceName AND e.eventType = :eventType')
            ->andWhere('e.uid NOT IN (SELECT IDENTITY(nestedEvent.parentEvent) FROM ' . Event::class . ' nestedEvent WHERE nestedEvent.parentEvent IS NOT NULL)')
            ->andWhere('EXISTS (SELECT interveningEvent.uid FROM ' . NodeEvent::class . ' interveningEvent WHERE interveningEvent.parentEvent IS NULL AND interveningEvent.eventType = :eventType AND interveningEvent.documentNodeIdentifier = e.documentNodeIdentifier AND interveningEvent.dimensionsHash = e.dimensionsHash AND interveningEvent.workspaceName != e.workspaceName AND interveningEvent.uid < e.uid)')
            ->setParameter('workspaceName', $workspaceName)
            ->setParameter('eventType', 'Node.Published')
        ;
        if ($siteIdentifier !== null) {
            $siteCondition = '%' . trim(json_encode(['site' => $siteIdentifier], JSON_PRETTY_PRINT), "{}\n\t ") . '%';
            $queryBuilder
                ->andWhere('NEOSCR_TOSTRING(e.data) LIKE :site')
                ->setParameter('site', $siteCondition)
            ;
        }
        if ($nodeIdentifier !== null) {
            $queryBuilder
                ->andWhere('e.nodeIdentifier = :nodeIdentifier')
                ->setParameter('nodeIdentifier', $nodeIdentifier)
            ;
        }
        $queryBuilder->orderBy('e.uid', 'ASC');

        return $query->execute()->toArray();
    }

    /**
     * Determine the name of the workspace the publish event was published from, as stored in
     * the node context path of the event data (e.g. "/sites/mysite/node-abc@other-workspace").
     */
    protected function extractSourceWorkspaceName(NodeEvent $publishEvent): ?string
    {
        $nodeContextPath = $publishEvent->getData()['nodeContextPath'] ?? null;
        if (!is_string($nodeContextPath) || !str_contains($nodeContextPath, '@')) {
            return null;
        }

        $sourceWorkspaceName = substr($nodeContextPath, strrpos($nodeContextPath, '@') + 1);
        $dimensionSeparator = strpos($sourceWorkspaceName, '#');
        if ($dimensionSeparator !== false) {
            $sourceWorkspaceName = substr($sourceWorkspaceName, 0, $dimensionSeparator);
        }

        return $sourceWorkspaceName === '' ? null : $sourceWorkspaceName;
    }

    /**
     * The uid of the publish event which published the same document node into the same target
     * workspace before the given one - used as lower bound of the inherited events.
     */
    protected function findPreviousPublishEventUid(NodeEvent $publishEvent): ?int
    {
        $query = $this->createQuery();
        $queryBuilder = $query->getQueryBuilder();
        $queryBuilder
            ->select('e')
            ->andWhere('e.parentEvent IS NULL AND e.eventType = :eventType')
            ->andWhere('e.workspaceName = :workspaceName')
            ->andWhere('e.documentNodeIdentifier = :documentNodeIdentifier')
            ->andWhere('e.dimensionsHash = :dimensionsHash')
            ->setParameter('eventType', 'Node.Published')
            ->setParameter('workspaceName', $publishEvent->getWorkspaceName())
            ->setParameter('documentNodeIdentifier', $publishEvent->getDocumentNodeIdentifier())
            ->setParameter('dimensionsHash', $publishEvent->getDimensionsHash())
        ;
        $queryBuilder->orderBy('e.uid', 'DESC');
        $query->setLimit(1);
        $queryBuilder
            ->andWhere('e.uid < :publishEventUid')
            ->setParameter('publishEventUid', $this->persistenceManager->getIdentifierByObject($publishEvent))
        ;

        $results = $query->execute()->toArray();
        $previousPublishEvent = $results === [] ? null : reset($results);

        return $previousPublishEvent instanceof NodeEvent
            ? (int)$this->persistenceManager->getIdentifierByObject($previousPublishEvent)
            : null;
    }
}
