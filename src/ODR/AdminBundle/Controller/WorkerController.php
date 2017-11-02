<?php

/**
 * Open Data Repository Data Publisher
 * Worker Controller
 * (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
 * (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
 * Released under the GPLv2
 *
 * The worker controller holds all of the functions that are called
 * by the worker processes, excluding those in the XML, CSV, and
 * MassEdit controllers.
 *
 */

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entities
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\FieldType;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\TrackedJob;
use ODR\OpenRepository\UserBundle\Entity\User;
// Exceptions
use ODR\AdminBundle\Exception\ODRBadRequestException;
use ODR\AdminBundle\Exception\ODRException;
use ODR\AdminBundle\Exception\ODRForbiddenException;
use ODR\AdminBundle\Exception\ODRNotFoundException;
use ODR\AdminBundle\Exception\ODRNotImplementedException;
// Services
use ODR\AdminBundle\Component\Service\CacheService;
use ODR\AdminBundle\Component\Service\DatarecordInfoService;
use ODR\AdminBundle\Component\Service\DatatypeInfoService;
use ODR\AdminBundle\Component\Service\PermissionsManagementService;
use ODR\AdminBundle\Component\Service\ThemeInfoService;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;


class WorkerController extends ODRCustomController
{

    /**
     * Called by the migration background process to transfer data from one storage entity to another compatible storage entity.
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function migrateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $ret = '';

        try {
            $post = $_POST;
//print_r($post);
            if ( !isset($post['tracked_job_id']) || !isset($post['datarecord_id']) || !isset($post['datafield_id']) || !isset($post['user_id']) || !isset($post['old_fieldtype_id']) || !isset($post['new_fieldtype_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $datarecord_id = $post['datarecord_id'];
            $datafield_id = $post['datafield_id'];
            $user_id = $post['user_id'];
            $old_fieldtype_id = $post['old_fieldtype_id'];
            $new_fieldtype_id = $post['new_fieldtype_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
//            $pheanstalk = $this->get('pheanstalk');
            $logger = $this->get('logger');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_user = $this->getDoctrine()->getRepository('ODROpenRepositoryUserBundle:User');
            $repo_fieldtype = $em->getRepository('ODRAdminBundle:FieldType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_radio_selection = $em->getRepository('ODRAdminBundle:RadioSelection');

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');

            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            $ret = '';

            // Grab necessary objects
            /** @var User $user */
            $user = $repo_user->find( $user_id );
            /** @var DataRecord $datarecord */
            $datarecord = $repo_datarecord->find( $datarecord_id );
            /** @var DataFields $datafield */
            $datafield = $repo_datafield->find( $datafield_id );
            $em->refresh($datafield);

            /** @var FieldType $old_fieldtype */
            $old_fieldtype = $repo_fieldtype->find( $old_fieldtype_id );
            $old_typeclass = $old_fieldtype->getTypeClass();
            /** @var FieldType $new_fieldtype */
            $new_fieldtype = $repo_fieldtype->find( $new_fieldtype_id );
            $new_typeclass = $new_fieldtype->getTypeClass();

            // Ensure datarecord/datafield pair exist
            if ($datarecord == null)
                throw new \Exception('Datarecord '.$datarecord_id.' is deleted');
            if ($datafield == null)
                throw new \Exception('Datafield '.$datafield_id.' is deleted');


            // Radio options need typename to distinguish...
            $old_typename = $old_fieldtype->getTypeName();
            $new_typename = $new_fieldtype->getTypeName();
            if ($old_typename == $new_typename)
                throw new \Exception('Not allowed to migrate between the same Fieldtype');

            // Need to handle radio options separately...
            if ( ($old_typename == 'Multiple Radio' || $old_typename == 'Multiple Select') && ($new_typename == 'Single Radio' || $new_typename == 'Single Select') ) {
                // If migrating from multiple radio/select to single radio/select, and more than one radio option selected...then need to deselect all but one option

                // Grab all selected radio options
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id, rs.id AS rs_id, rs.selected AS selected, ro.id AS ro_id, rom.optionName AS option_name
                    FROM ODRAdminBundle:DataRecord AS dr
                    JOIN ODRAdminBundle:DataRecordFields AS drf WITH drf.dataRecord = dr
                    JOIN ODRAdminBundle:RadioSelection AS rs WITH rs.dataRecordFields = drf
                    JOIN ODRAdminBundle:RadioOptions AS ro WITH rs.radioOption = ro
                    JOIN ODRAdminBundle:RadioOptionsMeta AS rom WITH rom.radioOption = ro
                    WHERE drf.dataRecord = :datarecord AND drf.dataField = :datafield AND rs.selected = 1
                    AND dr.deletedAt IS NULL AND drf.deletedAt IS NULL AND rs.deletedAt IS NULL AND ro.deletedAt IS NULL AND rom.deletedAt IS NULL
                    ORDER BY rom.displayOrder, ro.id'
                )->setParameters( array('datarecord' => $datarecord->getId(), 'datafield' => $datafield->getId()) );
                $results = $query->getArrayResult();

