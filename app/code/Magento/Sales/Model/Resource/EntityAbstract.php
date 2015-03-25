<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Sales\Model\Resource;

use Magento\Framework\Model\Resource\Db\AbstractDb;
use Magento\Sales\Model\EntityInterface;
use Magento\SalesSequence\Model\Sequence\SequenceManager;

/**
 * Flat sales resource abstract
 * @SuppressWarnings(PHPMD.NumberOfChildren)
 */
abstract class EntityAbstract extends AbstractDb
{
    /**
     * Event prefix
     *
     * @var string
     */
    protected $_eventPrefix = 'sales_order_resource';

    /**
     * Event object
     *
     * @var string
     */
    protected $_eventObject = 'resource';

    /**
     * Use additional is object new check for this resource
     *
     * @var bool
     */
    protected $_useIsObjectNew = true;

    /**
     * @var \Magento\Eav\Model\Entity\TypeFactory
     */
    protected $_eavEntityTypeFactory;

    /**
     * @var \Magento\Sales\Model\Resource\Attribute
     */
    protected $attribute;

    /**
     * @var SequenceManager
     */
    protected $sequenceManager;

    /**
     * @var \Magento\Sales\Model\Resource\GridInterface
     */
    protected $gridAggregator;

    /**
     * @var EntitySnapshot
     */
    protected $entitySnapshot;
    /**
     * @param \Magento\Framework\Model\Resource\Db\Context $context
     * @param Attribute $attribute
     * @param SequenceManager $sequenceManager
     * @param string|null $resourcePrefix
     * @param GridInterface|null $gridAggregator
     */
    public function __construct(
        \Magento\Framework\Model\Resource\Db\Context $context,
        \Magento\Sales\Model\Resource\Attribute $attribute,
        SequenceManager $sequenceManager,
        EntitySnapshot $entitySnapshot,
        $resourcePrefix = null,
        \Magento\Sales\Model\Resource\GridInterface $gridAggregator = null
    ) {
        $this->attribute = $attribute;
        $this->sequenceManager = $sequenceManager;
        $this->gridAggregator = $gridAggregator;
        $this->entitySnapshot = $entitySnapshot;
        parent::__construct($context, $resourcePrefix);
    }

    /**
     * Perform actions after object save
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @param string $attribute
     * @return $this
     * @throws \Exception
     */
    public function saveAttribute(\Magento\Framework\Model\AbstractModel $object, $attribute)
    {
        $this->attribute->saveAttribute($object, $attribute);
        return $this;
    }

    /**
     * Perform actions before object save, calculate next sequence value for increment Id
     *
     * @param \Magento\Framework\Model\AbstractModel|\Magento\Framework\Object $object
     * @return $this
     */
    protected function _beforeSave(\Magento\Framework\Model\AbstractModel $object)
    {

        /** @var \Magento\Sales\Model\AbstractModel $object */
        if ($object instanceof EntityInterface && $object->getIncrementId() == null) {
            $object->setIncrementId(
                $this->sequenceManager->getSequence(
                    $object->getEntityType(),
                    $object->getStore()->getId()
                )->getNextValue()
            );
        }
        parent::_beforeSave($object);
        return $this;
    }

    /**
     * Perform actions after object save
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _afterSave(\Magento\Framework\Model\AbstractModel $object)
    {
        if ($this->gridAggregator) {
            $this->gridAggregator->refresh($object->getId());
        }

        $adapter = $this->_getReadAdapter();
        $columns = $adapter->describeTable($this->getMainTable());

        if (isset($columns['created_at'], $columns['updated_at'])) {
            $select = $adapter->select()
                ->from($this->getMainTable(), ['created_at', 'updated_at'])
                ->where($this->getIdFieldName() . ' = :entity_id');
            $row = $adapter->fetchRow($select, [':entity_id' => $object->getId()]);

            if (is_array($row) && isset($row['created_at'], $row['updated_at'])) {
                $object->setCreatedAt($row['created_at']);
                $object->setUpdatedAt($row['updated_at']);
            }
        }
//        $object->flushDataIntoModel();
        parent::_afterSave($object);
        return $this;
    }

    /**
     * Perform actions after object delete
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function _afterDelete(\Magento\Framework\Model\AbstractModel $object)
    {
        if ($this->gridAggregator) {
            $this->gridAggregator->purge($object->getId());
        }
        parent::_afterDelete($object);
        return $this;
    }

    /**
     * Perform actions after object load, mark loaded data as data without changes
     *
     * @param \Magento\Framework\Model\AbstractModel|\Magento\Framework\Object $object
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    protected function _afterLoad(\Magento\Framework\Model\AbstractModel $object)
    {
//        $object->flushDataIntoModel();
        $this->entitySnapshot->registerSnapshot($object);
        return $this;
    }

    /**
     * Process entity relations
     *
     * @param \Magento\Framework\Model\AbstractModel $object
     * @return $this
     */
    protected function processRelations(\Magento\Framework\Model\AbstractModel $object)
    {
        return $this;
    }


    public function save(\Magento\Framework\Model\AbstractModel $object)
    {
        if ($object->isDeleted()) {
            return $this->delete($object);
        }
        if (!$this->entitySnapshot->isModified($object)) {
            $this->processRelations($object);
            return $this;
        }
        $this->beginTransaction();

        try {
            $object->validateBeforeSave();
            $object->beforeSave();
            if ($object->isSaveAllowed()) {
                $this->_serializeFields($object);
                $this->_beforeSave($object);
                $this->_checkUnique($object);
                $this->objectRelationProcessor->validateDataIntegrity($this->getMainTable(), $object->getData());
                if ($object->getId() !== null && (!$this->_useIsObjectNew || !$object->isObjectNew())) {
                    $condition = $this->_getWriteAdapter()->quoteInto($this->getIdFieldName() . '=?', $object->getId());
                    $data = $this->_prepareDataForSave($object);
                    unset($data[$this->getIdFieldName()]);
                    $this->_getWriteAdapter()->update($this->getMainTable(), $data, $condition);
                } else {
                    $bind = $this->_prepareDataForSave($object);
                    unset($bind[$this->getIdFieldName()]);
                    $this->_getWriteAdapter()->insert($this->getMainTable(), $bind);

                    $object->setId($this->_getWriteAdapter()->lastInsertId($this->getMainTable()));

                    if ($this->_useIsObjectNew) {
                        $object->isObjectNew(false);
                    }
                }
                $this->unserializeFields($object);
                $this->_afterSave($object);
                $this->entitySnapshot->registerSnapshot($object);
                $object->afterSave();
                $this->processRelations($object);
            }
            $this->addCommitCallback([$object, 'afterCommitCallback'])->commit();
            $object->setHasDataChanges(false);
        } catch (\Exception $e) {
            $this->rollBack();
            $object->setHasDataChanges(true);
            throw $e;
        }
        return $this;
    }
}
