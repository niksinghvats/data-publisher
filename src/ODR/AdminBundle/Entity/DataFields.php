<?php

/**
 * Open Data Repository Data Publisher
 * DataFields Entity
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The DataFields Entity is automatically generated from
 * ./Resources/config/doctrine/DataFields.orm.yml
 *
 */

namespace ODR\AdminBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;

/**
 * DataFields
 */
class DataFields
{
    /**
     * @var integer
     */
    private $id;

    /**
     * @var boolean
     */
    private $is_master_field;

    /**
     * @var string
     */
    private $fieldUuid;

    /**
     * @var string
     */
    private $templateFieldUuid;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $relatedMasterFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataRecordFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $themeDataFields;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $radioOptions;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $dataFieldMeta;

    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $groupDatafieldPermissions;

    /**
     * @var \ODR\AdminBundle\Entity\DataFields
     */
    private $masterDataField;

    /**
     * @var \ODR\AdminBundle\Entity\DataType
     */
    private $dataType;

    /**
     * @var \ODR\AdminBundle\Entity\RenderPlugin
     */
    private $renderPlugin;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $deletedBy;


    /**
     * Constructor
     */
    public function __construct()
    {
        $this->relatedMasterFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataRecordFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->themeDataFields = new \Doctrine\Common\Collections\ArrayCollection();
        $this->radioOptions = new \Doctrine\Common\Collections\ArrayCollection();
        $this->dataFieldMeta = new \Doctrine\Common\Collections\ArrayCollection();
        $this->groupDatafieldPermissions = new \Doctrine\Common\Collections\ArrayCollection();
    }

    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set isMasterField
     *
     * @param boolean $isMasterField
     *
     * @return DataFields
     */
    public function setIsMasterField($isMasterField)
    {
        $this->is_master_field = $isMasterField;

        return $this;
    }

    /**
     * Get isMasterField
     *
     * @return boolean
     */
    public function getIsMasterField()
    {
        return $this->is_master_field;
    }

    /**
     * Set created
     *
     * @param \DateTime $created
     * @return DataFields
     */
    public function setCreated($created)
    {
        $this->created = $created;

        return $this;
    }

    /**
     * Set fieldUuid
     *
     * @param string $fieldUuid
     *
     * @return DataFields
     */
    public function setFieldUuid($fieldUuid)
    {
        $this->fieldUuid = $fieldUuid;

        return $this;
    }

    /**
     * Get fieldUuid
     *
     * @return string
     */
    public function getFieldUuid()
    {
        return $this->fieldUuid;
    }

    /**
     * Set templateFieldUuid
     *
     * @param string $templateFieldUuid
     *
     * @return DataFields
     */
    public function setTemplateFieldUuid($templateFieldUuid)
    {
        $this->templateFieldUuid = $templateFieldUuid;

        return $this;
    }

    /**
     * Get templateFieldUuid
     *
     * @return string
     */
    public function getTemplateFieldUuid()
    {
        return $this->templateFieldUuid;
    }

    /**
     * Get created
     *
     * @return \DateTime
     */
    public function getCreated()
    {
        return $this->created;
    }

    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return DataFields
     */
    public function setDeletedAt($deletedAt)
    {
        $this->deletedAt = $deletedAt;

        return $this;
    }

    /**
     * Get deletedAt
     *
     * @return \DateTime
     */
    public function getDeletedAt()
    {
        return $this->deletedAt;
    }

    /**
     * Add relatedMasterField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $relatedMasterField
     *
     * @return DataFields
     */
    public function addRelatedMasterField(\ODR\AdminBundle\Entity\DataFields $relatedMasterField)
    {
        $this->relatedMasterFields[] = $relatedMasterField;

        return $this;
    }

    /**
     * Remove relatedMasterField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $relatedMasterField
     */
    public function removeRelatedMasterField(\ODR\AdminBundle\Entity\DataFields $relatedMasterField)
    {
        $this->relatedMasterFields->removeElement($relatedMasterField);
    }

    /**
     * Get relatedMasterFields
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRelatedMasterFields()
    {
        return $this->relatedMasterFields;
    }

    /**
     * Add dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return DataFields
     */
    public function addDataRecordField(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields)
    {
        $this->dataRecordFields[] = $dataRecordFields;

        return $this;
    }

    /**
     * Remove dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     */
    public function removeDataRecordField(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields)
    {
        $this->dataRecordFields->removeElement($dataRecordFields);
    }

    /**
     * Get dataRecordFields
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDataRecordFields()
    {
        return $this->dataRecordFields;
    }

    /**
     * Add themeDataFields
     *
     * @param \ODR\AdminBundle\Entity\ThemeDataField $themeDataFields
     * @return DataFields
     */
    public function addThemeDataField(\ODR\AdminBundle\Entity\ThemeDataField $themeDataFields)
    {
        $this->themeDataFields[] = $themeDataFields;

        return $this;
    }