                // If more than one radio option selected...
                if ( count($results) > 1 ) {
                    // ...deselect all but the first one in the list
                    for ($i = 1; $i < count($results); $i++) {
                        $rs_id = $results[$i]['rs_id'];
                        $ro_id = $results[$i]['ro_id'];
                        $option_name = $results[$i]['option_name'];

                        /** @var RadioSelection $radio_selection */
                        $radio_selection = $repo_radio_selection->find($rs_id);

                        if ($radio_selection->getSelected() == 1) {
                            // Ensure this RadioSelection is unselected
                            $properties = array('selected' => 0);
                            parent::ODR_copyRadioSelection($em, $user, $radio_selection, $properties);

                            $ret .= '>> Deselected Radio Option '.$ro_id.' ('.$option_name.')'."\n";
                        }
                    }
                    $em->flush();
                }
            }
            else if ( $new_typeclass !== 'Radio' ) {
                // Grab the source entity repository
                $src_repository = $em->getRepository('ODRAdminBundle:'.$old_typeclass);

                // Grab the entity that needs to be migrated
                $src_entity = $src_repository->findOneBy(array('dataField' => $datafield->getId(), 'dataRecord' => $datarecord->getId()));

                // No point migrating anything if the src entity doesn't exist in the first place...would be no data in it
                if ($src_entity !== null) {
                    $logger->info('WorkerController::migrateAction() >> Attempting to migrate data from "'.$old_typeclass.'" '.$src_entity->getId().' to "'.$new_typeclass.'"');
                    $ret .= '>> Attempting to migrate data from "'.$old_typeclass.'" '.$src_entity->getId().' to "'.$new_typeclass.'"'."\n";

                    $value = null;
                    if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'ShortVarchar' || $new_typeclass == 'MediumVarchar' || $new_typeclass == 'LongVarchar' || $new_typeclass == 'LongText') ) {
                        // text -> text requires nothing special
                        $value = $src_entity->getValue();
                    }
                    else if ( ($old_typeclass == 'IntegerValue' || $old_typeclass == 'DecimalValue') && ($new_typeclass == 'ShortVarchar' || $new_typeclass == 'MediumVarchar' || $new_typeclass == 'LongVarchar' || $new_typeclass == 'LongText') ) {
                        // number -> text is easy
                        $value = strval($src_entity->getValue());
                    }
                    else if ($old_typeclass == 'IntegerValue' && $new_typeclass == 'DecimalValue') {
                        // integer -> decimal
                        $value = floatval($src_entity->getValue());
                    }
                    else if ($old_typeclass == 'DecimalValue' && $new_typeclass == 'IntegerValue') {
                        // decimal -> integer
                        $value = intval($src_entity->getValue());
                    }
                    else if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'IntegerValue') ) {
                        // text -> integer
                        $pattern = '/[^0-9\.\-]+/i';
                        $replacement = '';
                        $new_value = preg_replace($pattern, $replacement, $src_entity->getValue());

                        $value = intval($new_value);
                    }
                    else if ( ($old_typeclass == 'ShortVarchar' || $old_typeclass == 'MediumVarchar' || $old_typeclass == 'LongVarchar' || $old_typeclass == 'LongText') && ($new_typeclass == 'DecimalValue') ) {
                        // text -> decimal
                        $pattern = '/[^0-9\.\-]+/i';
                        $replacement = '';
                        $new_value = preg_replace($pattern, $replacement, $src_entity->getValue());

                        $value = floatval($new_value);
                    }
                    else if ( $old_typeclass == 'DatetimeValue' ) {
                        // date -> anything
                        $value = null;
                    }
                    else if ( $new_typeclass == 'DatetimeValue' ) {
                        // anything -> date
                        $value = new \DateTime('9999-12-31 00:00:00');
                    }

                    // Save changes
                    if ( $new_typeclass == 'DatetimeValue' )
                        $ret .= 'set dest_entity to "'.$value->format('Y-m-d H:i:s').'"'."\n";
                    else
                        $ret .= 'set dest_entity to "'.$value.'"'."\n";
                    $em->remove($src_entity);

                    $new_obj = parent::ODR_addStorageEntity($em, $user, $datarecord, $datafield);

                    parent::ODR_copyStorageEntity($em, $user, $new_obj, array('value' => $value));
                }
                else {
                    $ret .= '>> No '.$old_typeclass.' source entity for datarecord "'.$datarecord->getId().'" datafield "'.$datafield->getId().'", skipping'."\n";
                }
            }

            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                $total = $tracked_job->getTotal();
                $count = $tracked_job->incrementCurrent($em);

                if ($count >= $total)
                    $tracked_job->setCompleted( new \DateTime() );

                $em->persist($tracked_job);
                $em->flush();
