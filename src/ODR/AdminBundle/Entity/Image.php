<?php

namespace ODR\AdminBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * ODR\AdminBundle\Entity\Image
 */
class Image
{
    /**
     * @var integer $id
     */
    private $id;

    /**
     * @var boolean $value
     */
    private $value;

    /**
     * @var string $imageData
     */
    private $imageData;

    /**
     * @var string $caption
     */
    private $caption;

    /**
     * @var string $originalFileName
     */
    private $originalFileName;

    /**
     * @var string $localFileName
     */
    private $localFileName;

    /**
     * @var ODR\AdminBundle\Entity\DataFields
     */
    private $dataField;

    /**
     * @var ODR\AdminBundle\Entity\FieldType
     */
    private $fieldType;

    /**
     * @var ODR\AdminBundle\Entity\DataRecord
     */
    private $dataRecord;

    /**
     * @var ODR\AdminBundle\Entity\ImageSizes
     */
    private $imageSize;


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
     * Set value
     *
     * @param boolean $value
     * @return Image
     */
    public function setValue($value)
    {
        $this->value = $value;
    
        return $this;
    }

    /**
     * Get value
     *
     * @return boolean 
     */
    public function getValue()
    {
        return $this->value;
    }

    /**
     * Set imageData
     *
     * @param string $imageData
     * @return Image
     */
    public function setImageData($imageData)
    {
        $this->imageData = $imageData;
    
        return $this;
    }

    /**
     * Get imageData
     *
     * @return string 
     */
    public function getImageData()
    {
        return $this->imageData;
    }

    /**
     * Set caption
     *
     * @param string $caption
     * @return Image
     */
    public function setCaption($caption)
    {
        $this->caption = $caption;
    
        return $this;
    }

    /**
     * Get caption
     *
     * @return string 
     */
    public function getCaption()
    {
        return $this->caption;
    }

    /**
     * Set originalFileName
     *
     * @param string $originalFileName
     * @return Image
     */
    public function setOriginalFileName($originalFileName)
    {
        $this->originalFileName = $originalFileName;
    
        return $this;
    }

    /**
     * Get originalFileName
     *
     * @return string 
     */
    public function getOriginalFileName()
    {
        return $this->originalFileName;
    }

    /**
     * Set localFileName
     *
     * @param string $localFileName
     * @return Image
     */
    public function setLocalFileName($localFileName)
    {
        $this->localFileName = $localFileName;
    
        return $this;
    }

    /**
     * Get localFileName
     *
     * @return string 
     */
    public function getLocalFileName()
    {
        return $this->localFileName;
    }

    /**
     * Set dataField
     *
     * @param ODR\AdminBundle\Entity\DataFields $dataField
     * @return Image
     */
    public function setDataField(\ODR\AdminBundle\Entity\DataFields $dataField = null)
    {
        $this->dataField = $dataField;
    
        return $this;
    }

    /**
     * Get dataField
     *
     * @return ODR\AdminBundle\Entity\DataFields 
     */
    public function getDataField()
    {
        return $this->dataField;
    }

    /**
     * Set fieldType
     *
     * @param ODR\AdminBundle\Entity\FieldType $fieldType
     * @return Image
     */
    public function setFieldType(\ODR\AdminBundle\Entity\FieldType $fieldType = null)
    {
        $this->fieldType = $fieldType;
    
        return $this;
    }

    /**
     * Get fieldType
     *
     * @return ODR\AdminBundle\Entity\FieldType 
     */
    public function getFieldType()
    {
        return $this->fieldType;
    }

    /**
     * Set dataRecord
     *
     * @param ODR\AdminBundle\Entity\DataRecord $dataRecord
     * @return Image
     */
    public function setDataRecord(\ODR\AdminBundle\Entity\DataRecord $dataRecord = null)
    {
        $this->dataRecord = $dataRecord;
    
        return $this;
    }

    /**
     * Get dataRecord
     *
     * @return ODR\AdminBundle\Entity\DataRecord 
     */
    public function getDataRecord()
    {
        return $this->dataRecord;
    }