    /**
     * Remove themeDataFields
     *
     * @param \ODR\AdminBundle\Entity\ThemeDataField $themeDataFields
     */
    public function removeThemeDataField(\ODR\AdminBundle\Entity\ThemeDataField $themeDataFields)
    {
        $this->themeDataFields->removeElement($themeDataFields);
    }

    /**
     * Get themeDataFields
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getThemeDataFields()
    {
        return $this->themeDataFields;
    }

    /**
     * Add radioOptions
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $radioOptions
     * @return DataFields
     */
    public function addRadioOption(\ODR\AdminBundle\Entity\RadioOptions $radioOptions)
    {
        $this->radioOptions[] = $radioOptions;

        return $this;
    }

    /**
     * Remove radioOptions
     *
     * @param \ODR\AdminBundle\Entity\RadioOptions $radioOptions
     */
    public function removeRadioOption(\ODR\AdminBundle\Entity\RadioOptions $radioOptions)
    {
        $this->radioOptions->removeElement($radioOptions);
    }

    /**
     * Get radioOptions
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRadioOptions()
    {
//        return $this->radioOptions;

        // Adapted from http://stackoverflow.com/a/16707694
        $iterator = $this->radioOptions->getIterator();
        $iterator->uasort(function ($a, $b) {
            // Sort by display order first if possible
            /** @var RadioOptions $a */
            /** @var RadioOptions $b */
            $a_display_order = $a->getDisplayOrder();
            $b_display_order = $b->getDisplayOrder();
            if ($a_display_order < $b_display_order)
                return -1;
            else if ($a_display_order > $b_display_order)
                return 1;
            else
                // otherwise, sort by radio_option_id
                return ($a->getId() < $b->getId()) ? -1 : 1;
        });
        return new ArrayCollection(iterator_to_array($iterator));
    }

    /**
     * Add dataFieldMeta
     *
     * @param \ODR\AdminBundle\Entity\DataFieldsMeta $dataFieldMeta
     * @return DataFields
     */
    public function addDataFieldMetum(\ODR\AdminBundle\Entity\DataFieldsMeta $dataFieldMeta)
    {
        $this->dataFieldMeta[] = $dataFieldMeta;

        return $this;
    }

    /**
     * Remove dataFieldMeta
     *
     * @param \ODR\AdminBundle\Entity\DataFieldsMeta $dataFieldMeta
     */
    public function removeDataFieldMetum(\ODR\AdminBundle\Entity\DataFieldsMeta $dataFieldMeta)
    {
        $this->dataFieldMeta->removeElement($dataFieldMeta);
    }

    /**
     * Get dataFieldMeta
     *
     * @return \ODR\AdminBundle\Entity\DataFieldsMeta
     */
    public function getDataFieldMeta()
    {
        return $this->dataFieldMeta->first();
    }

    /**
     * Add groupDatafieldPermission
     *
     * @param \ODR\AdminBundle\Entity\GroupDatafieldPermissions $groupDatafieldPermission
     * @return DataFields
     */
    public function addGroupDatafieldPermission(\ODR\AdminBundle\Entity\GroupDatafieldPermissions $groupDatafieldPermission)
    {
        $this->groupDatafieldPermissions[] = $groupDatafieldPermission;

        return $this;
    }

    /**
     * Remove groupDatafieldPermission
     *
     * @param \ODR\AdminBundle\Entity\GroupDatafieldPermissions $groupDatafieldPermission
     */
    public function removeGroupDatafieldPermission(\ODR\AdminBundle\Entity\GroupDatafieldPermissions $groupDatafieldPermission)
    {
        $this->groupDatafieldPermissions->removeElement($groupDatafieldPermission);
    }

    /**
     * Get groupDatafieldPermissions
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getGroupDatafieldPermissions()
    {
        return $this->groupDatafieldPermissions;
    }

    /**
     * Set masterDataField
     *
     * @param \ODR\AdminBundle\Entity\DataFields $masterDataField
     *
     * @return DataFields
     */
    public function setMasterDataField(\ODR\AdminBundle\Entity\DataFields $masterDataField = null)
    {
        $this->masterDataField = $masterDataField;

        return $this;
    }

    /**
     * Get masterDataField
     *
     * @return \ODR\AdminBundle\Entity\DataFields
     */
    public function getMasterDataField()
    {
        return $this->masterDataField;
    }

    /**
     * Set dataType
     *
     * @param \ODR\AdminBundle\Entity\DataType $dataType
     * @return DataFields
     */
    public function setDataType(\ODR\AdminBundle\Entity\DataType $dataType = null)
    {
        $this->dataType = $dataType;

        return $this;
    }

    /**
     * Get dataType
     *
     * @return \ODR\AdminBundle\Entity\DataType
     */
    public function getDataType()
    {
        return $this->dataType;
    }

    /**
     * Set renderPlugin
     * @internal Requred by Doctrine, but should not be used...this value should be saved in the associated datafieldMeta entry.
     *
     * @param \ODR\AdminBundle\Entity\RenderPlugin $renderPlugin
     * @return DataFields
     */
    public function setRenderPlugin(\ODR\AdminBundle\Entity\RenderPlugin $renderPlugin = null)
    {
        $this->renderPlugin = $renderPlugin;
        return $this;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return DataFields
     */
    public function setCreatedBy(\ODR\OpenRepository\UserBundle\Entity\User $createdBy = null)
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * Get createdBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User
     */
    public function getCreatedBy()
    {
        return $this->createdBy;
    }

    /**
     * Set deletedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $deletedBy
     * @return DataFields
     */
    public function setDeletedBy(\ODR\OpenRepository\UserBundle\Entity\User $deletedBy = null)
    {
        $this->deletedBy = $deletedBy;

        return $this;
    }

    /**
     * Get deletedBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User
     */
    public function getDeletedBy()
    {
        return $this->deletedBy;
    }

    /**
     * Is public
     *
     * @return boolean
     */
    public function isPublic()
    {
        if ($this->getPublicDate()->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
            return false;
        else
            return true;
    }


    /**
     * Get fieldName
     *
     * @return string
     */
    public function getFieldName()
    {
        return $this->getDataFieldMeta()->getFieldName();
    }

    /**
     * Get description
     *
     * @return string
     */
    public function getDescription()
    {
        return $this->getDataFieldMeta()->getDescription();
    }

    /**
     * Get xml_fieldName
     *
     * @return string
     */
    public function getXmlFieldName()
    {
        return $this->getDataFieldMeta()->getXmlFieldName();
    }

    /**
     * Get internalReferenceName
     *
     * @return string
     */
    public function getInternalReferenceName()
    {
        return $this->getDataFieldMeta()->getInternalReferenceName();
    }

    /**
     * Get master_published_revision
     *
     * @return int
     */
    public function getMasterPublishedRevision()
    {
        return $this->getDataFieldMeta()->getMasterPublishedRevision();
    }

    /**
     * Get master_revision
     *
     * @return int
     */
    public function getMasterRevision()
    {
        return $this->getDataFieldMeta()->getMasterRevision();
    }

    /**
     * Get tracking_master_revision
     *
     * @return int
     */
    public function getTrackingMasterRevision()
    {
        return $this->getDataFieldMeta()->getTrackingMasterRevision();
    }

    /**
     * Get markdownText
     *
     * @return string
     */
    public function getMarkdownText()
    {
        return $this->getDataFieldMeta()->getMarkdownText();
    }

    /**
     * Get regexValidator
     *
     * @return string
     */
    public function getRegexValidator()
    {
        return $this->getDataFieldMeta()->getRegexValidator();
    }

    /**
     * Get phpValidator
     *
     * @return string
     */
    public function getPhpValidator()
    {
        return $this->getDataFieldMeta()->getPhpValidator();
    }

    /**
     * Get required
     *
     * @return boolean
     */
    public function getRequired()
    {
        return $this->getDataFieldMeta()->getRequired();
    }

    /**
     * Get is_unique
     *
     * @return boolean
     */
    public function getIsUnique()
    {
        return $this->getDataFieldMeta()->getIsUnique();
    }

    /**
     * Get allow_multiple_uploads
     *
     * @return boolean
     */
    public function getAllowMultipleUploads()
    {
        return $this->getDataFieldMeta()->getAllowMultipleUploads();
    }

    /**
     * Get shorten_filename
     *
     * @return boolean
     */
    public function getShortenFilename()
    {
        return $this->getDataFieldMeta()->getShortenFilename();
    }

    /**
     * Get children_per_row
     *
     * @return integer
     */
    public function getChildrenPerRow()
    {
        return $this->getDataFieldMeta()->getChildrenPerRow();
    }

    /**
     * Get radio_option_name_sort
     *
     * @return boolean
     */
    public function getRadioOptionNameSort()
    {
        return $this->getDataFieldMeta()->getRadioOptionNameSort();
    }

    /**
     * Get radio_option_display_unselected
     *
     * @return boolean
     */
    public function getRadioOptionDisplayUnselected()
    {
        return $this->getDataFieldMeta()->getRadioOptionDisplayUnselected();
    }

    /**
     * Get searchable
     *
     * @return integer
     */
    public function getSearchable()
    {
        return $this->getDataFieldMeta()->getSearchable();
    }

    /**
     * Get publicDate
     *
     * @return \DateTime
     */
    public function getPublicDate()
    {
        return $this->getDataFieldMeta()->getPublicDate();
    }

    /**
     * Get fieldType
     *
     * @return \ODR\AdminBundle\Entity\FieldType
     */
    public function getFieldType()
    {
        return $this->getDataFieldMeta()->getFieldType();
    }

    /**
     * Get renderPlugin
     *
     * @return \ODR\AdminBundle\Entity\RenderPlugin
     */
    public function getRenderPlugin()
    {
        return $this->getDataFieldMeta()->getRenderPlugin();
    }
}
