<?php

/**
* Open Data Repository Data Publisher
* Record Controller
* (C) 2015 by Nathan Stone (nate.stone@opendatarepository.org)
* (C) 2015 by Alex Pires (ajpires@email.arizona.edu)
* Released under the GPLv2
*
* The record handles everything required to edit any kind of
* data stored in a DataRecord.
*
*/

namespace ODR\AdminBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller;

// Entites
use ODR\AdminBundle\StoredRecord;
use ODR\AdminBundle\Entity\Theme;
use ODR\AdminBundle\Entity\ThemeDataField;
use ODR\AdminBundle\Entity\ThemeDataType;
use ODR\AdminBundle\Entity\DataFields;
use ODR\AdminBundle\Entity\DataType;
use ODR\AdminBundle\Entity\DataTree;
use ODR\AdminBundle\Entity\LinkedDataTree;
use ODR\AdminBundle\Entity\DataRecord;
use ODR\AdminBundle\Entity\DataRecordFields;
use ODR\AdminBundle\Entity\Boolean;
use ODR\AdminBundle\Entity\ShortVarchar;
use ODR\AdminBundle\Entity\MediumVarchar;
use ODR\AdminBundle\Entity\LongVarchar;
use ODR\AdminBundle\Entity\LongText;
use ODR\AdminBundle\Entity\File;
use ODR\AdminBundle\Entity\Image;
use ODR\AdminBundle\Entity\ImageSizes;
use ODR\AdminBundle\Entity\ImageStorage;
use ODR\AdminBundle\Entity\RadioOption;
use ODR\AdminBundle\Entity\RadioSelection;
use ODR\AdminBundle\Entity\DecimalValue;
use ODR\AdminBundle\Entity\DatetimeValue;
use ODR\AdminBundle\Entity\IntegerValue;
// Forms
use ODR\AdminBundle\Form\BooleanForm;
use ODR\AdminBundle\Form\DatafieldsForm;
use ODR\AdminBundle\Form\DatatypeForm;
use ODR\AdminBundle\Form\UpdateDataFieldsForm;
use ODR\AdminBundle\Form\UpdateDataTypeForm;
use ODR\AdminBundle\Form\ShortVarcharForm;
use ODR\AdminBundle\Form\MediumVarcharForm;
use ODR\AdminBundle\Form\LongVarcharForm;
use ODR\AdminBundle\Form\LongTextForm;
use ODR\AdminBundle\Form\DecimalValueForm;
use ODR\AdminBundle\Form\DatetimeValueForm;
use ODR\AdminBundle\Form\IntegerValueForm;
// Symfony
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;


class RecordController extends ODRCustomController
{