    /**
     * Set imageSize
     *
     * @param ODR\AdminBundle\Entity\ImageSizes $imageSize
     * @return Image
     */
    public function setImageSize(\ODR\AdminBundle\Entity\ImageSizes $imageSize = null)
    {
        $this->imageSize = $imageSize;
    
        return $this;
    }

    /**
     * Get imageSize
     *
     * @return ODR\AdminBundle\Entity\ImageSizes 
     */
    public function getImageSize()
    {
        return $this->imageSize;
    }
    /**
     * @var \DateTime
     */
    private $deletedAt;

    /**
     * @var \DateTime
     */
    private $created;

    /**
     * @var \DateTime
     */
    private $updated;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $createdBy;

    /**
     * @var \ODR\OpenRepository\UserBundle\Entity\User
     */
    private $updatedBy;


    /**
     * Set deletedAt
     *
     * @param \DateTime $deletedAt
     * @return Image
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
     * Set created
     *
     * @param \DateTime $created
     * @return Image
     */
    public function setCreated($created)
    {
        $this->created = $created;
    
        return $this;
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
     * Set updated
     *
     * @param \DateTime $updated
     * @return Image
     */
    public function setUpdated($updated)
    {
        $this->updated = $updated;
    
        return $this;
    }

    /**
     * Get updated
     *
     * @return \DateTime 
     */
    public function getUpdated()
    {
        return $this->updated;
    }

    /**
     * Set createdBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $createdBy
     * @return Image
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
     * Set updatedBy
     *
     * @param \ODR\OpenRepository\UserBundle\Entity\User $updatedBy
     * @return Image
     */
    public function setUpdatedBy(\ODR\OpenRepository\UserBundle\Entity\User $updatedBy = null)
    {
        $this->updatedBy = $updatedBy;
    
        return $this;
    }

    /**
     * Get updatedBy
     *
     * @return \ODR\OpenRepository\UserBundle\Entity\User 
     */
    public function getUpdatedBy()
    {
        return $this->updatedBy;
    }
    /**
     * @var \ODR\AdminBundle\Entity\DataRecordFields
     */
    private $dataRecordFields;


    /**
     * Set dataRecordFields
     *
     * @param \ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields
     * @return Image
     */
    public function setDataRecordFields(\ODR\AdminBundle\Entity\DataRecordFields $dataRecordFields = null)
    {
        $this->dataRecordFields = $dataRecordFields;
    
        return $this;
    }

    /**
     * Get dataRecordFields
     *
     * @return \ODR\AdminBundle\Entity\DataRecordFields 
     */
    public function getDataRecordFields()
    {
        return $this->dataRecordFields;
    }
    /**
     * @var boolean
     */
    private $original;


    /**
     * Set original
     *
     * @param boolean $original
     * @return Image
     */
    public function setOriginal($original)
    {
        $this->original = $original;
    
        return $this;
    }

    /**
     * Get original
     *
     * @return boolean 
     */
    public function getOriginal()
    {
        return $this->original;
    }


   /**
     * @Assert\File(maxSize="6000000")
     */
    private $file;

    /**
     * Sets file.
     *
     * @param UploadedFile $file
     */
    public function setFile(UploadedFile $file = null)
    {
        $this->file = $file;
        // check if we have an old image path
        if (is_file($this->getAbsolutePath())) {
            // store the old name to delete after the update
            $this->temp = $this->getAbsolutePath();
        } else {
            $this->path = 'initial';
        }
    }

    /**
     */
    public function preUpload()
    {
        if (null !== $this->getFile()) {
            $this->path = $this->getFile()->guessExtension();

            $this->setExt($this->getFile()->guessExtension());
        }
    }

    /**
     * Get file.
     *
     * @return UploadedFile
     */
    public function getFile()
    {
        return $this->file;
    }


    /**
     */
    public function storeFilenameForRemove()
    {
        $this->temp = $this->getAbsolutePath();
    }

    /**
     */
    public function removeUpload()
    {
        // Do not remove file
        /*
        if (isset($this->temp)) {
            unlink($this->temp);
        }
        */
    }


    /**
     * TODO: description.
     * 
     * @var mixed
     */
    private $temp;


    /**
    * TODO: short description.
    * 
    */
    public function upload()
    {

        if (null === $this->getFile()) {
            return;
        }

        // check if we have an old image
        if (isset($this->temp)) {
            // delete the old image
            unlink($this->temp);
            // clear the temp image path
            $this->temp = null;
        }

        $filename = "Image_" . $this->id.'.'.$this->getFile()->guessExtension();
        // you must throw an exception here if the file cannot be moved
        // so that the entity is not persisted to the database
        // which the UploadedFile move() method does
        $this->getFile()->move(
            $this->getUploadRootDir(),
            $filename
        );


        $this->setFile(null);

    }

    /**
     * TODO: description.
     * 
     * @var mixed
     */
    public $path;

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getAbsolutePath()
    {
        return null === $this->path
            ? null
            : $this->getUploadRootDir().'/Image_'.$this->id.'.'.$this->path;
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getWebPath()
    {
        return null === $this->path
            ? null
            : $this->getUploadDir().'/'.$this->path;
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getUploadRootDir()
    {
        // the absolute directory path where uploaded
        // documents should be saved
        return __DIR__.'/../../../../web/'.$this->getUploadDir();
    }

    /**
     * TODO: short description.
     * 
     * @return TODO
     */
    public function getUploadDir()
    {
        // get rid of the __DIR__ so it doesn't screw up
        // when displaying uploaded doc/image in the view.
        return 'uploads/images';
    }

    /**
     * @var string
     */
    private $ext;


    /**
     * Set ext
     *
     * @param string $ext
     * @return Image
     */
    public function setExt($ext)
    {
        $this->ext = $ext;
    
        return $this;
    }

    /**
     * Get ext
     *
     * @return string 
     */
    public function getExt()
    {
        return $this->ext;
    }
    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $children;

    /**
     * @var \ODR\AdminBundle\Entity\Image
     */
    private $parent;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->children = new \Doctrine\Common\Collections\ArrayCollection();
    }
    
    /**
     * Add children
     *
     * @param \ODR\AdminBundle\Entity\Image $children
     * @return Image
     */
    public function addChildren(\ODR\AdminBundle\Entity\Image $children)
    {
        $this->children[] = $children;
    
        return $this;
    }

    /**
     * Remove children
     *
     * @param \ODR\AdminBundle\Entity\Image $children
     */
    public function removeChildren(\ODR\AdminBundle\Entity\Image $children)
    {
        $this->children->removeElement($children);
    }

    /**
     * Get children
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * Set parent
     *
     * @param \ODR\AdminBundle\Entity\Image $parent
     * @return Image
     */
    public function setParent(\ODR\AdminBundle\Entity\Image $parent = null)
    {
        $this->parent = $parent;
    
        return $this;
    }

    /**
     * Get parent
     *
     * @return \ODR\AdminBundle\Entity\Image 
     */
    public function getParent()
    {
        return $this->parent;
    }
    /**
     * @var string
     */
    private $imagetype;


    /**
     * Set imagetype
     *
     * @param string $imagetype
     * @return Image
     */
    public function setImagetype($imagetype)
    {
        $this->imagetype = $imagetype;
    
        return $this;
    }

    /**
     * Get imagetype
     *
     * @return string 
     */
    public function getImagetype()
    {
        return $this->imagetype;
    }
    /**
     * @var integer
     */
    private $displayorder;


    /**
     * Set displayorder
     *
     * @param integer $displayorder
     * @return Image
     */
    public function setDisplayorder($displayorder)
    {
        $this->displayorder = $displayorder;
    
        return $this;
    }

    /**
     * Get displayorder
     *
     * @return integer 
     */
    public function getDisplayorder()
    {
        return $this->displayorder;
    }
    /**
     * @var \DateTime
     */
    private $publicDate;


    /**
     * Set publicDate
     *
     * @param \DateTime $publicDate
     * @return Image
     */
    public function setPublicDate($publicDate)
    {
        $this->publicDate = $publicDate;
    
        return $this;
    }

    /**
     * Get publicDate
     *
     * @return \DateTime 
     */
    public function getPublicDate()
    {
        return $this->publicDate;
    }

    /**
     * Is public
     *
     * @return boolean
     */
    public function isPublic()
    {
        if ($this->publicDate->format('Y-m-d H:i:s') == '2200-01-01 00:00:00')
            return false;
        else
            return true;
    }

    /**
     * Add children
     *
     * @param \ODR\AdminBundle\Entity\Image $children
     * @return Image
     */
    public function addChild(\ODR\AdminBundle\Entity\Image $children)
    {
        $this->children[] = $children;

        return $this;
    }

    /**
     * Remove children
     *
     * @param \ODR\AdminBundle\Entity\Image $children
     */
    public function removeChild(\ODR\AdminBundle\Entity\Image $children)
    {
        $this->children->removeElement($children);
    }


    /**
     * @var integer
     */
    private $imageWidth;

    /**
     * @var integer
     */
    private $imageHeight;


    /**
     * Set imageWidth
     *
     * @param integer $imageWidth
     * @return Image
     */
    public function setImageWidth($imageWidth)
    {
        $this->imageWidth = $imageWidth;

        return $this;
    }

    /**
     * Get imageWidth
     *
     * @return integer 
     */
    public function getImageWidth()
    {
        return $this->imageWidth;
    }

    /**
     * Set imageHeight
     *
     * @param integer $imageHeight
     * @return Image
     */
    public function setImageHeight($imageHeight)
    {
        $this->imageHeight = $imageHeight;

        return $this;
    }

    /**
     * Get imageHeight
     *
     * @return integer 
     */
    public function getImageHeight()
    {
        return $this->imageHeight;
    }
    /**
     * @var string
     */
    private $encrypt_key;


    /**
     * Set encrypt_key
     *
     * @param string $encryptKey
     * @return Image
     */
    public function setEncryptKey($encryptKey)
    {
        $this->encrypt_key = $encryptKey;

        return $this;
    }

    /**
     * Get encrypt_key
     *
     * @return string 
     */
    public function getEncryptKey()
    {
        return $this->encrypt_key;
    }
    /**
     * @var \Doctrine\Common\Collections\Collection
     */
    private $ImageChecksum;


    /**
     * Add ImageChecksum
     *
     * @param \ODR\AdminBundle\Entity\ImageChecksum $imageChecksum
     * @return Image
     */
    public function addImageChecksum(\ODR\AdminBundle\Entity\ImageChecksum $imageChecksum)
    {
        $this->ImageChecksum[] = $imageChecksum;

        return $this;
    }

    /**
     * Remove ImageChecksum
     *
     * @param \ODR\AdminBundle\Entity\ImageChecksum $imageChecksum
     */
    public function removeImageChecksum(\ODR\AdminBundle\Entity\ImageChecksum $imageChecksum)
    {
        $this->ImageChecksum->removeElement($imageChecksum);
    }

    /**
     * Get ImageChecksum
     *
     * @return \Doctrine\Common\Collections\Collection 
     */
    public function getImageChecksum()
    {
        return $this->ImageChecksum;
    }
    /**
     * @var string
     */
    private $external_id;

    /**
     * @var string
     */
    private $original_checksum;


    /**
     * Set external_id
     *
     * @param string $externalId
     * @return Image
     */
    public function setExternalId($externalId)
    {
        $this->external_id = $externalId;

        return $this;
    }

    /**
     * Get external_id
     *
     * @return string 
     */
    public function getExternalId()
    {
        return $this->external_id;
    }

    /**
     * Set original_checksum
     *
     * @param string $originalChecksum
     * @return Image
     */
    public function setOriginalChecksum($originalChecksum)
    {
        $this->original_checksum = $originalChecksum;

        return $this;
    }

    /**
     * Get original_checksum
     *
     * @return string 
     */
    public function getOriginalChecksum()
    {
        return $this->original_checksum;
    }
}