$ret .= '  Set current to '.$count."\n";
            }


            // ----------------------------------------
            // Mark this datarecord as updated
            $dri_service->updateDatarecordCacheEntry($datarecord, $user);

            // TODO - cached search results?


            $return['d'] = $ret;
        }
        catch (\Exception $e) {
            // TODO - increment tracked job counter on error?

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x6642397856: '.$e->getMessage()."\n".$ret;
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Begins the process of rebuilding the image thumbnails for a specific datatype.
     * 
     * @param integer $datatype_id Which datatype should have all its image thumbnails rebuilt
     * @param Request $request
     *
     * @return Response
     */
    public function startrebuildthumbnailsAction($datatype_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $redis = $this->container->get('snc_redis.default');;
            // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');
            $api_key = $this->container->getParameter('beanstalk_api_key');

            /** @var DataType $datatype */
            $datatype = $em->getRepository('ODRAdminBundle:DataType')->find($datatype_id);
            if ($datatype == null)
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
//            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // TODO - check for permissions?  restrict rebuild of thumbnails to certain datatypes?

            if ( !$user->hasRole('ROLE_SUPER_ADMIN') )
                return parent::permissionDeniedError();
            // --------------------


            // ----------------------------------------
            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_rebuild_thumbnails');

            // Grab a list of all full-size images on the site
            $query = $em->createQuery(
               'SELECT e.id
                FROM ODRAdminBundle:Image AS e
                JOIN ODRAdminBundle:DataRecord AS dr WITH e.dataRecord = dr
                WHERE dr.dataType = :datatype AND e.parent IS NULL
                AND e.deletedAt IS NULL AND dr.deletedAt IS NULL'
            )->setParameters(array('datatype' => $datatype_id));
            $results = $query->getArrayResult();

//print_r($results);
//return;

            if (count($results) > 0) {
                // ----------------------------------------
                // Get/create an entity to track the progress of this thumbnail rebuild
                $job_type = 'rebuild_thumbnails';
                $target_entity = 'datatype_'.$datatype_id;
                $additional_data = array('description' => 'Rebuild of all image thumbnails for DataType '.$datatype_id);
                $restrictions = '';
                $total = count($results);
                $reuse_existing = false;

                $tracked_job = parent::ODR_getTrackedJob($em, $user, $job_type, $target_entity, $additional_data, $restrictions, $total, $reuse_existing);
                $tracked_job_id = $tracked_job->getId();

                // ----------------------------------------
                $object_type = 'image';
                foreach ($results as $num => $result) {
                    $object_id = $result['id'];

                    // Insert the new job into the queue
                    $priority = 1024;   // should be roughly default priority
                    $payload = json_encode(
                        array(
                            "tracked_job_id" => $tracked_job_id,
                            "object_type" => $object_type,
                            "object_id" => $object_id,
                            "redis_prefix" => $redis_prefix,    // debug purposes only
                            "url" => $url,
                            "api_key" => $api_key,
                        )
                    );

                    $delay = 1;
                    $pheanstalk->useTube('rebuild_thumbnails')->put($payload, $priority, $delay);
                }
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38472782 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Called by the rebuild_thumbnails worker process to rebuild the thumbnails of one of the uploaded images on the site. 
     * 
     * @param Request $request
     * 
     * @return Response
     */
    public function rebuildthumbnailsAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        $tracked_job_id = -1;

        try {
            $post = $_POST;
            if ( !isset($post['tracked_job_id']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $tracked_job_id = intval($post['tracked_job_id']);
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            // ----------------------------------------
            // Ensure the full-size image exists
            parent::decryptObject($object_id, $object_type);

            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var Image $img */
            $img = $em->getRepository('ODRAdminBundle:Image')->find($object_id);
            if ($img == null)
                throw new \Exception('Image '.$object_id.' has been deleted');

            /** @var User $user */
            $user = $em->getRepository('ODROpenRepositoryUserBundle:User')->find(2);    // TODO - need an actual system user...


            // Ensure an ImageSizes entity exists for this image
            /** @var ImageSizes[] $image_sizes */
            $image_sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataFields' => $img->getDataField()->getId()) );
            if ( count($image_sizes) == 0 ) {
                // Create missing ImageSizes entities for this datafield
                parent::ODR_checkImageSizes($em, $user, $img->getDataField());

                // Reload the newly created ImageSizes for this datafield
                while ( count($image_sizes) == 0 ) {
                    sleep(1);   // wait a second so whichever process is creating the ImageSizes entities has time to finish
                    $image_sizes = $em->getRepository('ODRAdminBundle:ImageSizes')->findBy( array('dataFields' => $img->getDataField()->getId()) );
                }

                // Set this image to point to the correct ImageSizes entity, since it didn't exist before
                foreach ($image_sizes as $size) {
                    if ($size->getOriginal() == true) {
                        $img->setImageSize($size);
                        $em->persist($img);
                    }
                }

                $em->flush($img);
                $em->refresh($img);
            }

            // Recreate the thumbnail from the full-sized image
            parent::resizeImages($img, $user);


            // ----------------------------------------
            // Update the job tracker if necessary
            if ($tracked_job_id !== -1) {
                /** @var TrackedJob $tracked_job */
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                if ($tracked_job !== null) {
                    $total = $tracked_job->getTotal();
                    $count = $tracked_job->incrementCurrent($em);

                    if ($count >= $total)
                        $tracked_job->setCompleted(new \DateTime());

                    $em->persist($tracked_job);
                    $em->flush();
//$ret .= '  Set current to '.$count."\n";
                }
            }

            $return['d'] = '>> Rebuilt thumbnails for '.$object_type.' '.$object_id."\n";
        }
        catch (\Exception $e) {
            // Update the job tracker even if an error occurred...right? TODO
            if ($tracked_job_id !== -1) {
                $em = $this->getDoctrine()->getManager();
                /** @var TrackedJob $tracked_job */
                $tracked_job = $em->getRepository('ODRAdminBundle:TrackedJob')->find($tracked_job_id);

                if ($tracked_job !== null) {
                    $total = $tracked_job->getTotal();
                    $count = $tracked_job->incrementCurrent($em);

                    if ($count >= $total)
                        $tracked_job->setCompleted(new \DateTime());

                    $em->persist($tracked_job);
                    $em->flush();
                }
            }

            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38472782 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Performs an asynchronous encrypt or decrypt on a specified file.  Also has the option
     *
     * @param Request $request
     *
     * @return Response
     */
    public function cryptorequestAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        $error_prefix = 'Error 0x65384782: ';
        $handle = null;

        try {
            $post = $_POST;
//print_r($post);
//return;
            if ( !isset($post['crypto_type']) || !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['target_filename']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $crypto_type = $post['crypto_type'];
            $object_type = strtolower( $post['object_type'] );
            $object_id = $post['object_id'];
            $target_filename = $post['target_filename'];
            $api_key = $post['api_key'];

            $error_prefix .= $crypto_type.' for '.$object_type.' '.$object_id.'...';

            // These two are only used if the files are being decrypted into a zip archive
            $archive_filepath = '';
            if ( isset($post['archive_filepath']) )
                $archive_filepath = $post['archive_filepath'];

            $desired_filename = '';
            if ( isset($post['desired_filename']) )
                $desired_filename = $post['desired_filename'];


            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var DatarecordInfoService $dri_service */
            $dri_service = $this->container->get('odr.datarecord_info_service');


            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            if ( !is_numeric($post['object_id']) )
                throw new \Exception('$object_id is not numeric');
            else
                $object_id = intval($object_id);

            $base_obj = null;
            if ($object_type == 'file')
                $base_obj = $em->getRepository('ODRAdminBundle:File')->find($object_id);
//            else if ($object_type == 'image')
//                $base_obj = $em->getRepository('ODRAdminBundle:Image')->find($object_id);

            // NOTE - encryption after image upload is currently done inline in ODRCustomController::finishUploadAction()
            // Also, they're decrypted inline when needed...if they were done asynch, the browser couldn't display non-public versions in <img> tags


            if ($base_obj == null)
                throw new \Exception('could not load object '.$object_id.' of type "'.$object_type.'"');
            /** @var File|Image $base_obj */


            // ----------------------------------------
            if ($crypto_type == 'encrypt') {

                // ----------------------------------------
                // Move file from completed directory to decrypted directory in preparation for encryption...
                $destination_path = dirname(__FILE__).'/../../../../web';
                $destination_filename = $base_obj->getUploadDir().'/File_'.$object_id.'.'.$base_obj->getExt();
                rename( $base_obj->getLocalFileName(), $destination_path.'/'.$destination_filename );

                // Update local filename and checksum in database...
                $base_obj->setLocalFileName($destination_filename);

                $original_checksum = md5_file($destination_path.'/'.$destination_filename);
                $base_obj->setOriginalChecksum($original_checksum);

                // Encryption of a given file/image is simple...
                parent::encryptObject($object_id, $object_type);

                if ($object_type == 'file') {
                    $base_obj->setProvisioned(false);

                    $em->persist($base_obj);
                    $em->flush();
                    $em->refresh($base_obj);
                }

                // Update the datarecord cache so whichever controller is handling the "are you done encrypting yet?" javascript requests can return the correct HTML
                $datarecord = $base_obj->getDataRecord();
                $dri_service->updateDatarecordCacheEntry($datarecord, $base_obj->getCreatedBy());
            }
            else if ($crypto_type == 'decrypt') {
                // This is (currently) the only request the user has made for this file...begin manually decrypting it because the crypto bundle offers limited control over filenames
                $crypto = $this->get("dterranova_crypto.crypto_adapter");
                $crypto_dir = dirname(__FILE__).'/../../../../app/crypto_dir/';     // TODO - load from config file somehow?
                $crypto_dir .= 'File_'.$object_id;

                $base_filepath = dirname(__FILE__).'/../../../../web/'.$base_obj->getUploadDir();
                $local_filepath = $base_filepath.'/'.$target_filename;

                // Don't decrypt the file if it already exists on the server
                if ( !file_exists($local_filepath) ) {
                    // Grab the hex string representation that the file was encrypted with
                    $key = $base_obj->getEncryptKey();
                    // Convert the hex string representation to binary...php had a function to go bin->hex, but didn't have a function for hex->bin for at least 7 years?!?
                    $key = pack("H*", $key);   // don't have hex2bin() in current version of php...this appears to work based on the "if it decrypts to something intelligible, you did it right" theory

                    // Open the target file
                    $handle = fopen($local_filepath, "wb");
                    if (!$handle)
                        throw new \Exception('Unable to open "'.$local_filepath.'" for writing');

                    // Decrypt each chunk and write to target file
                    $chunk_id = 0;
                    while (file_exists($crypto_dir.'/'.'enc.'.$chunk_id)) {
                        if (!file_exists($crypto_dir.'/'.'enc.'.$chunk_id))
                            throw new \Exception('Encrypted chunk not found: '.$crypto_dir.'/'.'enc.'.$chunk_id);

                        $data = file_get_contents($crypto_dir.'/'.'enc.'.$chunk_id);
                        fwrite($handle, $crypto->decrypt($data, $key));
                        $chunk_id++;
                    }
                }

                if ( $archive_filepath == '' ) {
                    $redis = $this->container->get('snc_redis.default');;
                    // $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);
                    $redis_prefix = $this->container->getParameter('memcached_key_prefix');

                    $file_decryptions = parent::getRedisData(($redis->get($redis_prefix.'_file_decryptions')));

                    unset($file_decryptions[$target_filename]);
                    $redis->set($redis_prefix.'_file_decryptions', gzcompress(serialize($file_decryptions)));
                }
                else {
                    // Attempt to open the specified zip archive
                    $handle = fopen($archive_filepath, 'c');    // create file if it doesn't exist, otherwise do not fail and position pointer at beginning of file
                    if (!$handle)
                        throw new \Exception('unable to open "'.$archive_filepath.'" for writing');

                    // Attempt to acquire a lock on the zip archive so only one process is adding to it at a time
                    $lock = false;
                    while (!$lock) {
                        $lock = flock($handle, LOCK_EX);
                        if (!$lock)
                            usleep(200000);     // sleep for a fifth of a second to try to acquire a lock...
                    }

                    // Open the archive for appending, or create if it doesn't exist
                    $zip_archive = new \ZipArchive();
                    $zip_archive->open($archive_filepath, \ZipArchive::CREATE);

                    // Add the specified file to the zip archive
                    $zip_archive->addFile($local_filepath, $desired_filename);
                    $zip_archive->close();

                    // Delete decrypted version of non-public files off the server
                    if (!$base_obj->isPublic())
                        unlink($local_filepath);

                    // Release the lock on the zip archive
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }
            }
            else {
                throw new \Exception('bad value for $crypto_type, got "'.$crypto_type.'"');
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = $error_prefix.$e->getMessage();

            // TODO - delete the partial non-public file if some sort of error during decryption?

            if ($handle != null) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears existing cached versions of datatypes (optionally for a specific datatype).
     * Should only be used when changes have been made to the structure of the cached array for datatypes
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function dtclearAction($datatype_id, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');


            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $results = array();
            if ($datatype_id == 0) {
                // Locate all existing datatype ids
                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id
                    FROM ODRAdminBundle:DataType AS dt'
                );

                $results = $query->getArrayResult();
            }
            else {
                // Locate all child/linked datatypes for the specified datatype
                $datatype_ids = $dti_service->getAssociatedDatatypes( array($datatype_id) );

                $query = $em->createQuery(
                   'SELECT dt.id AS dt_id
                    FROM ODRAdminBundle:DataType AS dt
                    WHERE dt.id IN (:datatype_ids)'
                )->setParameters( array('datatype_ids' => $datatype_ids) );

                $results = $query->getArrayResult();
            }

            $cache_service->delete('cached_datatree_array');
            $cache_service->delete('top_level_datatypes');

            $keys_to_delete = array();
            foreach ($results as $result) {
                $dt_id = $result['dt_id'];

                // All datatypes have these keys
                $keys_to_delete[] = 'cached_datatype_'.$dt_id;
                $keys_to_delete[] = 'datatype_'.$dt_id.'_record_order';

                // Child datatypes don't have these keys, but deleting them doesn't hurt anything
                $keys_to_delete[] = 'dashboard_'.$dt_id;
                $keys_to_delete[] = 'dashboard_'.$dt_id.'_public_only';
                $keys_to_delete[] = 'associated_datatypes_for_'.$dt_id;
            }

            foreach ($keys_to_delete as $key) {
                if ($cache_service->exists($key) ) {
                    $cache_service->delete($key);
                    print '"'.$key.'" deleted'."\n";
                }
            }
        }
        catch (\Exception $e) {
            $source = 0xaa016ab8;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears existing cached versions of themes (optionally for a specific datatype).
     * Should only be used when changes have been made to the structure of the cached array for themes.
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function themeclearAction($datatype_id, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');


            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $results = array();
            if ($datatype_id == 0) {
                // Locate all existing theme ids
                $query = $em->createQuery(
                   'SELECT t.id AS t_id
                    FROM ODRAdminBundle:Theme AS t'
                );

                $results = $query->getArrayResult();
            }
            else {
                // Locate all themes for this datatype, its children, and its linked datatypes (just because)
                $datatype_ids = $dti_service->getAssociatedDatatypes( array($datatype_id) );

                $query = $em->createQuery(
                   'SELECT t.id AS t_id
                    FROM ODRAdminBundle:Theme AS t
                    WHERE t.dataType IN (:datatype_ids)'
                )->setParameters( array('datatype_ids' => $datatype_ids) );

                $results = $query->getArrayResult();
            }

            $keys_to_delete = array();
            foreach ($results as $result) {
                $t_id = $result['t_id'];
                $keys_to_delete[] = 'cached_theme_'.$t_id;
            }

            foreach ($keys_to_delete as $key) {
                if ($cache_service->exists($key) ) {
                    $cache_service->delete($key);
                    print '"'.$key.'" deleted'."\n";
                }
            }
        }
        catch (\Exception $e) {
            $source = 0xe4e80e34;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears existing cached versions of datarecords (optionally for a given datatype).
     * Should only be used when changes have been made to the structure of the cached array for datarecords
     *
     * @param integer $datatype_id
     * @param Request $request
     *
     * @return Response
     */
    public function drclearAction($datatype_id, Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');


            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $query = null;
            if ($datatype_id == 0) {
                // Locate all existing datarecord ids
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr'
                );
            }
            else {
                // Only want datarecords of the specified datatype
                $query = $em->createQuery(
                   'SELECT dr.id AS dr_id
                    FROM ODRAdminBundle:DataRecord AS dr
                    WHERE dr.dataType = :datatype_id'
                )->setParameters(array('datatype_id' => $datatype_id));
            }
            $results = $query->getArrayResult();

            $keys_to_delete = array();
            foreach ($results as $result) {
                $dr_id = $result['dr_id'];

                $keys_to_delete[] = 'associated_datarecords_for_'.$dr_id;
                $keys_to_delete[] = 'cached_datarecord_'.$dr_id;
                $keys_to_delete[] = 'datarecord_table_data_'.$dr_id;
            }

            foreach ($keys_to_delete as $num => $key) {
                if ($cache_service->exists($key) ) {
                    $cache_service->delete($key);
                    print '"'.$key.'" deleted'."\n";
                }
            }
        }
        catch (\Exception $e) {
            $source = 0xc178ea4b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears all existing cached search result.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function searchclearAction(Request $request)
    {
        try {
            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();

            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            $cache_service->delete('cached_search_results');
        }
        catch (\Exception $e) {
            $source = 0xcb3e7952;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function...clears all existing cached search result.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function permissionsclearAction(Request $request)
    {
        try {
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');

            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();

            // ----------------------------------------
            // Clear all cached user permissions
            $query = $em->createQuery(
               'SELECT u.id AS user_id
                FROM ODROpenRepositoryUserBundle:User AS u'
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $user_id = $result['user_id'];
                $cache_service->delete('user_'.$user_id.'_permissions');
            }


            // ----------------------------------------
            // Clear all cached group permissions
            $query = $em->createQuery(
               'SELECT g.id AS group_id
                FROM ODRAdminBundle:Group AS g'
            );
            $results = $query->getArrayResult();

            foreach ($results as $result) {
                $group_id = $result['group_id'];
                $cache_service->delete('group_'.$group_id.'_permissions');
            }
        }
        catch (\Exception $e) {
            $source = 0x2afc476b;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $return = array(
            'r' => 0,
            't' => '',
            'd' => '',
        );

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Begins the process of forcibly (re)encrypting every uploaded file/image on the site
     *
     * @param string $object_type "File" or "Image"...which type of entity to encrypt
     * @param Request $request
     *
     */
    public function startencryptAction($object_type, Request $request)
    {
/*
        $em = $this->getDoctrine()->getManager();
        $pheanstalk = $this->get('pheanstalk');
        $router = $this->container->get('router');
        $redis = $this->container->get('snc_redis.default');;
        $redis_prefix = $this->container->getParameter('memcached_key_prefix');

        $api_key = $this->container->getParameter('beanstalk_api_key');

        // Generate the url for cURL to use
        $url = $this->container->getParameter('site_baseurl');
        $url .= $router->generate('odr_force_encrypt');

        if ($object_type == 'file' || $object_type == 'File')
            $object_type = 'File';
        else if ($object_type == 'image' || $object_type == 'Image')
            $object_type = 'Image';
        else
            return null;

        $query = $em->createQuery(
           'SELECT e.id
            FROM ODRAdminBundle:'.$object_type.' AS e
            WHERE e.deletedAt IS NULL'
        );
        $results = $query->getResult();

//print_r($results);
//return;

        $object_type = strtolower($object_type);
        foreach ($results as $num => $result) {
            $object_id = $result['id'];

            // Insert the new job into the queue
            $priority = 1024;   // should be roughly default priority
            $payload = json_encode(
                array(
                    "object_type" => $object_type,
                    "object_id" => $object_id,
                    "redis_prefix" => $redis_prefix,    // debug purposes only
                    "url" => $url,
                    "api_key" => $api_key,
                )
            );

            $delay = 1;
            $pheanstalk->useTube('mass_encrypt')->put($payload, $priority, $delay);

//return;
        }
*/
    }


    /**
     * Called by the mass_encrypt worker background process to (re)encrypt a single file or image.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function encryptAction(Request $request)
    {
/*
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            $post = $_POST;
            if ( !isset($post['object_type']) || !isset($post['object_id']) || !isset($post['api_key']) )
                throw new \Exception('Invalid Form');

            // Pull data from the post
            $object_type = $post['object_type'];
            $object_id = $post['object_id'];
            $api_key = $post['api_key'];

            // Load symfony objects
            $beanstalk_api_key = $this->container->getParameter('beanstalk_api_key');
            if ($api_key !== $beanstalk_api_key)
                throw new \Exception('Invalid Form');

            parent::decryptObject($object_id, $object_type);    // ensure a decrypted object exists prior to attempting to encrypt
            parent::encryptObject($object_id, $object_type);

            $return['d'] = '>> Encrypted '.$object_type.' '.$object_id."\n";
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x38378231 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
*/
    }


    /**
     * Begins the process of forcibly decrypting every uploaded file/image on the site.
     *
     * @param string $object_type "File" or "Image"...which type of entity to encrypt
     * @param Request $request
     *
     */
    public function startdecryptAction($object_type, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $pheanstalk = $this->get('pheanstalk');
            $router = $this->container->get('router');
            $redis = $this->container->get('snc_redis.default');;
            $redis_prefix = $this->container->getParameter('memcached_key_prefix');

            $api_key = $this->container->getParameter('beanstalk_api_key');

            // Generate the url for cURL to use
            $url = $this->container->getParameter('site_baseurl');
            $url .= $router->generate('odr_crypto_request');

            if ($object_type == 'file' || $object_type == 'File')
                $object_type = 'File';
//            else if ($object_type == 'image' || $object_type == 'Image')
//                $object_type = 'Image';
            else
                return null;

            $query = null;
            if ($object_type == 'File') {
                $query = $em->createQuery(
                    'SELECT e.id
                    FROM ODRAdminBundle:'.$object_type.' AS e
                    WHERE e.deletedAt IS NULL AND (e.original_checksum IS NULL OR e.filesize = 0)'
                );
            }
/*
            else if ($object_type == 'Image') {
                $query = $em->createQuery(
                    'SELECT e.id
                    FROM ODRAdminBundle:'.$object_type.' AS e
                    WHERE e.deletedAt IS NULL AND e.original_checksum IS NULL'
                );
            }
*/
            $results = $query->getResult();

            $object_type = strtolower($object_type);
            foreach ($results as $num => $file) {
                // Insert the new job into the queue
                $priority = 1024;   // should be roughly default priority
                $payload = json_encode(
                    array(
                        "object_type" => $object_type,
                        "object_id" => $file['id'],
                        "target_filename" => '',
                        "crypto_type" => 'decrypt',

                        "archive_filepath" => '',
                        "desired_filename" => '',

                        "redis_prefix" => $redis_prefix,    // debug purposes only
                        "url" => $url,
                        "api_key" => $api_key,
                    )
                );

                $delay = 1;
                $pheanstalk->useTube('crypto_requests')->put($payload, $priority, $delay);
            }

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x45387831 '.$e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Debug function to force the correct datetime format in the database
     *
     * @param Request $request
     */
    public function fixdatabasedatesAction(Request $request)
    {
        /** @var User $user */
        $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
        if (!$user->hasRole('ROLE_SUPER_ADMIN'))
            throw new ODRForbiddenException();

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();
        $em->getFilters()->disable('softdeleteable');

        $has_created = $has_updated = $has_deleted = $has_publicdate = array();

        print '<pre>';
        $filepath = dirname(__FILE__).'/../Entity/';
        $filelist = scandir($filepath);
        foreach ($filelist as $num => $filename) {

            if ( strlen($filename) > 3 && strpos($filename, '~') === false && strpos($filename, '.bck') === false ) {
                $handle = fopen($filepath.$filename, 'r');
                if (!$handle)
                    throw new ODRException('Unable to open file');

                $classname = '';
                while ( !feof($handle) ) {
                    $line = fgets($handle);

                    $matches = array();
                    if ( preg_match('/^class ([^\s]+)$/', $line, $matches) == 1 )
                        $classname = $matches[1];

                    if ( strpos($line, 'private $created;') !== false )
                        $has_created[] = $classname;
                    if ( strpos($line, 'private $updated;') !== false )
                        $has_updated[] = $classname;
                    if ( strpos($line, 'private $deletedAt;') !== false )
                        $has_deleted[] = $classname;
                    if ( strpos($line, 'private $publicDate;') !== false )
                        $has_publicdate[] = $classname;
                }

                fclose($handle);
            }
        }
/*
        print "has created: \n";
        print_r($has_created);
        print "has updated: \n";
        print_r($has_updated);
        print "has deleted: \n";
        print_r($has_deleted);
        print "has publicDate: \n";
        print_r($has_publicdate);
*/
        $bad_created = $bad_updated = $bad_deleted = $bad_publicdate = array();
        $parameter = array('bad_date' => "0000-00-00%");

        foreach ($has_created as $num => $classname) {
            $query = $em->createQuery(
               'SELECT COUNT(e.id)
                FROM ODRAdminBundle:'.$classname.' AS e
                WHERE e.created LIKE :bad_date'
            )->setParameters($parameter);
            $results = $query->getArrayResult();

            if ( $results[0][1] > 0 )
                $bad_created[$classname] = $results[0][1];
        }

        foreach ($has_updated as $num => $classname) {
            $query = $em->createQuery(
               'SELECT COUNT(e.id)
                FROM ODRAdminBundle:'.$classname.' AS e
                WHERE e.updated LIKE :bad_date'
            )->setParameters($parameter);
            $results = $query->getArrayResult();

            if ( $results[0][1] > 0 )
                $bad_updated[$classname] = $results[0][1];
        }

        foreach ($has_deleted as $num => $classname) {
            $query = $em->createQuery(
               'SELECT COUNT(e.id)
                FROM ODRAdminBundle:'.$classname.' AS e
                WHERE e.deletedAt LIKE :bad_date'
            )->setParameters($parameter);
            $results = $query->getArrayResult();

            if ( $results[0][1] > 0 )
                $bad_deleted[$classname] = $results[0][1];
        }

        foreach ($has_publicdate as $num => $classname) {
            $query = $em->createQuery(
               'SELECT COUNT(e.id)
                FROM ODRAdminBundle:'.$classname.' AS e
                WHERE e.publicDate LIKE :bad_date'
            )->setParameters($parameter);
            $results = $query->getArrayResult();

            if ( $results[0][1] > 0 )
                $bad_publicdate[$classname] = $results[0][1];
        }

        print "bad created: \n";
        print_r($bad_created);
        print "bad updated: \n";
        print_r($bad_updated);
        print "bad deleted: \n";
        print_r($bad_deleted);
        print "bad publicDate: \n";
        print_r($bad_publicdate);


        foreach ($bad_updated as $classname => $num) {
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:'.$classname.' AS e
                SET e.updated = e.deletedAt
                WHERE e.deletedAt IS NOT NULL'
            );
//            $first = $query->execute();

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:'.$classname.' AS e
                SET e.updated = e.created
                WHERE e.updated < :good_date'
            )->setParameters( array('good_date' => '2000-01-01 00:00:00') );
//            $second = $query->execute();
        }

        $em->getFilters()->enable('softdeleteable');
        print '</pre>';
    }


    /**
     * Migration function, fills in parent/grandparent property of the datatype.
     *
     * @param Request $request
     *
     * @return Response
     */
    public function datatypemigrateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        try {
            // ----------------------------------------
            // Load required objects
            /** @var \Doctrine\ORM\EntityManager $em */
            $em = $this->getDoctrine()->getManager();
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            /** @var CacheService $cache_service */
            $cache_service = $this->container->get('odr.cache_service');
            /** @var DatatypeInfoService $dti_service */
            $dti_service = $this->container->get('odr.datatype_info_service');


            /** @var User $user */
            $user = $this->container->get('security.token_storage')->getToken()->getUser();   // <-- will return 'anon.' when nobody is logged in
            if (!$user->hasRole('ROLE_SUPER_ADMIN'))
                throw new ODRForbiddenException();


            // Load all datatypes, deleted or not
            $em->getFilters()->disable('softdeleteable');

            $query = $em->createQuery(
               'SELECT dt.id, dt.deletedAt
                FROM ODRAdminBundle:DataType AS dt
                ORDER BY dt.id'
            );
            $results = $query->getArrayResult();

            print '<pre>';
            foreach ($results as $num => $dt) {
                $dt_id = $dt['id'];
                $cache_service->delete('cached_datatype_'.$dt_id);

                if ( $dt['deletedAt'] == null )
                    print 'datatype '.$dt_id.':'."\n";
                else
                    print 'deleted datatype '.$dt_id.':'."\n";

                $sub_query = $em->createQuery(
                   'SELECT dt, ancestor, descendant

                    FROM ODRAdminBundle:DataTree AS dt
                    JOIN dt.dataTreeMeta AS dtm
                    JOIN dt.ancestor AS ancestor
                    JOIN dt.descendant AS descendant

                    WHERE dt.descendant = :dt_id AND dtm.is_link = 0
                    ORDER BY dt.deletedAt ASC'
                )->setParameters( array('dt_id' => $dt_id) );
                $sub_results = $sub_query->getArrayResult();

                if ( count($sub_results) == 0 ) {
                    /** @var DataType $datatype */
                    $datatype = $repo_datatype->find($dt_id);

                    $datatype->setParent($datatype);
                    $datatype->setGrandparent($datatype);

                    if ($save) {
                        $em->persist($datatype);
                        $em->flush();
                        $em->refresh($datatype);
                    }

                    print "\tsetting parent to datatype ".$dt_id."\n";
                    print "\tsetting grandparent to datatype ".$dt_id."\n";
                }
                else if ( count($sub_results) == 1 ) {

                    $dt = $sub_results[0];
                    /** @var DataType $datatype */
                    $datatype = $repo_datatype->find($dt_id);
                    /** @var DataType $ancestor */
                    $ancestor = $repo_datatype->find($dt['ancestor']['id']);

                    $datatype->setParent($ancestor);
                    $datatype->setGrandparent($ancestor->getGrandparent());

                    if ($save) {
                        $em->persist($datatype);
                        $em->flush();
                        $em->refresh($datatype);
                    }

                    print "\tsetting parent to datatype ".$ancestor->getId()."\n";
                    if ($save)
                        print "\tsetting grandparent to datatype ".$ancestor->getGrandparent()->getId()."\n";
                }
                else {

                    if ( $sub_results[0]['deletedAt'] == null ) {
                        // Due to sort ASC, null values should be at the top
                        // A null value here means this is the current datatree entry, and therefore should be the one used

                        $dt = $sub_results[0];
                        /** @var DataType $datatype */
                        $datatype = $repo_datatype->find($dt_id);
                        /** @var DataType $ancestor */
                        $ancestor = $repo_datatype->find($dt['ancestor']['id']);

                        $datatype->setParent($ancestor);
                        $datatype->setGrandparent($ancestor->getGrandparent());

                        if ($save) {
                            $em->persist($datatype);
                            $em->flush();
                            $em->refresh($datatype);
                        }

                        print "\tsetting parent to datatype ".$ancestor->getId()."\n";
                        if ($save)
                            print "\tsetting grandparent to datatype ".$ancestor->getGrandparent()->getId()."\n";
                    }
                    else {
                        // ...otherwise, there is no non-deleted datatree entry
                        // The desired one is at the bottom of the array

                        $dt = $sub_results[ count($sub_results) - 1 ];
                        /** @var DataType $datatype */
                        $datatype = $repo_datatype->find($dt_id);
                        /** @var DataType $ancestor */
                        $ancestor = $repo_datatype->find($dt['ancestor']['id']);

                        $datatype->setParent($ancestor);
                        $datatype->setGrandparent($ancestor->getGrandparent());

                        if ($save) {
                            $em->persist($datatype);
                            $em->flush();
                            $em->refresh($datatype);
                        }

                        print "\tsetting parent to datatype ".$ancestor->getId()."\n";
                        if ($save)
                            print "\tsetting grandparent to datatype ".$ancestor->getGrandparent()->getId()."\n";
                    }
                }

                print "\n";
            }
            print '</pre>';

            $em->getFilters()->enable('softdeleteable');

            $cache_service->delete('cached_datatree_array');
            $datatree_array = $dti_service->getDatatreeArray();

            $query = $em->createQuery(
               'SELECT dt, parent

                FROM ODRAdminBundle:DataType AS dt
                JOIN dt.parent AS parent'
            );
            $results = $query->getArrayResult();

            print '<pre>';
            foreach ($results as $num => $dt) {
                $dt_id = $dt['id'];
                $parent_id = $dt['parent']['id'];

                $cache_service->delete('cached_datatype_'.$dt_id);

                if ( isset($datatree_array['descendant_of'][$dt_id]) ) {
                    if ( $datatree_array['descendant_of'][$dt_id] == '' ) {
                        if ( $dt_id == $parent_id ){
                            print 'datatype '.$dt_id.' is correctly marked as a top-level datatype'."\n";
                        }
                    }
                    else if ( $datatree_array['descendant_of'][$dt_id] == $parent_id ) {
                        print 'datatype '.$dt_id.' is correctly marked as child of datatype '.$parent_id."\n";
                    }
                    else {
                        print 'error with datatype '.$dt_id.'...marked as having parent datatype '.$parent_id.' but should have '.$datatree_array['descendant_of'][$dt_id]."\n";
                    }
                }
                else {
                    print 'datatype '.$dt_id.' not found in datatree array'."\n";
                }
            }
            print '</pre>';

        }
        catch (\Exception $e) {
            $em->getFilters()->enable('softdeleteable');

            $source = 0xabcdef00;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * @param Request $request
     */
    public function thememigrateAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        $save = false;
//        $save = true;

        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->getDoctrine()->getManager();

        try {
            // ----------------------------------------
            // Load required objects
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');


            print '<pre>';
            // ----------------------------------------
            // First off, ensure that all themes without a valid "theme_type" are deleted
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:Theme AS t
                SET t.deletedAt = :new_date
                WHERE t.themeType = :empty_string
                AND t.deletedAt IS NULL'
            )->setParameters( array('new_date' => new \DateTime(), 'empty_string' => '') );
            $rows = $query->execute();
            print 'Deleted '.$rows.' themes where theme_type == ""'."\n";

            // Also ensure no "derivative" theme_type exists either...this shouldn't do anything, but making sure
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:Theme AS t
                SET t.deletedAt = :new_date
                WHERE t.themeType = :empty_string
                AND t.deletedAt IS NULL'
            )->setParameters( array('new_date' => new \DateTime(), 'empty_string' => 'derivative') );
            $rows = $query->execute();
            print 'Deleted '.$rows.' themes where theme_type == "derivative"'."\n";


            // ----------------------------------------
            // Get a list of all table themes, even deleted ones
            $em->getFilters()->disable('softdeleteable');

            $query = $em->createQuery(
               'SELECT t.id AS id
                FROM ODRAdminBundle:Theme AS t
                WHERE t.themeType = :theme_type'
            )->setParameters( array('theme_type' => 'table') );
            $results = $query->getArrayResult();

            $table_theme_ids = array();
            foreach ($results as $num => $theme)
                $table_theme_ids[] = $theme['id'];


            // Store the 'table' flag in the themeMeta entry...
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeMeta AS tm
                SET tm.isTableTheme = :is_table_theme
                WHERE tm.theme IN (:theme_ids)'
            )->setParameters( array('is_table_theme' => true, 'theme_ids' => $table_theme_ids) );
            $rows = $query->execute();
            print 'Updated "isTableTheme" property for '.$rows.' theme meta entries'."\n";


            // Change all of these themes to be a 'search_results' theme instead
            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:Theme AS t
                SET t.themeType = :new_theme_type
                WHERE t.themeType = :old_theme_type'
            )->setParameters( array('old_theme_type' => 'table', 'new_theme_type' => 'search_results') );
            $rows = $query->execute();
            print 'Changed themeType property for '.$rows.' theme entries from "table" into "search_results"'."\n";

            $em->getFilters()->enable('softdeleteable');


            print "\n";

            // ----------------------------------------
            //
            $query = $em->createQuery(
               'SELECT t, tm, pt, st, dt, dt_g

                FROM ODRAdminBundle:Theme AS t
                JOIN t.themeMeta AS tm

                LEFT JOIN t.parentTheme AS pt
                LEFT JOIN t.sourceTheme AS st

                LEFT JOIN t.dataType AS dt
                JOIN dt.grandparent AS dt_g

                WHERE t.deletedAt IS NULL AND tm.deletedAt IS NULL
                AND dt.deletedAt IS NULL AND dt_g.deletedAt IS NULL'
            );
            $results = $query->getArrayResult();

//            print_r($results);

            // ----------------------------------------
            /*
             * - datatype 1
             *     - child datatype 2
             *     - child datatype 3
             * 	   - link to datatype 4
             * - datatype 4
             *
             * theme 1 is "master" for datatype 1, parent_theme_id == 1, source_theme_id == 1
             * theme 2 is "master" for datatype 2, parent_theme_id == 1, source_theme_id == 2
             * theme 3 is "master" for datatype 3, parent_theme_id == 1, source_theme_id == 3
             * theme 4 is "master" for datatype 4, parent_theme_id == 4, source_theme_id == 4
             *
             * creates a "search results" theme for datatype 1
             * - theme 5 is "search_results" for datatype 1, parent_theme_id == 5, source_theme_id == 1
             * - theme 6 is "search_results" for datatype 2, parent_theme_id == 5, source_theme_id == 2
             * - theme 7 is "search_results" for datatype 3, parent_theme_id == 5, source_theme_id == 3
             *
             * creates a "search results" theme for datatype 4
             * - theme 8 is "search_results" for datatype 4, parent_theme_id == 8, source_theme_id == 4
             *
             * copies the existing "search results" theme of datatype 1 to make a new one
             * - theme 9 is "search_results" for datatype 1, parent_theme_id == 9, source_theme_id == 1
             * - theme 10 is "search_results" for datatype 2, parent_theme_id == 9, source_theme_id == 2
             * - theme 11 is "search_results" for datatype 3, parent_theme_id == 9, source_theme_id == 3
             *
             * No copy made of theme 8, so both theme groups 5 and 9 use theme 8 to display datatype 4?
             */

            $theme_list = array();
            foreach ($results as $num => $theme) {
                $theme_id = $theme['id'];
                $theme_type = $theme['themeType'];

                $parent_theme_id = -1;
                if ( isset($theme['parentTheme']) && isset($theme['parentTheme']['id']) )
                    $parent_theme_id = $theme['parentTheme']['id'];
                $source_theme_id = -1;
                if ( isset($theme['sourceTheme']) && isset($theme['sourceTheme']['id']) )
                    $source_theme_id = $theme['sourceTheme']['id'];


                $dt_id = $theme['dataType']['id'];
                $g_dt_id = $theme['dataType']['grandparent']['id'];

                if ( !isset($theme_list[$dt_id]) )
                    $theme_list[$dt_id] = array();
                if ( !isset($theme_list[$dt_id][$theme_type]) )
                    $theme_list[$dt_id][$theme_type] = array();

                $theme_list[$dt_id][$theme_type][] = array(
                    'theme_id' => $theme_id,
                    'parent_theme_id' => $parent_theme_id,
                    'source_theme_id' => $source_theme_id,
                    'grandparent_datatype_id' => $g_dt_id
                );
            }

            print_r($theme_list);

            foreach ($theme_list as $dt_id => $themes) {

                print 'Updating themes for Datatype '.$dt_id.'...'."\n";

                // ----------------------------------------
                // Do some sanity checks...
                if ( !isset($themes['master']) )
                    throw new ODRException('Datatype '.$dt_id.' lacks a master theme, aborting');
                if ( count($themes['master']) > 1 )
                    throw new ODRException('Datatype '.$dt_id.' has more than one master theme, aborting');

                // ----------------------------------------
                // Deal with the master theme first...
                $master_theme_data = $themes['master'][0];
                $master_theme_id = $master_theme_data['theme_id'];

                print ' - master theme '.$master_theme_id.'...'."\n";

                /** @var Theme $master_theme */
                $master_theme = $repo_theme->find($master_theme_id);
                if ($master_theme == null)
                    throw new ODRException('Datatype '.$dt_id.' referenced a non-existent master theme '.$master_theme_id);

                // If source theme id is not set, then set it to this theme's id
                if ( $master_theme_data['source_theme_id'] == -1 ) {
                    $master_theme->setSourceTheme($master_theme);
                    print ' - - set source theme to '.$master_theme_id."\n";
                }
                else {
                    print ' - - source theme is already set to '.$master_theme->getSourceTheme()->getId().', would have set it to '.$master_theme_id."\n";
                    if ( $master_theme_id != $master_theme->getSourceTheme()->getId() )
                        print ' - - - MISMATCH'."\n";
                }

                // If parent theme id is not set, then set it to the datatype's grandparent's master theme
                $grandparent_datatype_id = $master_theme_data['grandparent_datatype_id'];
                $parent_theme_id = $theme_list[$grandparent_datatype_id]['master'][0]['theme_id'];
                if ( $master_theme_data['parent_theme_id'] == -1 ) {
                    /** @var Theme $parent_theme */
                    $parent_theme = $repo_theme->find($parent_theme_id);
                    if ($parent_theme == null)
                        throw new ODRException('Datatype '.$dt_id.' master theme '.$master_theme_id.' attempted to locate a non-existent grandparent datatype master theme '.$parent_theme_id);

                    $master_theme->setParentTheme($parent_theme);
                    print ' - - set parent theme to datatype '.$grandparent_datatype_id.' master, theme '.$parent_theme_id."\n";
                }
                else {
                    print ' - - parent theme is already set to '.$master_theme->getParentTheme()->getId().', would have set it to '.$parent_theme_id."\n";
                    if ( $parent_theme_id != $master_theme->getParentTheme()->getId() )
                        print ' - - - MISMATCH'."\n";
                }

                // Save changes to the master theme
                if ($save)
                    $em->persist($master_theme);


                // ----------------------------------------
                if ( isset($themes['search_results']) ) {
                    // Deal with search result themes...
                    foreach ($themes['search_results'] as $num => $search_results_theme_data) {
                        $search_results_theme_id = $search_results_theme_data['theme_id'];
                        $parent_theme_id = $search_results_theme_data['parent_theme_id'];
                        $source_theme_id = $search_results_theme_data['source_theme_id'];
                        $grandparent_datatype_id = $search_results_theme_data['grandparent_datatype_id'];

                        print ' - search results theme '.$search_results_theme_id.'...'."\n";

                        /** @var Theme $search_results_theme */
                        $search_results_theme = $repo_theme->find($search_results_theme_id);
                        if ($search_results_theme == null)
                            throw new ODRException('Datatype '.$dt_id.' referenced a non-existent search results theme '.$search_results_theme_id);


                        // If source theme id is not set, then set it to the datatype's master theme
                        $datatype_master_theme_id = $theme_list[$dt_id]['master'][0]['theme_id'];
                        if ($source_theme_id == -1) {
                            /** @var Theme $source_theme */
                            $source_theme = $repo_theme->find($datatype_master_theme_id);
                            if ($source_theme == null)
                                throw new ODRException('Datatype '.$dt_id.' search results theme '.$search_results_theme_id.' attempted to locate a non-existent datatype master theme '.$datatype_master_theme_id);

                            $search_results_theme->setSourceTheme($source_theme);
                            print ' - - set source theme to grandparent datatype '.$grandparent_datatype_id.' master theme '.$datatype_master_theme_id."\n";
                        }
                        else {
                            print ' - - source theme is already set to '.$search_results_theme->getSourceTheme()->getId().', would have set it to '.$datatype_master_theme_id."\n";
                            if ( $datatype_master_theme_id != $search_results_theme->getSourceTheme()->getId() )
                                print ' - - - MISMATCH'."\n";
                        }

                        // If this is a search results theme for a top-level datatype...
                        if ($dt_id == $grandparent_datatype_id) {
                            // ...then the parent_theme_id should be set to itself
                            if ( $parent_theme_id == -1 ) {
                                $search_results_theme->setParentTheme($search_results_theme);
                                print ' - - set parent theme to '.$search_results_theme_id."\n";
                            }
                            else {
                                print ' - - parent theme is already set to '.$search_results_theme->getParentTheme()->getId().', would have set it to '.$search_results_theme_id."\n";
                                if ( $search_results_theme_id != $search_results_theme->getParentTheme()->getId() )
                                    print ' - - - MISMATCH'."\n";
                            }
                        }
                        else {

                            if ( $source_theme_id == -1 ) {
                                /*
                                 * Technically, if there's just one search results theme in both this datatype and its grandparent, i should be able to match them up...
                                 * but do i have a way to figure out multiples?
                                 */

                                print ' - - set parent theme to grandparent datatype '.$grandparent_datatype_id.' search results theme ***UNKNOWN***'."\n";
                                print ' - - - MISMATCH'."\n";
                            }
                            else {
                                print ' - - parent theme is already set to '.$search_results_theme->getParentTheme()->getId().', would have set it to ***UNKNOWN***'."\n";
                                print ' - - - MISMATCH'."\n";
                            }

                        }

                        // Save changes to this search results theme
                        if ($save)
                            $em->persist($search_results_theme);
                    }
                }

                print "\n";
            }

            if ($save)
                $em->flush();


            // ----------------------------------------
            // Run one last set of queries to force all themes to be shared by default
            $em->getFilters()->disable('softdeleteable');

            $query = $em->createQuery(
               'UPDATE ODRAdminBundle:ThemeMeta AS tm
                SET tm.shared = :shared'
            )->setParameters( array('shared' => true) );

            if ($save)
                $rows = $query->execute();

            $em->getFilters()->enable('softdeleteable');

            print '</pre>';
        }
        catch (\Exception $e) {
            // Don't want any changes made being saved to the database
            $em->clear();

            $source = 0xabcdef11;
            if ($e instanceof ODRException)
                throw new ODRException($e->getMessage(), $e->getstatusCode(), $e->getSourceCode($source));
            else
                throw new ODRException($e->getMessage(), 500, $source, $e);
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }
}