    /**
     * Handles selection changes made to SingleRadio, MultipleRadio, SingleSelect, and MultipleSelect DataFields
     * 
     * @param integer $data_record_field_id The database id of the DataRecord/DataField pair being modified.
     * @param integer $radio_option_id      The database id of the RadioOption entity being (de)selected.
     * @param integer $multiple             '1' if RadioOption allows multiple selections, '0' otherwise.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function radioselectionAction($data_record_field_id, $radio_option_id, $multiple, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields')->find($data_record_field_id);
            if ( $datarecordfield == null )
                return parent::deletedEntityError('DataRecordField');

            $datafield = $datarecordfield->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datatype = $datafield->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            $radio_selections = $em->getRepository('ODRAdminBundle:RadioSelection')->findBy( array('dataRecordFields' => $datarecordfield->getId()) );
            $repo_radio_options = $em->getRepository('ODRAdminBundle:RadioOptions');
            $radio_option = $repo_radio_options->find($radio_option_id);
            if ( $radio_option == null )
                return parent::deletedEntityError('RadioOption');


            // Go through all the radio selections
            $found = false;
            if ($radio_selections != null) {
                foreach ($radio_selections as $selection) {

                    // If the radio selection already exists
                    if ($radio_option_id == $selection->getRadioOption()->getId()) {
                        // Found the one that was selected
                        $found = true;
                        $selection->setUpdatedBy($user);

                        if ($multiple == "1") {
                            // Radio group permits multiple selections, toggle the selected option
                            if ($selection->getSelected() == 0)
                                $selection->setSelected(1);
                            else
                                $selection->setSelected(0);
                        }
                        else {
                            // Radio group only permits single selection, set to selected
                            $selection->setSelected(1);
                        }
                    }
                    else if ($multiple == "0") {  // if the radio group only permits a single selection
                        // Unselect the other options
                        $selection->setUpdatedBy($user);
                        $selection->setSelected(0);
                    }
                }
            }

            if (!$found && $radio_option_id != "0") {
                // Create a new radio selection
                $initial_value = 1;
                parent::ODR_addRadioSelection($em, $user, $radio_option, $datarecordfield, $initial_value);
            }

            // Flush all changes
            $em->flush();

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            $datarecord = $datarecordfield->getDataRecord();
            parent::updateDatarecordCache($datarecord->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x18373679 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
     * Creates a new DataRecord.
     * 
     * @param integer $datatype_id The database id of the DataType this DataRecord will belong to.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function addAction($datatype_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo 
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'add' ])) )
                return parent::permissionDeniedError("create new DataRecords for");
            // --------------------

    
            // Get default form theme (theme_type = "form"
            $query = $em->createQuery(
                'SELECT t FROM ODRAdminBundle:Theme t WHERE t.isDefault = 1 AND t.templateType = :template_type'
                )->setParameter('template_type', 'form');

            $themes = $query->getResult();
            if(count($themes) > 1 || count($themes) == 0) {
                throw new \Exception("An invalid form theme was found.  Error: 0X82383992.");
            }
            $theme = $themes[0];


            // Create new Data Record
            $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);

            $em->flush();
            $em->refresh($datarecord);

            // Top Level Record - must have grandparent and parent set to itself
            $parent = $repo_datarecord->find($datarecord->getId());
            $grandparent = $repo_datarecord->find($datarecord->getId());
            $datarecord->setGrandparent($grandparent);
            $datarecord->setParent($parent);
            $em->persist($datarecord);

            $em->flush();
            $em->refresh($datarecord);

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'datarecord_id' => $datarecord->getId()
            );

            // Build the cache entries for this new datarecord
            $options = array();
            parent::updateDatarecordCache($datarecord->getId(), $options);

            // Delete the list of DataRecords for this DataType that ShortResults uses to build its list
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x29328834 ' . $e->getMessage();
        }
    
        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Creates a new DataRecord and sets it as a child of the given DataRecord.
     * 
     * @param integer $datatype_id    The database id of the child DataType this new child DataRecord will belong to.
     * @param integer $parent_id      The database id of the DataRecord...
     * @param integer $grandparent_id The database id of the top-level DataRecord in this inheritance chain.
     * @param Request $request
     * 
     * @return TODO
     */
    public function addchildrecordAction($datatype_id, $parent_id, $grandparent_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo 
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // Grab needed Entities from the repository
            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');

            $parent = $repo_datarecord->find($parent_id);
            if ( $parent == null )
                return parent::deletedEntityError('DataRecord');

            $grandparent = $repo_datarecord->find($grandparent_id);
            if ( $grandparent == null )
                return parent::deletedEntityError('DataRecord');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'add' ])) )
                return parent::permissionDeniedError("add child DataRecords to");
            // --------------------

            // Create new Data Record
            $datarecord = parent::ODR_addDataRecord($em, $user, $datatype);

            $datarecord->setGrandparent($grandparent);
            $datarecord->setParent($parent);
            $em->persist($datarecord);

            $grandparent->addChildren($datarecord); // TODO - needed?
            $em->persist($grandparent);

            $em->flush();

            // Ensure the new child record has all its fields
            parent::verifyExistence($datatype, $datarecord);


            // Get record_ajax.html.twig to re-render the datarecord
            $return['d'] = array(
                'new_datarecord_id' => $datarecord->getId(),
                'datatype_id' => $datatype_id,
                'parent_id' => $parent->getId(),
            );

            // Refresh the cache entries for this datarecord
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatarecordCache($grandparent->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x293288355555 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a DataRecord.
     * 
     * @param integer $datarecord_id The database id of the datarecord to delete.
     * @param Request $request
     * 
     * @return TODO
     */
    public function deleteAction($datarecord_id, $search_key, Request $request)
    {
   
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab memcached stuff
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
            $repo_linked_data_tree = $em->getRepository('ODRAdminBundle:LinkedDataTree');

            // Grab the necessary entities
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ($datarecord == null)
                return parent::deletedEntityError('Datarecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('Datatype');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'delete' ])) )
                return parent::permissionDeniedError("delete DataRecords from");
            // --------------------


            // -----------------------------------
            // Delete DataRecordField entries for this datarecord
            // TODO - do this with a DQL update query?

//            $datarecordfields = $repo_datarecordfields->findBy( array('dataRecord' => $datarecord->getId()) );
//            foreach ($datarecordfields as $drf)
//                $em->remove($drf);
            $query = $em->createQuery(
               'SELECT drf.id AS drf_id
                FROM ODRAdminBundle:DataRecordFields drf
                WHERE drf.dataRecord = :datarecord'
            )->setParameters( array('datarecord' => $datarecord->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $data) {
                $drf_id = $data['drf_id'];
                $drf = $repo_datarecordfields->find($drf_id);
                $em->remove($drf);
            }

            // Build a list of all datarecords that need recaching as a result of this deletion
            $recache_list = array();

            // Locate and delete any LinkedDataTree entities so rendering doesn't crash
            $linked_data_trees = $repo_linked_data_tree->findBy( array('descendant' => $datarecord->getId()) );
            foreach ($linked_data_trees as $ldt) {
                // Need to recache the datarecord on the other side of the link
                $ancestor_id = $ldt->getAncestor()->getGrandparent()->getId();
                if ( !in_array($ancestor_id, $recache_list) )
                    $recache_list[] = $ancestor_id;

                $em->remove($ldt);
            }
            $linked_data_trees = $repo_linked_data_tree->findBy( array('ancestor' => $datarecord->getId()) );
            foreach ($linked_data_trees as $ldt) {
                // Need to recache the datarecord on the other side of the link
                $descendant_id = $ldt->getDescendant()->getGrandparent()->getId();
                if ( !in_array($descendant_id, $recache_list) )
                    $recache_list[] = $descendant_id;
 
                $em->remove($ldt);
            }

            // Delete the datarecord entity like the user wanted, in addition to all children of this datarecord so external_id doesn't grab them
            $datarecords = $repo_datarecord->findBy( array('grandparent' => $datarecord->getId()) );
            foreach ($datarecords as $dr) {
                $em->remove($dr);

                // TODO - links to/from child datarecord?
            }
            $em->flush();

            // Delete the list of DataRecords for this DataType that ShortResults uses to build its list
            $memcached->delete($memcached_prefix.'.data_type_'.$datatype->getId().'_record_order');


            // Schedule all datarecords that were connected to the now deleted datarecord for a recache
            foreach ($recache_list as $num => $datarecord_id) {
                parent::updateDatarecordCache($datarecord_id);
            }


            // -----------------------------------
            // Determine how many datarecords of this datatype remain
            $query = $em->createQuery(
               'SELECT dr.id AS dr_id
                FROM ODRAdminBundle:DataRecord dr
                WHERE dr.deletedAt IS NULL AND dr.dataType = :datatype'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $remaining = $query->getArrayResult();

            // Determine which url to redirect to
            $url = '';
            if ($search_key == '') {
                // Not deleting from a search result list
                if ( count($remaining) > 0 ) {
                    // Return the url to Get ShortResults controller to regenerate the list of datarecords for this datatype
                    $url = $this->generateUrl('odr_shortresults_list', array('datatype_id' => $datatype->getId(), 'target' => 'record'));
                }
                else {
                    // No records to display on a ShortResults list, return to list of all datatypes
                    $url = $this->generateUrl('odr_list_types', array('section' => 'records'));
                }
            }
            else {
                // Deleting from a search result list
                if ( count($remaining) > 0 ) {
                    // Redirect to search list
                    $url = $this->generateURL('odr_search_render', array('search_key' => $search_key));
                }
                else {
                    // Redirect to search page
                    $url = $this->generateURL('odr_search');
                }
            }

            $return['d'] = $url;
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2039183556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a child DataRecord, and re-renders the DataRecord so the child disappears.
     * TODO - modify this so $datatype_id isn't needed?
     * 
     * @param integer $datarecord_id The database id of the datarecord being deleted
     * @param integer $datatype_id
     * @param Request $request
     * 
     * @return TODO
     */
    public function deletechildrecordAction($datarecord_id, $datatype_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');

            // Grab the necessary entities
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $repo_datatype->find($datatype_id);
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'delete' ])) )
                return parent::permissionDeniedError("delete child DataRecords from");
            // --------------------

            // Grab the grandparent data record so GetDisplayData creates html for all the child records of this datatype
            $parent = $datarecord->getParent();
            $grandparent = $datarecord->getGrandparent();

            // Delete the datarecord entity like the user wanted
            $em->remove($datarecord);
            $em->flush();

            // Refresh the cache entries for the grandparent
            $options = array();
            $options['mark_as_updated'] = true;
            parent::updateDatarecordCache($grandparent->getId(), $options);

            // TODO - schedule recaches for other datarecords?

            // Get record_ajax.html.twig to rRe-render the datarecord
            $return['d'] = array(
                'datatype_id' => $datatype_id,
                'parent_id' => $parent->getId(),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x203288355556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a user-uploaded file from the database.
     * TODO - delete from server as well?
     * 
     * @param integer $file_id The database id of the File to delete.
     * @param Request $request
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function deletefileAction($file_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_file = $em->getRepository('ODRAdminBundle:File');

            // Grab the necessary entities
            $file = $repo_file->find($file_id);
            if ( $file == null )
                return parent::deletedEntityError('File');

            $datafield = $file->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $file->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("delete files from");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Delete the file entity like the user wanted
            $em->remove($file);
            $em->flush();

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x203288355556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of a file.
     * 
     * @param integer $file_id The database id of the File to modify.
     * @param Request $request
     * 
     * @return TODO
     */
    public function publicfileAction($file_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_file = $em->getRepository('ODRAdminBundle:File');

            // Grab the necessary entities
            $file = $repo_file->find($file_id);
            if ( $file == null )
                return parent::deletedEntityError('File');

            $datafield = $file->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $file->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // If the file is public, make it non-public...if file is non-public, make it public
            $public_date = $file->getPublicDate();
            if ( $file->isPublic() ) {
                // Make the record non-public
                $file->setPublicDate(new \DateTime('2200-01-01 00:00:00'));

                // Delete the decrypted version of the file, if it exists
                $file_upload_path = dirname(__FILE__).'/../../../../web/uploads/files/';
                $filename = 'File_'.$file_id.'.'.$file->getExt();
                $absolute_path = realpath($file_upload_path).'/'.$filename;

//                if ( file_exists($absolute_path) )
//                    unlink($absolute_path);
            }
            else {
                // Make the record public
                $file->setPublicDate(new \DateTime());

                // Immediately decrypt the file
                parent::decryptObject($file->getId(), 'file');
            }

            $file->setUpdatedBy($user);
            $em->persist($file);
            $em->flush();

            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'datarecord' => $datarecord->getId(),
                'datafield' => $datafield->getId()
            );

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x203288355556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of an image.
     * 
     * @param integer $image_id The database id of the Image to modify
     * @param Request $request
     * 
     * @return TODO
     */
    public function publicimageAction($image_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            $image = $repo_image->find($image_id);
            if ( $image == null )
                return parent::deletedEntityError('Image');

            $datafield = $file->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $file->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Grab all children of the original image (resizes, i believe)
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            $images[] = $image;

            // If the images are public, make them non-public...if images are non-public, make them public
            $public_date = $image->getPublicDate();
            if ( $image->isPublic() ) {
                foreach ($images as $img) {
                    // Make the image non-public
                    $img->setPublicDate(new \DateTime('2200-01-01 00:00:00'));
                    $img->setUpdatedBy($user);
                    $em->persist($img);

                    // Delete the decrypted version of the image, if it exists
                    $image_upload_path = dirname(__FILE__).'/../../../../web/uploads/images/';
                    $filename = 'Image_'.$img->getId().'.'.$img->getExt();
                    $absolute_path = realpath($image_upload_path).'/'.$filename;

//                    if ( file_exists($absolute_path) )
//                        unlink($absolute_path);
                }
            }
            else {
                foreach ($images as $img) {
                    // Make the image public
                    $img->setPublicDate(new \DateTime());
                    $img->setUpdatedBy($user);
                    $em->persist($img);

                    // Immediately decrypt the image
                    parent::decryptObject($img->getId(), 'image');
                }
            }

            $em->flush();


            // Need to rebuild this particular datafield's html to reflect the changes...
            $return['t'] = 'html';
            $return['d'] = array(
                'datarecord' => $datarecord->getId(),
                'datafield' => $datafield->getId()
            );

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2038825456 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Deletes a user-uploaded image from the repository.
     * 
     * @param integer $image_id The database id of the Image to delete.
     * @param Request $request
     * 
     * @return TODO
     */
    public function deleteimageAction($image_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the necessary entities
            $image = $repo_image->find($image_id);
            if ( $image == null )
                return parent::deletedEntityError('Image');

            $datafield = $image->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $image->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Grab all children of the original image (resizes, i believe) and remove them
            $images = $repo_image->findBy( array('parent' => $image->getId()) );
            foreach ($images as $img)
                $em->remove($img);
            // Remove the original image as well
            $em->remove($image);
            $em->flush();

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2078485256 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Modifies the display order of the images in an Image control.
     * 
     * @param Request $request 
     * 
     * @return an empty Symfony JSON response, unless an error occurred
     */
    public function saveimageorderAction(Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $post = $_POST;
//print_r($post);
//return;
            $em = $this->getDoctrine()->getManager();
            $repo_image = $em->getRepository('ODRAdminBundle:Image');

            // Grab the first image just to check permissions
            $image = null;
            foreach ($post as $index => $image_id) {
                $image = $repo_image->find($image_id);
                break;
            }

            $datafield = $image->getDataField();
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $datarecord = $image->getDataRecord();
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield->getId() ]) && isset($datafield_permissions[ $datafield->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // If user has permissions, go through all of the image thumbnails setting the order
            for($i = 0; $i < count($post); $i++) {
                $image = $repo_image->find( $post[$i] );
                $em->refresh($image);

                $image->setDisplayorder($i);
                $image->setUpdatedBy($user);

                $em->persist($image);
            }
            $em->flush();

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;
            if ( parent::inShortResults($datafield) )
                $options['force_shortresults_recache'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId());
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x822889302 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Toggles the public status of a DataRecord.
     * 
     * @param integer $datarecord_id The database id of the DataRecord to modify.
     * @param Request $request 
     * 
     * @return TODO
     */
    public function publicdatarecordAction($datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype->getId() ]) && isset($user_permissions[ $datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Toggle the public status of the datarecord
            $public = 0;
            $public_date = $datarecord->getPublicDate();
            if ( $datarecord->isPublic() ) {
                // Make the record non-public
                $datarecord->setPublicDate(new \DateTime('2200-01-01 00:00:00'));
                $public = 0;
            }
            else {
                // Make the record public
                $datarecord->setPublicDate(new \DateTime());
                $public = 1;
            }

            // Save the change to this child datarecord
            $em->persist($datarecord);
            $em->flush();

            // Determine whether ShortResults needs a recache
            $options = array();
            $options['mark_as_updated'] = true;

            // Refresh the cache entries for this datarecord
            parent::updateDatarecordCache($datarecord->getId(), $options);

            // re-render?  wat
            $return['d'] = array(
                'datarecord_id' => $datarecord_id,
//                'datarecord_id' => $datarecord->getGrandparent()->getId(),
//                'datatype_id' => $datatype->getId(),
                'public' => $public,
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x2028983556 '. $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Parses a $_POST request to update the contents of a datafield.
     * 
     * @param string $record_type    Apparently, the typeclass of the datafield being modified.
     * @param integer $datarecord_id The database id of the datarecord being modified.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function updateAction($record_type, $datarecord_id, Request $request) 
    {
        // Save Data Record Entries
        $return = array();
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Get the Entity Manager
            $em = $this->getDoctrine()->getManager();
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datafield = $em->getRepository('ODRAdminBundle:DataFields');

            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');

            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');

            $datatype_id = $datatype->getId();
            $sortfield = $datatype->getSortField();

            $datafield_id = $_POST[$record_type.'Form']['data_field'];
            $datafield = $repo_datafield->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $datatype_id ]) && isset($user_permissions[ $datatype_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            if ( !(isset($datafield_permissions[ $datafield_id ]) && isset($datafield_permissions[ $datafield_id ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Need to reload the datafield on file/image update
            $need_datafield_reload = false;

            // Determine Form based on Type
            $form_classname = "\\ODR\\AdminBundle\\Form\\" . $record_type . 'Form';
            $obj_classname = "ODR\\AdminBundle\\Entity\\" . $record_type;

            $form = null;
            $my_obj = null;
            switch($record_type) {
                case 'DatetimeValue':
                case 'ShortVarchar':
                case 'MediumVarchar':
                case 'LongVarchar':
                case 'LongText':    // paragraph text
                case 'IntegerValue':
                case 'DecimalValue':
                case 'Boolean':
                    $my_obj = new $obj_classname();
                    $post = $_POST;
                    if ( isset($post['id']) && $post['id'] > 0 ) {
                        $repo = $em->getRepository('ODRAdminBundle:'.$record_type);
                        $my_obj = $repo->find($post['id']);
                    }
                break;

                case 'Image':
                    // Move and store file
                    $my_obj = new Image();
                    $my_obj->setDisplayOrder(0);

                    $need_datafield_reload = true;
                break;
                case 'File':
                    // Move and store file
                    $my_obj = new File();

                    $need_datafield_reload = true;
                break;
            }
            $form = $this->createForm(
                new $form_classname($em), 
                $my_obj
            );

//print_r($_POST);
//exit();

            // Check to see if the field's new data has to be unique...and if so, enforce uniqueness
            $uniqueness_failure = false;
            $uniqueness_failure_msg = '';

/*
            if ($datatype->getUniqueField() == $datafield) {
                // Ensure the new value doesn't collide with any existing value
                $new_value = $_POST[$record_type.'Form']['value'];
                $failed_values = parent::verifyUniqueness($datafield, (array)$new_value, $datarecord);

                // If a non-empty array was returned, the value collided
                if ( count($failed_values) > 0 ) {
                    // Garb the datarecordfield that holds the original piece of data
                    $drf = $repo_datarecordfields->findOneBy( array("dataRecord" => $datarecord->getId(), "dataField" => $datafield->getId()) );

                    // Determine which method to use to extract the old value
                    $old_value = '';
                    switch($record_type) {
                        case 'DecimalValue':
                        case 'IntegerValue':
                        case 'ShortVarchar':
                        case 'MediumVarchar':
                        case 'LongVarchar':
                        case 'LongText':
                            $tmp_obj = parent::loadFromDataRecordField($drf, $record_type);
                            $old_value = $tmp_obj->getValue();
                            break;
                    }

                    $form_name = 'EditDataRecordFieldsForm_'.$_POST[$record_type.'Form']['data_record_fields'];

                    // Build the error message
                    $uniqueness_failure = true;
                    $uniqueness_failure_msg = $form_name.'||'.$old_value.'||The datafield "'.$datafield->getFieldName().'" is marked as unique, but the value "'.$new_value.'" already exists in another datarecord!';
                }
            }
*/

            if ($uniqueness_failure) {
                // Notify of a uniqueness failure
                $return['r'] = 2;
                $return['t'] = '';
                $return['d'] = $uniqueness_failure_msg;
            }
            // $templating = $this->get('templating');
            else if ($request->getMethod() == 'POST') {
                $form->bind($request, $my_obj);
                if ($form->isValid()) {

                    if ($record_type == 'Image' || $record_type == 'File') {
                        // Pre-persist
                        $my_obj->setLocalFileName('temp');
                        $my_obj->setCreatedBy($user);
                        $my_obj->setUpdatedBy($user);
                        $my_obj->setExternalId('');
                        $my_obj->setOriginalChecksum('');
                    }

                    if ($record_type == 'Image') {
                        // Move and store file
                        $my_obj->setOriginalFileName($my_obj->getFile()->getClientOriginalName());
                        $my_obj->setOriginal('1');
                        $my_obj->setDisplayOrder(0);
                        $my_obj->setPublicDate(new \DateTime('1980-01-01 00:00:00'));   // default to not public
                    }
                    else if ($record_type == 'File') {
                        // Move and store file
                        $my_obj->setOriginalFileName($my_obj->getUploadedFile()->getClientOriginalName());
                        $my_obj->setGraphable('1');
                        $my_obj->setPublicDate(new \DateTime('2200-01-01 00:00:00'));   // TODO - default to public?
                    }

                    // Post Persist
                    $em->persist($my_obj);
                    $em->flush();

                    switch($record_type) {
                        case 'File':
                            // Generate Local File Name
                            $filename = $my_obj->getUploadDir() . "/File_" . $my_obj->getId() . "." . $my_obj->getExt();
                            $my_obj->setLocalFileName($filename);

                            parent::encryptObject($my_obj->getId(), 'file');

                            // set original checksum?
                            $file_path = parent::decryptObject($my_obj->getId(), 'file');
                            $original_checksum = md5_file($file_path);
                            $my_obj->setOriginalChecksum($original_checksum);
                        break;

                        case 'Image':
                            // Generate Local File Name
                            $filename = $my_obj->getUploadDir() . "/Image_" . $my_obj->getId() . "." . $my_obj->getExt();
                            $my_obj->setLocalFileName($filename);

                            $sizes = getimagesize($filename);
                            $my_obj->setImageWidth( $sizes[0] );
                            $my_obj->setImageHeight( $sizes[1] );

                            // Create thumbnails and other sizes/versions of the uploaded image
                            parent::resizeImages($my_obj, $user);

                            // Encrypt parent image AFTER thumbnails are created
                            parent::encryptObject($my_obj->getId(), 'image');

                            // Set original checksum for original image
                            $file_path = parent::decryptObject($my_obj->getId(), 'image');
                            $original_checksum = md5_file($file_path);
                            $my_obj->setOriginalChecksum($original_checksum);
                        break;
                    }

                    // Commit Changes
                    $em->persist($my_obj);
                    $em->flush();


                    // If the datafield needed to be reloaded (file/image), do it and return that?
                    if ($need_datafield_reload) {
                        $return['r'] = 0;
                        $return['t'] = 'html';
                        $return['d'] = array(
                            'datarecord' => $datarecord_id,
                            'datafield' => $datafield->getId()
                        );
                    }

                    // If the field that got modified is the sort field for this datatype, delete the pre-sorted datarecord list ShortResults uses from memcached
                    // This should force a resort next time the ShortResults for this datatype is accessed
                    if ($sortfield !== null && $sortfield->getId() == $datafield->getId())
                        $memcached->delete($memcached_prefix.'.data_type_'.$datatype_id.'_record_order');

                    // Determine whether ShortResults needs a recache
                    $options = array();
                    $options['mark_as_updated'] = true;
                    if ( parent::inShortResults($datafield) )
                        $options['force_shortresults_recache'] = true;
                    if ( $datafield->getDisplayOrder() != -1 )
                        $options['force_textresults_recache'] = true;

                    // Refresh the cache entries for this datarecord
                    parent::updateDatarecordCache($datarecord_id, $options);
                }
                else {
//                    $errors = $this->getErrorMessages($form);
                    $errors = parent::ODR_getErrorMessages($form);
//print_r($errors);   // TODO - what was the point of this?
//exit();
//                    $error = $errors['file'][0];
//                    $error = $errors['uploaded_file'][0];
                    throw new \Exception($errors);
                }
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x88320029 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));  
        $response->headers->set('Content-Type', 'application/json');
        return $response;  
    }


    /**
     * Builds and returns a list of available 'descendant' datarecords to link to from this 'ancestor' datarecord.
     * If such a link exists, GetDisplayData() will render a read-only version of the 'remote' datarecord in a ThemeElement of the 'local' datarecord.
     * 
     * @param integer $ancestor_datatype_id   The database id of the DataType that is being linked from
     * @param integer $descendant_datatype_id The database id of the DataType that is being linked to
     * @param integer $local_datarecord_id    The database id of the DataRecord being modified.
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function getlinkablerecordsAction($ancestor_datatype_id, $descendant_datatype_id, $local_datarecord_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Get Entity Manager and setup repo 
            $em = $this->getDoctrine()->getManager();
            $repo_linked_datatree = $em->getRepository('ODRAdminBundle:LinkedDataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');

            // Grab the datatypes from the database
            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ( $local_datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ( $ancestor_datatype == null )
                return parent::deletedEntityError('DataType');

            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ( $descendant_datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $ancestor_datatype->getId() ]) && isset($user_permissions[ $ancestor_datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------

$debug = true;
$debug = false;
if ($debug) {
    print "local datarecord: ".$local_datarecord_id."\n";
    print "ancestor datatype: ".$ancestor_datatype_id."\n";
    print "descendant datatype: ".$descendant_datatype_id."\n";
}

            // Determine which datatype we're trying to create a link with
            $local_datatype = $local_datarecord->getDataType();
            $remote_datatype = null;
            if ($local_datatype->getId() == $ancestor_datatype_id)
                $remote_datatype = $repo_datatype->find($descendant_datatype_id);   // Linking to a remote datarecord from this datarecord
            else
                $remote_datatype = $repo_datatype->find($ancestor_datatype_id);     // Getting a remote datarecord to link to this datarecord

if ($debug)
    print "\nremote datatype: ".$remote_datatype->getId()."\n";
/*
            // Locate all datarecords associated with that datatype
            $datarecords = array();
            $datarecord_str = parent::getSortedDatarecords($remote_datatype);
            $datarecords = explode(',', $datarecord_str);

if ($debug) {
    print " -- remote datarecords\n";
    foreach ($datarecords as $num => $dr_id)
        print " -- ".$dr_id."\n";
}
*/
            // Grab all datarecords currently linked to the local_datarecord
            $linked_datarecords = array();
            if ($local_datatype->getId() == $ancestor_datatype_id) {
                // local_datarecord is on the ancestor side of the link
                $query = $em->createQuery(
                   'SELECT descendant.id AS descendant_id
                    FROM ODRAdminBundle:DataRecord ancestor
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.ancestor = ancestor
                    JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                    WHERE ancestor = :local_datarecord AND descendant.dataType = :remote_datatype
                    AND ldt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
                )->setParameters( array('local_datarecord' => $local_datarecord->getId(), 'remote_datatype' => $remote_datatype->getId()) );
                $results = $query->getResult();
                foreach ($results as $num => $data) {
                    $descendant_id = $data['descendant_id'];
                    if ( $descendant_id == null || trim($descendant_id) == '' )
                        continue;

                    $linked_datarecords[ $descendant_id ] = 1;
                }
            }
            else {
                // local_datarecord is on the descendant side of the link
                $query = $em->createQuery(
                   'SELECT ancestor.id AS ancestor_id
                    FROM ODRAdminBundle:DataRecord descendant
                    JOIN ODRAdminBundle:LinkedDataTree AS ldt WITH ldt.descendant = descendant
                    JOIN ODRAdminBundle:DataRecord AS ancestor WITH ldt.ancestor = ancestor
                    WHERE descendant = :local_datarecord AND ancestor.dataType = :remote_datatype
                    AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
                )->setParameters( array('local_datarecord' => $local_datarecord->getId(), 'remote_datatype' => $remote_datatype->getId()) );
                $results = $query->getResult();
                foreach ($results as $num => $data) {
                    $ancestor_id = $data['ancestor_id'];
                    if ( $ancestor_id == null || trim($ancestor_id) == '' )
                        continue;

                    $linked_datarecords[ $ancestor_id ] = 1;
                }

            }

if ($debug) {
    print "\nlinked datarecords\n";
    foreach ($linked_datarecords as $id => $value)
        print '-- '.$id."\n";
}


            // Determine whether the user is permitted to select multiple datarecords in the dialog
            $allow_multiple_links = true;
            if ($local_datarecord->getDataType()->getId() == $ancestor_datatype->getId()) {
                // User entering this from the ancestor side...
                if ($descendant_datatype->getMultipleRecordsPerParent() == '0') {
                    // Only allowed to link to one descendant at a time
                    $allow_multiple_links = false;
                }
            }
            else {
                // User entering this from the descendant side...
                // Always allow, because any number of datarecords could link to this descendant
            }
if ($debug) {
    if ($allow_multiple_links)
        print "\nallow multiple links: true\n";
    else
        print "\nallow multiple links: false\n";
}

            // TODO - Determine which, if any, datarecords can't be linked to because doing so would violate the "multiple_records_per_parent" rule
            $illegal_datarecords = array();
/*
            if ($allow_multiple_links && $descendant_datatype->getMultipleRecordsPerParent() == '0') {
                // User entering from the descendant side of a relationship only allowed to have a single record per ancestor...
                foreach ($datarecords as $num => $dr_id) {
                    // ...for each of the remote datarecords that could link to this datarecord...
                    if ( !isset($linked_datarecords[$dr_id]) ) {
                        // ...if the remote datarecord isn't linked to this datarecord...
                        $datatrees = $repo_linked_datatree->findBy( array('ancestor' => $dr_id) );
                        // ...if the remote datarecord is linked to a different datarecord, we're not allowed to link to it in this dialog
                        if ( count($datatrees) > 0 )
//                            $illegal_datarecords[] = $datarecord;
                            $illegal_datarecords[] = $dr_id;
                    }
                }
            }
*/

if ($debug) {
    print "\nillegal datarecords\n";
    foreach ($illegal_datarecords as $key => $id)
        print '-- datarecord '.$id."\n";
}

            // Need memcached for this...
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $templating = $this->get('templating');
//            $theme = $em->getRepository('ODRAdminBundle:Theme')->find(2);
            $theme = $em->getRepository('ODRAdminBundle:Theme')->find(4);   // TODO - need an offcial theme to indicate "textresults"

            // Convert the list of linked datarecords into a slightly different format so renderTextResultsList() can build it
            $datarecord_list = array();
            foreach ($linked_datarecords as $dr_id => $value)
                $datarecord_list[] = $dr_id;

            $table_html = parent::renderTextResultsList($datarecord_list, $remote_datatype, $request);
            $table_html = json_encode($table_html);
//print_r($table_html);
/*
            $table_html = '';
//            foreach ($datarecords as $num => $dr_id) {
            foreach ($linked_datarecords as $dr_id => $value) {
                // Attempt to load the datarecord from the cache...
                $data = $memcached->get($memcached_prefix.'.data_record_short_text_form_'.$dr_id);

                // No caching in dev environment
                if ($this->container->getParameter('kernel.environment') === 'dev')
                    $data = null;

                if ($data != null) {    // TODO - right datatype?  need to change this anyways due to using array of entities...
//                if ($data != null && $data['revision'] >= $descendant_datatype->getRevision()) {    // TODO - right datatype?  need to change this anyways due to using array of entities...
                    // ...if the html exists, append to the current list and continue
                    $table_html .= $data['html'];
if ($debug)
    print 'datarecord '.$dr_id." cached\n";
                }
                else {
                    // ...otherwise, render a blank entry as a stopgap measure
                    $datarecord = $repo_datarecord->find($dr_id);
//                    $html = $templating->render( 'ODRAdminBundle:TextResults:textresults_blank.html.twig', array('datatype' => $datarecord->getDataType(), 'datarecord' => $datarecord, 'theme' => $theme) );
                    $html = parent::Text_GetDisplayData($request, $dr_id);
                    $table_html .= $html;

if ($debug)
    print 'datarecord '.$dr_id." uncached\n";

                    // Since one of the memcached entries was null, schedule the datarecord for a memcached update...unless it's dev
                    if ($this->container->getParameter('kernel.environment') !== 'dev') {
                        $options = array();
                        parent::updateDatarecordCache($datarecord->getId(), $options);
                    }
                }
            }
*/

            // Grab the column names for the datatables plugin
            $column_names = parent::getDatatablesColumnNames($remote_datatype->getId());

            // Render the dialog box for this request
            $return['d'] = array(
                'html' => $templating->render(
                    'ODRAdminBundle:Record:link_datarecord_form.html.twig',
                    array(
                        'local_datarecord' => $local_datarecord,
                        'ancestor_datatype' => $ancestor_datatype,
                        'descendant_datatype' => $descendant_datatype,

                        'allow_multiple_links' => $allow_multiple_links,
                        'linked_datarecords' => $linked_datarecords,
                        'illegal_datarecords' => $illegal_datarecords,

                        'count' => count($linked_datarecords),
                        'table_html' => $table_html,
                        'column_names' => $column_names,
                    )
                )
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x293428835555 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Parses a $_POST request to modify whether a 'local' datarecord is linked to a 'remote' datarecord.
     * If such a link exists, GetDisplayData() will render a read-only version of the 'remote' datarecord in a ThemeElement of the 'local' datarecord.
     * 
     * @param Request $request
     * 
     * @return TODO
     */
    public function linkrecordAction(Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab the data from the POST request 
            $post = $_POST;
//print_r($post);
//return;

            $local_datarecord_id = $post['local_datarecord_id'];
            $ancestor_datatype_id = $post['ancestor_datatype_id'];
            $descendant_datatype_id = $post['descendant_datatype_id'];
            $allow_multiple_links = $post['allow_multiple_links'];
            $datarecords = array();
//            if ($allow_multiple_links == '1') {
                if ( isset($post['datarecords']) )
                    $datarecords = $post['datarecords'];
/*
            }
            else {
                if ( isset($post['datarecord']) )
                    $datarecords = array( $post['datarecord'] => 1 );
            }
*/
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();

            $repo_linked_datatree = $em->getRepository('ODRAdminBundle:LinkedDataTree');
            $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');

            $local_datarecord = $repo_datarecord->find($local_datarecord_id);
            if ( $local_datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $local_datatype = $local_datarecord->getDataType();
            if ( $local_datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);

            // Ensure user has permissions to be doing this
            if ( !(isset($user_permissions[ $local_datatype->getId() ]) && isset($user_permissions[ $local_datatype->getId() ][ 'edit' ])) )
                return parent::permissionDeniedError("edit");
            // --------------------


            // Grab the datatypes from the database
            $ancestor_datatype = $repo_datatype->find($ancestor_datatype_id);
            if ( $ancestor_datatype == null )
                return parent::deletedEntityError('DataType');

            $descendant_datatype = $repo_datatype->find($descendant_datatype_id);
            if ( $descendant_datatype == null )
                return parent::deletedEntityError('DataType');


            $linked_datatree = null;
            $local_datarecord_is_ancestor = true;
            if ($local_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
                $local_datarecord_is_ancestor = false;
            }

            // Grab records currently linked to the local_datarecord
            $remote = 'ancestor';
            if (!$local_datarecord_is_ancestor)
                $remote = 'descendant';

            $query = $em->createQuery(
               'SELECT ldt
                FROM ODRAdminBundle:LinkedDataTree AS ldt
                WHERE ldt.'.$remote.' = :datarecord
                AND ldt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $local_datarecord->getId()) );
            $results = $query->getResult();

            $linked_datatree = array();
            foreach ($results as $num => $ldt)
                $linked_datatree[] = $ldt;

$debug = true;
$debug = false;
if ($debug) {
    print_r($datarecords);
    print "\nlocal datarecord: ".$local_datarecord_id."\n";
    print "ancestor datatype: ".$ancestor_datatype_id."\n";
    print "descendant datatype: ".$descendant_datatype_id."\n";
    if ($local_datarecord_is_ancestor)
        print "local datarecord is ancestor\n";
    else
        print "local datarecord is descendant\n";
}

if ($debug) {
    print "\nlinked datatree\n";
    foreach ($linked_datatree as $ldt)
        print "-- ldt ".$ldt->getId().' ancestor: '.$ldt->getAncestor()->getId().' descendant: '.$ldt->getDescendant()->getId()."\n";
}

            foreach ($linked_datatree as $ldt) {
                $remote_datarecord = null;
                if ($local_datarecord_is_ancestor)
                    $remote_datarecord = $ldt->getDescendant();
                else
                    $remote_datarecord = $ldt->getAncestor();

                // Ensure that this descendant datarecord is of the same datatype that's being modified...don't want to delete links to datarecords of another datatype
                if ($local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $descendant_datatype->getId()) {
if ($debug)
    print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match descendant datatype\n";
                    continue;
                }
                else if (!$local_datarecord_is_ancestor && $remote_datarecord->getDataType()->getId() !== $ancestor_datatype->getId()) {
if ($debug)
    print 'skipping remote datarecord '.$remote_datarecord->getId().", does not match ancestor datatype\n";
                    continue;
                }

                // If a descendant datarecord isn't listed in $datarecords, it got unlinked
                if ( !isset($datarecords[$remote_datarecord->getId()]) ) {
if ($debug)
    print 'removing link between ancestor datarecord '.$ldt->getAncestor()->getId().' and descendant datarecord '.$ldt->getDescendant()->getId()."\n";

                    // Remove the linked_data_tree entry
                    $em->remove($ldt);
                }
                else {
                    // Otherwise, a datarecord was linked and still is linked...
                    unset( $datarecords[$remote_datarecord->getId()] );
if ($debug)
    print 'link between local datarecord '.$local_datarecord->getId().' and remote datarecord '.$remote_datarecord->getId()." already exists\n";
                }
            }

            // Anything remaining in $datarecords is a newly linked datarecord
            foreach ($datarecords as $id => $num) {
                $remote_datarecord = $repo_datarecord->find($id);

                // Attempt to find a link between these two datarecords that was deleted at some point in the past
                $ancestor_datarecord = null;
                $descendant_datarecord = null;
                if ($local_datarecord_is_ancestor) {
                    $ancestor_datarecord = $local_datarecord;
                    $descendant_datarecord = $remote_datarecord;
if ($debug)
    print 'ensuring link from local datarecord '.$local_datarecord->getId().' to remote datarecord '.$remote_datarecord->getId()."\n";
                }
                else {
                    $ancestor_datarecord = $remote_datarecord;
                    $descendant_datarecord = $local_datarecord;
if ($debug)
    print 'ensuring link from remote datarecord '.$remote_datarecord->getId().' to local datarecord '.$local_datarecord->getId()."\n";
                }

                // Ensure there is a link between the two datarecords
                parent::ODR_linkDataRecords($em, $user, $ancestor_datarecord, $descendant_datarecord);
            }

            $em->flush();

            $return['d'] = array(
                'datatype_id' => $descendant_datatype->getId(),
                'datarecord_id' => $local_datarecord->getId()
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x832812835 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
    * Given a child datatype id and a datarecord, re-render and return the html for that child datatype.
    * 
    * @param integer $datarecord_id The database id of the parent DataRecord
    * @param integer $datatype_id   The database id of the child DataType to re-render
    * @param Request $request
    * 
    * @return a Symfony JSON response containing HTML
    */
    public function reloadchildAction($datatype_id, $datarecord_id, Request $request)
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            $return['d'] = array(
                'datarecord_id' => $datarecord_id,
                'html' => self::GetDisplayData($request, $datarecord_id, 'child', $datatype_id),
            );
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x833871285 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;

    }


    /**
    * Given a datarecord and datafield, re-render and return the html for that datafield.
    * 
    * @param integer $datafield_id  The database id of the DataField inside the DataRecord to re-render.
    * @param integer $datarecord_id The database id of the DataRecord to re-render
    * @param Request $request
    *  
    * @return a Symfony JSON response containing HTML
    */  
    public function reloaddatafieldAction($datafield_id, $datarecord_id, Request $request)
    {

        $return = array();
        $return['r'] = 0;
        $return['t'] = 'html';
        $return['d'] = '';

        try {
            // Grab necessary objects
            $em = $this->getDoctrine()->getManager();
            $datafield = $em->getRepository('ODRAdminBundle:DataFields')->find($datafield_id);
            if ( $datafield == null )
                return parent::deletedEntityError('DataField');

            $theme_datafield = $datafield->getThemeDataField();
            foreach ($theme_datafield as $tdf) {
                if ($tdf->getTheme()->getId() == 1) {
                    $theme_datafield = $tdf;
                    break;
                }
            }

            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
//            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            // --------------------

            $datatype = $datafield->getDataType();
            $datarecord = $em->getRepository('ODRAdminBundle:DataRecord')->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('DataRecord');

            $datarecordfield = $em->getRepository('ODRAdminBundle:DataRecordFields')->findOneBy( array('dataRecord' => $datarecord_id, 'dataField' => $datafield_id) );
            $form = parent::buildForm($em, $user, $datarecord, $datafield, $datarecordfield, false, 0);

            $templating = $this->get('templating');
            $html = $templating->render(
                'ODRAdminBundle:Record:record_datafield.html.twig',
                array(
                    'mytheme' => $theme_datafield,
                    'field' => $datafield,
                    'datatype' => $datatype,
                    'datarecord' => $datarecord,
                    'datarecordfield' => $datarecordfield,
                    'form' => $form,
                )
            );

            $return['d'] = array('html' => $html);
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x833871285 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
     * Renders the HTML required to edit datafield values for a given record.
     *
     * If $template_name == 'child', $datarecord_id is the id of the parent datarecord and $child_datatype_id is the id of the child datatype
     *
     * @param Request $request
     * @param integer $datarecord_id
     * @param string $template_name
     * @param integer $child_datatype_id
     *
     * @return string
     */
    private function GetDisplayData(Request $request, $datarecord_id, $template_name = 'default', $child_datatype_id = null)
    {
        // Required objects
        $em = $this->getDoctrine()->getManager();
        $repo_datatype = $em->getRepository('ODRAdminBundle:DataType');
        $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
        $theme = $em->getRepository('ODRAdminBundle:Theme')->find(1);

        // --------------------
        // Determine user privileges
        $user = $this->container->get('security.context')->getToken()->getUser();
        $datatype_permissions = parent::getPermissionsArray($user->getId(), $request);
        $datafield_permissions = parent::getDatafieldPermissionsArray($user->getId(), $request);
        // --------------------

        $datarecord = null;
        $datatype = null;
        $theme_element = null;

        if ( $template_name === 'child' && $child_datatype_id !== null ) {
            $datarecord = $repo_datarecord->find($datarecord_id);
            $datatype = $repo_datatype->find($child_datatype_id);
        }
        else {
            $datarecord = $repo_datarecord->find($datarecord_id);
            $datatype = $datarecord->getDataType();
        }

        $datarecords = array($datarecord);

        $indent = 0;
        $is_link = 0;
        $top_level = 1;
        $short_form = false;
        $use_render_plugins = false;
        $public_only = false;

        if ($template_name == 'child') {
            // Determine if this is a 'child' render request for a top-level datatype
            $query = $em->createQuery(
               'SELECT dt.id AS dt_id
                FROM ODRAdminBundle:DataTree dt
                WHERE dt.deletedAt IS NULL AND dt.descendant = :datatype'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $results = $query->getResult();

            // If query found something, then it's not a top-level datatype
            if ( count($results) > 0 )
                $top_level = 0;

            // Since this is a child reload, need to grab all child/linked datarecords that belong in this childtype
            // TODO - determine whether this will end up grabbing child datarecords or linked datarecords?  only one of these will return results, and figuring out which one to run would require a second query anyways...
            $datarecords = array();
            $query = $em->createQuery(
               'SELECT dr
                FROM ODRAdminBundle:DataRecord dr
                JOIN ODRAdminBundle:DataType AS dt WITH dr.dataType = dt
                WHERE dr.parent = :datarecord AND dr.id != :datarecord_id AND dr.dataType = :datatype
                AND dr.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord->getId(), 'datarecord_id' => $datarecord->getId(), 'datatype' => $datatype->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $child_datarecord)
                $datarecords[] = $child_datarecord;

            // ...do the same for any datarecords that this datarecord links to
            $query = $em->createQuery(
               'SELECT descendant
                FROM ODRAdminBundle:LinkedDataTree ldt
                JOIN ODRAdminBundle:DataRecord AS descendant WITH ldt.descendant = descendant
                JOIN ODRAdminBundle:DataType AS dt WITH descendant.dataType = dt
                WHERE ldt.ancestor = :datarecord AND descendant.dataType = :datatype
                AND ldt.deletedAt IS NULL AND descendant.deletedAt IS NULL AND dt.deletedAt IS NULL'
            )->setParameters( array('datarecord' => $datarecord->getId(), 'datatype' => $datatype->getId()) );
            $results = $query->getResult();
            foreach ($results as $num => $linked_datarecord)
                $datarecords[] = $linked_datarecord;

        }

$debug = true;
$debug = false;
if ($debug)
    print '<pre>';

$start = microtime(true);
if ($debug)
    print "\n>> starting timing...\n\n";

        // Construct the arrays which contain all the required data
        $datatype_tree = parent::buildDatatypeTree($user, $theme, $datatype, $theme_element, $em, $is_link, $top_level, $short_form, $debug, $indent);
if ($debug)
    print "\n>> datatype_tree done in: ".(microtime(true) - $start)."\n\n";

        $datarecord_tree = array();
        foreach ($datarecords as $datarecord) {
            $datarecord_tree[] = parent::buildDatarecordTree($datarecord, $em, $user, $short_form, $use_render_plugins, $public_only, $debug, $indent);

if ($debug)
    print "\n>> datarecord_tree for datarecord ".$datarecord->getId()." done in: ".(microtime(true) - $start)."\n\n";

        }

if ($debug)
    print '</pre>';

        // Determine which template to use for rendering
        $template = 'ODRAdminBundle:Record:record_ajax.html.twig';
        if ($template_name == 'child')
            $template = 'ODRAdminBundle:Record:record_area_child_load.html.twig';

        // Determine what datatypes link to this datatype
        $ancestor_linked_datatypes = array();
        if ($template_name == 'default') {
            $query = $em->createQuery(
               'SELECT ancestor.id AS ancestor_id, ancestor.shortName AS ancestor_name
                FROM ODRAdminBundle:DataTree dt
                JOIN ODRAdminBundle:DataType AS ancestor WITH dt.ancestor = ancestor
                WHERE dt.is_link = 1 AND dt.descendant = :datatype
                AND dt.deletedAt IS NULL AND ancestor.deletedAt IS NULL'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $results = $query->getArrayResult();
            foreach ($results as $num => $result) {
                $id = $result['ancestor_id'];
                $name = $result['ancestor_name'];
                $ancestor_linked_datatypes[$id] = $name;
            }
        }

        // Determine what datatypes link to this datatype
        $descendant_linked_datatypes = array();
        if ($template_name == 'default') {
            $query = $em->createQuery(
               'SELECT descendant.id AS descendant_id, descendant.shortName AS descendant_name
                FROM ODRAdminBundle:DataTree dt
                JOIN ODRAdminBundle:DataType AS descendant WITH dt.descendant = descendant
                WHERE dt.is_link = 1 AND dt.ancestor = :datatype
                AND dt.deletedAt IS NULL AND descendant.deletedAt IS NULL'
            )->setParameters( array('datatype' => $datatype->getId()) );
            $results = $query->getArrayResult();
            foreach ($results as $num => $result) {
                $id = $result['descendant_id'];
                $name = $result['descendant_name'];
                $descendant_linked_datatypes[$id] = $name;
            }
        }


        // Render the DataRecord
        $templating = $this->get('templating');
        $html = $templating->render(
            $template,
            array(
                'datatype_tree' => $datatype_tree,
                'datarecord_tree' => $datarecord_tree,
                'theme' => $theme,
                'datatype_permissions' => $datatype_permissions,
                'datafield_permissions' => $datafield_permissions,
                'ancestor_linked_datatypes' => $ancestor_linked_datatypes,
                'descendant_linked_datatypes' => $descendant_linked_datatypes,
            )
        );

        return $html;
    }


    /**
     * Renders the edit form for a DataRecord if the user has the requisite permissions.
     * 
     * @param integer $datarecord_id The database id of the DataRecord the user wants to edit
     * @param string $search_key     Used for search header, an optional string describing which search result list $datarecord_id is a part of
     * @param integer $offset        Used for search header, an optional integer indicating which page of the search result list $datarecord_id is on
     * @param Request $request
     * 
     * @return a Symfony JSON response containing HTML
     */
    public function editAction($datarecord_id, $search_key, $offset, Request $request) 
    {
        $return = array();
        $return['r'] = 0;
        $return['t'] = "";
        $return['d'] = "";

        try {
            // Get necessary objects
            $memcached = $this->get('memcached');
            $memcached->setOption(\Memcached::OPT_COMPRESSION, true);
            $memcached_prefix = $this->container->getParameter('memcached_key_prefix');
            $session = $request->getSession();

            $em = $this->getDoctrine()->getManager();
            $repo_theme = $em->getRepository('ODRAdminBundle:Theme');
            $repo_datarecord = $em->getRepository('ODRAdminBundle:DataRecord');
            $repo_user_permissions = $em->getRepository('ODRAdminBundle:UserPermissions');
            $repo_datatree = $em->getRepository('ODRAdminBundle:DataTree');

            // Get Default Theme
            $theme = $repo_theme->find(1);

            // Get Record In Question
            $datarecord = $repo_datarecord->find($datarecord_id);
            if ( $datarecord == null )
                return parent::deletedEntityError('Datarecord');

            $datatype = $datarecord->getDataType();
            if ( $datatype == null )
                return parent::deletedEntityError('DataType');


            // --------------------
            // Determine user privileges
            $user = $this->container->get('security.context')->getToken()->getUser();
            $user_permissions = parent::getPermissionsArray($user->getId(), $request);
            $logged_in = true;

            // Ensure user has permissions to be doing this
            if ( !( isset($user_permissions[$datatype->getId()]) && ( isset($user_permissions[$datatype->getId()]['edit']) || isset($user_permissions[$datatype->getId()]['child_edit']) ) ) )
                return parent::permissionDeniedError("edit");
            // --------------------

            // Ensure all objects exist before rendering
            parent::verifyExistence($datatype, $datarecord);


            // ----------------------------------------
            // TODO - this block of code is duplicated at least 5 times across various controllers
            $encoded_search_key = '';
            $datarecords = '';
            if ($search_key !== '') {
                $search_controller = $this->get('odr_search_controller', $request);
                $search_controller->setContainer($this->container);

                if ( !$session->has('saved_searches') ) {
                    // no saved searches at all for some reason, redo the search with the given search key...
                    $search_controller->performSearch($search_key, $request);
                }

                // Grab the list of saved searches and attempt to locate the desired search
                $saved_searches = $session->get('saved_searches');
                $search_checksum = md5($search_key);
                if ( !isset($saved_searches[$search_checksum]) ) {
                    // no saved search for this query, redo the search...
                    $search_controller->performSearch($search_key, $request);

                    // Grab the list of saved searches again
                    $saved_searches = $session->get('saved_searches');
                }

                $search_params = $saved_searches[$search_checksum];
                $was_logged_in = $search_params['logged_in'];

                // If user's login status changed between now and when the search was run...
                if ($was_logged_in !== $logged_in) {
                    // ...run the search again 
                    $search_controller->performSearch($search_key, $request);
                    $saved_searches = $session->get('saved_searches');
                    $search_params = $saved_searches[$search_checksum];
                }

                // Now that the search is guaranteed to exist and be correct...get all pieces of info about the search
                $datarecords = $search_params['datarecords'];
                $encoded_search_key = $search_params['encoded_search_key'];
            }


            // If the user is attempting to view a datarecord from a search that returned no results...
            if ($encoded_search_key !== '' && $datarecords === '') {
                // ...redirect to "no results found" page
                return $search_controller->renderAction($encoded_search_key, 1, 'searching', $request);
            }

            // ----------------------------------------
            // If this edit request is coming from a search result...
            $search_header = parent::getSearchHeaderValues($datarecords, $datarecord->getId(), $request);


            // Render the record header separately
            $templating = $this->get('templating');
            $router = $this->get('router');
            $redirect_path = $router->generate('odr_record_edit', array('datarecord_id' => 0));
            $record_header_html = $templating->render(
                'ODRAdminBundle:Record:record_header.html.twig',
                array(
                    'datarecord' => $datarecord,
                    'datatype' => $datatype,
                    'datatype_permissions' => $user_permissions,

//                    'search_key' => $search_key,
                    'search_key' => $encoded_search_key,
                    'offset' => $offset,
                    'page_length' => $search_header['page_length'],
                    'next_datarecord' => $search_header['next_datarecord'],
                    'prev_datarecord' => $search_header['prev_datarecord'],
                    'search_result_current' => $search_header['search_result_current'],
                    'search_result_count' => $search_header['search_result_count'],
                    'redirect_path' => $redirect_path,
                )
            );

            $return['d'] = array(
                'datatype_id' => $datatype->getId(),
                'html' => $record_header_html.self::GetDisplayData($request, $datarecord->getId(), 'default'),
            );

            // Store which datarecord this is in the session so 
            $session = $request->getSession();
            $session->set('scroll_target', $datarecord->getId());
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x435858435 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }


    /**
    * Builds an array of all prior values of the given datafield, to serve as a both display of field history and a reversion dialog.
    * 
    * @param DataRecordFields $datarecordfield_id The database id of the DataRecord/DataField pair to look-up in the transaction log
    * @param mixed $entity_id                     The database id of the storage entity to look-up in the transaction log
    * @param Request $request 
    * 
    * @return a Symfony JSON response containing HTML
    */
    public function getfieldhistoryAction($datarecordfield_id, $entity_id, Request $request) {
        $return['r'] = 0;
        $return['t'] = '';
        $return['d'] = '';

        try {
            // Ensure user has permissions to be doing this
            $user = $this->container->get('security.context')->getToken()->getUser();
            if (!$user->hasRole('ROLE_SUPER_ADMIN')) {
                $return['r'] = 2;
            }
            else {
                // Get Entity Manager and setup repositories
                $em = $this->getDoctrine()->getManager();
                $repo_datarecordfields = $em->getRepository('ODRAdminBundle:DataRecordFields');
                $drf = $repo_datarecordfields->find($datarecordfield_id);

                $type_class = $drf->getDataField()->getFieldType()->getTypeClass();
                $repo_entity = $em->getRepository("ODR\\AdminBundle\\Entity\\".$type_class);
                $entity = $repo_entity->find($entity_id);

                $repo_logging = $em->getRepository('Gedmo\Loggable\Entity\LogEntry');
                $all_log_entries = $repo_logging->getLogEntries($entity);
                $log_entries = array();

                $user_manager = $this->container->get('fos_user.user_manager');
                $all_users = $user_manager->findUsers();
                $users = array();
                foreach ($all_users as $user) {
                    $users[ $user->getUsername() ] = $user;
                }

                foreach ($all_log_entries as $entry) {
                    $data = $entry->getData();
//print_r($data);
//return;

                    // Due to log entries not being identical, need to create a new array so the templating doesn't get confused
                    $tmp = array();
                    $tmp['id'] = $entry->getId();
                    $tmp['version'] = $entry->getVersion();
                    $tmp['loggedat'] = $entry->getLoggedAt();
                    $tmp['user'] = $users[ $entry->getUsername() ];

                    if ( $type_class == 'DatetimeValue' ) {
                        // Null values in the log entries screw up the datetime to string formatter
                        if ( $data['value'] != null )
                            $tmp['value'] = $data['value']->format('Y-m-d');
                        else
                            $tmp['value'] = '';
                    }
                    else if ( $type_class == 'DecimalValue' ) {
                        // Log entries denote changes to base and exponent separately
                        $tmp['value'] = array();
                        if ( isset($data['base']) )
                            $tmp['value']['base'] = $data['base'];
                        if ( isset($data['exponent']) )
                            $tmp['value']['exponent'] = $data['exponent'];
                        if ( isset($data['value']) ) {
                            $tmp['value']['base'] = 0;
                            $tmp['value']['exponent'] = 0;
                        }
                    }
                    else if ( isset($data['value']) ) {
                        // Otherwise, just store the value
                        $tmp['value'] = $data['value'];
                    }
                    else {
                        // Don't bother if there's no value listed...
                        continue;
                    }

                    $log_entries[] = $tmp;
                }
//print_r($log_entries);

                if ( $type_class == 'DecimalValue' ) {
                    // Because changes to base/exponent stored separately, need to combine entries...
                    $base = null;
                    $exponent = null;
                    for ($i = count($log_entries)-1; $i >= 0; $i--) {
                        $value = $log_entries[$i]['value'];
                        if ($base === null && $exponent === null) {
                            // This should be the original creation of the entry, should always have base/exponent
                            $base = $value['base'];
                            $exponent = $value['exponent'];
                            $log_entries[$i]['value'] = 0;
                        }
                        else {
                            // Carry over or update the base
                            if ( isset($value['base']) )
                                $base = $value['base'];

                            // Carry over or update the exponent
                            if ( isset($value['exponent']) )
                                $exponent = $value['exponent'];

                            $log_entries[$i]['value'] = DecimalValue::DecimalToString($base, $exponent);
                        }
                    }
                }
//print "--------------------\n";
//print_r($log_entries);
//return;

                $form_classname = "\\ODR\\AdminBundle\\Form\\".$type_class."Form";
//                $datarecordfield = $repo_datarecordfields->find($datarecordfield_id);

                // Get related object using switch
                $ignore_request = false;
                $form = null;
                switch($type_class) {
                    case 'File':
                    case 'Image':
                    case 'Radio':
                        $ignore_request = true;
                        break;
                    default:
//                        $my_obj = parent::loadFromDataRecordField($datarecordfield, $field_type);
                        $my_obj = $drf->getAssociatedEntity();
                        $form = $this->createForm(new $form_classname($em), $my_obj);
                    break;
                }

                // Render the dialog box for this request
                if (!$ignore_request) {
                    $templating = $this->get('templating');
                    $return['d'] = array(
                        'html' => $templating->render(
                            'ODRAdminBundle:Record:field_history_dialog_form.html.twig',
                            array(
                                'log_entries' => $log_entries,
                                'record_type' => $type_class,
                                'data_record_field_id' => $drf->getId(),
                                'datarecord_id' => $drf->getDataRecord()->getId(),
                                'field_id' => $entity_id,
                                'form' => $form->createView()
                            )
                        )
                    );
                }
            }
        }
        catch (\Exception $e) {
            $return['r'] = 1;
            $return['t'] = 'ex';
            $return['d'] = 'Error 0x29534288935 ' . $e->getMessage();
        }

        $response = new Response(json_encode($return));
        $response->headers->set('Content-Type', 'application/json');
        return $response;
    }

}